<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Users;

use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Upload;
use StreamEngine\Domain\User;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\UploadService;
use Throwable;

/** Synchronous publication and authorized file lookup; owned entirely by Users. */
class PhotoUploadService
{
    public function __construct(
        private readonly PdoDatabase $db,
        private readonly PhotoService $photos,
        private readonly FeedService $feeds,
        private readonly UploadService $uploads,
    ) {
    }

    public function publish(int $contextId, int $albumId, User $user, array $file, string $title, string $caption, string $requestId): Feed
    {
        if (! $this->photos->canUploadToAlbum($contextId, $albumId, $user)) {
            throw new ForbiddenException('Cannot upload to this album');
        }
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255 || mb_strlen($caption) > 2000
            || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/i', $requestId) !== 1) {
            throw new ValidationException('Invalid photo data');
        }
        $pair = null;
        $this->db->begin();
        try {
            // Serialize quota and idempotency checks for this user and album.
            $this->db->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user->id]);
            $this->db->fetchOne('SELECT id FROM feeds WHERE id = ? FOR UPDATE', [$albumId]);
            $album = $this->photos->getAlbum($contextId, $albumId, $user);
            if (! $this->photos->canUploadToAlbum($contextId, $albumId, $user)) {
                throw new ForbiddenException('Cannot upload to this album');
            }
            $existing = $this->db->fetchOne(
                "SELECT f.id FROM feeds f JOIN feed_metadata m ON m.feed_id = f.id
                 WHERE f.parent_id = ? AND f.owner_id = ? AND f.type = 'gallery-photo'
                 AND m.name = 'gallery.request_id' AND m.content = ? LIMIT 1",
                [$albumId, $user->id, strtolower($requestId)],
            );
            if ($existing !== null) {
                $photo = $this->getPhoto($contextId, $albumId, (int) $existing['id'], $user);
                $this->db->commit();
                return $photo;
            }
            $pair = $this->uploads->uploadPhotoForUser($user, $file);
            $metadata = [
                'gallery.original_upload_id' => (string) $pair['original']->id,
                'gallery.preview_upload_id' => (string) $pair['preview']->id,
                'gallery.width' => (string) $pair['width'], 'gallery.height' => (string) $pair['height'],
                'gallery.state' => 'ready', 'gallery.updated_at' => (string) time(),
                'gallery.request_id' => strtolower($requestId),
            ];
            $photo = $this->feeds->createFeed(
                title: $title,
                slug: null,
                type: 'gallery-photo',
                parentId: $albumId,
                description: $caption,
                imageUrl: null,
                content: '',
                user: $user,
                metadata: $metadata,
                visibility: $album->visibility,
                containerId: $album->containerId,
                containerType: $album->containerType,
            );
            $photo->metadata = $metadata;
            $this->db->commit();
            return $photo;
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($pair !== null) {
                foreach (['original', 'preview'] as $variant) {
                    try {
                        $this->uploads->discardUnattachedPhoto($pair[$variant]);
                    } catch (Throwable $cleanupError) {
                        error_log('Photo publication cleanup failed: '.$cleanupError->getMessage());
                    }
                }
            }
            throw $error;
        }
    }

    public function getPhoto(int $contextId, int $albumId, int $photoId, User $user): Feed
    {
        $this->photos->getAlbum($contextId, $albumId, $user);
        $photo = $this->feeds->getFeedById($photoId, $user);
        if ($photo === null || $photo->type !== 'gallery-photo' || $photo->parentId !== $albumId
            || ($photo->metadata['gallery.state'] ?? '') !== 'ready') {
            throw new NotFoundException('Photo not found');
        }
        foreach (['gallery.width', 'gallery.height', 'gallery.original_upload_id', 'gallery.preview_upload_id'] as $key) {
            if (filter_var($photo->metadata[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new NotFoundException('Photo not ready');
            }
        }
        if ($photo->metadata['gallery.original_upload_id'] === $photo->metadata['gallery.preview_upload_id']) {
            throw new NotFoundException('Invalid photo variants');
        }
        return $photo;
    }

    public function getFile(int $contextId, int $albumId, int $photoId, string $variant, User $user): Upload
    {
        if (! in_array($variant, ['original', 'preview'], true)) {
            throw new NotFoundException('Photo variant not found');
        }
        $photo = $this->getPhoto($contextId, $albumId, $photoId, $user);
        $result = null;
        foreach (['original', 'preview'] as $name) {
            $upload = $this->uploads->findById((int) $photo->metadata['gallery.'.$name.'_upload_id']);
            if ($upload === null || $upload->userId !== $photo->ownerId || $upload->purpose !== UploadRepository::PURPOSE_GALLERY_PHOTO
                || ! in_array($upload->mime, ['image/webp', 'image/png'], true) || $upload->size <= 0) {
                throw new NotFoundException('Photo file not found');
            }
            if ($variant === $name) {
                $result = $upload;
            }
        }
        return $result;
    }
}
