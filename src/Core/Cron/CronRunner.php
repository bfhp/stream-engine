<?php

declare(strict_types=1);

namespace StreamEngine\Core\Cron;

use Exception;
use InvalidArgumentException;
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
            $this->execute($task, false, 'scheduled');
        }
    }

    /** Run one registered task immediately, regardless of its interval. */
    public function runTask(string $task): bool
    {
        $definition = $this->cronRegistry->get($task);
        if ($definition === null) {
            throw new InvalidArgumentException(sprintf('Unknown cron task "%s".', $task));
        }

        return $this->execute($definition, true, 'manual');
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
