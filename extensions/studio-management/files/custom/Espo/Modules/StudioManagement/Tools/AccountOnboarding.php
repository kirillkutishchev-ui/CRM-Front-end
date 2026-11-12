<?php

namespace Espo\Modules\StudioManagement\Tools;

use DateTimeImmutable;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\Attachment;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\Repositories\Attachment as AttachmentRepository;
use stdClass;

final class AccountOnboarding
{
    public const PENDING = 'smRegistrationPending';
    private const PAGE_SIZE = 50;
    private const PHOTO_LIMIT = 50;
    private const PHOTO_BYTES = 5 * 1024 * 1024;
    private const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const PHOTO_FIELD = 'smProfilePhotos';
    private const PERCENT_FIELDS = [
        'smSharePercentOverride',
        'smOperatorSharePercentOverride',
        'smProducerSharePercentOverride',
        'smTechnicalDirectorSharePercentOverride',
    ];
    private const TEXT_FIELDS = ['smProfileBio' => 10000, 'smContact' => 255];
    private const DATE_FIELDS = ['smBirthDate', 'smStartDate'];

    public function __construct(private EntityManager $em, private RolePolicy $policy) {}

    /** Registration callers invoke this before the new regular user's first save. */
    public static function prepare(User $user): void
    {
        if (!$user->isNew() || !$user->isRegular()) {
            throw new BadRequest('Only a new regular account can enter onboarding.');
        }
        $user->set([self::PENDING => true, 'isActive' => false]);
    }

    public static function isRecruiter(User $user): bool
    {
        return $user->isRegular() && $user->get('smAccountType') === 'Recruiter';
    }

    private function scope(User $actor): array
    {
        $canApproveAll = $this->policy->canApproveAccounts($actor);
        if (!$actor->isActive() || !$this->policy->canAccessOnboarding($actor)) {
            throw new Forbidden();
        }
        $scope = ['type' => User::TYPE_REGULAR, self::PENDING => true, 'isActive' => false];
        if (!$canApproveAll) {
            $scope['OR'] = [['smRecruiterId' => $actor->getId()], ['smRecruiterId' => null]];
        }
        return $scope;
    }

    public function getList(User $actor, int $offset): array
    {
        $users = $this->em->getRDBRepository(User::ENTITY_TYPE)
            ->select(['id', 'name', 'smAccountType', 'createdAt'])
            ->where($this->scope($actor))
            ->order('createdAt', 'DESC')->order('id', 'DESC')
            ->limit($offset, self::PAGE_SIZE + 1)->find();
        $list = [];
        foreach ($users as $user) {
            $list[] = [
                'id' => $user->getId(), 'name' => $user->get('name'),
                'accountType' => $user->get('smAccountType'), 'createdAt' => $user->get('createdAt'),
            ];
        }
        return ['list' => array_slice($list, 0, self::PAGE_SIZE),
            'hasMore' => count($list) > self::PAGE_SIZE, 'pageSize' => self::PAGE_SIZE];
    }

    public function getProfile(User $actor, string $id): array
    {
        return $this->profile($this->getPending($actor, $id));
    }

    public function save(User $actor, string $id, stdClass $data, bool $complete): array
    {
        return $this->em->getTransactionManager()->run(function () use ($actor, $id, $data, $complete) {
            $user = $this->getPending($actor, $id, true);
            $user->set($this->validateProfile($data));
            if ($complete) {
                $user->set([
                    self::PENDING => false,
                    'isActive' => true,
                    'smRegistrationCompletedAt' => gmdate('Y-m-d H:i:s'),
                    'smRegistrationCompletedById' => $actor->getId(),
                ]);
            }
            $this->em->saveEntity($user);
            return $complete ? ['id' => $id, 'completed' => true] : $this->profile($user);
        });
    }

    public function uploadPhoto(User $actor, string $id, stdClass $data): array
    {
        $user = $this->getPending($actor, $id);
        $encoded = $data->content ?? null;
        if (!is_string($encoded) || strlen($encoded) > 4 * (int) ceil(self::PHOTO_BYTES / 3)) {
            throw new BadRequest('Photo exceeds the 5 MB limit.');
        }
        $contents = base64_decode($encoded, true);
        $image = $contents !== false && strlen($contents) <= self::PHOTO_BYTES
            ? @getimagesizefromstring($contents) : false;
        $mime = $image['mime'] ?? '';
        if (!$image || !isset(self::PHOTO_TYPES[$mime]) || $image[0] * $image[1] > 24000000) {
            throw new BadRequest('Use a JPEG, PNG or WebP image up to 5 MB and 24 megapixels.');
        }
        $rawName = is_string($data->name ?? null) ? $data->name : '';
        $name = preg_replace('/[^\pL\pN ._-]/u', '_', $rawName) ?: 'photo';
        $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 180) . '.' . self::PHOTO_TYPES[$mime];

        return $this->em->getTransactionManager()->run(function () use ($actor, $user, $name, $mime, $contents) {
            $locked = $this->getPending($actor, $user->getId(), true);
            if ($this->photoQuery($locked)->count() >= self::PHOTO_LIMIT) {
                throw new BadRequest('The account already has 50 profile photos.');
            }
            $attachment = $this->em->getNewEntity(Attachment::ENTITY_TYPE);
            $attachment->set([
                'name' => $name, 'type' => $mime, 'role' => 'Attachment', 'contents' => $contents,
                'parentType' => User::ENTITY_TYPE, 'parentId' => $locked->getId(), 'field' => self::PHOTO_FIELD,
            ]);
            $this->em->saveEntity($attachment);
            return $this->profile($locked);
        });
    }

    public function getPhoto(User $actor, string $id, string $photoId): array
    {
        $photo = $this->photoQuery($this->getPending($actor, $id))->where(['id' => $photoId])->findOne();
        $mime = $photo?->get('type');
        if (!$photo instanceof Attachment || !isset(self::PHOTO_TYPES[$mime]) ||
            $photo->getSize() > self::PHOTO_BYTES) {
            throw new NotFound();
        }
        /** @var AttachmentRepository $repository */
        $repository = $this->em->getRepositoryByClass(Attachment::class);
        return ['dataUrl' => 'data:' . $mime . ';base64,' . base64_encode($repository->getContents($photo))];
    }

    public function removePhoto(User $actor, string $id, string $photoId): array
    {
        return $this->em->getTransactionManager()->run(function () use ($actor, $id, $photoId) {
            $user = $this->getPending($actor, $id, true);
            $photo = $this->photoQuery($user)->where(['id' => $photoId])->findOne();
            if (!$photo) {
                throw new NotFound();
            }
            $this->em->removeEntity($photo);
            return $this->profile($user);
        });
    }

    private function getPending(User $actor, string $id, bool $lock = false): User
    {
        $query = $this->em->getRDBRepository(User::ENTITY_TYPE)->where($this->scope($actor) + ['id' => $id]);
        $user = ($lock ? $query->forUpdate() : $query)->findOne();
        if (!$user instanceof User) {
            throw new NotFound();
        }
        return $user;
    }

    private function photoQuery(User $user): RDBSelectBuilder
    {
        return $this->em->getRDBRepository(Attachment::ENTITY_TYPE)->where([
            'parentType' => User::ENTITY_TYPE, 'parentId' => $user->getId(), 'field' => self::PHOTO_FIELD,
        ]);
    }

    private function profile(User $user): array
    {
        $data = ['id' => $user->getId(), 'name' => $user->get('name'),
            'userName' => $user->getUserName(), 'accountType' => $user->get('smAccountType'), 'photos' => []];
        foreach (array_merge(self::PERCENT_FIELDS, array_keys(self::TEXT_FIELDS), self::DATE_FIELDS) as $field) {
            $data[$field] = $user->get($field);
        }
        foreach ($this->photoQuery($user)->select(['id', 'name'])->order('createdAt')->find() as $photo) {
            $data['photos'][] = ['id' => $photo->getId(), 'name' => $photo->get('name')];
        }
        return $data;
    }

    private function validateProfile(stdClass $data): array
    {
        $values = [];
        foreach ((array) $data as $field => $value) {
            if (in_array($field, self::PERCENT_FIELDS, true)) {
                if ($value === '' || $value === null) {
                    $values[$field] = null;
                    continue;
                }
                if ((!is_string($value) && !is_int($value) && !is_float($value)) ||
                    !preg_match('/^\d{1,3}(?:\.\d{1,4})?$/D', (string) $value) || (float) $value > 100) {
                    throw new BadRequest('Percentages must be between 0 and 100, with up to four decimal places.');
                }
                $values[$field] = (string) $value;
                continue;
            }
            if (isset(self::TEXT_FIELDS[$field])) {
                if (!is_string($value) || mb_strlen($value) > self::TEXT_FIELDS[$field]) {
                    throw new BadRequest('Invalid profile text.');
                }
                $values[$field] = trim($value);
                continue;
            }
            if (in_array($field, self::DATE_FIELDS, true)) {
                $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if ($value !== '' && $value !== null && (!$date || $date->format('Y-m-d') !== $value)) {
                    throw new BadRequest('Invalid profile date.');
                }
                $values[$field] = $value ?: null;
                continue;
            }
            throw new BadRequest('Field is not editable during onboarding.');
        }
        return $values;
    }
}
