<?php

namespace Espo\Modules\StudioManagement\Tools;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Utils\PasswordHash;
use Espo\Entities\User;
use Espo\Modules\StudioManagement\Entities\SmInviteUse;
use Espo\Modules\StudioManagement\Entities\SmRegistrationLink;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\UserSecurity\Password\Checker;
use stdClass;
use Throwable;

final class RegistrationService
{
    public function __construct(
        private EntityManager $entityManager,
        private PasswordHash $passwordHash,
        private Checker $passwordChecker,
        private RegistrationToken $registrationToken,
    ) {}

    public function getInvitationInfo(string $token): ?array
    {
        $invitation = $this->findInvitation($token);

        if (!$invitation) {
            return null;
        }

        if ($invitation->get('isUsed')) {
            $userId = $invitation->get('usedByUserId');
            $user = is_string($userId) ? $this->entityManager->getEntityById('User', $userId) : null;

            if (!$user instanceof User || !$user->isRegular()) {
                return null;
            }

            if ($user->get(AccountOnboarding::PENDING)) {
                return [
                    'mode' => 'pending',
                    'displayName' => (string) $user->get('name'),
                ];
            }

            if (!$user->isActive()) {
                return null;
            }

            return [
                'mode' => 'login',
                'userName' => $user->getUserName(),
                'displayName' => (string) $user->get('name'),
            ];
        }

        if (!$this->isAvailable($invitation)) {
            return null;
        }

        $role = $this->entityManager->getEntityById('Role', (string) $invitation->get('targetRoleId'));

        if (!$role) {
            return null;
        }

        return [
            'mode' => 'register',
            'accountType' => (string) $invitation->get('accountType'),
            'roleName' => (string) $role->get('name'),
            'expiresAt' => (string) $invitation->get('expiresAt'),
        ];
    }

    public function register(string $token, string $email, string $fullName, string $password): User
    {
        $token = trim($token);
        $email = mb_strtolower(trim($email));
        $fullName = trim(preg_replace('/\s+/u', ' ', $fullName) ?? '');

        if (!$this->registrationToken->isValid($token)) {
            throw new BadRequest('Invalid invitation token.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new BadRequest('A valid email address is required.');
        }

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 150) {
            throw new BadRequest('Full name must contain between 2 and 150 characters.');
        }

        if (
            mb_strlen($password) < 10 ||
            strlen($password) > 255 ||
            !preg_match('/[a-z]/', $password) ||
            !preg_match('/[A-Z]/', $password) ||
            !preg_match('/[0-9]/', $password) ||
            !$this->passwordChecker->checkStrength($password)
        ) {
            throw new BadRequest('The password does not meet the configured strength requirements.');
        }

        if ($this->entityManager->getRDBRepository('User')->where(['userName' => $email])->findOne()) {
            throw new Conflict('A user with this email already exists.');
        }

        $invitation = $this->findInvitation($token);

        if (!$invitation || !$this->isAvailable($invitation)) {
            throw new Conflict('The invitation is invalid, expired or already used.');
        }

        try {
            return $this->entityManager->getTransactionManager()->run(
                function () use ($invitation, $email, $fullName, $password): User {
                    // Unique marker is inserted first. Concurrent redemption of the same token
                    // fails before a second user can be committed.
                    $marker = $this->entityManager->getNewEntity(SmInviteUse::ENTITY_TYPE);
                    $marker->set('registrationLinkId', $invitation->getId());
                    $this->entityManager->saveEntity($marker);

                    [$firstName, $lastName] = $this->splitName($fullName);
                    $user = $this->entityManager->getNewEntity('User');

                    if (!$user instanceof User) {
                        throw new Conflict('Could not create a user.');
                    }

                    $user->set([
                        'userName' => $email,
                        'emailAddress' => $email,
                        'firstName' => $firstName,
                        'lastName' => $lastName,
                        'type' => User::TYPE_REGULAR,
                        'password' => $this->passwordHash->hash($password),
                        'smAccountType' => $invitation->get('accountType'),
                        'smRecruiterId' => $invitation->get('recruiterId'),
                    ]);

                    AccountOnboarding::prepare($user);
                    $this->entityManager->saveEntity($user);

                    $this->entityManager
                        ->getRDBRepository('User')
                        ->getRelation($user, 'roles')
                        ->relateById((string) $invitation->get('targetRoleId'));

                    $invitation->set([
                        'isUsed' => true,
                        'usedAt' => gmdate('Y-m-d H:i:s'),
                        'usedByUserId' => $user->getId(),
                    ]);
                    $this->entityManager->saveEntity($invitation);

                    return $user;
                }
            );
        } catch (BadRequest|Conflict $e) {
            throw $e;
        } catch (Throwable) {
            throw new Conflict('Registration could not be completed. The invitation may already be used.');
        }
    }

    private function findInvitation(string $token): ?Entity
    {
        if (!$this->registrationToken->isValid($token)) {
            return null;
        }

        $invitation = $this->entityManager
            ->getRDBRepository(SmRegistrationLink::ENTITY_TYPE)
            ->where(['token' => $token])
            ->findOne();

        // An indexed lookup can be case-insensitive; never accept a different spelling of a secret.
        return $invitation && hash_equals((string) $invitation->get('token'), $token)
            ? $invitation
            : null;
    }

    private function isAvailable(Entity $invitation): bool
    {
        $expiresAt = $invitation->get('expiresAt');

        return !$invitation->get('isUsed') &&
            is_string($expiresAt) &&
            strtotime($expiresAt . ' UTC') > time();
    }

    /** @return array{string, string} */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/u', $fullName) ?: [];
        $lastName = (string) array_pop($parts);
        $firstName = implode(' ', $parts);

        return [$firstName, $lastName];
    }
}
