<?php

namespace Espo\Modules\StudioManagement\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\StudioManagement\Tools\Mq\MqTaskService;
use Espo\Modules\StudioManagement\Tools\Mq\TaskAuthorization;
use stdClass;

final class GetMqTask implements Action
{
    public function __construct(
        private MqTaskService $tasks,
        private User $user,
        private TaskAuthorization $authorization,
    ) {}

    public function process(Request $request): Response
    {
        $id = $request->getRouteParam('id');

        if (!is_string($id) || $id === '') {
            throw new BadRequest('No task ID.');
        }

        $task = $this->tasks->get($id);

        if (!$task) {
            throw new NotFound('Task not found.');
        }

        if (!$this->user->isAdmin() && $task->get('initiatedById') !== $this->user->getId()) {
            throw new Forbidden();
        }

        $this->authorization->taskResult($task, $this->user);

        $result = $task->get('result');
        $result = $result instanceof stdClass ? (array) $result : $result;

        if (is_array($result)) {
            unset($result['_mqAuthorization']);
        }
        $payload = (array) $task->get('payload');
        if (($payload['operation'] ?? null) === 'approve' && is_array($result)) {
            $result = ['shift' => $result['shift'] ?? null, 'approved' => $result['approved'] ?? false];
        }

        return ResponseComposer::json([
            'id' => $task->getId(),
            'status' => $task->get('status'),
            'routingKey' => $task->get('routingKey'),
            'attempt' => (int) $task->get('attempt'),
            'maxAttempts' => (int) $task->get('maxAttempts'),
            'result' => $result,
            'error' => $task->get('status') === 'Failed' ? $task->get('errorMessage') : null,
        ])->setHeader('Cache-Control', 'no-store');
    }
}
