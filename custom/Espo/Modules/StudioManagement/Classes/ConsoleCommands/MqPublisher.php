<?php

namespace Espo\Modules\StudioManagement\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Modules\StudioManagement\Tools\Mq\OutboxPublisher;
use Espo\Modules\StudioManagement\Tools\Mq\WorkerHeartbeat;

final class MqPublisher implements Command
{
    public function __construct(private OutboxPublisher $publisher) {}

    public function run(Params $params, IO $io): void
    {
        $once = $params->hasFlag('once');

        do {
            WorkerHeartbeat::arm(120);
            $count = $this->publisher->publishBatch();
            WorkerHeartbeat::disarm();

            if ($once) {
                $io->writeLine("Published {$count} outbox task(s).");
                return;
            }

            usleep($count > 0 ? 50_000 : 500_000);
        } while (true);
    }
}
