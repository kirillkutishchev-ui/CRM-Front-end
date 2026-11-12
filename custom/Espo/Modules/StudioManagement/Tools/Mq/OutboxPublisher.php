<?php

namespace Espo\Modules\StudioManagement\Tools\Mq;

use Espo\Core\Utils\Log;
use Throwable;

final class OutboxPublisher
{
    private int $lastCleanupAt = 0;

    public function __construct(
        private MqConfig $config,
        private MqTaskService $tasks,
        private RabbitMqTopology $topology,
        private Log $log,
    ) {}

    public function publishBatch(int $limit = 25): int
    {
        if (!$this->config->isEnabled()) {
            return 0;
        }

        $this->cleanupCompletedTasks();
        $published = 0;

        foreach ($this->tasks->queued($limit) as $task) {
            try {
                $sent = $this->tasks->publishLocked($task->getId(), function ($lockedTask): void {
                    $this->topology->publish(
                        (string) $lockedTask->get('routingKey'),
                        $this->tasks->envelope($lockedTask),
                    );
                });
                $published += $sent ? 1 : 0;
            } catch (Throwable $e) {
                $this->log->error('Studio MQ: outbox publication failed.', [
                    'taskId' => $task->getId(),
                    'eventId' => $task->get('eventId'),
                    'exception' => $e,
                ]);

                break;
            }
        }

        return $published;
    }

    private function cleanupCompletedTasks(): void
    {
        $now = time();

        if ($now - $this->lastCleanupAt < 3600) {
            return;
        }

        try {
            $removed = $this->tasks->cleanupCompleted();
            $this->lastCleanupAt = $now;

            if ($removed > 0) {
                $this->log->info("Studio MQ: removed {$removed} expired completed task(s).");
            }
        } catch (Throwable $e) {
            $this->log->error('Studio MQ: completed-task cleanup failed.', ['exception' => $e]);
        }
    }
}
