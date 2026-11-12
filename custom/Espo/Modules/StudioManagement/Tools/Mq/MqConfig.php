<?php

namespace Espo\Modules\StudioManagement\Tools\Mq;

use InvalidArgumentException;

final class MqConfig
{
    public const QUEUE_ANALYTICS = 'analytics_tasks';
    public const QUEUE_FILES = 'file_processing';
    public const QUEUE_WEBSOCKET = 'websocket_notifications';

    public const ROUTING_ANALYTICS = 'analytics.calculate';
    public const ROUTING_FILES = 'shift.materials.process';
    public const ROUTING_NOTIFICATION_PREFIX = 'ui.notification.';

    public function isEnabled(): bool
    {
        return filter_var($this->env('MQ_ENABLED', 'false'), FILTER_VALIDATE_BOOL);
    }

    public function host(): string
    {
        return $this->env('MQ_HOST', 'crm_rabbitmq');
    }

    public function port(): int
    {
        return $this->positiveInt('MQ_PORT', 5672);
    }

    public function user(): string
    {
        return $this->required('MQ_USER');
    }

    public function password(): string
    {
        return $this->required('MQ_PASSWORD');
    }

    public function vhost(): string
    {
        return $this->env('MQ_VHOST', '/');
    }

    public function exchange(): string
    {
        return $this->env('MQ_EXCHANGE', 'crm.events');
    }

    public function deadLetterExchange(): string
    {
        return $this->env('MQ_DLX', 'crm.dlx');
    }

    public function prefetch(): int
    {
        return $this->positiveInt('MQ_PREFETCH', 1);
    }

    public function maxAttempts(): int
    {
        return $this->positiveInt('MQ_MAX_ATTEMPTS', 3);
    }

    public function retryDelayMs(): int
    {
        return $this->positiveInt('MQ_RETRY_DELAY_MS', 5000);
    }

    public function taskRetentionDays(): int
    {
        return $this->positiveInt('MQ_TASK_RETENTION_DAYS', 7);
    }

    public function taskTimeoutSeconds(): int
    {
        return $this->positiveInt('MQ_TASK_TIMEOUT_SECONDS', 120);
    }

    public function queueForRoutingKey(string $routingKey): string
    {
        return match (true) {
            $routingKey === self::ROUTING_ANALYTICS => self::QUEUE_ANALYTICS,
            $routingKey === self::ROUTING_FILES => self::QUEUE_FILES,
            str_starts_with($routingKey, self::ROUTING_NOTIFICATION_PREFIX) => self::QUEUE_WEBSOCKET,
            default => throw new InvalidArgumentException("Unsupported routing key: {$routingKey}"),
        };
    }

    /** @return array<string, string> */
    public function bindings(): array
    {
        return [
            self::QUEUE_ANALYTICS => self::ROUTING_ANALYTICS,
            self::QUEUE_FILES => self::ROUTING_FILES,
            self::QUEUE_WEBSOCKET => self::ROUTING_NOTIFICATION_PREFIX . '*',
        ];
    }

    private function positiveInt(string $name, int $default): int
    {
        $value = filter_var($this->env($name, (string) $default), FILTER_VALIDATE_INT);

        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException("{$name} must be a positive integer.");
        }

        return $value;
    }

    private function required(string $name): string
    {
        $value = $this->env($name, '');

        if ($value === '') {
            throw new InvalidArgumentException("{$name} is required when RabbitMQ is enabled.");
        }

        return $value;
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
