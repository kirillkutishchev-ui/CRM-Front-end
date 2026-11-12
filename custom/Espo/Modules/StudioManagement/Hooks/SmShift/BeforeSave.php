<?php

namespace Espo\Modules\StudioManagement\Hooks\SmShift;

use Espo\Core\Hook\Hook\BeforeSave as BeforeSaveHook;
use Espo\Entities\User;
use Espo\Modules\StudioManagement\Entities\SmShift;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\StudioManagement\Tools\WorkflowError;
use Espo\Modules\StudioManagement\Tools\RolePolicy;
use Espo\Modules\StudioManagement\Tools\ModelShiftDefaults;
use Espo\Modules\StudioManagement\Tools\TeamComposition;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSaveHook<Entity> */
class BeforeSave implements BeforeSaveHook
{
    public function __construct(
        private User $user,
        private RolePolicy $policy,
        private EntityManager $entityManager,
        private ModelShiftDefaults $defaults,
        private TeamComposition $composition,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isAttributeChanged('screenshotOptimizations')) {
            throw WorkflowError::forbidden('Оптимизированные материалы изменяются только обработчиком очереди.');
        }
        if ($entity->isNew()) {
            $entity->set('status', SmShift::STATUS_DRAFT);
            $this->prepareDraft($entity);

            $this->syncRelations($entity);

            return;
        }

        $previousStatus = $entity->getFetched('status');

        if (in_array($previousStatus, [SmShift::STATUS_REVIEW, SmShift::STATUS_CLOSED], true)) {
            throw WorkflowError::forbidden('Изменения смены на проверке и подсчитанной смены доступны только через проверку или разрешённую корректировку.');
        }

        if ($previousStatus === SmShift::STATUS_DRAFT) {
            if ($entity->isAttributeChanged('status') || $entity->isAttributeChanged('endTime')) {
                throw WorkflowError::conflict('Статус смены изменяется только кнопками запуска и завершения.');
            }
            $this->prepareDraft($entity);
            $this->syncRelations($entity);

            return;
        }

        if ($previousStatus === SmShift::STATUS_OPEN && $entity->isAttributeChanged('siteData')) {
            if (!$this->policy->canOperate($this->user, $entity)) {
                throw WorkflowError::forbidden('У вашей роли нет права управлять этой сменой.');
            }
            $oldRows = (array) ($entity->getFetched('siteData') ?? []);
            $newRows = (array) ($entity->get('siteData') ?? []);
            if (count($oldRows) !== count($newRows)) {
                throw WorkflowError::conflict('Во время смены можно менять только состояние сайтов.');
            }
            foreach ($newRows as $index => $row) {
                $row = (array) $row;
                $old = (array) ($oldRows[$index] ?? []);
                if (!in_array($row['state'] ?? null, ['Pending', 'Started', 'Banned'], true)) {
                    throw WorkflowError::badRequest('Некорректное состояние сайта.');
                }
                unset($row['state'], $old['state']);
                if ($row != $old) {
                    throw WorkflowError::conflict('Во время смены можно менять только состояние сайтов.');
                }
            }
        }

        foreach ([
            'name',
            'status',
            'startTime',
            'endTime',
            'teamId',
            'operatorId',
            'modelId',
            'producerId',
            'modelBirthDate',
            'modelImagesIds',
            'participantsIds',
            'currency',
            'description',
            'screenshotsIds',
            'botTotal',
            'calculatedTotal',
            'offlineBonus',
            'result',
            'totalGross',
            'modelShare',
            'operatorShare',
            'producerShare',
            'technicalDirectorShare',
            'recruiterShare',
            'expensesTotal',
            'studioShare',
            'expenseBreakdown',
            'platformMetrics',
            'closedById',
            'submittedById',
            'submittedAt',
            'reviewedById',
            'reviewedAt',
            'financialSnapshotId',
        ] as $attribute) {
            if ($entity->isAttributeChanged($attribute)) {
                throw WorkflowError::conflict('На этом этапе смена изменяется только кнопками запуска, подсчёта и корректировки.');
            }
        }

        if ($previousStatus !== SmShift::STATUS_OPEN && $entity->isAttributeChanged('siteData')) {
            throw WorkflowError::conflict('Изменяйте расчёты через кнопку подсчёта или корректировки смены.');
        }
    }

    private function syncRelations(Entity $entity): void
    {
        $teamId = $entity->get('teamId');
        $entity->set('teamsIds', is_string($teamId) && $teamId !== '' ? [$teamId] : []);

        $participantIds = [];

        foreach (['modelId', 'operatorId', 'producerId'] as $field) {
            $id = $entity->get($field);

            if (is_string($id) && $id !== '') {
                $participantIds[$id] = $id;
            }
        }

        $entity->set('participantsIds', array_values($participantIds));
        $entity->set('assignedUserId', $entity->get('operatorId'));
    }

    private function prepareDraft(Entity $entity): void
    {
        $this->composition->applyToDraft($entity);
        if (!$this->policy->canPrepare($this->user, $entity)) {
            throw WorkflowError::forbidden('Создать или изменить предварительную смену могут только её оператор и продюсер.');
        }
        if ($entity->get('startTime')) {
            $value = (string) $entity->get('startTime');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
                throw WorkflowError::badRequest('Укажите корректные дату и время начала смены.');
            }
            $entity->set('startTime', $date->format('Y-m-d H:i:00'));
        }
        if (($entity->isNew() && !$entity->get('siteData')) ||
            (!$entity->isNew() && $entity->isAttributeChanged('modelId'))) {
            $modelId = $entity->get('modelId');
            $model = $modelId ? $this->entityManager->getEntityById('User', $modelId) : null;
            if ($model) {
                $entity->set($this->defaults->get($model));
            }
        }
    }
}
