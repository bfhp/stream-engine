<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Installation\InstallationPreflight;
use StreamEngine\Core\Installation\PreflightCheck;
use StreamEngine\Core\Installation\ReleaseVersion;

final class InstallationPreflightTest extends TestCase
{
    public function testSystemChecksProjectAndReleaseFiles(): void
    {
        $root = dirname(__DIR__, 3);
        $checks = $this->byCode((new InstallationPreflight($root, []))->system()->checks);

        self::assertSame(
            ['php', 'extensions', 'environment', 'storage', 'release'],
            array_keys($checks),
        );
        self::assertTrue($checks['php']->passed);
        self::assertTrue($checks['environment']->passed);
        self::assertTrue($checks['storage']->passed);
        self::assertTrue($checks['release']->passed);
        self::assertStringContainsString(
            ReleaseVersion::fromPackageJson($root.'/package.json'),
            $checks['release']->message,
        );
    }

    public function testFileWhereDirectoryIsRequiredFailsBeforeInstallation(): void
    {
        $root = sys_get_temp_dir().'/stream_engine_preflight_'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/.env');
        file_put_contents($root.'/storage', 'not a directory');

        try {
            $checks = $this->byCode((new InstallationPreflight($root, []))->system()->checks);

            self::assertFalse($checks['environment']->passed);
            self::assertFalse($checks['storage']->passed);
        } finally {
            unlink($root.'/storage');
            rmdir($root.'/.env');
            rmdir($root);
        }
    }

    /** @param list<PreflightCheck> $checks @return array<string, PreflightCheck> */
    private function byCode(array $checks): array
    {
        $result = [];
        foreach ($checks as $check) {
            $result[$check->code] = $check;
        }

        return $result;
    }
}
