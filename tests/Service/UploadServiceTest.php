<?php

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use Random\RandomException;
use RuntimeException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\FileProcessing\FileStorage;
use StreamEngine\Core\FileProcessing\ImageProcessor;
use StreamEngine\Core\FileProcessing\MimeDetector;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Upload;
use StreamEngine\Domain\User;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\SettingsService;
use StreamEngine\Service\UploadService;

class UploadServiceTest extends TestCase
{
    private function makeService(
        UploadRepository $uploads,
        MimeDetector $mime,
        FileStorage $storage,
        ImageProcessor $images,
        AccessService $access,
        ?int $userLimitMb = null,
    ): UploadService {
        return new UploadService(
            $uploads,
            $mime,
            $storage,
            $images,
            $access,
            new TranslationManager('ru', 'en'),
            $this->makeSettingsService($userLimitMb),
        );
    }

    private function makeSettingsService(?int $userLimitMb = null): SettingsService
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturnCallback(
            static function () use ($userLimitMb): array {
                if ($userLimitMb === null) {
                    return [];
                }

                return [[
                    'setting_key' => 'uploads.user_limit_mb',
                    'setting_value' => (string) $userLimitMb,
                    'updated_at' => 1_700_000_000,
                ]];
            }
        );

        return new SettingsService(new SettingsRepository($db));
    }

    private function trans(string $key, array $params = []): string
    {
        return (new TranslationManager('ru', 'en'))->trans($key, $params);
    }

    /**
     * @throws RandomException
     */
    public function testUploadSuccess(): void
    {
        $user = new User(id: 1, email: '', role: AccessService::ROLE_USER);
        $file = [
            'tmp_name' => '/tmp/file',
            'size' => 1000,
            'name' => 'test.jpg'
        ];

        $uploads = $this->createStub(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createStub(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access);

        $mime->method('detect')->willReturn('image/jpeg');
        $images->method('isImage')->willReturn(false);

        $access->method('isAdmin')->willReturn(false);
        $uploads->method('getUserUsage')->willReturn(0);

        $storage
            ->method('storeUserFile')
            ->willReturn([
                'path' => '1/file.jpg',
                'size' => 1000
            ]);

        $expectedUpload = $this->createStub(Upload::class);

        $uploads
            ->method('create')
            ->willReturn($expectedUpload);

        $result = $service->uploadForUser($user, $file);

        $this->assertSame($expectedUpload, $result);
    }

    public function testReportsUserStorageUsage(): void
    {
        $user = new User(id: 7, email: '', role: AccessService::ROLE_USER);
        $defaultLimit = 500 * 1024 * 1024;

        $uploads = $this->createMock(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createStub(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access);

        $uploads
            ->expects($this->once())
            ->method('getUserUsage')
            ->with(7)
            ->willReturn(128 * 1024 * 1024);

        $this->assertSame(
            [
                'used' => 128 * 1024 * 1024,
                'limit' => $defaultLimit,
                'remaining' => $defaultLimit - 128 * 1024 * 1024,
            ],
            $service->getUserStorageUsage($user)
        );
    }

    public function testReportsConfiguredUserStorageUsageLimit(): void
    {
        $user = new User(id: 7, email: '', role: AccessService::ROLE_USER);

        $uploads = $this->createMock(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createStub(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access, userLimitMb: 256);

        $uploads
            ->expects($this->once())
            ->method('getUserUsage')
            ->with(7)
            ->willReturn(128 * 1024 * 1024);

        $this->assertSame(
            [
                'used' => 128 * 1024 * 1024,
                'limit' => 256 * 1024 * 1024,
                'remaining' => 128 * 1024 * 1024,
            ],
            $service->getUserStorageUsage($user)
        );
    }

    /**
     * @throws RandomException
     */
    public function testImageProcessingChangesMime(): void
    {
        $user = new User(id: 1, email: '', role: AccessService::ROLE_USER);

        $file = [
            'tmp_name' => '/tmp/file',
            'size' => 1000,
            'name' => 'photo.jpg'
        ];

        $uploads = $this->createStub(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createMock(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access);

        $mime->method('detect')->willReturn('image/jpeg');

        $images->method('isImage')->willReturn(true);
        $images->method('process')->willReturn([
            '/tmp/processed.webp',
            'image/webp'
        ]);

        $access->method('isAdmin')->willReturn(false);
        $uploads->method('getUserUsage')->willReturn(0);

        $storage
            ->expects($this->once())
            ->method('storeUserFile')
            ->with(1, '/tmp/processed.webp', 'image/webp')
            ->willReturn([
                'path' => '1/photo.webp',
                'size' => 900
            ]);

        $uploads->method('create')->willReturn($this->createStub(Upload::class));

        $service->uploadForUser($user, $file);
    }

    /**
     * @throws RandomException
     */
    public function testRejectsLargeFile(): void
    {
        $user = new User(id: 1, email: '', role: AccessService::ROLE_USER);

        $file = [
            'tmp_name' => '/tmp/file',
            'size' => 60 * 1024 * 1024,
            'name' => 'big.jpg'
        ];

        $uploads = $this->createStub(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createStub(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access);

        $mime->method('detect')->willReturn('image/jpeg');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('upload.file_too_large'));

        $service->uploadForUser($user, $file);
    }

    /**
     * @throws RandomException
     */
    public function testRejectsInvalidMime(): void
    {
        $user = new User(id: 1, email: '', role: AccessService::ROLE_USER);

        $file = [
            'tmp_name' => '/tmp/file',
            'size' => 1000,
            'name' => 'virus.exe'
        ];

        $uploads = $this->createStub(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createStub(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access);


        $mime->method('detect')->willReturn('application/x-msdownload');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('upload.invalid_file_type'));

        $service->uploadForUser($user, $file);
    }

    /**
     * @throws RandomException
     */
    public function testRejectsWhenQuotaExceeded(): void
    {
        $user = new User(id: 1, email: '', role: AccessService::ROLE_USER);

        $file = [
            'tmp_name' => '/tmp/file',
            'size' => 1000,
            'name' => 'file.jpg'
        ];

        $uploads = $this->createStub(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createStub(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access);

        $mime->method('detect')->willReturn('image/jpeg');

        $access->method('isAdmin')->willReturn(false);

        $uploads->method('getUserUsage')->willReturn(500 * 1024 * 1024);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->trans('upload.storage_limit_exceeded'));

        $service->uploadForUser($user, $file);
    }

    /**
     * @throws RandomException
     */
    public function testRejectsWhenConfiguredQuotaIsExceeded(): void
    {
        $user = new User(id: 1, email: '', role: AccessService::ROLE_USER);

        $file = [
            'tmp_name' => '/tmp/file',
            'size' => 1000,
            'name' => 'file.jpg'
        ];

        $uploads = $this->createStub(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createStub(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access, userLimitMb: 1);

        $mime->method('detect')->willReturn('image/jpeg');

        $access->method('isAdmin')->willReturn(false);

        $uploads->method('getUserUsage')->willReturn(1024 * 1024);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->trans('upload.storage_limit_exceeded'));

        $service->uploadForUser($user, $file);
    }

    /**
     * @throws RandomException
     */
    public function testAdminBypassesQuota(): void
    {
        $user = new User(id: 1, email: '', role: AccessService::ROLE_ADMIN);

        $file = [
            'tmp_name' => '/tmp/file',
            'size' => 1000,
            'name' => 'file.jpg'
        ];

        $uploads = $this->createStub(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createStub(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access);


        $mime->method('detect')->willReturn('image/jpeg');
        $images->method('isImage')->willReturn(false);

        $access->method('isAdmin')->willReturn(true);

        $storage->method('storeUserFile')->willReturn([
            'path' => '1/file.jpg',
            'size' => 1000
        ]);

        $uploads->method('create')->willReturn($this->createStub(Upload::class));

        $service->uploadForUser($user, $file);

        $this->assertTrue(true); // дошли до конца без exception
    }

    /**
     * @throws RandomException
     */
    public function testUploadAvatarSuccess(): void
    {
        $user = new User(id: 1, email: '', role: AccessService::ROLE_USER);
        $file = [
            'tmp_name' => '/tmp/file',
            'size' => 1000,
            'name' => 'avatar.jpg'
        ];

        $uploads = $this->createStub(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createStub(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access);

        $mime->method('detect')->willReturn('image/jpeg');
        $images->method('isImage')->willReturn(false);

        $access->method('isAdmin')->willReturn(false);
        $uploads->method('getUserUsage')->willReturn(0);

        $storage
            ->method('storeUserFile')
            ->willReturn([
                'path' => '1/avatar.jpg',
                'size' => 1000
            ]);

        $expectedUpload = $this->createStub(Upload::class);

        $uploads
            ->method('create')
            ->willReturn($expectedUpload);

        $result = $service->uploadAvatarForUser($user, $file);

        $this->assertSame($expectedUpload, $result);
    }

    /**
     * @throws RandomException
     */
    public function testAvatarImageProcessingUsesProcessAvatar(): void
    {
        $user = new User(id: 1, email: '', role: AccessService::ROLE_USER);

        $file = [
            'tmp_name' => '/tmp/file',
            'size' => 1000,
            'name' => 'photo.jpg'
        ];

        $uploads = $this->createStub(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createMock(FileStorage::class);
        $images = $this->createMock(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access);

        $mime->method('detect')->willReturn('image/jpeg');

        $images->method('isImage')->willReturn(true);
        $images
            ->expects($this->once())
            ->method('processAvatar')
            ->with('/tmp/file', 'image/jpeg')
            ->willReturn([
                '/tmp/avatar-processed.webp',
                'image/webp'
            ]);
        $images->expects($this->never())->method('process');

        $access->method('isAdmin')->willReturn(false);
        $uploads->method('getUserUsage')->willReturn(0);

        $storage
            ->expects($this->once())
            ->method('storeUserFile')
            ->with(1, '/tmp/avatar-processed.webp', 'image/webp')
            ->willReturn([
                'path' => '1/photo-avatar.webp',
                'size' => 900
            ]);

        $uploads->method('create')->willReturn($this->createStub(Upload::class));

        $service->uploadAvatarForUser($user, $file);
    }

    /**
     * @throws RandomException
     */
    public function testUploadAvatarRejectsLargeFile(): void
    {
        $user = new User(id: 1, email: '', role: AccessService::ROLE_USER);

        $file = [
            'tmp_name' => '/tmp/file',
            'size' => 60 * 1024 * 1024,
            'name' => 'big-avatar.jpg'
        ];

        $uploads = $this->createStub(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createStub(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access);

        $mime->method('detect')->willReturn('image/jpeg');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('upload.file_too_large'));

        $service->uploadAvatarForUser($user, $file);
    }

    /**
     * @throws RandomException
     */
    public function testUploadAvatarRejectsInvalidMime(): void
    {
        $user = new User(id: 1, email: '', role: AccessService::ROLE_USER);

        $file = [
            'tmp_name' => '/tmp/file',
            'size' => 1000,
            'name' => 'virus.exe'
        ];

        $uploads = $this->createStub(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createStub(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access);

        $mime->method('detect')->willReturn('application/x-msdownload');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('upload.invalid_file_type'));

        $service->uploadAvatarForUser($user, $file);
    }

    /**
     * @throws RandomException
     */
    public function testUploadAvatarRejectsWhenQuotaExceeded(): void
    {
        $user = new User(id: 1, email: '', role: AccessService::ROLE_USER);

        $file = [
            'tmp_name' => '/tmp/file',
            'size' => 1000,
            'name' => 'avatar.jpg'
        ];

        $uploads = $this->createStub(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createStub(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access);

        $mime->method('detect')->willReturn('image/jpeg');

        $access->method('isAdmin')->willReturn(false);

        $uploads->method('getUserUsage')->willReturn(500 * 1024 * 1024);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->trans('upload.storage_limit_exceeded'));

        $service->uploadAvatarForUser($user, $file);
    }

    /**
     * @throws RandomException
     */
    public function testAvatarAdminBypassesQuota(): void
    {
        $user = new User(id: 1, email: '', role: AccessService::ROLE_ADMIN);

        $file = [
            'tmp_name' => '/tmp/file',
            'size' => 1000,
            'name' => 'avatar.jpg'
        ];

        $uploads = $this->createStub(UploadRepository::class);
        $mime = $this->createStub(MimeDetector::class);
        $storage = $this->createStub(FileStorage::class);
        $images = $this->createStub(ImageProcessor::class);
        $access = $this->createStub(AccessService::class);
        $service = $this->makeService($uploads, $mime, $storage, $images, $access);

        $mime->method('detect')->willReturn('image/jpeg');
        $images->method('isImage')->willReturn(false);

        $access->method('isAdmin')->willReturn(true);

        $storage->method('storeUserFile')->willReturn([
            'path' => '1/avatar.jpg',
            'size' => 1000
        ]);

        $created = new Upload(9, 1, '1/avatar.jpg', 'image/jpeg', 1000, 'avatar.jpg', 1_700_000_000);
        $uploads->method('create')->willReturn($created);

        // The quota check is skipped for admins, so this returns instead of
        // throwing - asserted on the returned row rather than on "no exception
        // was raised", which any early return would also satisfy.
        $this->assertSame($created, $service->uploadAvatarForUser($user, $file));
    }

    /* ===============================
       findOwnedUpload
    =============================== */

    /**
     * The ownership check for attachments. Everything that lets a user *use* an
     * upload id they typed - attaching a file to a message, setting an avatar -
     * goes through here, and the id is a small integer in a request body, so
     * enumeration is the obvious attack and this is the whole of the defence.
     */
    private function makeLookupService(?Upload $found): UploadService
    {
        $uploads = $this->createStub(UploadRepository::class);
        $uploads->method('findById')->willReturn($found);

        return $this->makeService(
            $uploads,
            $this->createStub(MimeDetector::class),
            $this->createStub(FileStorage::class),
            $this->createStub(ImageProcessor::class),
            $this->createStub(AccessService::class),
        );
    }

    private function upload(?int $ownerId, string $mime = 'image/jpeg'): Upload
    {
        return new Upload(7, $ownerId, '3/file.jpg', $mime, 1000, 'file.jpg', 1_700_000_000);
    }

    public function testTheOwnerGetsTheirOwnUpload(): void
    {
        $upload = $this->upload(3);
        $service = $this->makeLookupService($upload);

        $this->assertSame($upload, $service->findOwnedUpload(7, new User(3, 'a@b.c', AccessService::ROLE_USER)));
    }

    public function testSomebodyElsesUploadIsNotFound(): void
    {
        $service = $this->makeLookupService($this->upload(3));

        // Null, not the row and not an exception: callers treat it as "no such
        // attachment", which is also what a probing client should learn - the
        // response cannot distinguish "not yours" from "does not exist".
        $this->assertNull($service->findOwnedUpload(7, new User(4, 'a@b.c', AccessService::ROLE_USER)));
    }

    public function testAnUploadWithNoOwnerBelongsToNobody(): void
    {
        // user_id is nullable, and `null !== $user->id` holds for every user -
        // including, importantly, a guest whose id is 0.
        $service = $this->makeLookupService($this->upload(null));

        $this->assertNull($service->findOwnedUpload(7, new User(0, '', AccessService::ROLE_USER)));
        $this->assertNull($service->findOwnedUpload(7, new User(3, 'a@b.c', AccessService::ROLE_USER)));
    }

    public function testAnUnknownIdIsNull(): void
    {
        $this->assertNull($this->makeLookupService(null)->findOwnedUpload(7, new User(3, 'a@b.c', AccessService::ROLE_USER)));
    }

    /**
     * The mime prefix is how a caller says "this has to be an image" - the
     * avatar path uses it, so a user cannot point their avatar at a PDF they
     * legitimately own.
     */
    public function testTheMimePrefixNarrowsWhatCounts(): void
    {
        $user = new User(3, 'a@b.c', AccessService::ROLE_USER);

        $image = $this->makeLookupService($this->upload(3, 'image/png'));
        $this->assertNotNull($image->findOwnedUpload(7, $user, 'image/'));

        $pdf = $this->makeLookupService($this->upload(3, 'application/pdf'));
        $this->assertNull($pdf->findOwnedUpload(7, $user, 'image/'));
    }

    public function testAnEmptyPrefixMeansAnyType(): void
    {
        // The default - callers that don't care must not accidentally get a
        // str_starts_with('') check that matches everything by luck; it is
        // skipped outright.
        $service = $this->makeLookupService($this->upload(3, 'application/pdf'));

        $this->assertNotNull($service->findOwnedUpload(7, new User(3, 'a@b.c', AccessService::ROLE_USER)));
    }

    /**
     * Ownership is checked before the type, so a mime prefix cannot be used to
     * probe whether *someone else's* upload is an image.
     */
    public function testOwnershipIsCheckedBeforeTheType(): void
    {
        $service = $this->makeLookupService($this->upload(3, 'image/png'));

        $this->assertNull($service->findOwnedUpload(7, new User(4, 'a@b.c', AccessService::ROLE_USER), 'image/'));
    }

    /**
     * The prefix is a literal, not a pattern - `image` without the slash also
     * matches `image/...`, which is what callers pass today, but it would match
     * a hypothetical `imagemap/x` too. Recorded so the looseness is a decision
     * rather than a surprise.
     */
    public function testThePrefixIsAPlainStringPrefix(): void
    {
        $service = $this->makeLookupService($this->upload(3, 'image/png'));
        $user = new User(3, 'a@b.c', AccessService::ROLE_USER);

        $this->assertNotNull($service->findOwnedUpload(7, $user, 'image'));
        $this->assertNull($service->findOwnedUpload(7, $user, 'Image/'));
    }
}
