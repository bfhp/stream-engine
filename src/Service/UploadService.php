<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use Random\RandomException;
use RuntimeException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\FileProcessing\FileStorage;
use StreamEngine\Core\FileProcessing\ImageProcessor;
use StreamEngine\Core\FileProcessing\MimeDetector;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Upload;
use StreamEngine\Domain\User;
use StreamEngine\Repository\UploadRepository;

class UploadService
{
    private const string STORAGE_LIMIT_SETTING = 'uploads.user_limit_mb';
    private const int MAX_FILE_SIZE = 50 * 1024 * 1024;
    private const int ORPHAN_GRACE_SECONDS = 24 * 60 * 60;
    private const int ORPHAN_BATCH_SIZE = 100;
    private const int ORPHAN_MAX_BATCHES = 10;

    private const array ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'audio/mpeg',
        'audio/ogg',
        // For forums.topic-new's own "Attachments" card (Modules\Forums\
        // ForumsController::resolveTopicAttachments()) - the only caller
        // that uploads a non-image/non-audio file today.
        'application/pdf',
    ];

    public function __construct(
        private readonly UploadRepository $uploads,
        private readonly MimeDetector $mime,
        private readonly FileStorage $storage,
        private readonly ImageProcessor $images,
        private readonly AccessService $access,
        private readonly TranslationManager $tm,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @throws RandomException
     * @throws ValidationException
     */
    public function uploadForUser(User $user, array $file): Upload
    {
        return $this->storeUserUpload($user, $file, null);
    }

    public function uploadMessageAttachmentForUser(User $user, array $file): Upload
    {
        return $this->storeUserUpload($user, $file, UploadRepository::PURPOSE_MESSAGE_ATTACHMENT);
    }

    /**
     * Stores both image variants privately. The caller authorizes the album and
     * attaches returned IDs in feed_metadata; paths are never public URLs.
     * @return array{original: Upload, preview: Upload, width:int, height:int}
     */
    public function uploadPhotoForUser(User $user, array $file): array
    {
        if ($user->isGuest() || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || ! is_string($file['tmp_name'] ?? null) || ! is_file($file['tmp_name'])) {
            throw new ValidationException($this->tm->trans('upload.invalid_file_type'));
        }
        $size = filesize($file['tmp_name']);
        $maxBytes = max(1, min(50, $this->settings->getInt('photos.max_file_mb', 20))) * 1024 * 1024;
        if ($size === false || $size <= 0 || $size > $maxBytes) {
            throw new ValidationException($this->tm->trans('upload.file_too_large'));
        }
        $this->assertCanUpload($user, $size, true);
        $processed = $this->images->processWithPreview(
            $file['tmp_name'],
            $this->mime->detect($file['tmp_name']),
            max(1, min(40000000, $this->settings->getInt('photos.max_pixels', 20000000))),
            max(1, min(16000, $this->settings->getInt('photos.max_dimension', 10000))),
            max(1, min(2048, $this->settings->getInt('photos.preview_size', 1600))),
        );
        $stored = [];
        $uploads = [];
        try {
            $this->assertCanUpload($user, filesize($processed['original']) + filesize($processed['preview']), true);
            foreach (['original', 'preview'] as $variant) {
                $result = $this->storage->storePrivateUserFile($user->id, $processed[$variant], $processed['mime']);
                $stored[] = $result['path'];
                $uploads[$variant] = $this->uploads->create([
                    'user_id' => $user->id, 'path' => $result['path'], 'mime' => $processed['mime'],
                    'size' => $result['size'], 'original_name' => basename((string) ($file['name'] ?? 'photo')),
                    'purpose' => UploadRepository::PURPOSE_GALLERY_PHOTO,
                ]);
            }

            return [...$uploads, 'width' => $processed['width'], 'height' => $processed['height']];
        } catch (\Throwable $error) {
            foreach ($stored as $path) {
                try {
                    if (! $this->storage->deletePrivateFile($path)) {
                        throw new RuntimeException('Could not remove private file');
                    }
                    $this->uploads->deletePath($path, false);
                } catch (\Throwable $cleanupError) {
                    error_log('Photo upload cleanup failed for '.$path.': '.$cleanupError->getMessage());
                }
            }
            throw $error;
        } finally {
            foreach (['original', 'preview'] as $variant) {
                if (is_file($processed[$variant])) {
                    @unlink($processed[$variant]);
                }
            }
        }
    }

    /** Compensates a failed publication, after its database transaction rolls back. */
    public function discardUnattachedPhoto(\StreamEngine\Domain\Upload $upload): void
    {
        if ($upload->purpose !== UploadRepository::PURPOSE_GALLERY_PHOTO || $this->uploads->isPhotoReferenced($upload->id)) {
            return;
        }
        if (! $this->storage->deletePrivateFile($upload->path)) {
            throw new RuntimeException('Could not remove private photo file');
        }
        $this->uploads->deletePath($upload->path, false);
    }

    /** Authorization belongs to the calling module, including future galleries. */
    public function sendPrivateFile(Upload $upload): void
    {
        $this->storage->sendPrivateFile($upload->path, $upload->mime);
    }

    /** A pending file from the forum topic form's attachment card. */
    public function uploadForumAttachmentForUser(User $user, array $file): Upload
    {
        return $this->storeUserUpload($user, $file, UploadRepository::PURPOSE_FORUM_ATTACHMENT);
    }

    /**
     * @throws RandomException
     * @throws ValidationException
     */
    private function storeUserUpload(User $user, array $file, ?string $purpose): Upload
    {
        $mime = $this->mime->detect($file['tmp_name']);

        $this->validateFile($file['size'], $mime);

        $this->assertCanUpload($user, $file['size']);

        $tmpFile = $file['tmp_name'];

        if ($this->images->isImage($mime)) {
            [$tmpFile, $mime] = $this->images->process($tmpFile, $mime);
        }

        $audioTags = (new AudioMetadataReader())->read($tmpFile, $mime);
        $result = $purpose === UploadRepository::PURPOSE_MESSAGE_ATTACHMENT
            ? $this->storage->storePrivateUserFile($user->id, $tmpFile, $mime)
            : $this->storage->storeUserFile($user->id, $tmpFile, $mime);

        return $this->uploads->create([
            'user_id' => $user->id,
            'path' => $result['path'],
            'mime' => $mime,
            'size' => $result['size'],
            'original_name' => $file['name'],
            'audio_title' => $audioTags['title'],
            'audio_artist' => $audioTags['artist'],
            'purpose' => $purpose,
        ]);
    }

    /**
     * Upload from the administrator file browser into its currently open
     * directory. Unlike regular user uploads, the visible filename and the
     * original bytes are kept; images are deliberately not re-encoded.
     *
     * @throws ValidationException
     */
    public function uploadForAdmin(User $user, array $file, string $directory): Upload
    {
        $mime = $this->mime->detect($file['tmp_name']);
        $this->validateFile((int) $file['size'], $mime);

        $audioTags = (new AudioMetadataReader())->read($file['tmp_name'], $mime);
        $result = $this->storage->storeNamedFile(
            $directory,
            $file['tmp_name'],
            $mime,
            (string) $file['name'],
        );

        return $this->uploads->create([
            'user_id' => $user->id,
            'path' => $result['path'],
            'mime' => $mime,
            'size' => $result['size'],
            'original_name' => $file['name'],
            'audio_title' => $audioTags['title'],
            'audio_artist' => $audioTags['artist'],
        ]);
    }

    /**
     * @throws RandomException
     * @throws ValidationException
     */
    public function uploadAvatarForUser(User $user, array $file): Upload
    {
        $mime = $this->mime->detect($file['tmp_name']);

        $this->validateFile($file['size'], $mime);
        $this->assertCanUpload($user, $file['size']);

        $tmpFile = $file['tmp_name'];

        if ($this->images->isImage($mime)) {
            [$tmpFile, $mime] = $this->images->processAvatar($tmpFile, $mime);
        }

        $result = $this->storage->storeUserFile($user->id, $tmpFile, $mime);

        return $this->uploads->create([
            'user_id' => $user->id,
            'path' => $result['path'],
            'mime' => $mime,
            'size' => $result['size'],
            'original_name' => $file['name']
        ]);
    }

    /**
     * Resolves an existing upload for use as an attachment (e.g. a blog
     * post's music track), scoped to its owner: returns null if the upload
     * doesn't exist, wasn't uploaded by $user, or doesn't match
     * $mimePrefix (e.g. "audio/") - callers should treat null as "invalid
     * reference" rather than distinguishing why.
     */
    public function findOwnedUpload(int $uploadId, User $user, string $mimePrefix = ''): ?Upload
    {
        $upload = $this->uploads->findById($uploadId);

        if ($upload === null || $upload->userId !== $user->id
            || in_array($upload->purpose, [UploadRepository::PURPOSE_MESSAGE_ATTACHMENT, UploadRepository::PURPOSE_GALLERY_PHOTO], true)) {
            return null;
        }

        if ($mimePrefix !== '' && ! str_starts_with($upload->mime, $mimePrefix)) {
            return null;
        }

        return $upload;
    }

    /**
     * Reserves a pending forum attachment immediately before the topic write.
     * Refreshing its timestamp gives that write a full grace period even when
     * an old draft is finally submitted at the same time as cleanup runs.
     */
    public function reserveForumAttachment(int $uploadId, User $user): ?Upload
    {
        if ($user->isGuest()) {
            return null;
        }

        return $this->uploads->reserveOwnedForumAttachment($uploadId, $user->id);
    }

    /**
     * Removes old files uploaded through the forum attachment card but never
     * referenced by a topic. Work is bounded so one cron tick cannot spend an
     * unlimited amount of time draining a large historical backlog.
     */
    public function cleanupOrphanedForumAttachments(?int $now = null): int
    {
        $createdBefore = ($now ?? time()) - self::ORPHAN_GRACE_SECONDS;
        $deleted = 0;

        for ($batch = 0; $batch < self::ORPHAN_MAX_BATCHES; $batch++) {
            $ids = $this->uploads->findOrphanedForumAttachmentIds(
                $createdBefore,
                self::ORPHAN_BATCH_SIZE,
            );
            if ($ids === []) {
                break;
            }

            foreach ($ids as $id) {
                $upload = $this->uploads->claimOrphanedForumAttachment($id, $createdBefore);
                if ($upload === null) {
                    continue;
                }

                if (! $this->storage->deleteStoredFile($upload->path)) {
                    $this->uploads->releaseOrphanedForumAttachment($upload->id);
                    error_log('Unable to delete orphaned forum upload '.$upload->path);

                    continue;
                }

                if ($this->uploads->deleteClaimedForumAttachment($upload->id)) {
                    $deleted++;
                }
            }

            if (count($ids) < self::ORPHAN_BATCH_SIZE) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * A plain, unscoped lookup - unlike findOwnedUpload() this doesn't check
     * ownership, for read-only contexts where any viewer (not just the
     * upload's own owner) needs to resolve an id back to a real row, e.g.
     * rendering a public forum topic's own attachments to every reader
     * (Modules\Forums\ForumsController::buildAttachmentRows()). The ids
     * themselves are trusted already by the time they get here - they're
     * only ever written by an ownership-checked path like
     * resolveTopicAttachments() - so this is just resolving them back to
     * a row to render, not re-authorizing anything.
     */
    public function findById(int $uploadId): ?Upload
    {
        return $this->uploads->findById($uploadId);
    }

    /**
     * @return array{used:int, limit:int, remaining:int}
     */
    public function getUserStorageUsage(User $user): array
    {
        $used = $user->isGuest() ? 0 : $this->uploads->getUserUsage($user->id);
        $limit = $this->userLimitBytes();

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
        ];
    }

    private function assertCanUpload(User $user, int $fileSize, bool $validationError = false): void
    {
        if (!$this->access->isAdmin($user)) {

            $used = $this->uploads->getUserUsage($user->id);

            if ($used + $fileSize > $this->userLimitBytes()) {
                if ($validationError) {
                    throw new ValidationException($this->tm->trans('upload.storage_limit_exceeded'), 413, 'storage_limit_exceeded');
                }
                throw new RuntimeException($this->tm->trans('upload.storage_limit_exceeded'));
            }
        }
    }

    private function userLimitBytes(): int
    {
        $limitMb = $this->settings->getInt(self::STORAGE_LIMIT_SETTING, 500);

        if ($limitMb <= 0) {
            $limitMb = 500;
        }

        return $limitMb * 1024 * 1024;
    }

    /**
     * @throws ValidationException
     */
    private function validateFile(int $size, string $mime): void
    {
        if ($size > self::MAX_FILE_SIZE) {
            throw new ValidationException($this->tm->trans('upload.file_too_large'));
        }

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new ValidationException($this->tm->trans('upload.invalid_file_type'));
        }
    }
}
