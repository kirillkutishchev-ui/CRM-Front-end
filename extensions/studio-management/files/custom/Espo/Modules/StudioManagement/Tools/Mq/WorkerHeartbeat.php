<?php

namespace Espo\Modules\StudioManagement\Tools\Mq;

/** Process-local liveness, with a hard deadline so a stuck task is redelivered. */
final class WorkerHeartbeat
{
    public static function beat(): void
    {
        if (!touch('/tmp/studio-mq-heartbeat')) {
            throw new \RuntimeException('Cannot update MQ heartbeat.');
        }
    }

    public static function arm(int $seconds): void
    {
        self::beat();
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void { exit(124); });
        pcntl_alarm($seconds);
    }

    public static function disarm(): void
    {
        pcntl_alarm(0);
        self::beat();
    }
}
