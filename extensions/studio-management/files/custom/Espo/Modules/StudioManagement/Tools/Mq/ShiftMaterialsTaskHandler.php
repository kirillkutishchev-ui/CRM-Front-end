<?php

namespace Espo\Modules\StudioManagement\Tools\Mq;

use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\InjectableFactory;
use Espo\Entities\Attachment;
use Espo\Modules\StudioManagement\Entities\SmShift;
use Espo\Modules\StudioManagement\Tools\ShiftService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;

final class ShiftMaterialsTaskHandler implements TaskHandler
{
    private const MAX_BYTES = 20 * 1024 * 1024;
    private const MAX_PIXELS = 24000000;
    private const MAX_SIDE = 20000;
    private const MAX_ATTACHMENTS = 50;
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private EntityManager $entityManager,
        private FileStorageManager $fileStorage,
        private TaskAuthorization $authorization,
        private InjectableFactory $factory,
    ) {}

    public function handle(array $payload, array $security): array
    {
        $shiftId = $payload['shiftId'] ?? null;
        $operation = $payload['operation'] ?? 'validate';
        $attachmentIds = $operation === 'approve'
            ? ($payload['screenshotsIds'] ?? $payload['attachmentIds'] ?? [])
            : ($payload['attachmentIds'] ?? null);
        $userId = $security['userId'] ?? null;

        if (!is_string($shiftId) || !is_string($userId) || !is_array($attachmentIds) ||
            count($attachmentIds) > self::MAX_ATTACHMENTS || !in_array($operation, ['validate', 'approve'], true)) {
            throw new RuntimeException('Shift-material task payload is invalid.');
        }

        $context = $this->authorization->shift($userId, $shiftId, $operation === 'approve');
        $shift = $context['shift'];

        $files = [];
        $totalSize = 0;

        foreach ($attachmentIds as $attachmentId) {
            if (!is_string($attachmentId)) {
                throw new RuntimeException('Attachment ID must be a string.');
            }

            $attachment = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);

            if (!$attachment instanceof Attachment || $attachment->get('deleted')) {
                throw new RuntimeException("Attachment {$attachmentId} is missing.");
            }

            $this->authorization->attachment($context, $attachment);

            if (!$this->fileStorage->exists($attachment)) {
                throw new RuntimeException("Attachment {$attachmentId} is missing.");
            }

            $size = $this->fileStorage->getSize($attachment);
            if ($size < 1 || $size > self::MAX_BYTES) {
                throw new BadRequest('Screenshots must be between 1 byte and 20 MiB.');
            }

            $contents = $this->boundedContents($attachment);
            $info = @getimagesizefromstring($contents);
            $mime = $info['mime'] ?? '';

            if (!$info || !in_array($mime, self::IMAGE_TYPES, true) ||
                $mime !== $attachment->getType() || $info[0] < 1 || $info[1] < 1 ||
                $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE ||
                $info[0] * $info[1] > self::MAX_PIXELS) {
                throw new BadRequest('Use a valid JPEG, PNG, WebP or GIF screenshot up to 24 megapixels.');
            }

            $this->assertMemoryBudget($info[0] * $info[1], strlen($contents));
            $image = @imagecreatefromstring($contents);

            if ($image === false) {
                throw new BadRequest('Screenshot image data cannot be decoded.');
            }

            $compressed = null;
            try {
                // PNG recompression is lossless, keeps dimensions/transparency and
                // never overwrites the original. Preserve JPEG metadata/orientation,
                // animated GIF/WebP and existing lossy encodings at this stage.
                if ($mime === 'image/png') {
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                    ob_start();
                    try {
                        if (!imagepng($image, null, 9)) {
                            throw new RuntimeException('PNG compression failed.');
                        }
                        $encoded = ob_get_contents();
                    } finally {
                        ob_end_clean();
                    }
                    if (is_string($encoded) && strlen($encoded) < strlen($contents)) {
                        $compressed = $this->storeCompressed($shift, $attachment, $context['user']->getId(),
                            $contents, $encoded);
                    }
                }
            } finally {
                imagedestroy($image);
                unset($image);
            }

            $totalSize += $size;
            $files[] = [
                'id' => $attachmentId,
                'name' => (string) $attachment->getName(),
                'mime' => $mime,
                'size' => $size,
                'width' => $info[0],
                'height' => $info[1],
                'compressedAttachmentId' => $compressed?->getId(),
                'compressedSize' => $compressed?->getSize(),
            ];
            unset($contents, $encoded);
        }

        $result = [
            'shiftId' => $shiftId,
            'validatedCount' => count($files),
            'totalSize' => $totalSize,
            'files' => $files,
        ];

        // Merge under the shift lock: another material task must not lose mappings.
        $this->entityManager->getTransactionManager()->run(function () use ($shiftId, $files): void {
            $current = $this->entityManager->getRDBRepository(SmShift::ENTITY_TYPE)
                ->where(['id' => $shiftId])->forUpdate()->findOne();
            $mapping = (array) ($current->get('screenshotOptimizations') ?? []);
            foreach ($files as $file) {
                if ($file['compressedAttachmentId']) {
                    $mapping[$file['id']] = $file['compressedAttachmentId'];
                }
            }
            $current->set('screenshotOptimizations', (object) $mapping);
            $this->entityManager->saveEntity($current, ['skipBeforeSave' => true]);
        });

        if ($operation === 'approve') {
            $siteData = $payload['siteData'] ?? null;
            $comment = $payload['comment'] ?? '';

            if (!is_array($siteData) || !is_string($comment)) {
                throw new BadRequest('Invalid queued shift approval data.');
            }

            $approved = $this->factory->create(ShiftService::class)->approve(
                $shiftId,
                $siteData,
                $attachmentIds,
                $comment,
                $context['user'],
            );
            $result['shift'] = ['id' => $approved->getId(), 'status' => $approved->get('status')];
            $result['approved'] = true;
        }

        return $result;
    }

    private function boundedContents(Attachment $attachment): string
    {
        $stream = $this->fileStorage->getStream($attachment);
        $contents = '';

        try {
            while (!$stream->eof() && strlen($contents) <= self::MAX_BYTES) {
                $chunk = $stream->read(min(65536, self::MAX_BYTES + 1 - strlen($contents)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException('Screenshot stream stopped before EOF.');
                }
                $contents .= $chunk;
            }
        } finally {
            $stream->close();
        }

        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw new BadRequest('Screenshot exceeds the 20 MiB processing limit.');
        }

        return $contents;
    }

    private function assertMemoryBudget(int $pixels, int $bytes): void
    {
        $rawLimit = trim((string) ini_get('memory_limit'));
        if ($rawLimit === '-1') {
            return;
        }

        $limit = (int) $rawLimit;
        $suffix = strtolower(substr($rawLimit, -1));
        $limit *= match ($suffix) { 'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1 };
        $needed = $pixels * 8 + $bytes * 3 + 8 * 1024 * 1024;

        if ($limit > 0 && $needed > $limit - memory_get_usage(true)) {
            throw new BadRequest('Screenshot dimensions exceed the worker memory budget.');
        }
    }

    private function storeCompressed(
        Entity $shift,
        Attachment $source,
        string $userId,
        string $sourceContents,
        string $encoded,
    ): ?Attachment {
        $id = substr(hash('sha256', 'studio-png-v1:' . $shift->getId() . ':' . $source->getId() . ':' .
            hash('sha256', $sourceContents)), 0, 17);

        return $this->entityManager->getTransactionManager()->run(function () use (
            $shift, $source, $userId, $encoded, $id,
        ): ?Attachment {
            // Serialize jobs creating the same derived artifact. Its stable ID
            // makes redelivery reuse it instead of appending another attachment.
            $this->entityManager->getRDBRepository(SmShift::ENTITY_TYPE)
                ->where(['id' => $shift->getId()])->forUpdate()->findOne();
            $existing = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $id);

            if ($existing instanceof Attachment) {
                if ($existing->get('deleted')) {
                    return null;
                }
                if ($existing->getRelatedType() !== SmShift::ENTITY_TYPE ||
                    $existing->getRelatedId() !== $shift->getId() || $existing->getTargetField() !== 'screenshots') {
                    throw new RuntimeException('Derived screenshot identifier is already in use.');
                }
                if (!$this->fileStorage->exists($existing)) {
                    $this->fileStorage->putContents($existing, $encoded);
                }
                return $existing;
            }

            $attachment = $this->entityManager->getNewEntity(Attachment::ENTITY_TYPE);
            $attachment->set([
                'id' => $id,
                'name' => mb_substr(pathinfo((string) $source->getName(), PATHINFO_FILENAME), 0, 200) . '.optimized.png',
                'type' => 'image/png',
                'role' => Attachment::ROLE_ATTACHMENT,
                'contents' => $encoded,
                'createdById' => $userId,
                // Related-only artifacts inherit screenshot ACL while remaining
                // outside the originals' parent/field attachment collection.
                'relatedType' => SmShift::ENTITY_TYPE,
                'relatedId' => $shift->getId(),
                'field' => 'screenshots',
            ]);
            $this->entityManager->saveEntity($attachment);

            if (!$attachment instanceof Attachment) {
                throw new RuntimeException('Attachment entity type was not available.');
            }

            return $attachment;
        });
    }
}
