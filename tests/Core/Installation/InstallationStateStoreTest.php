<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\Installation\InstallationState;
use StreamEngine\Core\Installation\InstallationStateStore;

final class InstallationStateStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/stream_engine_installation_'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testMissingStateLoadsAsNotInstalledAndSaveIsAtomic(): void
    {
        $store = new InstallationStateStore($this->dir.'/installation.json');
        self::assertNull($store->load());

        $state = InstallationState::start('1.2.3')->advanceTo('schema');
        $store->save($state);

        self::assertEquals($state, $store->load());
        self::assertSame([], glob($this->dir.'/.installation-*') ?: []);
    }

    public function testCorruptedStateIsRejected(): void
    {
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir.'/installation.json', '{broken');
        $store = new InstallationStateStore($this->dir.'/installation.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not valid JSON');
        $store->load();
    }

    public function testLockPreventsASecondInstallerAndIsReleasedAfterCallback(): void
    {
        $store = new InstallationStateStore($this->dir.'/installation.json');

        $store->withLock(function () use ($store): void {
            try {
                $store->withLock(static fn (): null => null);
                self::fail('A nested installer unexpectedly acquired the same lock.');
            } catch (RuntimeException $e) {
                self::assertSame('Another installation process is already running.', $e->getMessage());
            }
        });

        self::assertSame('released', $store->withLock(static fn (): string => 'released'));
    }
}
