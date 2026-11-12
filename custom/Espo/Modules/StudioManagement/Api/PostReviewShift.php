<?php

namespace Espo\Modules\StudioManagement\Api;

use Espo\Core\Acl;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\StudioManagement\Entities\SmShift;
use Espo\Modules\StudioManagement\Tools\RolePolicy;
use Espo\Modules\StudioManagement\Tools\ShiftService;
use Espo\Modules\StudioManagement\Tools\Mq\MqConfig;
use Espo\ORM\EntityManager;

final class PostReviewShift implements Action
{
    public function __construct(
        private Acl $acl,
        private RolePolicy $policy,
        private ShiftService $service,
        private User $user,
        private EntityManager $entityManager,
        private MqConfig $mqConfig = new MqConfig(),
    ) {}

    public function process(Request $request): Response
    {
        $id = $request->getRouteParam('id');
        $data = $request->getParsedBody();
        if (!is_string($id) || $id === '') {
            throw new BadRequest('No shift ID.');
        }
        $shift = $this->entityManager->getEntityById(SmShift::ENTITY_TYPE, $id);
        if (!$shift) {
            throw new NotFound('Shift not found.');
        }
        if (!$this->acl->checkEntityEdit($shift) || !$this->policy->canReview($this->user, $shift)) {
            throw new Forbidden('Your role cannot review this shift.');
        }
        $siteData = $data->siteData ?? null;
        $screenshots = $data->screenshotsIds ?? [];
        $comment = $data->comment ?? '';
        $confirm = $data->confirmClosure ?? false;
        if (!is_array($siteData) || !is_array($screenshots) || !is_string($comment) || !is_bool($confirm)) {
            throw new BadRequest('Invalid review data or confirmation flag.');
        }
        // Approval is a separate request from operator submission, even for dual-role users.
        if ($confirm && $this->mqConfig->isEnabled()) {
            $task = $this->service->queueApproval($id, $siteData, $screenshots, $comment, $this->user);
            return ResponseComposer::json([
                'async' => true, 'taskId' => $task->getId(), 'status' => $task->get('status'),
            ])->setStatus(202);
        }
        $result = $confirm
            ? $this->service->approve($id, $siteData, $screenshots, $comment, $this->user)
            : $this->service->review($id, $siteData, $screenshots, $comment);
        return ResponseComposer::json($result->getValueMap());
    }
}
