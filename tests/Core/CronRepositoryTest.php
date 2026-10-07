<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Cron\CronRepository;
use StreamEngine\Core\PdoDatabase;

final class CronRepositoryTest extends TestCase
{
    /**
     * lock() used to `return true` unconditionally, which made
     * CronRunner::run()'s `if (!lock()) continue;` a dead branch - two runners
     * would execute the same task at the same time. These tests replace one that
     * pinned that behaviour as the contract.
     *
     * The acquisition is decided by affected rows from a single conditional
     * UPDATE, which is what makes it atomic, so that is what these assert: same
     * SQL either way, different rowCount.
     */
    public function testLockIsTakenWhenTheConditionalUpdateMatches(): void
    {
        $db = $this->createMock(PdoDatabase::class);

        $calls = [];
        $db
            ->expects($this->exactly(4))
            ->method('execute')
            ->willReturnCallback(function (string $sql, array $params) use (&$calls): int {
                $calls[] = [$sql, $params];

                return 1;
            });

        $repository = new CronRepository($db);
        $db->method('fetchOne')->willReturn(['active_run_id' => null]);
        $db->method('lastInsertId')->willReturn(41);

        $this->assertTrue($repository->lock('cron:probe'));

        $this->assertStringContainsString('INSERT INTO cron_tasks', $calls[0][0]);
        // Must not clobber a lock another runner is holding.
        $this->assertStringContainsString('task = task', $calls[0][0]);

        $this->assertStringContainsString('UPDATE cron_tasks', $calls[1][0]);
        $this->assertStringContainsString('locked_at IS NULL', $calls[1][0]);
        $this->assertStringContainsString('locked_at <', $calls[1][0]);
        // Trigger, task, then the staleness window.
        $this->assertSame(['scheduled', 'cron:probe'], array_slice($calls[1][1], 0, 2));
        $this->assertGreaterThan(0, $calls[1][1][2]);
        $this->assertStringContainsString('is_enabled = 1', $calls[1][0]);
        $this->assertStringContainsString("manual_requested_at IS NULL", $calls[1][0]);
    }

    public function testLockIsRefusedWhenAnotherRunnerHoldsIt(): void
    {
        $db = $this->createMock(PdoDatabase::class);

        $db
            ->expects($this->exactly(2))
            ->method('execute')
            // 0 affected rows on the UPDATE: the WHERE didn't match, because
            // locked_at is set and not yet stale.
            ->willReturnOnConsecutiveCalls(1, 0);

        $repository = new CronRepository($db);

        $this->assertFalse($repository->lock('cron:probe'));
    }

    public function testManualLockRecordsItsTrigger(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $calls = [];
        $db->expects($this->exactly(3))->method('execute')
            ->willReturnCallback(function (string $sql, array $params) use (&$calls): int {
                $calls[] = [$sql, $params];

                return 1;
            });

        $db->method('fetchOne')->willReturn(['active_run_id' => 41]);
        self::assertTrue((new CronRepository($db))->lock('cron:probe', 'manual'));
        self::assertSame('manual', $calls[1][1][0]);
        self::assertStringContainsString("status = 'running'", $calls[2][0]);
    }

    public function testTaskIsEnabledUntilExplicitlyDisabled(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturnOnConsecutiveCalls(null, ['is_enabled' => 0], ['is_enabled' => 1]);
        $repository = new CronRepository($db);

        self::assertTrue($repository->isEnabled('new:task'));
        self::assertFalse($repository->isEnabled('disabled:task'));
        self::assertTrue($repository->isEnabled('enabled:task'));
    }

    public function testSetEnabledUpsertsTaskState(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $calls = [];
        $db->expects($this->exactly(3))->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use (&$calls): int {
                $calls[] = [$sql, $params];

                return 1;
            });

        $repository = new CronRepository($db);
        $repository->setEnabled('cron:probe', false);
        $repository->setEnabled('cron:probe', true);
        self::assertStringContainsString("h.status = 'queued'", $calls[0][0]);
        self::assertStringContainsString('active_run_id = IF', $calls[1][0]);
        self::assertSame(['cron:probe', 0], $calls[1][1]);
        self::assertSame(['cron:probe', 1], $calls[2][1]);
    }

    public function testManualRunIsPersistedBeforeWorkerStarts(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $calls = [];
        $db->expects($this->exactly(5))->method('execute')
            ->willReturnCallback(function (string $sql, array $params) use (&$calls): int {
                $calls[] = [$sql, $params];

                return 1;
            });

        $db->method('lastInsertId')->willReturn(73);
        self::assertSame(73, (new CronRepository($db))->queueManualRun('cron:probe', 9));
        self::assertStringContainsString('INSERT INTO cron_tasks', $calls[0][0]);
        self::assertStringContainsString('manual_requested_at = UNIX_TIMESTAMP()', $calls[1][0]);
        self::assertSame('cron:probe', $calls[1][1][0]);
        self::assertStringContainsString('INSERT INTO cron_run_history', $calls[2][0]);
        self::assertSame(['cron:probe', 'manual', 'queued', 9], $calls[2][1]);
        self::assertSame([73, 'cron:probe'], $calls[3][1]);
        self::assertStringContainsString('LIMIT 1 OFFSET 24', $calls[4][0]);
    }

    public function testManualRunIsRefusedWhenConditionalQueueUpdateDoesNotMatch(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->exactly(2))->method('execute')->willReturnOnConsecutiveCalls(1, 0);

        self::assertNull((new CronRepository($db))->queueManualRun('cron:probe'));
    }

    public function testNewManualRunExpiresAnOldQueuedHistoryRecord(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $calls = [];
        $db->expects($this->exactly(6))->method('execute')
            ->willReturnCallback(function (string $sql, array $params) use (&$calls): int {
                $calls[] = [$sql, $params];

                return 1;
            });
        $db->method('fetchOne')->willReturn(['active_run_id' => 72]);
        $db->method('lastInsertId')->willReturn(73);

        self::assertSame(73, (new CronRepository($db))->queueManualRun('cron:probe', 9));
        self::assertStringContainsString("'start_failed'", $calls[2][0]);
        self::assertSame([72], $calls[2][1]);
        self::assertStringContainsString('INSERT INTO cron_run_history', $calls[3][0]);
        self::assertSame([73, 'cron:probe'], $calls[4][1]);
        self::assertStringContainsString('LIMIT 1 OFFSET 24', $calls[5][0]);
    }

    public function testTakingLockConsumesManualRequest(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $calls = [];
        $db->expects($this->exactly(3))->method('execute')
            ->willReturnCallback(function (string $sql, array $params) use (&$calls): int {
                $calls[] = [$sql, $params];

                return 1;
            });

        $db->method('fetchOne')->willReturn(['active_run_id' => 73]);
        self::assertTrue((new CronRepository($db))->lock('cron:probe', 'manual'));
        self::assertStringContainsString('manual_requested_at = NULL', $calls[1][0]);
        self::assertSame([73], $calls[2][1]);
    }

    public function testReleaseClearsTheLockWithoutRecordingARun(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('UPDATE cron_tasks'),
                    $this->stringContains('locked_at = NULL'),
                    // The distinction from markDone(): a task that threw hasn't
                    // done its work, so last_run must not move.
                    $this->logicalNot($this->stringContains('last_run'))
                ),
                ['cron:probe']
            )
            ->willReturn(1);

        $repository = new CronRepository($db);
        $repository->release('cron:probe');
    }

    public function testMarkDoneUpdatesLastRunAndClearsLock(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $calls = [];
        $db->expects($this->exactly(3))->method('execute')
            ->willReturnCallback(function (string $sql, array $params) use (&$calls): int {
                $calls[] = [$sql, $params];

                return 1;
            });

        $repository = new CronRepository($db);
        $repository->markDone('cron:probe');
        self::assertStringContainsString('UPDATE cron_run_history', $calls[0][0]);
        self::assertStringContainsString('UPDATE cron_tasks', $calls[1][0]);
        self::assertStringContainsString('locked_at = NULL', $calls[1][0]);
        self::assertSame([0, 'cron:probe'], $calls[1][1]);
        self::assertStringContainsString('LIMIT 1 OFFSET 24', $calls[2][0]);
    }

    public function testMarkFailedRecordsDiagnosticsWithoutMovingLastRun(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $calls = [];
        $db->expects($this->exactly(3))->method('execute')
            ->willReturnCallback(function (string $sql, array $params) use (&$calls): int {
                $calls[] = [$sql, $params];

                return 1;
            });

        $repository = new CronRepository($db);
        $repository->markFailed('cron:probe', 125, 'probe failure');
        self::assertStringContainsString("last_status = 'failed'", $calls[1][0]);
        self::assertStringContainsString('locked_at = NULL', $calls[1][0]);
        self::assertStringNotContainsString('last_run =', $calls[1][0]);
        self::assertSame([125, 'probe failure', 'cron:probe'], $calls[1][1]);
    }

    public function testGetLastRunReturnsZeroWhenNoRowExists(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(null);

        $repository = new CronRepository($db);

        $this->assertSame(0, $repository->getLastRun('cron:probe'));
    }

    public function testGetLastRunReturnsZeroWhenLastRunIsNotNumeric(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['last_run' => null]);

        $repository = new CronRepository($db);

        $this->assertSame(0, $repository->getLastRun('cron:probe'));
    }

    public function testGetLastRunReturnsStoredTimestamp(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['last_run' => '1700000000']);

        $repository = new CronRepository($db);

        $this->assertSame(1700000000, $repository->getLastRun('cron:probe'));
    }
}
