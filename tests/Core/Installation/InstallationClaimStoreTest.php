<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\Installation\InstallationClaimStore;

final class InstallationClaimStoreTest extends TestCase
{
    private string $directory;
    private string $claimFile;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/stream_engine_install_claim_'.bin2hex(random_bytes(8));
        $this->claimFile = $this->directory.'/installation-claim.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->claimFile, $this->claimFile.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testFirstBrowserAcquiresClaimAndOnlyItOwnsInstallation(): void
    {
        $store = new InstallationClaimStore($this->claimFile);

        $claim = $store->acquire();

        self::assertIsString($claim);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $claim);
        self::assertTrue($store->owns($claim));
        self::assertFalse($store->owns(str_repeat('a', 64)));
        self::assertNull($store->acquire());
        self::assertStringNotContainsString($claim, (string) file_get_contents($this->claimFile));
        self::assertSame(0600, fileperms($this->claimFile) & 0777);
    }

    public function testDeletingClaimAllowsAnotherBrowserToAcquireIt(): void
    {
        $store = new InstallationClaimStore($this->claimFile);
        $first = $store->acquire();
        $store->delete();

        $second = $store->acquire();

        self::assertIsString($first);
        self::assertIsString($second);
        self::assertNotSame($first, $second);
    }

    public function testExpiredClaimCanBeReplaced(): void
    {
        mkdir($this->directory);
        file_put_contents($this->claimFile, json_encode([
            'hash' => str_repeat('a', 64),
            'expires_at' => time() - 1,
        ], JSON_THROW_ON_ERROR));
        $store = new InstallationClaimStore($this->claimFile);

        self::assertIsString($store->acquire());
    }

    public function testInvalidClaimFileIsRejected(): void
    {
        mkdir($this->directory);
        file_put_contents($this->claimFile, '{}');
        $store = new InstallationClaimStore($this->claimFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('claim file is invalid');

        $store->acquire();
    }
}
