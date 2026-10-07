<?php

declare(strict_types=1);

namespace StreamEngine\Core\Cron;

use Exception;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\RequestContext;
use Throwable;

readonly class CronRunner
{
    public function __construct(
        private CronRegistry   $cronRegistry,
        private CronRepository $cronRepository,
        private ControllerFactory $controllerFactory,
        private RequestContext $context
    ) {

    }

    /**
     * @throws Exception
     */
    public function run(): void
    {
        foreach ($this->cronRegistry->all() as $task) {

            $lastRun = $this->cronRepository->getLastRun($task['task']);

            if (time() - $lastRun < $task['interval']) {
                continue;
            }

            // Real as of now: lock() used to return true unconditionally, so
            // this branch never fired and nothing stopped two runners from
            // executing the same task simultaneously.
            if (!$this->cronRepository->lock($task['task'])) {
                continue;
            }

            $startedAt = hrtime(true);

            try {
                $controller = $this->controllerFactory->create($task['controller'], $this->context);
                $controller->runCron($task['task']);
            } catch (Throwable $e) {
                // One task's failure used to take the whole tick with it:
                // the exception escaped the loop, so every task registered
                // after it was skipped until the next run - and since
                // markDone() never ran, the failing one retried immediately
                // while the others kept waiting. Logged and skipped
                // instead. Deliberately no markDone() here: a task that
                // threw hasn't done its work, so it should be retried on
                // the next tick rather than treated as done for the hour.
                error_log(sprintf(
                    'Cron task "%s" failed: %s',
                    $task['task'],
                    $e->getMessage()
                ));

                // Record the failure and hand the lock back. Without clearing
                // locked_at the retry above would wait for the stale window;
                // last_run deliberately stays unchanged so the task remains
                // due on the next tick.
                $this->cronRepository->markFailed(
                    $task['task'],
                    self::elapsedMilliseconds($startedAt),
                    $e->getMessage(),
                );

                continue;
            }

            $this->cronRepository->markDone($task['task'], self::elapsedMilliseconds($startedAt));
        }
    }

    private static function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

}
