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
        // Task plus the staleness window.
        $this->assertSame('cron:probe', $calls[1][1][0]);
        $this->assertGreaterThan(0, $calls[1][1][1]);
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
                ['cron:probe']
            );

        $repository = new CronRepository($db);
        $repository->markDone('cron:probe');
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
