<?php

namespace Espo\Modules\StudioManagement\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Entities\User;
use Espo\Modules\StudioManagement\Tools\Mq\MqTaskService;
use Espo\ORM\EntityManager;
use RuntimeException;

final class MqRetry implements Command
{
    public function __construct(
        private MqTaskService $tasks,
        private EntityManager $entityManager,
    ) {}

    public function run(Params $params, IO $io): void
    {
        $taskId = $params->getOption('taskId');

        if (!$taskId) {
            throw new RuntimeException('The --task-id option is required.');
        }

        $task = $this->tasks->get($taskId);

        if (!$task) {
            throw new RuntimeException("Task {$taskId} was not found.");
        }

        $payload = null;
        $payloadFile = $params->getOption('payloadFile');

        if ($payloadFile) {
            $contents = file_get_contents($payloadFile);
            $payload = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            if (!is_array($payload)) {
                throw new RuntimeException('Payload file must contain a JSON object.');
            }
        }

        $userId = $params->getOption('asUserId') ?: $task->get('initiatedById');
        $initiator = is_string($userId)
            ? $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId)
            : null;

        if (!$initiator instanceof User) {
            throw new RuntimeException('A valid original or --as-user-id initiator is required.');
        }

        $newTask = $this->tasks->retry($task, $initiator, $payload);
        $io->writeLine('Created corrected retry task ' . $newTask->getId() . '.');
    }
}
