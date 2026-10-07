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
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $sql, array $params) use (&$calls): int {
                $calls[] = [$sql, $params];

                return 1;
            });

        $repository = new CronRepository($db);

        $this->assertTrue($repository->lock('cron:probe'));

        $this->assertStringContainsString('INSERT INTO cron_runs', $calls[0][0]);
        // Must not clobber a lock another runner is holding.
        $this->assertStringContainsString('task = task', $calls[0][0]);

        $this->assertStringContainsString('UPDATE cron_runs', $calls[1][0]);
        $this->assertStringContainsString('locked_at IS NULL', $calls[1][0]);
        $this->assertStringContainsString('locked_at <', $calls[1][0]);
        // Trigger, task, then the staleness window.
        $this->assertSame(['scheduled', 'cron:probe'], array_slice($calls[1][1], 0, 2));
        $this->assertGreaterThan(0, $calls[1][1][2]);
        $this->assertStringContainsString('is_enabled = 1', $calls[1][0]);
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
        $db->expects($this->exactly(2))->method('execute')
            ->willReturnCallback(function (string $sql, array $params) use (&$calls): int {
                $calls[] = [$sql, $params];

                return 1;
            });

        self::assertTrue((new CronRepository($db))->lock('cron:probe', 'manual'));
        self::assertSame('manual', $calls[1][1][0]);
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
        $db->expects($this->exactly(2))->method('execute')
            ->with($this->stringContains('is_enabled = VALUES(is_enabled)'))
            ->willReturnCallback(static function (string $sql, array $params): int {
                self::assertContains($params, [
                    ['cron:probe', 0],
                    ['cron:probe', 1],
                ]);

                return 1;
            });

        $repository = new CronRepository($db);
        $repository->setEnabled('cron:probe', false);
        $repository->setEnabled('cron:probe', true);
    }

    public function testReleaseClearsTheLockWithoutRecordingARun(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('UPDATE cron_runs'),
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
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('UPDATE cron_runs'),
                    $this->stringContains('locked_at = NULL')
                ),
                [0, 'cron:probe']
            );

        $repository = new CronRepository($db);
        $repository->markDone('cron:probe');
    }

    public function testMarkFailedRecordsDiagnosticsWithoutMovingLastRun(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains("last_status = 'failed'"),
                    $this->stringContains('locked_at = NULL'),
                    $this->logicalNot($this->stringContains('last_run ='))
                ),
                [125, 'probe failure', 'cron:probe']
            );

        $repository = new CronRepository($db);
        $repository->markFailed('cron:probe', 125, 'probe failure');
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
