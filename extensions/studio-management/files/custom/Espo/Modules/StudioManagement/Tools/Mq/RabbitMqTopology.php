<?php

namespace Espo\Modules\StudioManagement\Tools\Mq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;

final class RabbitMqTopology
{
    private ?AMQPChannel $confirmedChannel = null;
    public function __construct(
        private MqConfig $config,
        private RabbitMqConnection $connection,
    ) {}

    public function declare(): AMQPChannel
    {
        $channel = $this->connection->channel();
        $channel->exchange_declare($this->config->exchange(), 'topic', false, true, false);
        $channel->exchange_declare($this->config->deadLetterExchange(), 'topic', false, true, false);

        foreach ($this->config->bindings() as $queue => $routingKey) {
            $channel->queue_declare(
                $queue,
                false,
                true,
                false,
                false,
                false,
                new AMQPTable([
                    'x-dead-letter-exchange' => $this->config->deadLetterExchange(),
                    'x-dead-letter-routing-key' => $queue,
                ]),
            );
            $channel->queue_bind($queue, $this->config->exchange(), $routingKey);

            $deadLetterQueue = $queue . '.dlq';
            $channel->queue_declare($deadLetterQueue, false, true, false, false);
            $channel->queue_bind($deadLetterQueue, $this->config->deadLetterExchange(), $queue);

        }

        return $channel;
    }

    /** @param array<string, mixed> $envelope */
    public function publish(string $routingKey, array $envelope): void
    {
        $channel = $this->declare();
        if ($this->confirmedChannel !== $channel) {
            $channel->confirm_select();
            $this->confirmedChannel = $channel;
        }
        $failure = null;
        $channel->set_nack_handler(static function () use (&$failure): void {
            $failure = new RuntimeException('RabbitMQ rejected the publication.');
        });
        $channel->set_return_listener(static function () use (&$failure): void {
            $failure = new RuntimeException('RabbitMQ returned an unroutable publication.');
        });
        $channel->basic_publish(
            $this->message($envelope),
            $this->config->exchange(),
            $routingKey,
            true,
        );
        $channel->wait_for_pending_acks_returns(5.0);
        if ($failure) {
            throw $failure;
        }
    }

    /** @param array<string, mixed> $envelope */
    private function message(array $envelope): AMQPMessage
    {
        $eventId = is_string($envelope['eventId'] ?? null) ? $envelope['eventId'] : '';
        $routingKey = is_string($envelope['routingKey'] ?? null) ? $envelope['routingKey'] : '';

        return new AMQPMessage(
            json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            [
                'content_type' => 'application/json',
                'content_encoding' => 'utf-8',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $eventId,
                'correlation_id' => $eventId,
                'type' => $routingKey,
                'timestamp' => time(),
            ],
        );
    }
}
