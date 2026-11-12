<?php

namespace Espo\Modules\StudioManagement\Tools\Mq;

use Espo\Core\Utils\Config;
use RuntimeException;
use stdClass;

final class WebSocketTaskHandler implements TaskHandler
{
    public function __construct(private Config $config, private TaskAuthorization $authorization) {}

    public function handle(array $payload, array $security): array
    {
        $topic = $payload['topic'] ?? null;
        $userId = $payload['userId'] ?? ($security['userId'] ?? null);
        $data = $payload['data'] ?? [];
        if ($data instanceof stdClass) {
            $data = (array) $data;
        }

        if ($topic !== 'studioMqTaskCompleted' || !is_string($userId) ||
            $userId !== ($security['userId'] ?? null) || !is_array($data)) {
            throw new RuntimeException('WebSocket task payload is invalid.');
        }

        if (!$this->config->get('useWebSocket')) {
            throw new RuntimeException('WebSocket is disabled.');
        }
        $this->authorization->notification($userId);
        // Core Submission intentionally swallows transport errors. A queue handler
        // must propagate them, and must not buffer a successful send to a dead peer.
        $socket = (new \ZMQContext())->getSocket(\ZMQ::SOCKET_PUSH);
        $socket->setSockOpt(\ZMQ::SOCKOPT_LINGER, 0);
        $socket->setSockOpt(\ZMQ::SOCKOPT_IMMEDIATE, 1);
        $socket->setSockOpt(\ZMQ::SOCKOPT_SNDTIMEO, 3000);
        $socket->connect($this->config->get('webSocketZeroMQSubmissionDsn') ?? 'tcp://websocket:7777');
        $socket->send(json_encode(array_merge($data, ['userId' => $userId, 'topicId' => $topic]), JSON_THROW_ON_ERROR));

        // This confirms the transport handoff, not receipt by an offline browser.
        return ['submitted' => true, 'topic' => $topic, 'userId' => $userId];
    }
}
