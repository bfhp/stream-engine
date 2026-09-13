<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\Installation\EnvironmentFile;

final class EnvironmentFileTest extends TestCase
{
    private string $directory;
    private string $file;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/stream_engine_env_'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->file = $this->directory.'/.env';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        rmdir($this->directory);
    }

    public function testNewFileIsPrivateAndDotenvCanReadQuotedValues(): void
    {
        (new EnvironmentFile($this->file))->update([
            'DB_HOST' => 'localhost',
            'DB_PASSWORD' => 'quotes " slash \\ dollar $ hash # apostrophe \'',
        ]);

        self::assertSame(0600, fileperms($this->file) & 0777);
        $values = \Dotenv\Dotenv::parse((string) file_get_contents($this->file));
        self::assertSame('localhost', $values['DB_HOST']);
        self::assertSame('quotes " slash \\ dollar $ hash # apostrophe \'', $values['DB_PASSWORD']);
    }

    public function testExistingUnrelatedLinesArePreservedAndManagedDuplicatesRemoved(): void
    {
        file_put_contents($this->file, "# custom\nSMTP_HOST=mail.example.com\nDB_HOST=old\nexport DB_HOST=duplicate\n");
        chmod($this->file, 0640);

        (new EnvironmentFile($this->file))->update([
            'DB_HOST' => 'new',
            'DB_NAME' => 'app',
        ]);

        $contents = (string) file_get_contents($this->file);
        self::assertStringContainsString("# custom\nSMTP_HOST=mail.example.com\n", $contents);
        self::assertSame(1, substr_count($contents, 'DB_HOST='));
        self::assertStringContainsString('DB_HOST="new"', $contents);
        self::assertStringContainsString('DB_NAME="app"', $contents);
        self::assertSame(0640, fileperms($this->file) & 0777);
    }

    public function testMultilineValueIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be written safely');

        (new EnvironmentFile($this->file))->update(['DB_PASSWORD' => "first\nsecond"]);
    }
}
