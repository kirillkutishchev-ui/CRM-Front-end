<?php

namespace Espo\Modules\StudioManagement\Tools\Mq;

use Espo\Core\Utils\Util;
use Espo\Entities\User;
use Espo\Modules\StudioManagement\Entities\SmMqTask;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;
use stdClass;
use DateTimeImmutable;
use DateTimeZone;
use Closure;

final class MqTaskService
{
    public function __construct(
        private EntityManager $entityManager,
        private MqConfig $config,
    ) {}

    /** @param array<string, mixed> $payload */
    public function enqueue(string $routingKey, array $payload, User $initiator, ?string $retryOfId = null): Entity
    {
        if (!$this->config->isEnabled()) {
            throw new RuntimeException('RabbitMQ task processing is disabled.');
        }

        $eventId = Util::generateId();
        $task = $this->entityManager->getNewEntity(SmMqTask::ENTITY_TYPE);
        $task->set([
            'name' => $routingKey . ' — ' . $eventId,
            'eventId' => $eventId,
            'routingKey' => $routingKey,
            'queueName' => $this->config->queueForRoutingKey($routingKey),
            'status' => SmMqTask::STATUS_QUEUED,
            'payload' => (object) $payload,
            'securityContext' => (object) $this->securityContext($initiator),
            'attempt' => 0,
            'maxAttempts' => $this->config->maxAttempts(),
            'retryOfId' => $retryOfId,
            'initiatedById' => $initiator->getId(),
            'aggregateId' => is_string($payload['shiftId'] ?? null) ? $payload['shiftId'] : null,
            'availableAt' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($task);

        return $task;
    }

    public function get(string $id, bool $lock = false): ?Entity
    {
        $query = $this->entityManager
            ->getRDBRepository(SmMqTask::ENTITY_TYPE)
            ->where(['id' => $id, 'deleted' => false]);

        return ($lock ? $query->forUpdate() : $query)->findOne();
    }

    /** @return iterable<Entity> */
    public function queued(int $limit = 25): iterable
    {
        return $this->entityManager
            ->getRDBRepository(SmMqTask::ENTITY_TYPE)
            ->where(['status' => SmMqTask::STATUS_QUEUED, 'deleted' => false,
                'availableAt<=' => gmdate('Y-m-d H:i:s')])
            ->order('createdAt')
            ->limit(0, $limit)
            ->find();
    }

    /** @return array<string, mixed> */
    public function envelope(Entity $task): array
    {
        return [
            'schemaVersion' => 1,
            'taskId' => $task->getId(),
            'eventId' => (string) $task->get('eventId'),
            'routingKey' => (string) $task->get('routingKey'),
            'occurredAt' => (string) ($task->get('createdAt') ?: gmdate('Y-m-d H:i:s')),
            'security' => $this->objectToArray($task->get('securityContext')),
            'payload' => $this->objectToArray($task->get('payload')),
        ];
    }

    public function markPublished(string $id): void
    {
        $this->entityManager->getTransactionManager()->run(function () use ($id): void {
            $task = $this->get($id, true);
            if ($task && $task->get('status') === SmMqTask::STATUS_QUEUED) {
                $task->set(['status' => SmMqTask::STATUS_PUBLISHED, 'publishedAt' => gmdate('Y-m-d H:i:s')]);
                $this->entityManager->saveEntity($task);
            }
        });
    }

    /** Keep the row locked until publish-confirm and the DB transition finish. */
    public function publishLocked(string $id, Closure $publish): bool
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($id, $publish): bool {
            $task = $this->get($id, true);
            if (!$task || $task->get('status') !== SmMqTask::STATUS_QUEUED ||
                (string) $task->get('availableAt') > gmdate('Y-m-d H:i:s')) {
                return false;
            }
            $publish($task);
            $this->markPublished($id);
            return true;
        });
    }

    public function markProcessing(string $id): Entity
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($id): Entity {
            $task = $this->get($id, true);

            if (!$task) {
                throw new RuntimeException("MQ task {$id} was not found.");
            }

            if (in_array($task->get('status'), [SmMqTask::STATUS_COMPLETED, SmMqTask::STATUS_FAILED], true)) {
                return $task;
            }

            $task->set([
                'status' => SmMqTask::STATUS_PROCESSING,
                'startedAt' => gmdate('Y-m-d H:i:s'),
                'attempt' => (int) $task->get('attempt') + 1,
                'errorMessage' => null,
            ]);
            $this->entityManager->saveEntity($task);

            return $task;
        });
    }

    /** @param array<string, mixed> $result */
    public function markCompleted(string $id, array $result): void
    {
        $this->update($id, [
            'status' => SmMqTask::STATUS_COMPLETED,
            'result' => (object) $result,
            'completedAt' => gmdate('Y-m-d H:i:s'),
            'errorMessage' => null,
        ]);
    }

    public function markFailed(string $id, string $error): Entity
    {
        return $this->update($id, [
            'status' => SmMqTask::STATUS_FAILED,
            'errorMessage' => mb_substr($error, 0, 5000),
            'completedAt' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function markRetrying(string $id, string $error): Entity
    {
        return $this->update($id, [
            'status' => SmMqTask::STATUS_QUEUED,
            'errorMessage' => mb_substr($error, 0, 5000),
            'completedAt' => null,
            'availableAt' => gmdate('Y-m-d H:i:s', time() + (int) ceil($this->config->retryDelayMs() / 1000)),
        ]);
    }

    /** @param array<string, mixed>|null $payload */
    public function retry(Entity $failedTask, User $initiator, ?array $payload = null): Entity
    {
        if ($failedTask->get('status') !== SmMqTask::STATUS_FAILED) {
            throw new RuntimeException('Only a failed task can be retried manually.');
        }

        return $this->enqueue(
            (string) $failedTask->get('routingKey'),
            $payload ?? $this->objectToArray($failedTask->get('payload')),
            $initiator,
            $failedTask->getId(),
        );
    }

    public function cleanupCompleted(): int
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $this->config->taskRetentionDays() . ' days')
            ->format('Y-m-d H:i:s');
        $repository = $this->entityManager->getRDBRepository(SmMqTask::ENTITY_TYPE);
        $tasks = $repository
            ->where([
                'status' => SmMqTask::STATUS_COMPLETED,
                'completedAt<' => $cutoff,
            ])
            ->limit(0, 500)
            ->find();
        $count = 0;

        foreach ($tasks as $task) {
            $repository->deleteFromDb($task->getId());
            $count++;
        }

        return $count;
    }

    /** @param array<string, mixed> $values */
    private function update(string $id, array $values): Entity
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($id, $values): Entity {
            $task = $this->get($id, true);

            if (!$task) {
                throw new RuntimeException("MQ task {$id} was not found.");
            }

            $task->set($values);
            $this->entityManager->saveEntity($task);

            return $task;
        });
    }

    /** @return array<string, mixed> */
    private function securityContext(User $user): array
    {
        return [
            'userId' => $user->getId(),
            'userName' => (string) $user->get('userName'),
            'isAdmin' => $user->isAdmin(),
            'roleIds' => array_values(array_filter(
                (array) ($user->get('rolesIds') ?? []),
                static fn (mixed $id): bool => is_string($id),
            )),
        ];
    }

    /** @return array<string, mixed> */
    private function objectToArray(mixed $value): array
    {
        if ($value instanceof stdClass) {
            return (array) $value;
        }

        return is_array($value) ? $value : [];
    }
}
