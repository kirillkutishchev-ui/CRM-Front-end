<?php

// Run only in an isolated local CRM/broker with background MQ services stopped:
// docker compose exec -T -e STUDIO_MQ_TEST_ISOLATED=1 crm php /tmp/RabbitMqIntegrationTest.php
// This script never purges queues. Only its own fixture messages/rows are removed.
require '/var/www/html/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\ApplicationUser;
use Espo\Core\Api\RequestWrapper;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Entities\Attachment;
use Espo\Entities\User;
use Espo\Modules\StudioManagement\Api\GetMqTask;
use Espo\Modules\StudioManagement\Entities\SmMqTask;
use Espo\Modules\StudioManagement\Tools\Mq\MqConfig;
use Espo\Modules\StudioManagement\Tools\Mq\MqTaskService;
use Espo\Modules\StudioManagement\Tools\Mq\OutboxPublisher;
use Espo\Modules\StudioManagement\Tools\Mq\RabbitMqTopology;
use Espo\Modules\StudioManagement\Tools\Mq\RabbitMqWorker;
use Espo\Modules\StudioManagement\Tools\Mq\ShiftMaterialsTaskHandler;
use Espo\Modules\StudioManagement\Tools\Mq\TaskAuthorization;
use Espo\Modules\StudioManagement\Tools\ShiftService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use GuzzleHttp\Psr7\ServerRequest;
use PhpAmqpLib\Channel\AMQPChannel;

if (getenv('STUDIO_MQ_TEST_ISOLATED') !== '1') {
    throw new RuntimeException('Explicit isolated-test opt-in is required. Do not run against production.');
}

$app = new Application();
$container = $app->getContainer();
$factory = $app->getInjectableFactory();
$em = $container->getByClass(EntityManager::class);
$admin = $em->getRDBRepository(User::ENTITY_TYPE)->where(['type' => 'admin', 'isActive' => true])->findOne();

if (!$admin instanceof User) {
    throw new RuntimeException('The isolated CRM needs an active administrator.');
}

$container->getByClass(ApplicationUser::class)->setUser($admin);
$config = $factory->create(MqConfig::class);
$tasks = $factory->create(MqTaskService::class);
$topology = $factory->create(RabbitMqTopology::class);
$worker = $factory->create(RabbitMqWorker::class);
$publisher = $factory->create(OutboxPublisher::class);
$authorization = $factory->create(TaskAuthorization::class);
$storage = $factory->create(FileStorageManager::class);
$channel = $topology->declare();
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }

    $checks++;
};
$queues = [];

foreach (array_keys($config->bindings()) as $queue) {
    foreach ([$queue, $queue . '.dlq'] as $name) {
        [, $ready, $consumers] = $channel->queue_declare($name, true);
        $assert($ready === 0 && $consumers === 0,
            "Isolated test requires an empty queue with no consumers: {$name}");
        $queues[] = $name;
    }
}

$suffix = bin2hex(random_bytes(6));
$userIds = [];
$roleIds = [];
$shiftIds = [];
$teamIds = [];
$attachmentIds = [];
$fixtureTaskIds = [];
$taskRepository = $em->getRDBRepository(SmMqTask::ENTITY_TYPE);
$userRepository = $em->getRDBRepository(User::ENTITY_TYPE);
$makeUser = static function (string $label, string $type) use ($em, $suffix, &$userIds): User {
    $user = $em->getNewEntity(User::ENTITY_TYPE);
    $user->set([
        'userName' => "mq-test-{$label}-{$suffix}",
        'firstName' => 'MQ',
        'lastName' => "Integration {$suffix}",
        'type' => $type,
        'isActive' => true,
        'smAccountType' => 'TechnicalDirector',
    ]);
    $em->saveEntity($user, ['skipHooks' => true]);
    $userIds[] = $user->getId();

    return $user;
};
$remember = static function (Entity $task) use (&$fixtureTaskIds): Entity {
    $fixtureTaskIds[$task->getId()] = true;

    return $task;
};
$reload = static function (string $id) use ($taskRepository): Entity {
    $task = $taskRepository->where(['id' => $id])->findOne();

    if (!$task) {
        throw new RuntimeException("Fixture task {$id} disappeared.");
    }

    return $task;
};
$waitReady = static function (AMQPChannel $channel, string $queue, float $seconds): void {
    $deadline = microtime(true) + $seconds;

    do {
        [, $count] = $channel->queue_declare($queue, true);

        if ($count > 0) {
            return;
        }

        usleep(100_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("Timed out waiting for a fixture message in {$queue}.");
};

try {
    $owner = $makeUser('owner', 'admin');
    $outsider = $makeUser('outsider', 'regular');
    $payload = ['from' => '2026-01-01', 'to' => '2026-01-07'];

    // A business rollback must also remove its outbox entry.
    $rolledBackId = null;
    $rollback = new RuntimeException('ROLLBACK_MQ_TEST_FIXTURE');

    try {
        $em->getTransactionManager()->run(function () use ($tasks, $owner, $payload, &$rolledBackId, $rollback): void {
            $task = $tasks->enqueue(MqConfig::ROUTING_ANALYTICS, $payload, $owner);
            $rolledBackId = $task->getId();
            throw $rollback;
        });
    } catch (RuntimeException $error) {
        if ($error !== $rollback) {
            throw $error;
        }
    }

    $assert(is_string($rolledBackId) && $tasks->get($rolledBackId) === null,
        'Rolled-back business transaction must not leave an outbox message.');

    $task = $remember($tasks->enqueue(MqConfig::ROUTING_ANALYTICS, $payload, $owner));
    $assert($task->get('status') === SmMqTask::STATUS_QUEUED, 'New task must start Queued.');
    $assert($publisher->publishBatch() === 1, 'Publisher must confirm and publish the queued analytics task.');
    $assert($reload($task->getId())->get('status') === SmMqTask::STATUS_PUBLISHED,
        'Confirmed task must be marked Published.');
    $worker->run(MqConfig::QUEUE_ANALYTICS, true);
    $completed = $reload($task->getId());
    $assert($completed->get('status') === SmMqTask::STATUS_COMPLETED, 'Analytics worker must complete the task.');
    $assert((int) $completed->get('attempt') === 1, 'First delivery must count as one processing attempt.');
    $result = (array) $completed->get('result');
    $assert(($result['from'] ?? null) === $payload['from'] && ($result['to'] ?? null) === $payload['to'],
        'Analytics result must contain the requested date range.');

    // Broker redelivery is expected in at-least-once delivery and must be harmless.
    $topology->publish(MqConfig::ROUTING_ANALYTICS, $tasks->envelope($completed));
    $worker->run(MqConfig::QUEUE_ANALYTICS, true);
    $duplicate = $reload($task->getId());
    $assert($duplicate->get('status') === SmMqTask::STATUS_COMPLETED, 'Duplicate delivery must preserve Completed.');
    $assert((int) $duplicate->get('attempt') === 1, 'Duplicate completed delivery must not rerun the handler.');
    $tasks->markPublished($task->getId());
    $assert($reload($task->getId())->get('status') === SmMqTask::STATUS_COMPLETED,
        'A late publisher confirmation must never overwrite Completed.');

    $forgedTask = $remember($tasks->enqueue(MqConfig::ROUTING_ANALYTICS, $payload, $owner));
    $forgedEnvelope = $tasks->envelope($forgedTask);
    $forgedEnvelope['eventId'] = 'forged-event-id';
    $topology->publish(MqConfig::ROUTING_ANALYTICS, $forgedEnvelope);
    $worker->run(MqConfig::QUEUE_ANALYTICS, true);
    $assert($reload($forgedTask->getId())->get('status') === SmMqTask::STATUS_QUEUED,
        'Forged event identity must not mutate the referenced task status.');
    $assert((int) $reload($forgedTask->getId())->get('attempt') === 0,
        'Rejected envelope must not consume a legitimate task attempt.');
    $waitReady($channel, MqConfig::QUEUE_ANALYTICS . '.dlq', 5);
    $forgedMessage = $channel->basic_get(MqConfig::QUEUE_ANALYTICS . '.dlq');
    $assert($forgedMessage !== null, 'Forged envelope must go to DLQ for diagnosis.');
    $forgedMessage->ack();
    $taskRepository->deleteFromDb($forgedTask->getId());

    // Only server-stored payload/security may determine the work being executed.
    $tamperTask = $remember($tasks->enqueue(MqConfig::ROUTING_ANALYTICS, $payload, $owner));
    $envelope = $tasks->envelope($tamperTask);
    $envelope['payload'] = ['from' => 'definitely-invalid', 'to' => 'also-invalid'];
    $envelope['security'] = ['userId' => 'missing-user', 'isAdmin' => true];
    $topology->publish(MqConfig::ROUTING_ANALYTICS, $envelope);
    $worker->run(MqConfig::QUEUE_ANALYTICS, true);
    $tamperResult = $reload($tamperTask->getId());
    $assert($tamperResult->get('status') === SmMqTask::STATUS_COMPLETED,
        'Worker must use stored payload/security rather than tampered broker values.');
    $assert(((array) $tamperResult->get('result'))['from'] === $payload['from'],
        'Tampered broker dates must not replace stored dates.');

    $request = new RequestWrapper(new ServerRequest('GET', '/'), '', ['id' => $task->getId()]);
    try {
        (new GetMqTask($tasks, $outsider, $authorization))->process($request);
        $assert(false, 'An unrelated user must not read task results.');
    } catch (Forbidden) {
        $assert(true, 'Unrelated user is forbidden from reading task results.');
    }
    $response = (new GetMqTask($tasks, $owner, $authorization))->process($request);
    $assert($response->getStatusCode() === 200, 'Task owner must be able to read completed result.');

    $deniedRole = $em->getNewEntity('Role');
    $deniedRole->set([
        'name' => "MQ denied analytics {$suffix}",
        'smAnalyticsAccessPermission' => 'no',
        'data' => (object) ['SmAnalytics' => false, 'SmShiftFinancialSnapshot' => (object) ['read' => 'no']],
    ]);
    $em->saveEntity($deniedRole);
    $roleIds[] = $deniedRole->getId();
    $outsider->set('rolesIds', [$deniedRole->getId()]);
    $em->saveEntity($outsider, ['skipHooks' => true]);
    $revokedTask = $remember($tasks->enqueue(MqConfig::ROUTING_ANALYTICS, $payload, $outsider));
    $revokedTask->set('maxAttempts', 1);
    $em->saveEntity($revokedTask);
    $revokedEnvelope = $tasks->envelope($revokedTask);
    $revokedEnvelope['security']['isAdmin'] = true;
    $topology->publish(MqConfig::ROUTING_ANALYTICS, $revokedEnvelope);
    $worker->run(MqConfig::QUEUE_ANALYTICS, true);
    $assert($reload($revokedTask->getId())->get('status') === SmMqTask::STATUS_FAILED,
        'Worker must enforce current native/Studio analytics ACL despite a forged admin flag.');
    $revokedRequest = new RequestWrapper(new ServerRequest('GET', '/'), '', ['id' => $revokedTask->getId()]);
    try {
        (new GetMqTask($tasks, $outsider, $authorization))->process($revokedRequest);
        $assert(false, 'Even the task owner must not read analytics after permission revocation.');
    } catch (Forbidden) {
        $assert(true, 'Task-result endpoint must recheck current analytics ACL.');
    }
    $waitReady($channel, MqConfig::QUEUE_ANALYTICS . '.dlq', 5);
    $revokedMessage = $channel->basic_get(MqConfig::QUEUE_ANALYTICS . '.dlq');
    $assert($revokedMessage !== null, 'Rejected current ACL task must reach DLQ.');
    $revokedMessage->ack();

    // Invalid business input receives a bounded retry then moves to DLQ.
    $poison = $remember($tasks->enqueue(MqConfig::ROUTING_ANALYTICS,
        ['from' => 'invalid-date', 'to' => '2026-01-07'], $owner));
    $poison->set('maxAttempts', 2);
    $em->saveEntity($poison);
    $topology->publish(MqConfig::ROUTING_ANALYTICS, $tasks->envelope($poison));
    $worker->run(MqConfig::QUEUE_ANALYTICS, true);
    $retrying = $reload($poison->getId());
    $assert((int) $retrying->get('attempt') === 1, 'Poison task must record its failed attempt.');
    $assert($retrying->get('status') !== SmMqTask::STATUS_COMPLETED,
        'Invalid dates must never produce a completed analytics result.');
    $assert($retrying->get('status') === SmMqTask::STATUS_QUEUED,
        'Failed attempt must return to the transactional outbox for a delayed retry.');
    $retryDeadline = microtime(true) + $config->retryDelayMs() / 1000 + 10;

    do {
        $publisher->publishBatch();
        $retrying = $reload($poison->getId());

        if ($retrying->get('status') === SmMqTask::STATUS_PUBLISHED) {
            break;
        }

        usleep(100_000);
    } while (microtime(true) < $retryDeadline);

    $assert($retrying->get('status') === SmMqTask::STATUS_PUBLISHED,
        'Publisher must deliver the delayed retry after its available time.');
    $waitReady($channel, MqConfig::QUEUE_ANALYTICS, 5);
    $worker->run(MqConfig::QUEUE_ANALYTICS, true);
    $failed = $reload($poison->getId());
    $assert($failed->get('status') === SmMqTask::STATUS_FAILED, 'Exhausted poison task must become Failed.');
    $assert((int) $failed->get('attempt') === 2, 'Worker must stop at the configured attempt limit.');
    $waitReady($channel, MqConfig::QUEUE_ANALYTICS . '.dlq', 5);
    $deadLetter = $channel->basic_get(MqConfig::QUEUE_ANALYTICS . '.dlq');
    $assert($deadLetter !== null, 'Exhausted poison message must reach the dead-letter queue.');
    $deadEnvelope = json_decode($deadLetter->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $assert(($deadEnvelope['taskId'] ?? null) === $poison->getId(), 'DLQ must preserve the original task identity.');
    $deadLetter->ack();

    $originalPayload = json_encode($failed->get('payload'), JSON_THROW_ON_ERROR);
    $corrected = $remember($tasks->retry($failed, $owner, $payload));
    $assert($corrected->getId() !== $failed->getId(), 'Manual retry must create a separate task.');
    $assert($corrected->get('retryOfId') === $failed->getId(), 'Corrected copy must reference the failed original.');
    $topology->publish(MqConfig::ROUTING_ANALYTICS, $tasks->envelope($corrected));
    $worker->run(MqConfig::QUEUE_ANALYTICS, true);
    $assert($reload($corrected->getId())->get('status') === SmMqTask::STATUS_COMPLETED,
        'Corrected copy must complete after its payload is fixed.');
    $assert($reload($failed->getId())->get('status') === SmMqTask::STATUS_FAILED,
        'Retry must leave the original Failed status unchanged.');
    $assert(json_encode($reload($failed->getId())->get('payload'), JSON_THROW_ON_ERROR) === $originalPayload,
        'Retry must not mutate the original failed payload.');

    // Real file storage/image decoding and non-destructive PNG optimization.
    $shift = $em->getNewEntity('SmShift');
    $shift->set(['name' => "MQ screenshot {$suffix}", 'status' => 'Review', 'operatorId' => $owner->getId()]);
    $em->saveEntity($shift, ['skipHooks' => true]);
    $shiftIds[] = $shift->getId();
    $image = imagecreatetruecolor(64, 64);
    imagefill($image, 0, 0, imagecolorallocate($image, 32, 64, 128));
    ob_start();
    imagepng($image, null, 0);
    $png = ob_get_clean();
    imagedestroy($image);
    $source = $em->getNewEntity(Attachment::ENTITY_TYPE);
    $source->set([
        'name' => "mq-screenshot-{$suffix}.png", 'type' => 'image/png',
        'role' => Attachment::ROLE_ATTACHMENT, 'contents' => $png, 'createdById' => $owner->getId(),
        'parentType' => 'SmShift', 'parentId' => $shift->getId(), 'field' => 'screenshots',
    ]);
    $em->saveEntity($source);
    $attachmentIds[] = $source->getId();
    $materialHandler = $factory->create(ShiftMaterialsTaskHandler::class);
    $materialPayload = ['shiftId' => $shift->getId(), 'attachmentIds' => [$source->getId()]];
    $materialResult = $materialHandler->handle($materialPayload, ['userId' => $owner->getId()]);
    $assert($materialResult['validatedCount'] === 1, 'Material worker must validate a real screenshot.');
    $assert($materialResult['files'][0]['width'] === 64 && $materialResult['files'][0]['height'] === 64,
        'Material worker must read actual decoded image dimensions.');
    $optimizedId = $materialResult['files'][0]['compressedAttachmentId'];
    $assert(is_string($optimizedId) && $optimizedId !== $source->getId(),
        'Compression must create a separate optimized attachment.');
    $attachmentIds[] = $optimizedId;
    $mapping = (array) $em->getEntityById('SmShift', $shift->getId())->get('screenshotOptimizations');
    $assert(($mapping[$source->getId()] ?? null) === $optimizedId,
        'Shift previews must receive the persisted optimized attachment mapping.');
    $optimized = $em->getEntityById(Attachment::ENTITY_TYPE, $optimizedId);
    $assert($optimized instanceof Attachment && $storage->getSize($optimized) < strlen($png),
        'Optimized PNG must consume less space than the source.');
    $assert(hash('sha256', $storage->getContents($source)) === hash('sha256', $png),
        'Original screenshot bytes must remain intact.');
    $again = $materialHandler->handle($materialPayload, ['userId' => $owner->getId()]);
    $assert($again['files'][0]['compressedAttachmentId'] === $optimizedId,
        'Redelivery must reuse the same optimized artifact.');

    $fake = $em->getNewEntity(Attachment::ENTITY_TYPE);
    $fake->set([
        'name' => "mq-fake-{$suffix}.png", 'type' => 'image/png',
        'role' => Attachment::ROLE_ATTACHMENT, 'contents' => 'This is not a PNG.', 'createdById' => $owner->getId(),
        'parentType' => 'SmShift', 'parentId' => $shift->getId(), 'field' => 'screenshots',
    ]);
    $em->saveEntity($fake);
    $attachmentIds[] = $fake->getId();
    try {
        $materialHandler->handle(['shiftId' => $shift->getId(), 'attachmentIds' => [$fake->getId()]],
            ['userId' => $owner->getId()]);
        $assert(false, 'An extension/MIME label alone must not count as a valid screenshot.');
    } catch (BadRequest) {
        $assert(true, 'Fake image contents must be rejected by decoding validation.');
    }

    // Final approval itself runs in the file worker, with financial writes in
    // the same database transaction as task completion.
    $owner->set([
        'smAccountType' => 'ProducerAdmin',
        'smOperatorSharePercentOverride' => 25,
        'smProducerSharePercentOverride' => 10,
    ]);
    $em->saveEntity($owner, ['skipHooks' => true]);
    $model = $makeUser('model', 'regular');
    $model->set(['smAccountType' => 'Model', 'smSharePercentOverride' => 40]);
    $em->saveEntity($model, ['skipHooks' => true]);
    $team = $em->getNewEntity('Team');
    $team->set([
        'name' => "MQ financial {$suffix}",
        'smProducerId' => $owner->getId(),
        'smOperatorId' => $owner->getId(),
        'smModelId' => $model->getId(),
    ]);
    $em->saveEntity($team, ['skipHooks' => true]);
    $teamIds[] = $team->getId();
    $closingShift = $em->getNewEntity('SmShift');
    $closingShift->set([
        'name' => "MQ financial {$suffix}",
        'status' => 'Review',
        'teamId' => $team->getId(),
        'producerId' => $owner->getId(),
        'operatorId' => $owner->getId(),
        'modelId' => $model->getId(),
        'currency' => 'USD',
        'startTime' => '2026-01-01 08:00:00',
        'endTime' => '2026-01-01 12:00:00',
    ]);
    $em->saveEntity($closingShift, ['skipHooks' => true]);
    $shiftIds[] = $closingShift->getId();
    $sites = [[
        'site' => 'CB', 'login' => 'mq-fixture', 'password' => 'fixture-only',
        'state' => 'Started', 'earning' => '100.00', 'offlineBonus' => '0.00',
    ]];
    $shiftService = $factory->create(ShiftService::class);
    $approval = $remember($shiftService->queueApproval($closingShift->getId(), $sites, [], 'MQ approval', $owner));
    $sameApproval = $shiftService->queueApproval($closingShift->getId(), $sites, [], 'MQ approval', $owner);
    $assert($approval->getId() === $sameApproval->getId(), 'Duplicate approval request must reuse one pending task.');
    try {
        $shiftService->review($closingShift->getId(), $sites, [], 'Late edit');
        $assert(false, 'Review must reject changes while approval is pending.');
    } catch (\Espo\Core\Exceptions\Conflict) {
        $assert(true, 'Queued approval protects against lost review edits.');
    }
    $assert($em->getEntityById('SmShift', $closingShift->getId())->get('status') === 'Review',
        'Queued financial approval must preserve Review until worker execution.');
    $assert($em->getRDBRepository('SmShiftFinancialSnapshot')->where(['shiftId' => $closingShift->getId()])->count() === 0,
        'Queue acceptance must not post financial snapshots prematurely.');
    $publisher->publishBatch();
    $worker->run(MqConfig::QUEUE_FILES, true);
    $finishedApproval = $reload($approval->getId());
    $assert($finishedApproval->get('status') === SmMqTask::STATUS_COMPLETED,
        'File worker must complete the queued financial approval.');
    $closedShift = $em->getEntityById('SmShift', $closingShift->getId());
    $assert($closedShift->get('status') === 'Closed', 'File worker must move the reviewed shift to Closed.');
    $assert((float) $closedShift->get('totalGross') === 100.0, 'Queued approval must retain precise shift totals.');
    $snapshotRepository = $em->getRDBRepository('SmShiftFinancialSnapshot');
    $assert($snapshotRepository->where(['shiftId' => $closingShift->getId()])->count() === 1,
        'Queued approval must create exactly one immutable financial snapshot.');
    $expenseCount = $em->getRDBRepository('SmShiftExpense')->where(['shiftId' => $closingShift->getId()])->count();
    $assert($expenseCount >= 3, 'Queued approval must create model/operator/producer expense rows.');
    $topology->publish(MqConfig::ROUTING_FILES, $tasks->envelope($finishedApproval));
    $worker->run(MqConfig::QUEUE_FILES, true);
    $assert($snapshotRepository->where(['shiftId' => $closingShift->getId()])->count() === 1,
        'Replayed approval must not create another snapshot.');
    $assert($em->getRDBRepository('SmShiftExpense')->where(['shiftId' => $closingShift->getId()])->count() === $expenseCount,
        'Replayed approval must not duplicate financial expenses.');
    $assert((int) $reload($approval->getId())->get('attempt') === 1,
        'Completed financial approval redelivery must not rerun the handler.');

    // Crash after the durable claim, while ZeroMQ waits for an unavailable peer.
    // Each child creates its own connections; the parent observes committed attempts.
    $publisher->publishBatch();
    while (true) {
        [, $ready] = $channel->queue_declare(MqConfig::QUEUE_WEBSOCKET, true);
        if ($ready === 0) break;
        $worker->run(MqConfig::QUEUE_WEBSOCKET, true);
    }
    $crashTask = $remember($tasks->enqueue(MqConfig::ROUTING_NOTIFICATION_PREFIX . 'taskCompleted', [
        'topic' => 'studioMqTaskCompleted', 'userId' => $owner->getId(), 'data' => ['taskId' => 'crash-fixture'],
    ], $owner));
    $publisher->publishBatch();
    for ($delivery = 1; $delivery <= $config->maxAttempts(); $delivery++) {
        $code = 'require "/var/www/html/bootstrap.php"; $app = new \\Espo\\Core\\Application(); ' .
            '$em=$app->getContainer()->getByClass(\\Espo\\ORM\\EntityManager::class); ' .
            '$app->getContainer()->getByClass(\\Espo\\Core\\ApplicationUser::class)->setUser(' .
            '$em->getEntityById("User", ' . var_export($admin->getId(), true) . ')); ' .
            '$app->getContainer()->getByClass(\\Espo\\Core\\Utils\\Config::class)' .
            '->set("webSocketZeroMQSubmissionDsn", "tcp://127.0.0.1:1"); ' .
            '$app->getInjectableFactory()->create(\\Espo\\Modules\\StudioManagement\\Tools\\Mq\\RabbitMqWorker::class)' .
            '->run("websocket_notifications", true);';
        $child = proc_open([PHP_BINARY, '-r', $code], [0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/tmp/mq-crash-test.log', 'a'], 2 => ['file', '/tmp/mq-crash-test.log', 'a']], $pipes);
        $assert(is_resource($child), 'Crash-test worker must start with independent connections.');
        $deadline = microtime(true) + 4;
        do {
            usleep(50_000);
            $claimed = $reload($crashTask->getId());
        } while ((int) $claimed->get('attempt') < $delivery && microtime(true) < $deadline);
        proc_terminate($child, SIGKILL);
        proc_close($child);
        $assert((int) $reload($crashTask->getId())->get('attempt') === $delivery,
            'SIGKILL must not roll back the persisted delivery attempt.');
        $waitReady($channel, MqConfig::QUEUE_WEBSOCKET, 8);
    }
    $worker->run(MqConfig::QUEUE_WEBSOCKET, true);
    $assert($reload($crashTask->getId())->get('status') === SmMqTask::STATUS_FAILED,
        'Repeated worker crashes must terminate with Failed.');
    $waitReady($channel, MqConfig::QUEUE_WEBSOCKET . '.dlq', 5);
    $dead = $channel->basic_get(MqConfig::QUEUE_WEBSOCKET . '.dlq');
    $assert(json_decode($dead->getBody(), true)['taskId'] === $crashTask->getId(),
        'Crash poison must reach the DLQ instead of blocking subsequent tasks.');
    $dead->ack();

    echo "RabbitMQ integration: {$checks} checks passed.\n";
} finally {
    // Collect notifications generated by these fixture users, then remove only
    // their messages from every queue. Unexpected messages are requeued intact.
    foreach ($userIds as $userId) {
        foreach ($taskRepository->where(['initiatedById' => $userId])->find() as $task) {
            $fixtureTaskIds[$task->getId()] = true;
        }
    }

    // Broker redelivery/dead-lettering is asynchronous across worker connections.
    // Give those deliveries a few turns before removing the fixture's outbox rows.
    for ($cleanupPass = 0; $cleanupPass < 4; $cleanupPass++) {
    foreach ($queues as $queue) {
        for ($i = 0; $i < 100; $i++) {
            $message = $channel->basic_get($queue);

            if (!$message) {
                break;
            }

            $body = json_decode($message->getBody(), true);

            if (is_array($body) && isset($fixtureTaskIds[$body['taskId'] ?? ''])) {
                $message->ack();
            } else {
                $message->nack(false, true);
                break;
            }
        }
    }
    usleep(100_000);
    }

    foreach (array_keys($fixtureTaskIds) as $taskId) {
        $taskRepository->deleteFromDb($taskId);
    }

    foreach ($shiftIds as $shiftId) {
        foreach ($em->getRDBRepository(Attachment::ENTITY_TYPE)->where(['relatedType' => 'SmShift', 'relatedId' => $shiftId])->find() as $attachment) {
            $attachmentIds[] = $attachment->getId();
        }
    }

    foreach (array_unique($attachmentIds) as $attachmentId) {
        $attachment = $em->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);
        if ($attachment instanceof Attachment && $storage->exists($attachment)) {
            $storage->unlink($attachment);
        }
        $em->getRDBRepository(Attachment::ENTITY_TYPE)->deleteFromDb($attachmentId);
    }

    foreach ($shiftIds as $shiftId) {
        foreach ($em->getRDBRepository('SmShiftExpense')->where(['shiftId' => $shiftId])->find() as $expense) {
            $em->getRDBRepository('SmShiftExpense')->deleteFromDb($expense->getId());
        }
        foreach ($em->getRDBRepository('SmShiftFinancialSnapshot')->where(['shiftId' => $shiftId])->find() as $snapshot) {
            foreach ($teamIds as $teamId) {
                $em->getRDBRepository('SmShiftFinancialSnapshot')->getRelation($snapshot, 'teams')->unrelateById($teamId);
            }
            $em->getRDBRepository('SmShiftFinancialSnapshot')->deleteFromDb($snapshot->getId());
        }
        $em->getRDBRepository('SmShift')->deleteFromDb($shiftId);
    }

    foreach ($teamIds as $teamId) {
        $team = $em->getEntityById('Team', $teamId);
        if ($team) {
            foreach ($userIds as $userId) {
                $em->getRDBRepository('Team')->getRelation($team, 'users')->unrelateById($userId);
            }
        }
        $em->getRDBRepository('Team')->deleteFromDb($teamId);
    }

    foreach ($userIds as $userId) {
        $user = $em->getEntityById(User::ENTITY_TYPE, $userId);
        if ($user instanceof User) {
            foreach ($roleIds as $roleId) {
                $userRepository->getRelation($user, 'roles')->unrelateById($roleId);
            }
        }
        $userRepository->deleteFromDb($userId);
    }

    foreach ($roleIds as $roleId) {
        $em->getRDBRepository('Role')->deleteFromDb($roleId);
    }

    echo "RabbitMQ fixture cleanup finished; existing accounts were not edited.\n";
}
