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
            ->with('SELECT last_run FROM cron_tasks WHERE task = ?', ['cron:probe'])
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
            ->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql): ?array {
                return str_contains($sql, 'last_run')
                    ? ['last_run' => time() - 3600]
                    : ['active_run_id' => null];
            });

        $db
            ->expects($this->exactly(7))
            ->method('execute')
            ->with(
                $this->logicalOr(
                    $this->stringContains('cron_tasks'),
                    $this->stringContains('cron_run_history')
                )
            )
            ->willReturn(1);
        $db->method('lastInsertId')->willReturn(41);

        $runner = new CronRunner(
            $registry,
            new CronRepository($db),
            $this->makeControllerFactory($db),
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'))
        );

        $runner->run();

        $this->assertSame(['cron:probe'], CronProbeController::$ranTasks);
    }

    public function testManualRunIgnoresIntervalAndRecordsManualTrigger(): void
    {
        CronProbeController::reset();
        $registry = new CronRegistry();
        $registry->add('cron:probe', 'CronProbeController', 3600);

        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->willReturn(['active_run_id' => null]);
        $calls = [];
        $db->expects($this->exactly(7))->method('execute')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$calls): int {
                $calls[] = [$sql, $params];

                return 1;
            });
        $db->method('lastInsertId')->willReturn(41);

        $runner = new CronRunner(
            $registry,
            new CronRepository($db),
            $this->makeControllerFactory($db),
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'))
        );

        self::assertTrue($runner->runTask('cron:probe'));
        self::assertSame(['cron:probe'], CronProbeController::$ranTasks);
        self::assertSame('manual', $calls[1][1][0]);
    }

    public function testManualRunDoesNothingWhenTaskCannotTakeTheLock(): void
    {
        CronProbeController::reset();
        $registry = new CronRegistry();
        $registry->add('cron:probe', 'CronProbeController', 3600);

        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->exactly(2))->method('execute')->willReturnOnConsecutiveCalls(1, 0);

        $runner = new CronRunner(
            $registry,
            new CronRepository($db),
            $this->makeControllerFactory($db),
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'))
        );

        self::assertFalse($runner->runTask('cron:probe'));
        self::assertSame([], CronProbeController::$ranTasks);
    }

    public function testManualRunRejectsUnknownTask(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown cron task');

        $db = $this->createStub(PdoDatabase::class);
        (new CronRunner(
            new CronRegistry(),
            new CronRepository($db),
            $this->makeControllerFactory($db),
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'))
        ))->runTask('missing:task');
    }

    /**
     * The point of the whole exercise. lock() used to return true
     * unconditionally, so this branch in CronRunner::run() was dead and two
     * runners executed the same task at once - and cron is triggered from web
     * requests in the cron.mode=web fallback, so overlapping runners are
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

        $db
            ->expects($this->exactly(14))
            ->method('execute')
            ->willReturn(1);
        $db->method('lastInsertId')->willReturnOnConsecutiveCalls(41, 42);

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
     * not what it is. This pins it: a task that threw must be recorded as
     * failed and released, not marked done. Otherwise the lock would block the
     * retry until the stale window expired or last_run would falsely move.
     */
    public function testRunRecordsFailureAndReleasesTheLockWhenATaskThrows(): void
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

        $this->assertCount(7, $statements, 'expected lock, history lifecycle, failure state, and pruning');

        $release = $statements[5];
        $this->assertStringContainsString('locked_at = NULL', $release);
        $this->assertStringNotContainsString('last_run', $release);
    }
}
