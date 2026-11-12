<?php

namespace Espo\Modules\StudioManagement\Tools\Mq;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\StudioManagement\Entities\SmMqTask;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Throwable;

final class RabbitMqWorker
{
    public function __construct(
        private MqConfig $config,
        private RabbitMqTopology $topology,
        private MqTaskService $tasks,
        private InjectableFactory $factory,
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function run(string $queue, bool $once = false): void
    {
        if (!$this->config->isEnabled() || !array_key_exists($queue, $this->config->bindings())) {
            throw new RuntimeException('RabbitMQ is disabled or the queue is unsupported.');
        }
        $channel = $this->topology->declare();
        $channel->basic_qos(0, $this->config->prefetch(), false);
        // A one-shot consumer must not prefetch a second delivery that is left
        // unacked until process shutdown (not visible to fixture/CLI cleanup).
        if ($once) {
            $message = $channel->basic_get($queue);
            if ($message) {
                WorkerHeartbeat::arm($this->config->taskTimeoutSeconds());
                $this->process($queue, $message);
                WorkerHeartbeat::disarm();
            }
            return;
        }
        $tag = $channel->basic_consume($queue, '', false, false, false, false,
            function (AMQPMessage $message) use ($queue): void {
                WorkerHeartbeat::arm($this->config->taskTimeoutSeconds());
                $this->process($queue, $message);
                WorkerHeartbeat::disarm();
            });
        try {
            while ($channel->is_consuming()) {
                WorkerHeartbeat::beat();
                try {
                    $channel->wait(null, false, 1.0);
                } catch (AMQPTimeoutException) {
                }
            }
        } finally {
            if ($channel->is_open()) {
                $channel->basic_cancel($tag);
            }
        }
    }

    private function process(string $queue, AMQPMessage $message): void
    {
        try {
            $envelope = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($envelope) || ($envelope['schemaVersion'] ?? null) !== 1 ||
                !is_string($envelope['taskId'] ?? null) || !is_string($envelope['eventId'] ?? null) ||
                !is_string($envelope['routingKey'] ?? null) ||
                $this->config->queueForRoutingKey($envelope['routingKey']) !== $queue) {
                throw new RuntimeException('Invalid RabbitMQ envelope.');
            }
        } catch (Throwable $e) {
            $this->log->error('Studio MQ: rejecting malformed message.', ['exception' => $e]);
            $message->nack(false, false);
            return;
        }

        // Commit the delivery attempt BEFORE execution. SIGKILL/OOM must not reset it.
        $claim = $this->entityManager->getTransactionManager()->run(function () use ($envelope, $queue): array {
            $task = $this->tasks->get($envelope['taskId'], true);
            if (!$task || $task->get('eventId') !== $envelope['eventId'] ||
                $task->get('routingKey') !== $envelope['routingKey'] || $task->get('queueName') !== $queue) {
                return ['outcome' => 'reject'];
            }
            if ($task->get('status') === SmMqTask::STATUS_COMPLETED) {
                return ['outcome' => 'ack'];
            }
            if ($task->get('status') === SmMqTask::STATUS_FAILED) {
                return ['outcome' => 'reject'];
            }
            if ($task->get('status') === SmMqTask::STATUS_QUEUED &&
                (string) $task->get('availableAt') > gmdate('Y-m-d H:i:s')) {
                return ['outcome' => 'ack'];
            }
            if ((int) $task->get('attempt') >= (int) $task->get('maxAttempts')) {
                $this->tasks->markFailed($task->getId(), 'Worker interrupted repeatedly; delivery limit reached.');
                return ['outcome' => 'reject'];
            }
            $task = $this->tasks->markProcessing($task->getId());
            return ['attempt' => (int) $task->get('attempt')];
        });
        $outcome = $claim['outcome'] ?? $this->entityManager->getTransactionManager()->run(function () use ($envelope, $claim): string {
            // Fence a competing duplicate claim; execution and financial changes remain atomic.
            $task = $this->tasks->get($envelope['taskId'], true);
            if (!$task || $task->get('status') !== SmMqTask::STATUS_PROCESSING ||
                (int) $task->get('attempt') !== $claim['attempt']) {
                return 'ack';
            }
            $stored = $this->tasks->envelope($task);
            try {
                $registry = $this->factory->create(TaskHandlerRegistry::class);
                $result = $this->entityManager->getTransactionManager()->run(
                    fn () => $registry->get($stored['routingKey'])->handle($stored['payload'], $stored['security'])
                );
            } catch (Throwable $e) {
                $this->log->error('Studio MQ: task failed.', ['taskId' => $task->getId(), 'exception' => $e]);
                if ((int) $task->get('attempt') < (int) $task->get('maxAttempts')) {
                    $this->tasks->markRetrying($task->getId(), $e->getMessage());
                    return 'ack';
                }
                $this->tasks->markFailed($task->getId(), $e->getMessage());
                return 'reject';
            }
            $this->tasks->markCompleted($task->getId(), $result);
            $this->enqueueCompletionNotification($task, $stored['routingKey']);
            return 'ack';
        });

        // ACK errors escape to Docker restart without rewriting committed state.
        if ($outcome === 'ack') {
            $message->ack();
        } else {
            $message->nack(false, false);
        }
    }

    private function enqueueCompletionNotification(Entity $task, string $routingKey): void
    {
        if (str_starts_with($routingKey, MqConfig::ROUTING_NOTIFICATION_PREFIX)) {
            return;
        }
        $userId = $task->get('initiatedById');
        $user = is_string($userId) ? $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId) : null;
        if (!$user instanceof User) {
            return;
        }
        $this->tasks->enqueue(MqConfig::ROUTING_NOTIFICATION_PREFIX . 'taskCompleted', [
            'topic' => 'studioMqTaskCompleted',
            'userId' => $userId,
            'data' => ['taskId' => $task->getId(), 'routingKey' => $routingKey, 'status' => SmMqTask::STATUS_COMPLETED],
        ], $user);
    }
}
