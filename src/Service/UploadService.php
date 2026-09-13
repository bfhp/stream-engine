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
        $mime = $this->mime->detect($file['tmp_name']);

        $this->validateFile($file['size'], $mime);

        $this->assertCanUpload($user, $file['size']);

        $tmpFile = $file['tmp_name'];

        if ($this->images->isImage($mime)) {
            [$tmpFile, $mime] = $this->images->process($tmpFile, $mime);
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

        if ($upload === null || $upload->userId !== $user->id) {
            return null;
        }

        if ($mimePrefix !== '' && ! str_starts_with($upload->mime, $mimePrefix)) {
            return null;
        }

        return $upload;
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

    private function assertCanUpload(User $user, int $fileSize): void
    {
        if (!$this->access->isAdmin($user)) {

            $used = $this->uploads->getUserUsage($user->id);

            if ($used + $fileSize > $this->userLimitBytes()) {
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
