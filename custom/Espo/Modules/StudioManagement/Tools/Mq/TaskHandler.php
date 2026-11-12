<?php

namespace Espo\Modules\StudioManagement\Tools\Mq;

interface TaskHandler
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $security
     * @return array<string, mixed>
     */
    public function handle(array $payload, array $security): array;
}
