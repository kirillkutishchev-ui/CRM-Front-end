const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const root = path.resolve(__dirname, '../../..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
let checks = 0;
const contains = (contents, value, message) => {
    assert(contents.includes(value), message);
    checks++;
};

const compose = read('compose.yaml');
for (const service of [
    'crm_rabbitmq:',
    'mq-publisher:',
    'mq-worker-analytics:',
    'mq-worker-files:',
    'mq-worker-websocket:',
]) {
    contains(compose, service, `Missing Compose service ${service}`);
}
contains(compose, 'rabbitmq:4.1-management-alpine', 'RabbitMQ image must be pinned.');
contains(compose, 'crm-rabbitmq:/var/lib/rabbitmq', 'RabbitMQ data must be persistent.');
contains(compose, 'rabbitmq-diagnostics", "-q", "ping', 'RabbitMQ health check is required.');
contains(compose, '127.0.0.1}:${MQ_MANAGEMENT_PORT:-15672}:15672',
    'Management UI must bind to loopback by default.');
assert(!/^\s*-\s*[^#\n]*:5672(?::5672)?\s*$/m.test(compose), 'AMQP port 5672 must not be published.');
checks++;
assert(!/supervisor/i.test(compose), 'Supervisor must not be added to the stack.');
checks++;

const topology = read('custom/Espo/Modules/StudioManagement/Tools/Mq/RabbitMqTopology.php');
contains(topology, "exchange_declare($this->config->exchange(), 'topic', false, true, false)",
    'Main exchange must be durable topic.');
contains(topology, "'x-dead-letter-exchange'", 'Main queues must use a DLX.');
contains(topology, "'.dlq'", 'Every queue must have a dead-letter queue.');
contains(topology, 'AMQPMessage::DELIVERY_MODE_PERSISTENT', 'Messages must be persistent.');
contains(topology, 'confirm_select()', 'Publisher confirms must be enabled.');
contains(topology, 'set_nack_handler(', 'Broker rejection must fail publication.');
contains(topology, 'set_return_listener(', 'Unroutable publication must fail.');

const config = read('custom/Espo/Modules/StudioManagement/Tools/Mq/MqConfig.php');
for (const value of [
    "QUEUE_ANALYTICS = 'analytics_tasks'",
    "QUEUE_FILES = 'file_processing'",
    "QUEUE_WEBSOCKET = 'websocket_notifications'",
    "ROUTING_ANALYTICS = 'analytics.calculate'",
    "ROUTING_FILES = 'shift.materials.process'",
    "ROUTING_NOTIFICATION_PREFIX = 'ui.notification.'",
]) {
    contains(config, value, `Missing queue or routing declaration ${value}`);
}

const tasks = read('custom/Espo/Modules/StudioManagement/Tools/Mq/MqTaskService.php');
for (const value of [
    "'securityContext'",
    "'roleIds'",
    'getTransactionManager()->run',
    "STATUS_FAILED",
    'retryOfId',
    'cleanupCompleted',
    'availableAt',
    'publishLocked',
]) {
    contains(tasks, value, `Outbox is missing ${value}`);
}

const worker = read('custom/Espo/Modules/StudioManagement/Tools/Mq/RabbitMqWorker.php');
for (const value of [
    'basic_qos',
    'markProcessing',
    'markCompleted',
    'markFailed',
    'markRetrying',
    'nack(false, false)',
    'enqueueCompletionNotification',
]) {
    contains(worker, value, `Worker is missing ${value}`);
}

const analytics = read('custom/Espo/Modules/StudioManagement/Api/GetAnalytics.php');
contains(analytics, 'ROUTING_ANALYTICS', 'Studio analytics must enqueue an MQ task.');
contains(analytics, "'async' => true", 'Analytics API must identify asynchronous responses.');
const shifts = read('custom/Espo/Modules/StudioManagement/Tools/ShiftService.php');
contains(shifts, 'ROUTING_FILES', 'Shift submission must enqueue material processing.');
contains(shifts, 'queueApproval', 'Final financial approval must have a queued path.');
const reviewApi = read('custom/Espo/Modules/StudioManagement/Api/PostReviewShift.php');
contains(reviewApi, 'queueApproval(', 'Approval API must enqueue the financial workflow.');
contains(reviewApi, 'setStatus(202)', 'Queued approval must return HTTP 202.');

const taskApi = read('custom/Espo/Modules/StudioManagement/Api/GetMqTask.php');
contains(taskApi, "initiatedById", 'Task API must restrict results to the initiating user.');
contains(taskApi, 'isAdmin()', 'Administrators must be able to inspect task results.');
contains(taskApi, 'taskResult(', 'Current access must be rechecked before returning a result.');

const env = read('.env.example');
for (const name of [
    'MQ_USER=', 'MQ_PASSWORD=', 'MQ_HOST=', 'MQ_PORT=', 'MQ_EXCHANGE=', 'MQ_DLX=',
    'MQ_MAX_ATTEMPTS=', 'MQ_RETRY_DELAY_MS=', 'MQ_TASK_RETENTION_DAYS=',
]) {
    contains(env, name, `Example environment is missing ${name}`);
}

const composer = JSON.parse(read('composer.json'));
assert(composer.require['php-amqplib/php-amqplib'], 'Official php-amqplib dependency is missing.');
checks++;
const runtimeComposer = JSON.parse(read('docker/runtime/mq-composer.json'));
assert.equal(runtimeComposer.require['php-amqplib/php-amqplib'], '3.7.4',
    'RabbitMQ runtime dependency must be pinned.');
checks++;
const runtimeDockerfile = read('docker/runtime/Dockerfile');
contains(runtimeDockerfile, 'FROM composer:2.8 AS mq-dependencies',
    'Runtime must install AMQP through Composer.');
contains(runtimeDockerfile, '/opt/studio-mq-vendor/', 'Runtime AMQP vendor directory is missing.');
contains(runtimeDockerfile, 'mq-composer.lock', 'Runtime must install from a dependency lock file.');
contains(runtimeDockerfile, 'docker-php-ext-install sockets', 'Runtime must supply the required sockets extension.');
const binding = read('custom/Espo/Modules/StudioManagement/Binding.php');
contains(binding, '/opt/studio-mq-vendor/autoload.php', 'Module must load the AMQP Composer autoloader.');

const jsonFiles = [
    'custom/Espo/Modules/StudioManagement/Resources/metadata/entityDefs/SmMqTask.json',
    'custom/Espo/Modules/StudioManagement/Resources/metadata/scopes/SmMqTask.json',
    'custom/Espo/Modules/StudioManagement/Resources/metadata/app/consoleCommands.json',
    'custom/Espo/Modules/StudioManagement/Resources/routes.json',
];
for (const file of jsonFiles) {
    JSON.parse(read(file));
    checks++;
}

const mirrored = [
    'custom/Espo/Modules/StudioManagement/Binding.php',
    'custom/Espo/Modules/StudioManagement/Api/GetAnalytics.php',
    'custom/Espo/Modules/StudioManagement/Api/GetMqTask.php',
    'custom/Espo/Modules/StudioManagement/Api/PostReviewShift.php',
    'custom/Espo/Modules/StudioManagement/Classes/ConsoleCommands/MqPublisher.php',
    'custom/Espo/Modules/StudioManagement/Classes/ConsoleCommands/MqRetry.php',
    'custom/Espo/Modules/StudioManagement/Classes/ConsoleCommands/MqWorker.php',
    'custom/Espo/Modules/StudioManagement/Entities/SmMqTask.php',
    'custom/Espo/Modules/StudioManagement/Resources/metadata/entityDefs/SmMqTask.json',
    'custom/Espo/Modules/StudioManagement/Resources/metadata/scopes/SmMqTask.json',
    'custom/Espo/Modules/StudioManagement/Resources/metadata/app/consoleCommands.json',
    'custom/Espo/Modules/StudioManagement/Resources/routes.json',
    'custom/Espo/Modules/StudioManagement/Tools/ShiftService.php',
    ...fs.readdirSync(path.join(root, 'custom/Espo/Modules/StudioManagement/Tools/Mq'))
        .map(name => `custom/Espo/Modules/StudioManagement/Tools/Mq/${name}`),
    'client/custom/modules/studio-management/src/views/sm-analytics/index.js',
    'client/custom/modules/studio-management/src/views/sm-shift/record/edit.js',
];
for (const file of mirrored) {
    const hash = text => crypto.createHash('sha256').update(text).digest('hex');
    assert.equal(hash(read(file)), hash(read(`extensions/studio-management/files/${file}`)),
        `Extension mirror differs: ${file}`);
    checks++;
}

console.log(`RabbitMQ architecture: ${checks} checks passed.`);
