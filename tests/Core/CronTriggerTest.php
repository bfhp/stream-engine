<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Cron\CronTrigger;

/**
 * Whether a page view forks a background cron run.
 *
 * This lived inside `StreamEngine::handleRequest()`, whose only entry point is
 * a constructor that opens a database connection - so a three-branch decision
 * that fires on every request to the site had no test at all. Extracting it
 * surfaced the old inverted production throttle. Production deployments now
 * use an external scheduler. The request-driven mode
 * remains as an explicit fallback and starts one runner per fifty requests.
 *
 * What is at stake: each trigger is `exec()` of a whole PHP process. Getting
 * the frequency wrong in one direction spawns a process per pageview; in the
 * other, an hourly sweep runs whenever a visitor next happens to roll it.
 */
final class CronTriggerTest extends TestCase
{
    /**
     * The escape hatch, and the only one that ignores everything else: a real
     * crontab is running `bin/cron.php` on a schedule, so a web request doing
     * it too would just contend for the same lock.
     */
    #[DataProvider('anyEnvProvider')]
    public function testARealCrontabTakesTheWebRequestOutOfIt(string $appEnv, int $roll): void
    {
        $this->assertFalse(CronTrigger::shouldTrigger('os', $appEnv, $roll));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function anyEnvProvider(): array
    {
        return [
            'in dev' => ['dev', 1],
            'in production, on the triggering roll' => ['prod', 1],
            'in production, on any other roll' => ['prod', 25],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonOsModeProvider(): array
    {
        return [
            'web' => ['web'],
        ];
    }

    #[DataProvider('nonOsModeProvider')]
    public function testWithoutARealCrontabTheRequestIsResponsible(string $cronMode): void
    {
        $this->assertTrue(CronTrigger::shouldTrigger($cronMode, 'dev', 1));
    }

    /**
     * Dev ignores the dice entirely. Deliberate: a developer watching the dream
     * book's queue should not have to reload fifty times to see it move.
     */
    #[DataProvider('everyRollProvider')]
    public function testDevTriggersOnEveryRequest(int $roll): void
    {
        $this->assertTrue(CronTrigger::shouldTrigger('web', 'dev', $roll));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function everyRollProvider(): array
    {
        return [
            'the lowest roll' => [1],
            'a middling roll' => [25],
            'the highest roll' => [CronTrigger::PRODUCTION_ONE_IN],
        ];
    }

    /**
     * This mode deliberately trades process-start overhead for unbounded
     * wall-clock delay. It is not the production scheduling model.
     */
    public function testProductionWebFallbackTriggersOnOneRequestOutOfFifty(): void
    {
        $triggered = 0;

        for ($roll = 1; $roll <= CronTrigger::PRODUCTION_ONE_IN; $roll++) {
            if (CronTrigger::shouldTrigger('web', 'prod', $roll)) {
                $triggered++;
            }
        }

        $this->assertSame(1, $triggered);
        $this->assertTrue(CronTrigger::shouldTrigger('web', 'prod', 1));
        $this->assertFalse(CronTrigger::shouldTrigger('web', 'prod', 2));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function productionEnvProvider(): array
    {
        return [
            'prod' => ['prod'],
            'production' => ['production'],
            'staging' => ['staging'],
            // Only the literal 'dev' is dev. An unset APP_ENV therefore lands
            // on the production path, which is the safe direction.
            'empty' => [''],
            'the wrong case' => ['DEV'],
        ];
    }

    #[DataProvider('productionEnvProvider')]
    public function testOnlyTheLiteralDevSkipsTheDice(string $appEnv): void
    {
        $this->assertFalse(CronTrigger::shouldTrigger('web', $appEnv, 2));
    }

    /* ===============================
       The die itself
    =============================== */

    public function testTheRollStaysInsideItsRange(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $roll = CronTrigger::roll();

            $this->assertGreaterThanOrEqual(1, $roll);
            $this->assertLessThanOrEqual(CronTrigger::PRODUCTION_ONE_IN, $roll);
        }
    }

    public function testTheRollCanActuallyReachTheSkippingValue(): void
    {
        // If it could not, the production branch would be unconditional and
        // the constant decorative. Not a distribution test - just that the
        // one value the decision turns on is reachable at all.
        $rolls = [];

        for ($i = 0; $i < 2000; $i++) {
            $rolls[CronTrigger::roll()] = true;
        }

        $this->assertArrayHasKey(1, $rolls);
    }

    /**
     * The fork guard, asserted from the outside: `bin/cron.php` defines
     * `CRON_PROCESS`, and a cron task that renders a page must not be able to
     * start another cron run off the back of it.
     *
     * Checked by *not* defining the constant and confirming the method is
     * reachable, rather than by letting it `exec()` - the point is that the
     * guard is the first thing in it.
     */
    public function testTheSpawnGuardIsTheConstantCronPhpDefines(): void
    {
        $this->assertFalse(
            defined('CRON_PROCESS'),
            'the suite must not define CRON_PROCESS, or this guard would be untestable'
        );

        // Asserted structurally rather than by running it: spawn() ends in
        // exec(), and a test that forks a real PHP process on every run is
        // worse than no test.
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Core/Cron/CronTrigger.php'
        );

        $body = substr($source, strpos($source, 'public function spawn()'));

        $this->assertStringContainsString("defined('CRON_PROCESS')", $body);
        $this->assertLessThan(
            strpos($body, 'exec('),
            strpos($body, "defined('CRON_PROCESS')"),
            'the guard has to come before the exec, or it guards nothing'
        );
    }
}
