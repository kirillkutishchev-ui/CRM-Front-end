<?php

namespace Espo\Modules\StudioManagement\Tools\Mq;

use InvalidArgumentException;

final class TaskHandlerRegistry
{
    public function __construct(
        private AnalyticsTaskHandler $analytics,
        private ShiftMaterialsTaskHandler $materials,
        private WebSocketTaskHandler $webSocket,
    ) {}

    public function get(string $routingKey): TaskHandler
    {
        return match (true) {
            $routingKey === MqConfig::ROUTING_ANALYTICS => $this->analytics,
            $routingKey === MqConfig::ROUTING_FILES => $this->materials,
            str_starts_with($routingKey, MqConfig::ROUTING_NOTIFICATION_PREFIX) => $this->webSocket,
            default => throw new InvalidArgumentException("No handler for routing key {$routingKey}."),
        };
    }
}
