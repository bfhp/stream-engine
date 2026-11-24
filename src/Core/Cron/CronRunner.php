<?php

declare(strict_types=1);

namespace StreamEngine\Core\Cron;

use Exception;
use InvalidArgumentException;
use RuntimeException;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\RequestContext;
use Throwable;

/**
 * Runs scheduled tasks, each in its own process.
 *
 * A scheduler tick (`bin/cron.php`, once a minute) only *dispatches*: it starts
 * `bin/cron.php --task=<name> --scheduled` for every task that is due and
 * exits. The task runs in that worker. Tasks used to run one after another
 * inside the tick, so one long task (a feed import) held back every other task
 * until it finished.
 *
 * The worker re-checks the interval and takes the task's database lock, so
 * a duplicate dispatch - two ticks racing a worker that has not started yet -
 * is refused there rather than run twice.
 */
readonly class CronRunner
{
    public function __construct(
        private CronRegistry   $cronRegistry,
        private CronRepository $cronRepository,
        private ControllerFactory $controllerFactory,
        private RequestContext $context,
        private CronTrigger $cronTrigger = new CronTrigger(),
    ) {

    }

    /**
     * One scheduler tick: start a worker for every task that is due.
     *
     * A worker that cannot be started does not stop the others; the tick
     * throws afterwards so the scheduler state records the failure.
     *
     * @throws RuntimeException
     */
    public function run(): void
    {
        $states = [];
        foreach ($this->cronRepository->states() as $state) {
            $states[(string) $state['task']] = $state;
        }

        $now = time();
        $failed = [];

        foreach ($this->cronRegistry->all() as $task) {
            if (! self::shouldDispatch($states[$task['task']] ?? null, $task['interval'], $now)) {
                continue;
            }

            if (! $this->cronTrigger->spawnScheduledTask($task['task'])) {
                error_log(sprintf('Cron task "%s" failed: the worker could not be started.', $task['task']));
                $failed[] = $task['task'];
            }
        }

        if ($failed !== []) {
            throw new RuntimeException(sprintf(
                'Could not start cron workers for: %s',
                implode(', ', $failed)
            ));
        }
    }

    /**
     * Whether a tick should start a worker for a task, from its `cron_tasks`
     * row (null when the task has never run).
     *
     * Mirrors the conditions CronRepository::lock() applies to a scheduled
     * run, so a tick does not fork a process every minute only for the worker
     * to find the task still running. The lock stays the real guard.
     *
     * @param array<string, mixed>|null $state
     */
    public static function shouldDispatch(?array $state, int $interval, int $now): bool
    {
        if ($state === null) {
            return true;
        }

        if ((int) ($state['is_enabled'] ?? 1) !== 1) {
            return false;
        }

        if ($now - (int) ($state['last_run'] ?? 0) < $interval) {
            return false;
        }

        $lockedAt = $state['locked_at'] ?? null;
        if ($lockedAt !== null && (int) $lockedAt >= $now - CronRepository::LOCK_STALE_AFTER_SECONDS) {
            return false;
        }

        // A queued manual run owns the next start; lock() refuses a scheduled
        // run until it is gone.
        return ($state['manual_requested_at'] ?? null) === null;
    }

    /**
     * The worker side of a scheduled run, honouring the task's interval.
     *
     * @throws Exception
     */
    public function runScheduledTask(string $task): bool
    {
        return $this->execute($this->definition($task), false, 'scheduled');
    }

    /** Run one registered task immediately, regardless of its interval. */
    public function runTask(string $task): bool
    {
        return $this->execute($this->definition($task), true, 'manual');
    }

    /** @return array{task: string, controller: string, interval: int} */
    private function definition(string $task): array
    {
        $definition = $this->cronRegistry->get($task);
        if ($definition === null) {
            throw new InvalidArgumentException(sprintf('Unknown cron task "%s".', $task));
        }

        return $definition;
    }

    /** @param array{task: string, controller: string, interval: int} $task */
    private function execute(array $task, bool $ignoreInterval, string $trigger): bool
    {
        if (! $ignoreInterval) {
            $lastRun = $this->cronRepository->getLastRun($task['task']);
            if (time() - $lastRun < $task['interval']) {
                return false;
            }
        }

        // The same atomic task lock protects scheduled and manual workers.
        // Its is_enabled predicate also closes the race with an administrator
        // disabling a task while a worker is being started.
        if (! $this->cronRepository->lock($task['task'], $trigger)) {
            return false;
        }

        $startedAt = hrtime(true);

        try {
            $controller = $this->controllerFactory->create($task['controller'], $this->context);
            $controller->runCron($task['task']);
        } catch (Throwable $e) {
            error_log(sprintf(
                'Cron task "%s" failed: %s',
                $task['task'],
                $e->getMessage()
            ));

            $this->cronRepository->markFailed(
                $task['task'],
                self::elapsedMilliseconds($startedAt),
                $e->getMessage(),
            );

            return true;
        }

        $this->cronRepository->markDone($task['task'], self::elapsedMilliseconds($startedAt));

        return true;
    }

    private static function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

}
