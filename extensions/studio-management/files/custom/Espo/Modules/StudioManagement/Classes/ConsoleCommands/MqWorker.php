<?php

namespace Espo\Modules\StudioManagement\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Modules\StudioManagement\Tools\Mq\RabbitMqWorker;
use RuntimeException;

final class MqWorker implements Command
{
    public function __construct(private RabbitMqWorker $worker) {}

    public function run(Params $params, IO $io): void
    {
        $queue = $params->getOption('queue');

        if (!$queue) {
            throw new RuntimeException('The --queue option is required.');
        }

        $io->writeLine("Starting Studio RabbitMQ worker for {$queue}.");
        $this->worker->run($queue, $params->hasFlag('once'));
    }
}
