<?php

namespace Espo\Modules\StudioManagement\Tools\Mq;

use Espo\Core\InjectableFactory;
use Espo\Modules\StudioManagement\Tools\AnalyticsService;
use RuntimeException;

final class AnalyticsTaskHandler implements TaskHandler
{
    public function __construct(
        private TaskAuthorization $authorization,
        private InjectableFactory $factory,
    ) {}

    public function handle(array $payload, array $security): array
    {
        $userId = $security['userId'] ?? null;
        $from = $payload['from'] ?? null;
        $to = $payload['to'] ?? null;

        if (!is_string($userId) || !is_string($from) || !is_string($to)) {
            throw new RuntimeException('Analytics task payload is invalid.');
        }

        $context = $this->authorization->analytics($userId);
        $analytics = $this->factory->createWith(AnalyticsService::class, ['policy' => $context['policy']]);

        $result = $analytics->get($from, $to, $context['user']);
        $result['_mqAuthorization'] = $this->authorization->analyticsFingerprint($context);

        return $result;
    }
}
