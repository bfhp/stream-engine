<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Installation\SchemaSnapshotBuilder;
use StreamEngine\Core\Migrations\MigrationRunner;

final class SchemaSnapshotBuilderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/stream_engine_snapshot_builder_'.bin2hex(random_bytes(8));
        mkdir($this->dir.'/migrations', 0775, true);
        file_put_contents($this->dir.'/migrations/20260101000000_initial.sql', 'CREATE TABLE example (id INT);');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
    }

    public function testBuildWritesAStableSchemaAndMigrationManifest(): void
    {
        $unsupported = $this->createStub(PDOStatement::class);
        $unsupported->method('fetch')->willReturn([
            'triggers_count' => 0,
            'routines_count' => 0,
            'events_count' => 0,
        ]);
        $objects = $this->createStub(PDOStatement::class);
        $objects->method('fetchAll')->willReturn([
            ['TABLE_NAME' => 'example', 'TABLE_TYPE' => 'BASE TABLE'],
            ['TABLE_NAME' => 'example_view', 'TABLE_TYPE' => 'VIEW'],
        ]);
        $table = $this->createStub(PDOStatement::class);
        $table->method('fetch')->willReturn([
            'Create Table' => 'CREATE TABLE `example` (`id` int NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB AUTO_INCREMENT=42',
        ]);
        $view = $this->createStub(PDOStatement::class);
        $view->method('fetch')->willReturn([
            'Create View' => 'CREATE ALGORITHM=UNDEFINED DEFINER=`local`@`localhost` SQL SECURITY DEFINER VIEW `example_view` AS select `example`.`id` AS `id` from `example`',
        ]);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->exactly(4))->method('query')->willReturnCallback(
            static function (string $sql) use ($unsupported, $objects, $table, $view): PDOStatement {
                if (str_contains($sql, 'information_schema.TRIGGERS')) {
                    return $unsupported;
                }
                if (str_contains($sql, 'information_schema.TABLES')) {
                    return $objects;
                }

                return str_starts_with($sql, 'SHOW CREATE VIEW') ? $view : $table;
            }
        );

        $runner = new MigrationRunner(null, $this->dir.'/migrations', $this->dir.'/migrations.json');
        $snapshot = (new SchemaSnapshotBuilder($pdo, $runner))->build($this->dir.'/install', '1.2.3');
        $sql = (string) file_get_contents($snapshot->schemaFile);

        self::assertStringContainsString('SET FOREIGN_KEY_CHECKS = 0;', $sql);
        self::assertStringNotContainsString('AUTO_INCREMENT=42', $sql);
        self::assertStringNotContainsString('`local`@`localhost`', $sql);
        self::assertSame('20260101000000_initial', $snapshot->migrations[0]['version']);
        self::assertSame(hash_file('sha256', $snapshot->schemaFile), $snapshot->schemaChecksum);
    }

    public function testBuildRejectsObjectsItWouldOtherwiseSilentlyOmit(): void
    {
        $unsupported = $this->createStub(PDOStatement::class);
        $unsupported->method('fetch')->willReturn([
            'triggers_count' => 1,
            'routines_count' => 0,
            'events_count' => 0,
        ]);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($unsupported);
        $runner = new MigrationRunner(null, $this->dir.'/migrations', $this->dir.'/migrations.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('do not support database triggers');
        (new SchemaSnapshotBuilder($pdo, $runner))->build($this->dir.'/install', '1.2.3');
    }

    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path.'/'.$item;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}
