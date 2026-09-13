<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\Installation\SchemaSnapshot;

final class SchemaSnapshotTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/stream_engine_snapshot_'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testValidManifestResolvesAndVerifiesItsSchema(): void
    {
        $schema = $this->dir.'/schema.sql';
        file_put_contents($schema, 'CREATE TABLE example (id INT);');
        $migrationChecksum = str_repeat('a', 64);
        $this->writeManifest([
            'format' => 1,
            'release' => '1.2.3',
            'schema' => 'schema.sql',
            'schema_checksum' => hash_file('sha256', $schema),
            'migrations' => [[
                'version' => '20260101000000_initial',
                'file' => '20260101000000_initial.sql',
                'checksum' => $migrationChecksum,
            ]],
        ]);

        $snapshot = SchemaSnapshot::load($this->dir.'/manifest.json');

        self::assertSame('1.2.3', $snapshot->release);
        self::assertSame($schema, $snapshot->schemaFile);
        self::assertSame($migrationChecksum, $snapshot->migrations[0]['checksum']);
    }

    public function testChangedSchemaIsRejected(): void
    {
        $schema = $this->dir.'/schema.sql';
        file_put_contents($schema, 'SELECT 1;');
        $this->writeManifest([
            'format' => 1,
            'release' => '1.0.0',
            'schema' => 'schema.sql',
            'schema_checksum' => str_repeat('0', 64),
            'migrations' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checksum does not match');
        SchemaSnapshot::load($this->dir.'/manifest.json');
    }

    public function testManifestCannotEscapeItsDirectory(): void
    {
        $this->writeManifest([
            'format' => 1,
            'release' => '1.0.0',
            'schema' => '../schema.sql',
            'schema_checksum' => str_repeat('0', 64),
            'migrations' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid structure');
        SchemaSnapshot::load($this->dir.'/manifest.json');
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(array $manifest): void
    {
        file_put_contents(
            $this->dir.'/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }
}
