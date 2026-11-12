<?php

namespace Espo\Modules\StudioManagement\Tools\Mq;

use Espo\Core\Acl\Table;
use Espo\Core\Acl\Table\DefaultCacheKeyProvider;
use Espo\Core\Acl\Table\DefaultRoleListProvider;
use Espo\Core\Acl\Table\DefaultTable;
use Espo\Core\Acl\Table\TableFactory;
use Espo\Core\AclManager;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\SystemConfig;
use Espo\Core\Utils\Event\Configuration as EventConfiguration;
use Espo\Core\Utils\Event\EventDispatcher;
use Espo\Entities\Attachment;
use Espo\Entities\User;
use Espo\Modules\StudioManagement\Entities\SmShift;
use Espo\Modules\StudioManagement\Entities\SmShiftFinancialSnapshot;
use Espo\Modules\StudioManagement\Tools\AccountOnboarding;
use Espo\Modules\StudioManagement\Tools\RolePolicy;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Rebuild current authorization for each delivery; a long-lived worker must not pin old role grants. */
final class TaskAuthorization
{
    public function __construct(
        private EntityManager $entityManager,
        private InjectableFactory $factory,
        private Config $config,
    ) {}

    public function notification(string $userId): void
    {
        $this->context($userId);
    }

    /** @return array{user: User, acl: AclManager, policy: RolePolicy} */
    public function analytics(string $userId): array
    {
        $context = $this->context($userId);
        ['user' => $user, 'acl' => $acl, 'policy' => $policy] = $context;

        if (!$acl->checkScope($user, 'SmAnalytics') ||
            !$acl->check($user, SmShiftFinancialSnapshot::ENTITY_TYPE, Table::ACTION_READ) ||
            $policy->level($user, 'smAnalyticsAccess', 'legacy') === 'no') {
            throw new Forbidden('Your current role cannot access studio analytics.');
        }

        return $context;
    }

    /** @param array{user: User, acl: AclManager, policy: RolePolicy} $context */
    public function analyticsFingerprint(array $context): string
    {
        ['user' => $user, 'acl' => $acl, 'policy' => $policy] = $context;
        $roleIds = array_values((array) ($user->get('rolesIds') ?? []));
        $teamIds = array_values($user->getTeamIdList());
        sort($roleIds);
        sort($teamIds);

        return hash('sha256', json_encode([
            'userId' => $user->getId(),
            'isAdmin' => $user->isAdmin(),
            'accountType' => $user->get('smAccountType'),
            'studioScope' => $acl->checkScope($user, 'SmAnalytics'),
            'snapshotRead' => $acl->getLevel($user, SmShiftFinancialSnapshot::ENTITY_TYPE, Table::ACTION_READ),
            'studioPermission' => $policy->level($user, 'smAnalyticsAccess', 'legacy'),
            'roleIds' => $roleIds,
            'teamIds' => $teamIds,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{user: User, acl: AclManager, policy: RolePolicy, shift: Entity} */
    public function shift(string $userId, string $shiftId, bool $approve = false): array
    {
        $context = $this->context($userId);
        ['user' => $user, 'acl' => $acl, 'policy' => $policy] = $context;
        $shift = $this->entityManager->getEntityById(SmShift::ENTITY_TYPE, $shiftId);

        if (!$shift || $shift->get('deleted')) {
            throw new Forbidden('The shift is unavailable.');
        }

        if ($approve) {
            $allowed = $acl->checkEntity($user, $shift, Table::ACTION_EDIT) && $policy->canReview($user, $shift);
        } else {
            $allowed = ($acl->checkEntity($user, $shift, Table::ACTION_READ) ||
                    $acl->checkEntity($user, $shift, Table::ACTION_EDIT)) &&
                ($user->isAdmin() || $policy->canOperate($user, $shift) ||
                    $policy->canReview($user, $shift) || $policy->canRevise($user, $shift));
        }

        if (!$allowed || !$acl->checkField($user, SmShift::ENTITY_TYPE, 'screenshots')) {
            throw new Forbidden('Your current role cannot process this shift.');
        }

        return $context + ['shift' => $shift];
    }

    /** @param array{user: User, acl: AclManager, policy: RolePolicy, shift: Entity} $context */
    public function attachment(array $context, Attachment $attachment): void
    {
        $shift = $context['shift'];
        $linkedIds = (array) ($shift->get('screenshotsIds') ?? []);
        $isParent = $attachment->getParentType() === SmShift::ENTITY_TYPE &&
            $attachment->getParentId() === $shift->getId() && $attachment->getTargetField() === 'screenshots';
        $unbound = !$attachment->getParentType() && !$attachment->getParentId();
        $relatedToThisShift = $attachment->getRelatedType() === SmShift::ENTITY_TYPE &&
            $attachment->getRelatedId() === $shift->getId() && $attachment->getTargetField() === 'screenshots';
        $isOwnUpload = $unbound && $attachment->get('createdById') === $context['user']->getId() &&
            ((!$attachment->getRelatedType() && !$attachment->getRelatedId()) || $relatedToThisShift);

        if ((!$isParent && !$isOwnUpload && !in_array($attachment->getId(), $linkedIds, true)) ||
            !$context['acl']->checkEntity($context['user'], $attachment, Table::ACTION_READ)) {
            throw new Forbidden('An attachment is not an accessible screenshot of this shift.');
        }
    }

    public function taskResult(Entity $task, User $requestUser): void
    {
        $context = $this->context($requestUser->getId());
        $user = $context['user'];

        if (!$user->isAdmin() && $task->get('initiatedById') !== $user->getId()) {
            throw new Forbidden();
        }

        $routingKey = (string) $task->get('routingKey');
        if ($routingKey === MqConfig::ROUTING_ANALYTICS) {
            $analyticsContext = $this->analytics($user->getId());
            $result = (array) $task->get('result');
            $fingerprint = $result['_mqAuthorization'] ?? null;

            if (!$user->isAdmin() && $task->get('status') === 'Completed' &&
                (!is_string($fingerprint) || !hash_equals($this->analyticsFingerprint($analyticsContext), $fingerprint))) {
                throw new Forbidden('Analytics permissions changed. Request a new report.');
            }
            return;
        }

        if ($routingKey === MqConfig::ROUTING_FILES) {
            $payload = $task->get('payload');
            $payload = (array) $payload;
            $shiftId = $payload['shiftId'] ?? null;

            if (!is_string($shiftId)) {
                throw new Forbidden();
            }

            // A delegated reviewer may be unable to open Closed shifts. They
            // can still receive the minimal acknowledgement of their own action.
            if (($payload['operation'] ?? null) === 'approve') {
                $shift = $this->entityManager->getEntityById(SmShift::ENTITY_TYPE, $shiftId);
                if (!$shift || !$context['acl']->checkScope($user, SmShift::ENTITY_TYPE, Table::ACTION_EDIT) ||
                    !$context['policy']->canReview($user, $shift)) {
                    throw new Forbidden();
                }
                return;
            }

            $shiftContext = $this->shift($user->getId(), $shiftId);
            $result = (array) $task->get('result');
            foreach ((array) ($result['files'] ?? []) as $file) {
                $file = (array) $file;
                $attachmentId = $file['id'] ?? null;
                $attachment = is_string($attachmentId)
                    ? $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attachmentId)
                    : null;
                if (!$attachment instanceof Attachment || $attachment->get('deleted')) {
                    throw new Forbidden('A task attachment is no longer available.');
                }
                $this->attachment($shiftContext, $attachment);
            }
            return;
        }

        if (!str_starts_with($routingKey, MqConfig::ROUTING_NOTIFICATION_PREFIX)) {
            throw new Forbidden();
        }
    }

    /** @return array{user: User, acl: AclManager, policy: RolePolicy} */
    private function context(string $userId): array
    {
        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

        if (!$user instanceof User || !$user->isActive() || $user->get('deleted') ||
            AccountOnboarding::isRecruiter($user)) {
            throw new Forbidden('Task user is unavailable or restricted.');
        }

        // StableAclCache pins entries for a request. A worker request can live for
        // days, so construct uncached tables from current roles for this delivery.
        $tableFactory = new class($this->factory, $this->config) implements TableFactory {
            public function __construct(private InjectableFactory $factory, private Config $config) {}

            public function create(User $user): Table
            {
                $config = clone $this->config;
                $config->set('useCache', false);

                return $this->factory->createWith(DefaultTable::class, [
                    'roleListProvider' => $this->factory->createWith(DefaultRoleListProvider::class, ['user' => $user]),
                    'cacheKeyProvider' => new DefaultCacheKeyProvider($user),
                    'user' => $user,
                    'systemConfig' => new SystemConfig($config),
                ]);
            }
        };
        $with = ['tableFactory' => $tableFactory];

        // Keep subscriptions scoped to this context rather than retaining a new
        // ACL manager in the process-wide event dispatcher for every message.
        if (class_exists(EventDispatcher::class) && class_exists(EventConfiguration::class)) {
            $with['eventDispatcher'] = $this->factory->createWith(EventDispatcher::class, [
                'configuration' => new EventConfiguration(false),
            ]);
        }

        $acl = $this->factory->createWith(AclManager::class, $with);
        $policy = $this->factory->createWith(RolePolicy::class, ['aclManager' => $acl]);

        return ['user' => $user, 'acl' => $acl, 'policy' => $policy];
    }
}
