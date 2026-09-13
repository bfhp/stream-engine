<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\Installation\ReleaseVersion;

final class ReleaseVersionTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/stream_engine_package_'.bin2hex(random_bytes(8)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testVersionIsReadFromPackageJson(): void
    {
        file_put_contents($this->file, '{"name":"stream-engine","version":" 1.2.3-beta.1 "}');

        self::assertSame('1.2.3-beta.1', ReleaseVersion::fromPackageJson($this->file));
    }

    public function testMissingVersionIsRejected(): void
    {
        file_put_contents($this->file, '{"name":"stream-engine"}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no release version');
        ReleaseVersion::fromPackageJson($this->file);
    }
}
