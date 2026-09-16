<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Installation\ConfiguredInstaller;
use StreamEngine\Core\Installation\InstallationConfig;
use StreamEngine\Core\Installation\InstallationEnvironment;
use StreamEngine\Core\Installation\InstallationState;
use StreamEngine\Core\Installation\InstallationStateStore;

final class ConfiguredInstallerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/stream_engine_configured_'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory.'/.env', "UNCHANGED=yes\n");
        (new InstallationStateStore($this->directory.'/storage/installation.json'))
            ->save(InstallationState::start('0.2.0')->complete());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/storage/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory.'/storage');
        @unlink($this->directory.'/.env');
        @rmdir($this->directory);
    }

    public function testReadyInstallationDoesNotConnectOrRewriteEnvironment(): void
    {
        $state = (new ConfiguredInstaller($this->directory, []))->install(
            new InstallationConfig('Site', 'ru', 'admin@example.com', 'Admin', 'secure-password'),
            new InstallationEnvironment(
                'prod',
                'https://example.com',
                'unreachable.invalid',
                3306,
                'app',
                'user',
                'password',
            ),
        );

        self::assertSame(InstallationState::STATUS_READY, $state->status);
        self::assertSame("UNCHANGED=yes\n", file_get_contents($this->directory.'/.env'));
    }
}
