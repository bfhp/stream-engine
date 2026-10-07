<?php

declare(strict_types=1);

namespace StreamEngine\Core\Cron;

/**
 * Whether a web request should kick off a background cron run, and how.
 *
 * Two halves, split because only one of them is testable. `shouldTrigger()` is
 * a pure decision over three inputs. `spawn()` runs `exec()` on a real process
 * and is the reason this could not be tested where it lived - it was a static
 * on `StreamEngine`, whose constructor opens a database connection.
 *
 * `cron.mode=os` is the production model: an external scheduler invokes
 * `bin/cron.php` every minute, and web requests never start it. `web` is a
 * development and compatibility fallback in which qualifying requests fork
 * the runner. It provides no wall-clock delivery guarantee on a quiet site.
 */
class CronTrigger
{
    public function __construct(
        private readonly ?string $phpBinary = null,
        private readonly ?\Closure $commandRunner = null,
    ) {
    }

    /**
     * How often a production request should fork the cron process: one in
     * this many.
     *
     * The number is a trade between two costs. Forking a PHP process per
     * request is not free even when the lock makes it a no-op; but on a quiet
     * site a rare trigger means an hourly task effectively runs whenever the
     * next visitor happens to roll it.
     */
    public const int PRODUCTION_ONE_IN = 50;

    /**
     * @param string $cronMode  SettingsService::cronMode(): 'os' means a real
     *                          crontab is responsible, while 'off' disables it.
     * @param string $appEnv    'dev' triggers on every request, so a developer
     *                          watching a queue does not have to reload fifty
     *                          times.
     * @param int    $roll      A throw of 1..PRODUCTION_ONE_IN, injected so the
     *                          decision is a function rather than a coin flip.
     */
    public static function shouldTrigger(string $cronMode, string $appEnv, int $roll): bool
    {
        if ($cronMode === 'os' || $cronMode === 'off') {
            return false;
        }

        if ($appEnv === 'dev') {
            return true;
        }

        return $roll === 1;
    }

    public static function roll(): int
    {
        return mt_rand(1, self::PRODUCTION_ONE_IN);
    }

    /**
     * Fork `bin/cron.php` and return immediately.
     *
     * The `CRON_PROCESS` guard is what stops a cron run from triggering
     * another one: bin/cron.php defines it, so a task that happens to render a
     * page cannot start a fork bomb.
     */
    public function spawn(): bool
    {
        return $this->spawnCommand();
    }

    /** Fork a worker for one registered task, bypassing its interval. */
    public function spawnTask(string $task): bool
    {
        return $this->spawnCommand($task);
    }

    private function spawnCommand(?string $task = null): bool
    {
        if (defined('CRON_PROCESS')) {
            return false;
        }

        $phpBinary = $this->phpBinary ?? $this->cliBinary();
        if ($phpBinary === null || ! is_file($phpBinary) || ! is_executable($phpBinary)) {
            return false;
        }

        $vendor = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2);
        $script = $vendor.'/bin/cron.php';
        if (! is_file($script)) {
            $script = realpath(__DIR__.'/../../../bin/cron.php');
        }
        if ($script === false || ! is_file($script)) {
            return false;
        }

        $argument = $task === null ? '' : ' --task='.escapeshellarg($task);
        $command = escapeshellarg($phpBinary).' '.escapeshellarg($script).$argument.' > /dev/null 2>&1 &';
        if ($this->commandRunner !== null) {
            return ($this->commandRunner)($command) === 0;
        }

        $exitCode = 1;
        exec($command, result_code: $exitCode);

        return $exitCode === 0;
    }

    /** PHP_BINARY points to php-fpm in web requests; cron needs the CLI SAPI. */
    private function cliBinary(): ?string
    {
        $suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $binary = PHP_BINDIR.DIRECTORY_SEPARATOR.'php'.$suffix;

        return is_file($binary) ? $binary : null;
    }
}
