<?php

declare(strict_types=1);

namespace Tests\Core;

require_once __DIR__.'/../Support/ControllerFactoryFixtures.php';

use PHPUnit\Framework\TestCase;
use StreamEngine\Controllers\CronProbeController;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\Cron\CronRepository;
use StreamEngine\Core\Cron\CronRunner;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Domain\User;
use StreamEngine\Service\AccessService;

final class CronRunnerTest extends TestCase
{
    private function makeControllerFactory(PdoDatabase $db): ControllerFactory
    {
        $modules = \StreamEngine\Controllers\registryWithFixtures([CronProbeController::class]);

        return new ControllerFactory($modules, $db);
    }

    public function testRunSkipsTaskWhenIntervalHasNotElapsed(): void
    {
        CronProbeController::reset();

        $registry = new CronRegistry();
        $registry->add('cron:probe', 'CronProbeController', 3600);

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT last_run FROM cron_runs WHERE task = ?', ['cron:probe'])
            ->willReturn(['last_run' => time()]);
        $db->expects($this->never())->method('execute');

        $runner = new CronRunner(
            $registry,
            new CronRepository($db),
            $this->makeControllerFactory($db),
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'))
        );

        $runner->run();

        $this->assertSame([], CronProbeController::$ranTasks);
    }

    public function testRunExecutesDueTaskAndMarksItDone(): void
    {
        CronProbeController::reset();

        $registry = new CronRegistry();
        $registry->add('cron:probe', 'CronProbeController', 60);

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT last_run FROM cron_runs WHERE task = ?', ['cron:probe'])
            ->willReturn(['last_run' => time() - 3600]);

        // Three writes: lock() is two statements now (ensure the row, then take
        // it with a conditional UPDATE whose affected-row count decides the
        // outcome), plus markDone(). Returning 1 throughout means the lock is
        // acquired.
        $db
            ->expects($this->exactly(3))
            ->method('execute')
            ->with(
                $this->logicalOr(
                    $this->stringContains('INSERT INTO cron_runs'),
                    $this->stringContains('UPDATE cron_runs')
                )
            )
            ->willReturn(1);

        $runner = new CronRunner(
            $registry,
            new CronRepository($db),
            $this->makeControllerFactory($db),
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'))
        );

        $runner->run();

        $this->assertSame(['cron:probe'], CronProbeController::$ranTasks);
    }

    /**
     * The point of the whole exercise. lock() used to return true
     * unconditionally, so this branch in CronRunner::run() was dead and two
     * runners executed the same task at once - and cron is triggered from web
     * requests in the CRON_MODE=web fallback, so overlapping runners are
     * possible. Behind it sit row deletions (`users:cleanup`) and other
     * non-idempotent work.
     */
    public function testRunSkipsATaskAnotherRunnerHolds(): void
    {
        CronProbeController::reset();

        $registry = new CronRegistry();
        $registry->add('cron:probe', 'CronProbeController', 60);

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->method('fetchOne')
            ->willReturn(['last_run' => time() - 3600]);

        // The row-ensuring upsert, then 0 affected rows from the acquisition:
        // locked_at is set and not yet stale. No third call - nothing to mark
        // done, nothing to release, since we never held it.
        $db
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnOnConsecutiveCalls(1, 0);

        $runner = new CronRunner(
            $registry,
            new CronRepository($db),
            $this->makeControllerFactory($db),
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'))
        );

        $runner->run();

        $this->assertSame([], CronProbeController::$ranTasks);
    }

    /**
     * A throwing task used to escape the foreach, so every task registered
     * after it was silently skipped for the whole tick - and, since
     * markDone() never ran, the failing one retried immediately while the
     * others kept waiting. It became worth fixing when session cleanup
     * (`users:sessions-cleanup`) joined the registry: a DELETE can fail on
     * a lock-wait timeout without anything else being wrong.
     *
     * The full observable contract of that fix is two-sided - the rest of the
     * tick still runs (asserted below) *and* the failure is reported rather
     * than swallowed silently (CronRunner::run()'s own error_log() call, which
     * is the only trace a dropped task leaves) - so expectErrorLog() asserts
     * the logging half, same idiom Tests\Core\UrlGeneratorTest uses for
     * UrlGenerator::feeds()' own logged-and-dropped feeds.
     *
     * It also keeps that line out of the test run's output: PHPUnit captures
     * error_log() per test and prints anything it didn't expect, so without
     * this the run emits a bare 'Cron task "cron:probe-failing" failed: cron
     * probe failure: cron:probe-failing' - indistinguishable, to anything
     * watching the output, from a real cron task falling over in production.
     */
    public function testRunKeepsGoingAfterATaskThrows(): void
    {
        CronProbeController::reset();
        CronProbeController::$failingTasks = ['cron:probe-failing'];

        $this->expectErrorLog();

        $registry = new CronRegistry();
        $registry->add('cron:probe-failing', 'CronProbeController', 60);
        $registry->add('cron:probe', 'CronProbeController', 60);

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->method('fetchOne')
            ->willReturn(['last_run' => time() - 3600]);

        // Three writes each: lock() is two statements, then markDone() for the
        // task that succeeded and release() for the one that threw. release()
        // rather than markDone() is what lets the failure retry on the next tick
        // - it hands the lock back without moving last_run, where leaving
        // locked_at set would block the retry for the whole stale window.
        $db
            ->expects($this->exactly(6))
            ->method('execute')
            ->willReturn(1);

        $runner = new CronRunner(
            $registry,
            new CronRepository($db),
            $this->makeControllerFactory($db),
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'))
        );

        $runner->run();

        $this->assertSame(
            ['cron:probe-failing', 'cron:probe'],
            CronProbeController::$ranTasks
        );
    }

    /**
     * The counts in the test above prove there is a third statement per task,
     * not what it is. This pins it: a task that threw must be *released*, not
     * marked done. Without the release the lock would outlive the failure and
     * block the retry until the stale window expired; with a markDone the
     * failure would be recorded as a successful run.
     */
    public function testRunReleasesTheLockWhenATaskThrowsRatherThanMarkingItDone(): void
    {
        CronProbeController::reset();
        CronProbeController::$failingTasks = ['cron:probe-failing'];

        $this->expectErrorLog();

        $registry = new CronRegistry();
        $registry->add('cron:probe-failing', 'CronProbeController', 60);

        // A stub, not a mock: this test verifies nothing through the double
        // itself - it collects the SQL and asserts on it afterwards. PHPUnit
        // rightly complains about a mock with no expectations configured.
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['last_run' => time() - 3600]);

        $statements = [];
        $db
            ->method('execute')
            ->willReturnCallback(function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 1;
            });

        $runner = new CronRunner(
            $registry,
            new CronRepository($db),
            $this->makeControllerFactory($db),
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'))
        );

        $runner->run();

        $this->assertCount(3, $statements, 'expected the two lock statements plus a release');

        $release = $statements[2];
        $this->assertStringContainsString('locked_at = NULL', $release);
        $this->assertStringNotContainsString('last_run', $release);
    }
}
