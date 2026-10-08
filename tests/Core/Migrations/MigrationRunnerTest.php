<?php

declare(strict_types=1);

namespace Tests\Core\Migrations;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use StreamEngine\Core\Migrations\MigrationRunner;
use StreamEngine\Core\PdoDatabase;

final class MigrationRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/stream_engine_migrations_'.bin2hex(random_bytes(8));
        mkdir($this->dir.'/migrations', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
    }

    public function testMultipleDirectoriesShareOrderingHistoryAndChecksumChecks(): void
    {
        mkdir($this->dir.'/package');
        $first = $this->dir.'/package/20260101000000_core.sql';
        file_put_contents($first, 'SELECT 1;');
        file_put_contents($this->dir.'/migrations/20260102000000_site.sql', 'SELECT 2;');
        $statements = [];
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->exactly(2))->method('exec')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;
            return 0;
        });
        $runner = new MigrationRunner($this->makeDatabase($pdo), [
            $this->dir.'/migrations', $this->dir.'/package', $this->dir.'/package/../package',
        ], $this->dir.'/state.json');
        self::assertCount(2, $runner->migrate());
        self::assertSame(['SELECT 1', 'SELECT 2'], $statements);
        self::assertSame([], $runner->pending());
        self::assertStringStartsWith($this->dir.'/migrations/', $runner->create('site change'));
        file_put_contents($first, 'SELECT 3;');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was changed after execution');
        $runner->migrate();
    }

    public function testDuplicateVersionsAcrossDirectoriesAreRejected(): void
    {
        mkdir($this->dir.'/package');
        foreach (['package', 'migrations'] as $directory) {
            file_put_contents($this->dir.'/'.$directory.'/20260101000000_same.sql', 'SELECT 1;');
        }
        $runner = new MigrationRunner(null, [$this->dir.'/migrations', $this->dir.'/package'], $this->dir.'/state.json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate migration version');
        $runner->pending();
    }

    public function testMigrateAppliesPendingSqlFilesAndStoresState(): void
    {
        file_put_contents($this->dir.'/migrations/20260101000000_first.sql', "CREATE TABLE first (id INT);\nINSERT INTO first VALUES ('a;b');");
        file_put_contents($this->dir.'/migrations/20260101000001_second.sql', 'ALTER TABLE first ADD name VARCHAR(255)');

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->exactly(3))
            ->method('exec')
            ->willReturnCallback(static function (string $sql): int {
                self::assertContains($sql, [
                    'CREATE TABLE first (id INT)',
                    "INSERT INTO first VALUES ('a;b')",
                    'ALTER TABLE first ADD name VARCHAR(255)',
                ]);

                return 0;
            });

        $runner = new MigrationRunner($this->makeDatabase($pdo), $this->dir.'/migrations', $this->dir.'/storage/migrations.json');

        $applied = $runner->migrate();

        $this->assertCount(2, $applied);
        $this->assertSame('20260101000000_first', $applied[0]['version']);
        $this->assertSame([], $runner->pending());

        $state = json_decode((string) file_get_contents($this->dir.'/storage/migrations.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $state['applied']);
    }

    public function testMigrateSkipsAlreadyAppliedFiles(): void
    {
        $migration = $this->dir.'/migrations/20260101000000_first.sql';
        file_put_contents($migration, 'CREATE TABLE first (id INT)');
        mkdir($this->dir.'/storage', 0775, true);
        file_put_contents($this->dir.'/storage/migrations.json', json_encode([
            'applied' => [[
                'version' => '20260101000000_first',
                'file' => '20260101000000_first.sql',
                'checksum' => hash_file('sha256', $migration),
                'applied_at' => '2026-01-01T00:00:00+00:00',
            ]],
        ], JSON_THROW_ON_ERROR));

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');

        $runner = new MigrationRunner($this->makeDatabase($pdo), $this->dir.'/migrations', $this->dir.'/storage/migrations.json');

        $this->assertSame([], $runner->migrate());
    }

    public function testMigrateFailsWhenAppliedMigrationWasChanged(): void
    {
        $migration = $this->dir.'/migrations/20260101000000_first.sql';
        file_put_contents($migration, 'CREATE TABLE first (id INT)');
        mkdir($this->dir.'/storage', 0775, true);
        file_put_contents($this->dir.'/storage/migrations.json', json_encode([
            'applied' => [[
                'version' => '20260101000000_first',
                'file' => '20260101000000_first.sql',
                'checksum' => str_repeat('0', 64),
                'applied_at' => '2026-01-01T00:00:00+00:00',
            ]],
        ], JSON_THROW_ON_ERROR));

        $runner = new MigrationRunner($this->makeDatabase($this->createStub(PDO::class)), $this->dir.'/migrations', $this->dir.'/storage/migrations.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Applied migration "20260101000000_first.sql" was changed after execution');

        $runner->migrate();
    }

    public function testBaselineThroughMarksEarlierMigrationsAsAppliedWithoutExecutingSql(): void
    {
        file_put_contents($this->dir.'/migrations/20260101000000_first.sql', 'CREATE TABLE first (id INT)');
        file_put_contents($this->dir.'/migrations/20260101000001_second.sql', 'CREATE TABLE second (id INT)');
        file_put_contents($this->dir.'/migrations/20260101000002_third.sql', 'CREATE TABLE third (id INT)');

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');

        $runner = new MigrationRunner($this->makeDatabase($pdo), $this->dir.'/migrations', $this->dir.'/storage/migrations.json');

        $baselined = $runner->baselineThrough('20260101000001_second');

        $this->assertCount(2, $baselined);
        $this->assertSame(['20260101000002_third'], array_column($runner->pending(), 'version'));
    }

    public function testSnapshotBaselineMarksListedMigrationsAndLeavesSiteMigrationPending(): void
    {
        $first = $this->dir.'/migrations/20260101000000_first.sql';
        $second = $this->dir.'/migrations/20260101000001_second.sql';
        $third = $this->dir.'/migrations/20260101000002_third.sql';
        file_put_contents($first, 'CREATE TABLE first (id INT)');
        file_put_contents($second, 'CREATE TABLE second (id INT)');
        file_put_contents($third, 'CREATE TABLE third (id INT)');

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $runner = new MigrationRunner($this->makeDatabase($pdo), $this->dir.'/migrations', $this->dir.'/storage/migrations.json');
        $manifest = array_map(static fn (string $file): array => [
            'version' => basename($file, '.sql'),
            'file' => basename($file),
            'checksum' => (string) hash_file('sha256', $file),
        ], [$first, $third]);

        self::assertCount(2, $runner->baselineSnapshot($manifest));
        self::assertSame([], $runner->baselineSnapshot($manifest));
        self::assertSame(['20260101000001_second'], array_column($runner->pending(), 'version'));
    }

    public function testSnapshotBaselineRejectsAMigrationChecksumMismatch(): void
    {
        $first = $this->dir.'/migrations/20260101000000_first.sql';
        file_put_contents($first, 'CREATE TABLE first (id INT)');
        $runner = new MigrationRunner(null, $this->dir.'/migrations', $this->dir.'/storage/migrations.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match the available migration file');
        $runner->baselineSnapshot([[
            'version' => basename($first, '.sql'),
            'file' => basename($first),
            'checksum' => str_repeat('0', 64),
        ]]);
    }

    public function testMigrateThrowsWhenDatabaseIsMissing(): void
    {
        $runner = new MigrationRunner(null, $this->dir.'/migrations', $this->dir.'/storage/migrations.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database connection is required to run migrations.');

        $runner->migrate();
    }

    public function testPendingThrowsWhenMigrationStateJsonIsCorrupted(): void
    {
        mkdir($this->dir.'/storage', 0775, true);
        file_put_contents($this->dir.'/storage/migrations.json', '{not valid json');

        $runner = new MigrationRunner($this->makeDatabase($this->createStub(PDO::class)), $this->dir.'/migrations', $this->dir.'/storage/migrations.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not valid JSON');

        $runner->pending();
    }

    public function testPendingThrowsWhenMigrationStateTopLevelStructureIsInvalid(): void
    {
        mkdir($this->dir.'/storage', 0775, true);
        file_put_contents($this->dir.'/storage/migrations.json', json_encode(['applied' => 'not-an-array'], JSON_THROW_ON_ERROR));

        $runner = new MigrationRunner($this->makeDatabase($this->createStub(PDO::class)), $this->dir.'/migrations', $this->dir.'/storage/migrations.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has invalid structure');

        $runner->pending();
    }

    public function testPendingThrowsWhenAnAppliedRecordIsMissingRequiredFields(): void
    {
        mkdir($this->dir.'/storage', 0775, true);
        file_put_contents($this->dir.'/storage/migrations.json', json_encode([
            'applied' => [[
                'version' => '20260101000000_first',
                'file' => '20260101000000_first.sql',
                // 'checksum' and 'applied_at' are missing: a corrupted/partial record.
            ]],
        ], JSON_THROW_ON_ERROR));

        $runner = new MigrationRunner($this->makeDatabase($this->createStub(PDO::class)), $this->dir.'/migrations', $this->dir.'/storage/migrations.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has invalid record structure');

        $runner->pending();
    }

    public function testMigrateWrapsPdoExceptionAsRuntimeExceptionWhenDatabaseBecomesUnreachableMidExecution(): void
    {
        file_put_contents($this->dir.'/migrations/20260101000000_first.sql', 'CREATE TABLE first (id INT)');
        file_put_contents($this->dir.'/migrations/20260101000001_second.sql', "CREATE TABLE second (id INT);\nCREATE TABLE second_extra (id INT);");
        file_put_contents($this->dir.'/migrations/20260101000002_third.sql', 'CREATE TABLE third (id INT)');

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->exactly(2))
            ->method('exec')
            ->willReturnCallback(static function (string $sql): int {
                self::assertContains($sql, [
                    'CREATE TABLE first (id INT)',
                    'CREATE TABLE second (id INT)',
                ]);

                if ($sql === 'CREATE TABLE second (id INT)') {
                    throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
                }

                return 0;
            });

        $runner = new MigrationRunner($this->makeDatabase($pdo), $this->dir.'/migrations', $this->dir.'/storage/migrations.json');

        try {
            $runner->migrate();
            $this->fail('Expected a RuntimeException to be thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not execute migration "20260101000001_second.sql".', $e->getMessage());
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
        }

        // The first migration, applied before the failure, remains recorded; the
        // failed one and everything after it are left pending (no rollback occurs).
        $this->assertSame(
            ['20260101000001_second', '20260101000002_third'],
            array_column($runner->pending(), 'version')
        );

        $state = json_decode((string) file_get_contents($this->dir.'/storage/migrations.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $state['applied']);
        $this->assertSame('20260101000000_first', $state['applied'][0]['version']);
    }

    public function testMigrateThrowsWhenStatementExecutionFailsWithoutThrowing(): void
    {
        file_put_contents($this->dir.'/migrations/20260101000000_first.sql', 'CREATE TABLE first (id INT)');

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('exec')
            ->with('CREATE TABLE first (id INT)')
            ->willReturn(false);

        $runner = new MigrationRunner($this->makeDatabase($pdo), $this->dir.'/migrations', $this->dir.'/storage/migrations.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not execute migration "20260101000000_first.sql".');

        $runner->migrate();
    }

    private function makeDatabase(PDO $pdo): PdoDatabase
    {
        $reflection = new ReflectionClass(PdoDatabase::class);
        /** @var PdoDatabase $db */
        $db = $reflection->newInstanceWithoutConstructor();

        $pdoProperty = new ReflectionProperty(PdoDatabase::class, 'pdo');
        $pdoProperty->setValue($db, $pdo);

        $queryLogProperty = new ReflectionProperty(PdoDatabase::class, 'queryLog');
        $queryLogProperty->setValue($db, []);

        return $db;
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

            $child = $path.'/'.$item;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }
}
