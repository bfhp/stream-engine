<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\FileProcessing\FileStorage;

final class FileStorageTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir().'/stream-engine-file-storage-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);

        parent::tearDown();
    }

    public function testStoreUserFileMovesFileToUserDirectoryAndReturnsMetadata(): void
    {
        $storage = new FileStorage($this->baseDir);
        $tmpFile = tempnam(sys_get_temp_dir(), 'upload-');
        file_put_contents($tmpFile, 'avatar-data');

        $result = $storage->storeUserFile(42, $tmpFile, 'image/png');

        $storedPath = $this->baseDir.'/'.$result['path'];

        $this->assertSame(11, $result['size']);
        $this->assertStringStartsWith('42/', $result['path']);
        $this->assertStringEndsWith('.png', $result['path']);
        $this->assertFileExists($storedPath);
        $this->assertSame('avatar-data', file_get_contents($storedPath));
        $this->assertFileDoesNotExist($tmpFile);
    }

    public function testStoreUserFileRejectsUnsupportedMime(): void
    {
        $storage = new FileStorage($this->baseDir);
        $tmpFile = tempnam(sys_get_temp_dir(), 'upload-');
        file_put_contents($tmpFile, 'payload');

        try {
            $this->expectException(ValidationException::class);
            // 'application/pdf' used to be this test's own "unsupported"
            // example, but MIME_EXT_MAP now maps it to 'pdf' (see
            // Modules\Forums\ForumsController's own "Вложения" card, the
            // first caller that uploads a PDF) - 'application/zip' is a
            // genuinely unmapped mime instead, so this still tests the
            // real rejection path rather than a now-stale one.
            $this->expectExceptionMessage('Unsupported mime: application/zip');

            $storage->storeUserFile(7, $tmpFile, 'application/zip');
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path.'/'.$item;

            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
