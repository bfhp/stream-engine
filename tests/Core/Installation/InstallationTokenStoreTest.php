<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\Installation\InstallationTokenStore;

final class InstallationTokenStoreTest extends TestCase
{
    private string $directory;
    private string $tokenFile;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/stream_engine_install_token_'.bin2hex(random_bytes(8));
        $this->tokenFile = $this->directory.'/installation-token';
    }

    protected function tearDown(): void
    {
        foreach ([$this->tokenFile, $this->tokenFile.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testTokenIsGeneratedOnceStoredPrivatelyAndLogged(): void
    {
        $messages = [];
        $store = new InstallationTokenStore(
            $this->tokenFile,
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        self::assertNull($store->load());
        $first = $store->create();
        $second = $store->create();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        self::assertSame($first, $second);
        self::assertSame($first, trim((string) file_get_contents($this->tokenFile)));
        self::assertSame(0600, fileperms($this->tokenFile) & 0777);
        self::assertCount(1, $messages);
        self::assertStringContainsString($first, $messages[0]);
    }

    public function testTokenIsDeletedAfterInstallation(): void
    {
        $store = new InstallationTokenStore($this->tokenFile, static function (): void {
        });
        $store->create();

        $store->delete();

        self::assertFileDoesNotExist($this->tokenFile);
    }

    public function testInvalidExistingTokenIsRejectedInsteadOfSilentlyReplaced(): void
    {
        mkdir($this->directory);
        file_put_contents($this->tokenFile, 'predictable');
        $store = new InstallationTokenStore($this->tokenFile, static function (): void {
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('token file is invalid');

        $store->load();
    }
}
