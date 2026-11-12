<?php

namespace Espo\Modules\StudioManagement\Tools\Mq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

/** A process-local singleton managed by Espo's dependency-injection container. */
final class RabbitMqConnection
{
    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;

    public function __construct(private MqConfig $config) {}

    public function channel(): AMQPChannel
    {
        if ($this->channel && $this->channel->is_open()) {
            return $this->channel;
        }

        $this->connection = new AMQPStreamConnection(
            $this->config->host(),
            $this->config->port(),
            $this->config->user(),
            $this->config->password(),
            $this->config->vhost(),
        );
        $this->channel = $this->connection->channel();

        return $this->channel;
    }

    public function close(): void
    {
        try {
            if ($this->channel?->is_open()) {
                $this->channel->close();
            }
        } catch (Throwable) {
        }

        try {
            if ($this->connection?->isConnected()) {
                $this->connection->close();
            }
        } catch (Throwable) {
        }

        $this->channel = null;
        $this->connection = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
