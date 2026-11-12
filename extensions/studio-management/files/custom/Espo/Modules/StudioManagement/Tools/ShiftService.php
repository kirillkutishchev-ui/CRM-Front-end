<?php

namespace Espo\Modules\StudioManagement\Tools;

use Espo\Entities\User;
use Espo\Modules\StudioManagement\Entities\SmShift;
use Espo\Modules\StudioManagement\Entities\SmShiftExpense;
use Espo\Modules\StudioManagement\Entities\SmShiftFinancialSnapshot;
use Espo\Modules\StudioManagement\Entities\SmMqTask;
use Espo\Modules\StudioManagement\Tools\Mq\MqConfig;
use Espo\Modules\StudioManagement\Tools\Mq\MqTaskService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use stdClass;

final class ShiftService
{
    private const int MAX_SITE_ROWS = 50;
    private const int MAX_SCREENSHOTS = 50;

    public function __construct(
        private EntityManager $entityManager,
        private MoneyCalculator $money,
        private TeamComposition $composition,
        private AccountTypeResolver $types,
        private MqConfig $mqConfig,
        private MqTaskService $mqTasks,
    ) {}

    public function open(string $id): Entity
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($id): Entity {
            $shift = $this->getShiftForUpdate($id);

            if ($shift->get('status') !== SmShift::STATUS_DRAFT) {
                throw WorkflowError::conflict('Запустить можно только предварительную смену.');
            }

            if (!$shift->get('teamId')) {
                throw WorkflowError::badRequest('Перед запуском смены выберите группу.');
            }

            $model = $this->getParticipant($shift, 'modelId', ['Model']);
            $operator = $this->getParticipant($shift, 'operatorId', ['Operator', 'ProducerAdmin']);
            $producer = $this->getProducer($shift);
            [$siteData] = $this->normalizeSiteData((array) ($shift->get('siteData') ?? []));
            $now = gmdate('Y-m-d H:i:00');

            $shift->set([
                'name' => sprintf('Смена №%s — %s', (string) $shift->get('number'), (string) $model->get('name')),
                'status' => SmShift::STATUS_OPEN,
                'startTime' => $now,
                'siteData' => $siteData,
                'producerId' => $producer->getId(),
                'participantsIds' => array_values(array_unique([$model->getId(), $operator->getId(), $producer->getId()])),
            ]);

            $this->entityManager->saveEntity($shift, ['skipBeforeSave' => true]);

            return $shift;
        });
    }

    public function finish(string $id): Entity
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($id): Entity {
            $shift = $this->getShiftForUpdate($id);

            if ($shift->get('status') !== SmShift::STATUS_OPEN) {
                throw WorkflowError::conflict('Перейти к подсчёту можно только из активной смены.');
            }

            $shift->set([
                'status' => SmShift::STATUS_COUNTING,
                'endTime' => gmdate('Y-m-d H:i:s'),
            ]);

            $this->entityManager->saveEntity($shift, ['skipBeforeSave' => true]);

            return $shift;
        });
    }

    /**
     * @param array<int, mixed> $siteData
     * @param array<int, mixed> $screenshotIds
     */
    public function close(string $id, array $siteData, array $screenshotIds, string $comment, User $submittedBy): Entity
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($id, $siteData, $screenshotIds, $comment, $submittedBy): Entity {
            $shift = $this->getShiftForUpdate($id);
            if ($shift->get('status') !== SmShift::STATUS_COUNTING) {
                throw WorkflowError::conflict('Отправить на проверку можно только смену на этапе подсчёта.');
            }
            $this->saveReviewData($shift, $siteData, $screenshotIds, $comment);
            $shift->set([
                'status' => SmShift::STATUS_REVIEW,
                'submittedById' => $submittedBy->getId(),
                'submittedAt' => gmdate('Y-m-d H:i:s'),
                'producerId' => $this->getProducer($shift)->getId(),
            ]);
            $this->entityManager->saveEntity($shift, ['skipBeforeSave' => true]);

            if ($this->mqConfig->isEnabled()) {
                $this->mqTasks->enqueue(
                    MqConfig::ROUTING_FILES,
                    [
                        'shiftId' => $shift->getId(),
                        'attachmentIds' => $this->normalizeAttachmentIds($screenshotIds),
                    ],
                    $submittedBy,
                );
            }

            return $shift;
        });
    }

    public function review(string $id, array $siteData, array $screenshotIds, string $comment): Entity
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($id, $siteData, $screenshotIds, $comment): Entity {
            $shift = $this->getShiftForUpdate($id);
            if ($shift->get('status') !== SmShift::STATUS_REVIEW) {
                throw WorkflowError::conflict('Проверять можно только смену со статусом «На проверке».');
            }
            foreach ($this->pendingApprovals($id) as $task) {
                if ((((array) $task->get('payload'))['operation'] ?? null) === 'approve') {
                    throw WorkflowError::conflict('Смена уже поставлена на закрытие. Дождитесь результата обработки.');
                }
            }
            $this->saveReviewData($shift, $siteData, $screenshotIds, $comment);
            $this->entityManager->saveEntity($shift, ['skipBeforeSave' => true]);
            return $shift;
        });
    }

    private function saveReviewData(Entity $shift, array $siteData, array $screenshotIds, string $comment): void
    {
        [$rows, $earning, $bonus] = $this->normalizeSiteData($siteData);
        $shift->set([
            'siteData' => $rows,
            'calculatedTotal' => $this->money->centsToAmount($earning),
            'offlineBonus' => $this->money->centsToAmount($bonus),
            'result' => $this->money->centsToAmount($earning + $bonus),
            'totalGross' => $this->money->centsToAmount($earning + $bonus),
            'screenshotsIds' => $this->normalizeAttachmentIds($screenshotIds),
            'description' => $this->normalizeComment($comment),
        ]);
    }

    public function approve(
        string $id,
        array $siteData,
        array $screenshotIds,
        string $comment,
        User $closedBy,
    ): Entity
    {
        return $this->entityManager->getTransactionManager()->run(
            function () use ($id, $siteData, $screenshotIds, $comment, $closedBy): Entity {
                $shift = $this->getShiftForUpdate($id);

                if ($shift->get('status') !== SmShift::STATUS_REVIEW) {
                    throw WorkflowError::conflict('Подтвердить можно только смену со статусом «На проверке».');
                }

                $financial = $this->calculateFinancials($shift, $siteData, $closedBy);
                $closedAt = gmdate('Y-m-d H:i:s');
                $snapshot = $this->entityManager->getNewEntity(SmShiftFinancialSnapshot::ENTITY_TYPE);
                $snapshot->set(array_merge($financial['snapshot'], [
                    'name' => 'Snapshot ' . (string) $shift->get('name'),
                    'shiftId' => $shift->getId(),
                    'closedAt' => $closedAt,
                ]));

                $this->entityManager->saveEntity($snapshot);
                $this->syncExpenseRows(
                    $shift,
                    $snapshot,
                    $financial['snapshot']['expenseBreakdown'],
                    $closedAt,
                );

                $shift->set(array_merge($financial['shift'], [
                    'status' => SmShift::STATUS_CLOSED,
                    'endTime' => $shift->get('endTime') ?: $closedAt,
                    'closedById' => $closedBy->getId(),
                    'reviewedById' => $closedBy->getId(),
                    'reviewedAt' => $closedAt,
                    'screenshotsIds' => $this->normalizeAttachmentIds($screenshotIds),
                    'description' => $this->normalizeComment($comment),
                ]));

                $this->entityManager->saveEntity($shift, ['skipBeforeSave' => true]);

                foreach ($financial['milestoneUserIds'] as $userId) {
                    $recruitedUser = $this->entityManager
                        ->getRDBRepository(User::ENTITY_TYPE)
                        ->where(['id' => $userId])
                        ->forUpdate()
                        ->findOne();

                    if ($recruitedUser instanceof User) {
                        $recruitedUser->set('smRecruiterMilestonePaid', true);
                        $this->entityManager->saveEntity($recruitedUser);
                    }
                }

                return $shift;
            }
        );
    }

    public function queueApproval(string $id, array $siteData, array $screenshotIds, string $comment, User $actor): Entity
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($id, $siteData, $screenshotIds, $comment, $actor): Entity {
            $shift = $this->getShiftForUpdate($id);
            if ($shift->get('status') !== SmShift::STATUS_REVIEW) {
                throw WorkflowError::conflict('Подтвердить можно только смену на проверке.');
            }
            [$rows] = $this->normalizeSiteData($siteData);
            $payload = [
                'operation' => 'approve', 'shiftId' => $id, 'siteData' => $rows,
                'attachmentIds' => $this->normalizeAttachmentIds($screenshotIds),
                'comment' => $this->normalizeComment($comment),
            ];
            $pending = $this->pendingApprovals($id);
            foreach ($pending as $task) {
                if ((((array) $task->get('payload'))['operation'] ?? null) === 'approve') {
                    if ($task->get('initiatedById') !== $actor->getId() ||
                        json_encode($task->get('payload')) !== json_encode((object) $payload)) {
                        throw WorkflowError::conflict('Подтверждение этой смены уже выполняется.');
                    }
                    return $task;
                }
            }
            return $this->mqTasks->enqueue(MqConfig::ROUTING_FILES, $payload, $actor);
        });
    }

    private function pendingApprovals(string $id): iterable
    {
        if (!$this->mqConfig->isEnabled()) {
            return [];
        }
        return $this->entityManager->getRDBRepository(SmMqTask::ENTITY_TYPE)
            ->where(['aggregateId' => $id, 'routingKey' => MqConfig::ROUTING_FILES,
                'status' => [SmMqTask::STATUS_QUEUED, SmMqTask::STATUS_PUBLISHED, SmMqTask::STATUS_PROCESSING]])
            ->find();
    }

    /**
     * Privileged correction of a completed shift. The immutable snapshot can only be
     * changed through this transaction so the list totals and analytics stay aligned.
     *
     * @param array<int, mixed> $siteData
     * @param array<int, mixed> $screenshotIds
     */
    public function revise(string $id, array $siteData, array $screenshotIds, User $revisedBy): Entity
    {
        return $this->entityManager->getTransactionManager()->run(
            function () use ($id, $siteData, $screenshotIds, $revisedBy): Entity {
                $shift = $this->getShiftForUpdate($id);

                if ($shift->get('status') !== SmShift::STATUS_CLOSED) {
                    throw WorkflowError::conflict('Изменять итоги можно только у подсчитанной смены.');
                }

                $snapshot = $this->entityManager
                    ->getRDBRepository(SmShiftFinancialSnapshot::ENTITY_TYPE)
                    ->where(['shiftId' => $shift->getId()])
                    ->forUpdate()
                    ->findOne();

                if (!$snapshot) {
                    throw WorkflowError::notFound('Финансовый снимок смены не найден.');
                }

                $financial = $this->calculateFinancials(
                    $shift,
                    $siteData,
                    $revisedBy,
                    true,
                    (array) ($snapshot->get('recruiterBreakdown') ?? []),
                );

                $financial['snapshot']['closedById'] = $snapshot->get('closedById');
                $snapshot->set($financial['snapshot']);
                $this->entityManager->saveEntity($snapshot, ['skipBeforeSave' => true]);
                $this->syncExpenseRows(
                    $shift,
                    $snapshot,
                    $financial['snapshot']['expenseBreakdown'],
                    (string) $snapshot->get('closedAt'),
                );

                $shift->set(array_merge($financial['shift'], [
                    'screenshotsIds' => $this->normalizeAttachmentIds($screenshotIds),
                ]));
                $this->entityManager->saveEntity($shift, ['skipBeforeSave' => true]);

                return $shift;
            }
        );
    }

    /**
     * @param array<int, mixed> $siteData
     * @param array<int, mixed> $existingRecruiterBreakdown
     * @return array{shift: array<string, mixed>, snapshot: array<string, mixed>, milestoneUserIds: array<int, string>}
     */
    private function calculateFinancials(
        Entity $shift,
        array $siteData,
        User $closedBy,
        bool $isRevision = false,
        array $existingRecruiterBreakdown = [],
    ): array {
        [$normalizedRows, $earnedCents, $bonusCents] = $this->normalizeSiteData($siteData);
        $grossCents = $earnedCents + $bonusCents;
        $teamId = $shift->get('teamId');
        $team = is_string($teamId) ? $this->entityManager->getEntityById('Team', $teamId) : null;

        if (!$team) {
            throw WorkflowError::badRequest('Группа смены не найдена.');
        }

        $producer = $this->getProducer($shift);

        $participants = [
            'Model' => $this->getParticipant($shift, 'modelId', ['Model']),
            'Operator' => $this->getParticipant($shift, 'operatorId', ['Operator', 'ProducerAdmin']),
        ];
        $breakdown = [];
        $participantCents = 0;
        $modelCents = 0;
        $operatorCents = 0;
        $percentUnitsTotal = 0;

        foreach ($participants as $category => $participant) {
            $percentUnits = $this->getEffectivePercentUnits($participant, $category);
            $shareCents = $this->money->share($grossCents, $percentUnits);
            $participantCents += $shareCents;
            $percentUnitsTotal += $percentUnits;

            if ($category === 'Model') {
                $modelCents += $shareCents;
            } else {
                $operatorCents += $shareCents;
            }

            $breakdown[] = [
                'userId' => $participant->getId(),
                'name' => (string) $participant->get('name'),
                'accountType' => (string) $participant->get('smAccountType'),
                'category' => $category,
                'percent' => $this->money->unitsToPercent($percentUnits),
                'amount' => $this->money->centsToAmount($shareCents),
            ];
        }

        $producerPercentUnits = $this->getEffectivePercentUnits($producer, 'Producer');
        $percentUnitsTotal += $producerPercentUnits;

        $technicalDirectorBreakdown = [];
        $technicalDirectorCents = 0;
        $technicalDirectors = $this->entityManager
            ->getRDBRepository(User::ENTITY_TYPE)
            ->where(['isActive' => true, 'smAccountType' => 'TechnicalDirector'])
            ->find();

        foreach ($technicalDirectors as $technicalDirector) {
            if (!$technicalDirector instanceof User) {
                continue;
            }

            $percentUnits = $this->getEffectivePercentUnits($technicalDirector, 'TechnicalDirector');
            $shareCents = $this->money->share($grossCents, $percentUnits);
            $technicalDirectorCents += $shareCents;
            $percentUnitsTotal += $percentUnits;
            $technicalDirectorBreakdown[] = [
                'userId' => $technicalDirector->getId(),
                'name' => (string) $technicalDirector->get('name'),
                'category' => 'TechnicalDirector',
                'percent' => $this->money->unitsToPercent($percentUnits),
                'amount' => $this->money->centsToAmount($shareCents),
            ];
        }

        $recruiterResult = $this->calculateRecruiterExpenses(
            $participants,
            $grossCents,
            $isRevision,
            $existingRecruiterBreakdown,
        );
        $percentUnitsTotal += $recruiterResult['percentUnits'];

        if ($percentUnitsTotal > 1_000_000) {
            throw WorkflowError::badRequest('Сумма долей участников и расходов превышает 100%. Проверьте проценты в профилях и ролях.');
        }

        $producerCents = $this->money->share($grossCents, $producerPercentUnits);
        $recruiterCents = $recruiterResult['amountCents'];
        $expensesCents = $participantCents + $producerCents + $technicalDirectorCents + $recruiterCents;
        $studioCents = $grossCents - $expensesCents;
        $currency = strtoupper((string) ($shift->get('currency') ?: 'USD'));
        $platformMetrics = (object) [
            'sites' => array_map(
                static fn (array $row): array => [
                    'site' => $row['site'],
                    'state' => $row['state'],
                    'earning' => $row['earning'],
                    'offlineBonus' => $row['offlineBonus'],
                ],
                $normalizedRows,
            ),
        ];
        $common = [
            'currency' => $currency,
            'totalGross' => $this->money->centsToAmount($grossCents),
            'modelShare' => $this->money->centsToAmount($modelCents),
            'operatorShare' => $this->money->centsToAmount($operatorCents),
            'producerShare' => $this->money->centsToAmount($producerCents),
            'technicalDirectorShare' => $this->money->centsToAmount($technicalDirectorCents),
            'recruiterShare' => $this->money->centsToAmount($recruiterCents),
            'expensesTotal' => $this->money->centsToAmount($expensesCents),
            'studioShare' => $this->money->centsToAmount($studioCents),
            'expenseBreakdown' => array_merge(
                $breakdown,
                [[
                    'userId' => $producer->getId(),
                    'name' => (string) $producer->get('name'),
                    'category' => 'Producer',
                    'percent' => $this->money->unitsToPercent($producerPercentUnits),
                    'amount' => $this->money->centsToAmount($producerCents),
                ]],
                $technicalDirectorBreakdown,
                $recruiterResult['breakdown'],
            ),
            'platformMetrics' => $platformMetrics,
        ];

        return [
            'shift' => array_merge($common, [
                'producerId' => $producer->getId(),
                'siteData' => $normalizedRows,
                'calculatedTotal' => $this->money->centsToAmount($earnedCents),
                'offlineBonus' => $this->money->centsToAmount($bonusCents),
                'result' => $this->money->centsToAmount($grossCents),
            ]),
            'snapshot' => array_merge($common, [
                'teamId' => $team->getId(),
                'producerId' => $producer->getId(),
                'modelId' => $participants['Model']->getId(),
                'operatorId' => $participants['Operator']->getId(),
                'closedById' => $closedBy->getId(),
                'participantShare' => $this->money->centsToAmount($participantCents),
                'participantBreakdown' => $breakdown,
                'technicalDirectorBreakdown' => $technicalDirectorBreakdown,
                'recruiterBreakdown' => $recruiterResult['breakdown'],
                'assignedUserId' => $producer->getId(),
                'teamsIds' => [$team->getId()],
            ]),
            'milestoneUserIds' => $recruiterResult['milestoneUserIds'],
        ];
    }

    /**
     * @param array<string, User> $participants
     * @param array<int, mixed> $existingBreakdown
     * @return array{amountCents: int, percentUnits: int, breakdown: array<int, array<string, mixed>>, milestoneUserIds: array<int, string>}
     */
    private function calculateRecruiterExpenses(
        array $participants,
        int $grossCents,
        bool $isRevision,
        array $existingBreakdown,
    ): array {
        $amountCents = 0;
        $percentUnitsTotal = 0;
        $breakdown = [];
        $milestoneUserIds = [];

        foreach ($participants as $category => $participant) {
            $existingFlatLine = $isRevision
                ? $this->findExistingFlatRecruiterLine($existingBreakdown, $participant->getId())
                : null;

            if ($existingFlatLine !== null) {
                try {
                    $lineCents = $this->money->amountToCents((string) $existingFlatLine['amount']);
                } catch (InvalidArgumentException $e) {
                    throw WorkflowError::badRequest('Некорректная сумма или процент: сумма должна быть неотрицательной с точностью до 2 знаков, процент — от 0 до 100 с точностью до 4 знаков.');
                }

                $amountCents += $lineCents;
                $breakdown[] = $existingFlatLine;

                continue;
            }

            if ($participant->get('smAccountType') === 'ProducerAdmin') {
                continue;
            }

            $recruiterId = $participant->get('smRecruiterId');
            $mode = (string) ($participant->get('smRecruiterRewardMode') ?: 'None');

            if (!is_string($recruiterId) || $recruiterId === '' || $mode === 'None') {
                continue;
            }

            $recruiter = $this->entityManager->getEntityById(User::ENTITY_TYPE, $recruiterId);

            if (
                !$recruiter instanceof User ||
                !$recruiter->isActive() ||
                $recruiter->get('smAccountType') !== 'Recruiter'
            ) {
                throw WorkflowError::badRequest('Для выплаты рекрутеру выберите активного пользователя с типом аккаунта «Рекрутер».');
            }

            $lineCents = 0;
            $percentUnits = 0;

            try {
                if ($mode === 'PercentPerShift') {
                    $percentUnits = $this->money->percentToUnits($participant->get('smRecruiterPercent') ?: 5);
                    $lineCents = $this->money->share($grossCents, $percentUnits);
                    $percentUnitsTotal += $percentUnits;
                } elseif ($mode === 'FlatAfterFiveShifts') {
                    if (
                        !$isRevision &&
                        !$participant->get('smRecruiterMilestonePaid') &&
                        $this->getClosedShiftCount($participant, $category) >= 4
                    ) {
                        $lineCents = $this->money->amountToCents(
                            $participant->get('smRecruiterFlatAmount') ?: 150,
                        );
                        $milestoneUserIds[] = $participant->getId();
                    }
                } else {
                    throw WorkflowError::badRequest('Неизвестный способ выплаты рекрутеру.');
                }
            } catch (InvalidArgumentException $e) {
                throw WorkflowError::badRequest('Некорректная сумма или процент: сумма должна быть неотрицательной с точностью до 2 знаков, процент — от 0 до 100 с точностью до 4 знаков.');
            }

            if ($lineCents === 0) {
                continue;
            }

            $amountCents += $lineCents;
            $breakdown[] = [
                'userId' => $recruiter->getId(),
                'name' => (string) $recruiter->get('name'),
                'category' => 'Recruiter',
                'mode' => $mode,
                'subjectUserId' => $participant->getId(),
                'subjectName' => (string) $participant->get('name'),
                'subjectCategory' => $category,
                'percent' => $percentUnits > 0 ? $this->money->unitsToPercent($percentUnits) : null,
                'amount' => $this->money->centsToAmount($lineCents),
            ];
        }

        return [
            'amountCents' => $amountCents,
            'percentUnits' => $percentUnitsTotal,
            'breakdown' => $breakdown,
            'milestoneUserIds' => array_values(array_unique($milestoneUserIds)),
        ];
    }

    /** @param array<int, mixed> $rows @return array<string, mixed>|null */
    private function findExistingFlatRecruiterLine(array $rows, string $subjectUserId): ?array
    {
        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $row = (array) $row;
            }

            if (
                is_array($row) &&
                ($row['mode'] ?? null) === 'FlatAfterFiveShifts' &&
                ($row['subjectUserId'] ?? null) === $subjectUserId &&
                isset($row['amount']) &&
                (is_string($row['amount']) || is_int($row['amount']) || is_float($row['amount']))
            ) {
                return $row;
            }
        }

        return null;
    }

    private function getClosedShiftCount(User $participant, string $category): int
    {
        $field = $category === 'Model' ? 'modelId' : 'operatorId';

        return $this->entityManager
            ->getRDBRepository(SmShift::ENTITY_TYPE)
            ->where([
                'deleted' => false,
                'status' => SmShift::STATUS_CLOSED,
                $field => $participant->getId(),
            ])
            ->count();
    }

    /** @param array<int, array<string, mixed>> $breakdown */
    private function syncExpenseRows(
        Entity $shift,
        Entity $snapshot,
        array $breakdown,
        string $incurredAt,
    ): void {
        $existing = $this->entityManager
            ->getRDBRepository(SmShiftExpense::ENTITY_TYPE)
            ->where(['financialSnapshotId' => $snapshot->getId()])
            ->find();

        foreach ($existing as $expense) {
            $this->entityManager->removeEntity($expense);
        }

        foreach ($breakdown as $line) {
            $userId = $line['userId'] ?? null;
            $category = $line['category'] ?? null;
            $amount = $line['amount'] ?? null;

            if (!is_string($userId) || !is_string($category) || !is_string($amount)) {
                throw WorkflowError::badRequest('Не удалось сформировать строку расходов смены.');
            }

            $expense = $this->entityManager->getNewEntity(SmShiftExpense::ENTITY_TYPE);
            $expense->set([
                'name' => mb_substr(sprintf(
                    '%s — %s — %s',
                    $category,
                    (string) $shift->get('name'),
                    (string) ($line['name'] ?? ''),
                ), 0, 190),
                'shiftId' => $shift->getId(),
                'financialSnapshotId' => $snapshot->getId(),
                'userId' => $userId,
                'teamId' => (string) $snapshot->get('teamId'),
                'productionProducerId' => (string) $snapshot->get('producerId'),
                'subjectUserId' => is_string($line['subjectUserId'] ?? null)
                    ? $line['subjectUserId']
                    : null,
                'category' => $category,
                'rewardMode' => is_string($line['mode'] ?? null) ? $line['mode'] : '',
                'amount' => $amount,
                'percentage' => is_string($line['percent'] ?? null) ? $line['percent'] : null,
                'currency' => (string) $snapshot->get('currency'),
                'incurredAt' => $incurredAt,
                'details' => (object) $line,
            ]);
            $this->entityManager->saveEntity($expense);
        }
    }

    /**
     * @param array<int, mixed> $rows
     * @return array{0: array<int, array<string, string>>, 1: int, 2: int}
     */
    private function normalizeSiteData(array $rows): array
    {
        if ($rows === [] || count($rows) > self::MAX_SITE_ROWS) {
            throw WorkflowError::badRequest('Укажите доступы хотя бы к одному сайту (не более 50).');
        }

        $normalized = [];
        $earnedCents = 0;
        $bonusCents = 0;

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $row = (array) $row;
            }

            if (!is_array($row)) {
                throw WorkflowError::badRequest('Некорректный формат строки сайта.');
            }

            $siteValue = $row['site'] ?? '';
            $loginValue = $row['login'] ?? '';
            $passwordValue = $row['password'] ?? '';
            $stateValue = $row['state'] ?? 'Pending';
            $earningValue = $row['earning'] ?? 0;
            $bonusValue = $row['offlineBonus'] ?? 0;

            if (
                !is_string($siteValue) ||
                !is_string($loginValue) ||
                !is_string($passwordValue) ||
                !is_string($stateValue) ||
                (!is_string($earningValue) && !is_int($earningValue) && !is_float($earningValue)) ||
                (!is_string($bonusValue) && !is_int($bonusValue) && !is_float($bonusValue))
            ) {
                throw WorkflowError::badRequest('Некорректный формат полей сайта.');
            }

            $site = trim($siteValue);
            $login = trim($loginValue);
            $password = $passwordValue;
            $state = $stateValue;

            if ($site === '' || $login === '' || $password === '' || mb_strlen($site) > 80) {
                throw WorkflowError::badRequest('В каждой строке заполните сайт, логин и пароль.');
            }

            if (mb_strlen($login) > 150 || mb_strlen($password) > 500) {
                throw WorkflowError::badRequest('Логин или пароль сайта превышает допустимую длину.');
            }

            if (!in_array($state, ['Pending', 'Started', 'Banned'], true)) {
                throw WorkflowError::badRequest('Неизвестное состояние сайта.');
            }

            try {
                $earning = $this->money->amountToCents($earningValue);
                $bonus = $this->money->amountToCents($bonusValue);
            } catch (InvalidArgumentException $e) {
                throw WorkflowError::badRequest('Некорректная сумма или процент: сумма должна быть неотрицательной с точностью до 2 знаков, процент — от 0 до 100 с точностью до 4 знаков.');
            }

            $earnedCents += $earning;
            $bonusCents += $bonus;
            $normalized[] = [
                'site' => $site,
                'login' => $login,
                'password' => $password,
                'state' => $state,
                'earning' => $this->money->centsToAmount($earning),
                'offlineBonus' => $this->money->centsToAmount($bonus),
            ];
        }

        return [$normalized, $earnedCents, $bonusCents];
    }

    /** @param array<int, mixed> $ids @return array<int, string> */
    private function normalizeAttachmentIds(array $ids): array
    {
        $result = [];

        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,36}$/', $id)) {
                throw WorkflowError::badRequest('Некорректный идентификатор скриншота.');
            }

            $result[$id] = $id;
        }

        if (count($result) > self::MAX_SCREENSHOTS) {
            throw WorkflowError::badRequest('Можно загрузить не более 50 скриншотов.');
        }

        return array_values($result);
    }

    private function normalizeComment(string $value): string
    {
        $value = trim($value);

        if (mb_strlen($value) > 5000) {
            throw WorkflowError::badRequest('Комментарий к смене не должен превышать 5000 символов.');
        }

        return $value;
    }

    /** @param array<int, string> $allowedTypes */
    private function getParticipant(Entity $shift, string $field, array $allowedTypes): User
    {
        $id = $shift->get($field);
        $participant = is_string($id)
            ? $this->entityManager
                ->getRDBRepository(User::ENTITY_TYPE)
                ->where(['id' => $id])
                ->forUpdate()
                ->findOne()
            : null;

        if (
            !$participant instanceof User ||
            !$participant->isActive() ||
            !in_array($this->types->resolve($participant), $allowedTypes, true)
        ) {
            throw WorkflowError::badRequest(sprintf(
                'Для смены требуется активный %s. Проверьте тип аккаунта студии в профиле.',
                $field === 'modelId' ? 'пользователь-модель' : 'оператор (или продюсер в слоте оператора)',
            ));
        }

        $participant->set('smAccountType', $this->types->resolve($participant));
        return $participant;
    }

    private function getProducer(Entity $shift): User
    {
        // Once started, the shift owns its producer. Later team edits must not reroute earnings.
        $id = $shift->get('producerId');
        if (!$id && $shift->get('status') === SmShift::STATUS_CLOSED) {
            $snapshot = $this->entityManager->getRDBRepository(SmShiftFinancialSnapshot::ENTITY_TYPE)
                ->where(['shiftId' => $shift->getId()])->findOne();
            $id = $snapshot?->get('producerId');
        }
        if (!$id) {
            $team = $this->entityManager->getEntityById('Team', (string) $shift->get('teamId'));
            if (!$team) {
                throw WorkflowError::badRequest('Группа смены не найдена.');
            }
            $members = $this->composition->resolve($team);
            $id = isset($members['smProducer']) ? $members['smProducer']->getId() : null;
        }
        return $this->composition->participant($id, 'smProducer');
    }

    private function getShiftForUpdate(string $id): Entity
    {
        $shift = $this->entityManager
            ->getRDBRepository(SmShift::ENTITY_TYPE)
            ->where(['id' => $id])
            ->forUpdate()
            ->findOne();

        if (!$shift) {
            throw WorkflowError::notFound('Смена не найдена.');
        }

        return $shift;
    }

    private function getEffectivePercentUnits(User $user, string $category): int
    {
        $categoryOverrideField = match ($category) {
            'Operator' => 'smOperatorSharePercentOverride',
            'Producer' => 'smProducerSharePercentOverride',
            'TechnicalDirector' => 'smTechnicalDirectorSharePercentOverride',
            default => null,
        };
        $override = $categoryOverrideField ? $user->get($categoryOverrideField) : null;

        if (
            $category === 'Operator' &&
            $user->get('smAccountType') === 'ProducerAdmin' &&
            ($override === null || $override === '')
        ) {
            return 0;
        }

        if ($override === null || $override === '') {
            $override = $user->get('smSharePercentOverride');
        }

        try {
            if ($override !== null && $override !== '') {
                return $this->money->percentToUnits($override);
            }

            $result = 0;
            $roles = $this->entityManager
                ->getRDBRepository('User')
                ->getRelation($user, 'roles')
                ->find();

            foreach ($roles as $role) {
                $result = max($result, $this->money->percentToUnits($role->get('smSharePercent')));
            }

            return $result;
        } catch (InvalidArgumentException $e) {
            throw WorkflowError::badRequest('Некорректная сумма или процент: сумма должна быть неотрицательной с точностью до 2 знаков, процент — от 0 до 100 с точностью до 4 знаков.');
        }
    }
}
