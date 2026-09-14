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
 * `CRON_MODE=os` is the production model: an external scheduler invokes
 * `bin/cron.php` every minute, and web requests never start it. `web` is a
 * development and compatibility fallback in which qualifying requests fork
 * the runner. It provides no wall-clock delivery guarantee on a quiet site.
 */
final class CronTrigger
{
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
     * @param string $cronMode  Config::cronMode() - 'os' means a real crontab
     *                          is doing this and the web request must not.
     * @param string $appEnv    'dev' triggers on every request, so a developer
     *                          watching a queue does not have to reload fifty
     *                          times.
     * @param int    $roll      A throw of 1..PRODUCTION_ONE_IN, injected so the
     *                          decision is a function rather than a coin flip.
     */
    public static function shouldTrigger(string $cronMode, string $appEnv, int $roll): bool
    {
        if ($cronMode === 'os') {
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
    public function spawn(): void
    {
        if (defined('CRON_PROCESS')) {
            return;
        }

        $vendor = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2);
        $script = $vendor.'/bin/cron.php';
        if (!is_file($script)) {
            $script = realpath(__DIR__.'/../../../bin/cron.php');
        }

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' > /dev/null 2>&1 &');
    }
}
