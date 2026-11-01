<?php

// Run in the installed CRM: php /tmp/WorkflowIntegrationTest.php
// All fixtures and financial snapshots are rolled back, including on failure.
require '/var/www/html/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\ApplicationUser;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Acl;
use Espo\Core\Api\RequestWrapper;
use Espo\Modules\StudioManagement\Api\GetModelShiftDefaults;
use GuzzleHttp\Psr7\ServerRequest;
use Espo\Modules\StudioManagement\Tools\AccountTypeResolver;
use Espo\Modules\StudioManagement\Tools\ModelShiftDefaults;
use Espo\Modules\StudioManagement\Tools\ShiftService;
use Espo\Modules\StudioManagement\Tools\StaffService;
use Espo\Modules\StudioManagement\Tools\StaffMigration;
use Espo\Modules\StudioManagement\Tools\TeamComposition;
use Espo\Modules\StudioManagement\Tools\RegistrationService;
use Espo\Modules\StudioManagement\Tools\RegistrationLinkMigration;
use Espo\ORM\EntityManager;

$app = new Application();
$container = $app->getContainer();
$em = $container->getByClass(EntityManager::class);
$admin = $em->getRDBRepository('User')->where(['type' => 'admin', 'isActive' => true])->findOne();
$container->getByClass(ApplicationUser::class)->setUser($admin);
$factory = $app->getInjectableFactory();
$siteUrl = rtrim((string) $container->getByClass(\Espo\Core\Utils\Config::class)->get('siteUrl'), '/');
$checks = 0;
$assert = function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$rollback = new RuntimeException('ROLLBACK_TEST_FIXTURES');

try {
    $em->getTransactionManager()->run(function () use ($em, $factory, $admin, $assert, $rollback, $siteUrl): void {
        $suffix = bin2hex(random_bytes(5));
        $createUser = function (string $type, array $extra = []) use ($em, $suffix) {
            $user = $em->getNewEntity('User');
            $user->set(array_merge([
                'userName' => 'regression-' . strtolower($type) . '-' . $suffix,
                'firstName' => 'Regression', 'lastName' => $type,
                'type' => 'regular', 'isActive' => true, 'smAccountType' => $type,
            ], $extra));
            $em->saveEntity($user);
            return $em->getEntityById('User', $user->getId());
        };
        $role = $em->getNewEntity('Role');
        $role->set(['name' => 'Модель', 'smAccountType' => 'Model', 'smSharePercent' => 0]);
        $em->saveEntity($role);
        $model = $createUser('Model', [
            'smAccountType' => '', 'rolesIds' => [$role->getId()],
            'smBirthDate' => '2001-01-01',
            'smSiteData' => [['site' => 'CB', 'login' => 'regression', 'password' => 'fixture-only']],
        ]);
        $assert($model->get('smAccountType') === 'Model', 'Manual role-based user creation must assign studio type.');

        $legacy = $createUser('Legacy', ['smAccountType' => '', 'title' => 'Модель']);
        $legacy->set('smAccountType', '');
        $em->saveEntity($legacy, ['skipBeforeSave' => true]);
        $resolver = $factory->create(AccountTypeResolver::class);
        $resolver->syncExisting();
        $legacy = $em->getEntityById('User', $legacy->getId());
        $assert($legacy->get('smAccountType') === 'Model', 'Existing manually created model must be repaired.');

        $producer = $createUser('ProducerAdmin');
        $operator = $createUser('Operator');
        $recruiter = $createUser('Recruiter');
        $team = $em->getNewEntity('Team');
        $team->set(['name' => 'Regression ' . $suffix, 'smProducerId' => $producer->getId()]);
        $em->saveEntity($team);
        foreach ([$model, $operator] as $member) {
            $em->getRDBRepository('Team')->getRelation($team, 'users')->relateById($member->getId());
        }
        $rows = $factory->create(StaffService::class)->getList('model', $admin)['rows'];
        $assert(in_array($model->getId(), array_column($rows, 'id'), true), 'Model must appear in the staff list.');
        $assert(in_array($legacy->getId(), array_column($rows, 'id'), true), 'Legacy model must appear in the staff list.');
        $producerRows = $factory->create(StaffService::class)->getList('model', $producer)['rows'];
        $assert(in_array($model->getId(), array_column($producerRows, 'id'), true), 'Producer sees their models.');
        $assert(!in_array($legacy->getId(), array_column($producerRows, 'id'), true), 'Producer cannot see another team.');

        $request = new RequestWrapper(new ServerRequest('GET', '/'), '', ['id' => $model->getId()]);
        $response = $factory->create(GetModelShiftDefaults::class)->process($request);
        $defaults = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $assert($defaults['siteData'][0]['login'] === 'regression', 'Defaults API returns model access to an authorized viewer.');
        $outsider = $createUser('Operator', ['userName' => 'regression-outsider-' . $suffix]);
        $api = new GetModelShiftDefaults($em, $outsider, $factory->create(Acl::class),
            $factory->create(\Espo\Modules\StudioManagement\Tools\RolePolicy::class), $factory->create(ModelShiftDefaults::class));
        try {
            $api->process($request);
            $assert(false, 'An unrelated operator must not receive model credentials.');
        } catch (Forbidden) {
            $assert(true, 'Unrelated operator cannot load model defaults.');
        }

        $shift = $em->getNewEntity('SmShift');
        $shift->set([
            'name' => 'Regression ' . $suffix, 'teamId' => $team->getId(),
            'modelId' => $model->getId(), 'operatorId' => $operator->getId(),
            'startTime' => '2026-01-01 14:00:00', 'currency' => 'USD',
        ]);
        $em->saveEntity($shift);
        $assert($shift->get('status') === 'Draft', 'Created shift must be preliminary.');
        $assert($shift->get('assignedUserId') === $operator->getId(), 'Operator owns the shift for ACL.');
        $shift->set('assignedUserId', null);
        $em->saveEntity($shift, ['skipBeforeSave' => true]);
        $factory->create(StaffMigration::class)->run();
        $shift = $em->getEntityById('SmShift', $shift->getId());
        $assert($shift->get('assignedUserId') === $operator->getId(), 'Existing shifts must regain assigned-operator access.');
        $assert($shift->get('startTime') === '2026-01-01 14:00:00', 'Planned start must be saved.');
        $assert(((array) $shift->get('siteData')[0])['login'] === 'regression', 'Profile credentials copied into draft.');
        $assert($shift->get('modelBirthDate') === '2001-01-01', 'Birth date copied from profile.');

        $service = $factory->create(ShiftService::class);
        $shift = $service->open($shift->getId());
        $assert($shift->get('status') === 'Open', 'Start transitions Draft to Open.');
        $assert($shift->get('startTime') !== '2026-01-01 14:00:00', 'Actual start replaces planned start.');
        $assert(substr($shift->get('startTime'), -2) === '00', 'Start uses minute precision.');
        try {
            $service->open($shift->getId());
            $assert(false, 'Starting twice must be rejected.');
        } catch (Conflict) {
            $assert(true, 'Duplicate start rejected.');
        }
        $sites = array_map(fn ($row) => (array) $row, $shift->get('siteData'));
        $sites[0]['state'] = 'Started';
        $shift->set('siteData', $sites);
        $em->saveEntity($shift);
        $assert(((array) $shift->get('siteData')[0])['state'] === 'Started', 'Active site can be started.');

        $shift = $service->finish($shift->getId());
        $assert($shift->get('status') === 'Counting', 'Finish transitions Open to Counting.');
        $sites[0]['earning'] = '125.00';
        $sites[0]['offlineBonus'] = '10.00';
        $shift = $service->close($shift->getId(), $sites, [], 'Operator test comment', $admin);
        $assert($shift->get('status') === 'Closed', 'Count transitions Counting to Closed.');
        $assert($shift->get('description') === 'Operator test comment', 'Operator comment is saved with the closed shift.');
        $assert((float) $shift->get('totalGross') === 135.0, 'Online and offline income summed correctly.');
        $assert((bool) $em->getRDBRepository('SmShiftFinancialSnapshot')->where(['shiftId' => $shift->getId()])->findOne(), 'Close creates financial snapshot.');
        try {
            $service->close($shift->getId(), $sites, [], '', $admin);
            $assert(false, 'Closing twice must be rejected.');
        } catch (Conflict) {
            $assert(true, 'Duplicate close rejected.');
        }

        $composition = $factory->create(TeamComposition::class);
        $storedTeam = $em->getEntityById('Team', $team->getId());
        $assert($storedTeam->get('smModelId') === $model->getId() &&
            $storedTeam->get('smOperatorId') === $operator->getId(), 'Native membership populates template slots.');
        $groupOnly = $em->getNewEntity('SmShift');
        $groupOnly->set(['teamId' => $team->getId(), 'currency' => 'USD']);
        $em->saveEntity($groupOnly);
        $assert($groupOnly->get('modelId') === $model->getId() &&
            $groupOnly->get('operatorId') === $operator->getId() &&
            $groupOnly->get('producerId') === $producer->getId(), 'Group-only ORM create resolves all participants.');
        $producerRole = $em->getNewEntity('Role');
        $producerRole->set(['name' => 'Продюсер', 'smAccountType' => 'ProducerAdmin', 'smSharePercent' => 10]);
        $em->saveEntity($producerRole);
        $linkedRoleUser = $createUser('', ['userName' => 'linked-role-' . $suffix]);
        $em->getRDBRepository('User')->getRelation($linkedRoleUser, 'roles')->relateById($producerRole->getId());
        $assert($em->getEntityById('User', $linkedRoleUser->getId())->get('smAccountType') === 'ProducerAdmin',
            'Assigning a role through the native relation resolves an empty studio type.');
        $producer->set(['rolesIds' => [$producerRole->getId()], 'smAccountType' => null]);
        $em->saveEntity($producer, ['skipBeforeSave' => true]);
        $groupOnly = $service->open($groupOnly->getId());
        $groupOnly = $service->finish($groupOnly->getId());
        // Reproduce pre-upgrade Counting records: no shift producer, member exists but the group FK is empty.
        $groupOnly->set('producerId', null);
        $em->saveEntity($groupOnly, ['skipBeforeSave' => true]);
        $storedTeam->set('smProducerId', null);
        $em->saveEntity($storedTeam, ['skipHooks' => true]);
        $closedLegacy = $service->close($groupOnly->getId(), $sites, [], '', $admin);
        $assert($closedLegacy->get('status') === 'Closed' &&
            $closedLegacy->get('producerId') === $producer->getId(), 'Legacy Counting shift resolves producer from native group and role.');
        $assert((float) $closedLegacy->get('producerShare') === 13.5, 'Legacy producer gets correct percentage.');
        $expense = $em->getRDBRepository('SmShiftExpense')->where([
            'shiftId' => $groupOnly->getId(), 'category' => 'Producer'])->findOne();
        $assert($expense && (float) $expense->get('amount') === 13.5, 'Producer expense is persisted, not just rendered.');

        $producer->set(['smAccountType' => 'ProducerAdmin', 'smOperatorSharePercentOverride' => 5]);
        $em->saveEntity($producer);
        $dualTeam = $em->getNewEntity('Team');
        $dualTeam->set(['name' => 'Dual ' . $suffix, 'smProducerId' => $producer->getId(),
            'smOperatorId' => $producer->getId(), 'smModelId' => $model->getId()]);
        $em->saveEntity($dualTeam);
        $dualShift = $em->getNewEntity('SmShift');
        $dualShift->set(['teamId' => $dualTeam->getId(), 'currency' => 'USD']);
        $em->saveEntity($dualShift);
        $service->open($dualShift->getId());
        $service->finish($dualShift->getId());
        $dualClosed = $service->close($dualShift->getId(), $sites, [], '', $admin);
        $assert((float) $dualClosed->get('operatorShare') === 6.75 &&
            (float) $dualClosed->get('producerShare') === 13.5, 'Producer/operator has separate financial shares.');
        $assert($em->getRDBRepository('SmShift')->getRelation($dualClosed, 'participants')->count() === 2,
            'Dual role does not duplicate participants.');

        $incompleteTeam = $em->getNewEntity('Team');
        $incompleteTeam->set(['name' => 'Incomplete ' . $suffix, 'smProducerId' => $producer->getId()]);
        $em->saveEntity($incompleteTeam);
        try {
            $composition->resolve($incompleteTeam, true);
            $assert(false, 'Incomplete template must fail.');
        } catch (BadRequest $e) {
            $body = json_decode($e->getBody() ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $assert(str_contains($body['message'] ?? '', 'оператор'), 'Incomplete template has readable UTF-8 JSON error.');
        }
        $invite = $em->getNewEntity('SmRegistrationLink');
        $invite->set(['accountType' => 'Model', 'targetRoleId' => $role->getId(), 'recruiterId' => $recruiter->getId()]);
        $em->saveEntity($invite);
        $assert(
            preg_match('/^[A-Za-z0-9_-]{32}$/', (string) $invite->get('token')) === 1,
            'Invitation uses a 32-character cryptographically random URL-safe token.'
        );
        $assert(
            (string) $invite->get('registrationUrl') ===
                $siteUrl . '/?entryPoint=studioRegister#' . $invite->get('token'),
            'Invitation uses the configured domain and keeps the secret in the URL fragment.'
        );
        $registration = $factory->create(RegistrationService::class);
        $originalToken = (string) $invite->get('token');
        $caseChangedToken = preg_replace_callback('/[A-Za-z]/',
            fn ($match) => ctype_lower($match[0]) ? strtoupper($match[0]) : strtolower($match[0]), $originalToken, 1);
        $assert($originalToken !== $caseChangedToken, 'Fixture changes the token case without changing its format.');
        $assert(($registration->getInvitationInfo($originalToken)['mode'] ?? null) === 'register',
            'Exact URL-safe token resolves.');
        $assert($registration->getInvitationInfo($caseChangedToken) === null,
            'Case-insensitive database lookup cannot authorize a differently cased token.');
        try {
            $registration->register($caseChangedToken, 'case-' . $suffix . '@example.invalid', 'Case Fixture', 'CaseFixture-Strong7');
            $assert(false, 'Registration with a differently cased token must be rejected.');
        } catch (Conflict) {
            $assert(true, 'Registration rejects a differently cased token.');
        }

        $legacyInvite = $em->getNewEntity('SmRegistrationLink');
        $legacyInvite->set(['accountType' => 'Model', 'targetRoleId' => $role->getId()]);
        $em->saveEntity($legacyInvite);
        $legacyToken = bin2hex(random_bytes(32));
        $legacyUrl = 'https://wesperr.studio/?entryPoint=studioRegister#access=' . $legacyToken;
        $legacyInvite->set(['token' => $legacyToken, 'registrationUrl' => $legacyUrl]);
        $em->saveEntity($legacyInvite, ['skipBeforeSave' => true]);
        $factory->create(RegistrationLinkMigration::class)->run();
        $migrated = $em->getEntityById('SmRegistrationLink', $legacyInvite->getId());
        $assert($migrated->get('token') === $legacyToken &&
            (string) $migrated->get('registrationUrl') ===
                $siteUrl . '/?entryPoint=studioRegister#' . $legacyToken,
            'Migration moves an existing link to the configured domain and preserves its secret.');
        $assert(($registration->getInvitationInfo($legacyToken)['mode'] ?? null) === 'register',
            'Legacy token still opens registration.');
        $legacyAccount = $registration->register($legacyToken, 'old-' . $suffix . '@example.invalid',
            'Legacy Account', 'LegacyFixture-Strong7');
        $assert($legacyAccount->get('smAccountType') === 'Model', 'Legacy token still registers the intended account type.');
        $assert(($registration->getInvitationInfo($legacyToken)['mode'] ?? null) === 'login',
            'Legacy token becomes the same account login link after registration.');
        foreach (['Operator', 'ProducerAdmin', 'TechnicalDirector', 'Recruiter'] as $allowedType) {
            $allowedRole = $em->getNewEntity('Role');
            $allowedRole->set(['name' => 'Registration ' . $allowedType . ' ' . $suffix, 'smAccountType' => $allowedType]);
            $em->saveEntity($allowedRole);
            $allowedInvite = $em->getNewEntity('SmRegistrationLink');
            $allowedInvite->set(['accountType' => $allowedType, 'targetRoleId' => $allowedRole->getId()]);
            $em->saveEntity($allowedInvite);
            $assert(strlen((string) $allowedInvite->get('token')) === 32, $allowedType . ' invitation can be created.');
        }
        $mismatchedInvite = $em->getNewEntity('SmRegistrationLink');
        $mismatchedInvite->set(['accountType' => 'Operator', 'targetRoleId' => $role->getId()]);
        $em->saveEntity($mismatchedInvite);
        $assert($mismatchedInvite->get('accountType') === 'Operator' &&
            $mismatchedInvite->get('targetRoleId') === $role->getId(),
            'Account type and ACL role can be selected independently.');
        $badInvite = $em->getNewEntity('SmRegistrationLink');
        $badInvite->set(['accountType' => 'Model', 'targetRoleId' => $role->getId(), 'recruiterId' => $operator->getId()]);
        try {
            $em->saveEntity($badInvite);
            $assert(false, 'Non-recruiter must be rejected.');
        } catch (BadRequest $e) {
            $assert(str_contains($e->getMessage(), 'Рекрутер'), 'Recruiter validation explains the problem in Russian.');
            $body = json_decode($e->getBody() ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $assert(str_contains($body['message'] ?? '', 'Рекрутер'), 'Recruiter validation reaches the browser as UTF-8 JSON.');
        }

        $untypedRole = $em->getNewEntity('Role');
        $untypedRole->set(['name' => 'Untyped ' . $suffix]);
        $em->saveEntity($untypedRole);
        $untypedInvite = $em->getNewEntity('SmRegistrationLink');
        $untypedInvite->set(['accountType' => 'Model', 'targetRoleId' => $untypedRole->getId()]);
        $em->saveEntity($untypedInvite);
        $assert($untypedInvite->get('targetRoleId') === $untypedRole->getId(),
            'Legacy roles without a studio account type remain selectable for account links.');

        $model->set(['smSiteData' => [], 'smSiteAccess' => 'CB - legacy-login - legacy-pass']);
        $defaults = $factory->create(ModelShiftDefaults::class)->get($model);
        $assert($defaults['siteData'][0]['login'] === 'legacy-login', 'Legacy profile access text remains supported.');
        throw $rollback;
    });
} catch (Throwable $e) {
    if ($e !== $rollback) {
        fwrite(STDERR, $e . PHP_EOL);
        exit(1);
    }
}
echo "Workflow integration: $checks checks passed; all fixtures rolled back.\n";
