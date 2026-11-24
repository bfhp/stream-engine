<?php

declare(strict_types=1);

namespace Tests\Core;

require_once __DIR__.'/../Support/ControllerFactoryFixtures.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Controllers\CronProbeController;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\Cron\CronRepository;
use StreamEngine\Core\Cron\CronRunner;
use StreamEngine\Core\Cron\CronTrigger;
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

    public function testScheduledRunSkipsTaskWhenIntervalHasNotElapsed(): void
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

        $runner->runScheduledTask(array_key_first($registry->all()));

        $this->assertSame([], CronProbeController::$ranTasks);
    }

    public function testScheduledRunExecutesDueTaskAndMarksItDone(): void
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

        $runner->runScheduledTask(array_key_first($registry->all()));

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
     * unconditionally, so this branch in CronRunner was dead and two
     * runners executed the same task at once - and cron is triggered from web
     * requests in the cron.mode=web fallback, so overlapping runners are
     * possible. Behind it sit row deletions (`users:cleanup`) and other
     * non-idempotent work.
     */
    public function testScheduledRunSkipsATaskAnotherRunnerHolds(): void
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

        $runner->runScheduledTask(array_key_first($registry->all()));

        $this->assertSame([], CronProbeController::$ranTasks);
    }

    /**
     * Pins the failure path: a task that threw must be recorded as
     * failed and released, not marked done. Otherwise the lock would block the
     * retry until the stale window expired or last_run would falsely move.
     */
    public function testScheduledRunRecordsFailureAndReleasesTheLockWhenATaskThrows(): void
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

        $runner->runScheduledTask(array_key_first($registry->all()));

        $this->assertCount(7, $statements, 'expected lock, history lifecycle, failure state, and pruning');

        $release = $statements[5];
        $this->assertStringContainsString('locked_at = NULL', $release);
        $this->assertStringNotContainsString('last_run', $release);
    }

    /* ===============================
       The scheduler tick: dispatch only
    =============================== */

    /**
     * @param list<array<string, mixed>> $states cron_tasks rows
     * @param list<string> $commands the spawned commands, collected
     * @param list<string> $failingTasks tasks whose worker fails to start
     */
    private function makeDispatcher(
        CronRegistry $registry,
        array $states,
        array &$commands,
        array $failingTasks = [],
    ): CronRunner {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturn($states);
        $db->method('execute')->willReturnCallback(static function (): int {
            throw new \LogicException('a tick must not write task state - its workers do');
        });

        $trigger = new CronTrigger(PHP_BINARY, static function (string $command) use (&$commands, $failingTasks): int {
            $commands[] = $command;
            foreach ($failingTasks as $task) {
                if (str_contains($command, "--task='$task'")) {
                    return 1;
                }
            }

            return 0;
        });

        return new CronRunner(
            $registry,
            new CronRepository($db),
            $this->makeControllerFactory($db),
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC')),
            $trigger,
        );
    }

    /**
     * The point of the change: a tick starts workers and runs nothing itself,
     * so one long task no longer holds back the rest.
     */
    public function testATickStartsAWorkerPerDueTaskAndRunsNothingItself(): void
    {
        CronProbeController::reset();
        $registry = new CronRegistry();
        $registry->add('cron:probe', 'CronProbeController', 60);
        $registry->add('cron:probe-2', 'CronProbeController', 60);
        $commands = [];

        $this->makeDispatcher($registry, [], $commands)->run();

        self::assertSame([], CronProbeController::$ranTasks);
        self::assertCount(2, $commands);
        self::assertStringContainsString("--task='cron:probe' --scheduled", $commands[0]);
        self::assertStringContainsString("--task='cron:probe-2' --scheduled", $commands[1]);
        self::assertStringEndsWith('&', $commands[0], 'the worker must be started in the background');
    }

    public function testATickSkipsTasksThatAreNotDue(): void
    {
        $registry = new CronRegistry();
        $registry->add('cron:due', 'CronProbeController', 60);
        $registry->add('cron:recent', 'CronProbeController', 3600);
        $commands = [];

        $this->makeDispatcher($registry, [
            ['task' => 'cron:due', 'is_enabled' => 1, 'last_run' => time() - 120, 'locked_at' => null, 'manual_requested_at' => null],
            ['task' => 'cron:recent', 'is_enabled' => 1, 'last_run' => time() - 120, 'locked_at' => null, 'manual_requested_at' => null],
        ], $commands)->run();

        self::assertCount(1, $commands);
        self::assertStringContainsString("--task='cron:due'", $commands[0]);
    }

    /**
     * A worker that will not start must not cost the other tasks their tick,
     * and must not vanish silently either.
     */
    public function testAWorkerThatFailsToStartDoesNotStopTheOthers(): void
    {
        $registry = new CronRegistry();
        $registry->add('cron:broken', 'CronProbeController', 60);
        $registry->add('cron:probe', 'CronProbeController', 60);
        $commands = [];

        $this->expectErrorLog();

        try {
            $this->makeDispatcher($registry, [], $commands, failingTasks: ['cron:broken'])->run();
            self::fail('a tick with a worker that did not start must report it');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('cron:broken', $e->getMessage());
        }

        self::assertCount(2, $commands);
    }

    /** @param array<string, mixed>|null $state */
    #[DataProvider('dispatchProvider')]
    public function testShouldDispatch(?array $state, bool $expected): void
    {
        self::assertSame($expected, CronRunner::shouldDispatch($state, 3600, 1_000_000));
    }

    /** @return array<string, array{0: array<string, mixed>|null, 1: bool}> */
    public static function dispatchProvider(): array
    {
        $now = 1_000_000;
        $idle = ['is_enabled' => 1, 'last_run' => $now - 3600, 'locked_at' => null, 'manual_requested_at' => null];

        return [
            'never ran' => [null, true],
            'due' => [$idle, true],
            'not due yet' => [['last_run' => $now - 3599] + $idle, false],
            'disabled' => [['is_enabled' => 0] + $idle, false],
            'running' => [['locked_at' => $now - 60] + $idle, false],
            'a stale lock is taken over' => [['locked_at' => $now - CronRepository::LOCK_STALE_AFTER_SECONDS - 1] + $idle, true],
            'a manual run is queued' => [['manual_requested_at' => $now - 10] + $idle, false],
        ];
    }
}
