<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\Cron\CronRepository;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Service\CronStatusService;
use StreamEngine\Service\SettingsService;

final class CronStatusServiceTest extends TestCase
{
    private function settings(string $mode): SettingsService
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturn([[
            'setting_key' => 'cron.mode',
            'setting_value' => $mode,
            'updated_at' => 1,
        ]]);

        return new SettingsService(new SettingsRepository($db));
    }

    public function testPayloadMergesRegisteredTasksWithPersistedState(): void
    {
        $now = 1_800_000_000;
        $registry = new CronRegistry();
        $registry->add('notifications:deliveries', 'Profile', 60);
        $registry->add('users:cleanup', 'Users', 3600);

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturn([[
            'task' => 'notifications:deliveries',
            'last_run' => $now - 30,
            'locked_at' => null,
            'last_started_at' => $now - 31,
            'last_finished_at' => $now - 30,
            'last_status' => 'success',
            'last_duration_ms' => 250,
            'last_error' => null,
            'consecutive_failures' => 0,
        ], [
            // Removed tasks must not leak back into the live schedule.
            'task' => 'removed:task',
            'last_run' => $now,
        ]]);
        $db->method('fetchOne')->willReturn([
            'last_started_at' => $now - 2,
            'last_finished_at' => $now - 1,
            'last_status' => 'success',
        ]);

        $payload = (new CronStatusService(
            $registry,
            new CronRepository($db),
            $this->settings('os'),
        ))->payload($now);

        self::assertSame('os', $payload['mode']);
        self::assertSame('healthy', $payload['scheduler']['status']);
        self::assertSame(2, $payload['summary']['total']);
        self::assertSame(['notifications:deliveries', 'users:cleanup'], array_column($payload['data'], 'task'));
        self::assertSame('scheduled', $payload['data'][0]['status']);
        self::assertSame($now + 30, $payload['data'][0]['nextRunAt']);
        self::assertSame('never', $payload['data'][1]['status']);
        self::assertNull($payload['data'][1]['nextRunAt']);
    }

    public function testFailureAndStaleLockAreReportedAsProblems(): void
    {
        $now = 1_800_000_000;
        $registry = new CronRegistry();
        $registry->add('failed:task', 'Probe', 60);
        $registry->add('stale:task', 'Probe', 60);

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturn([[
            'task' => 'failed:task',
            'last_run' => $now - 500,
            'last_started_at' => $now - 5,
            'last_finished_at' => $now - 4,
            'last_status' => 'failed',
            'last_error' => 'boom',
            'consecutive_failures' => 2,
        ], [
            'task' => 'stale:task',
            'last_run' => $now - 5000,
            'locked_at' => $now - CronRepository::LOCK_STALE_AFTER_SECONDS - 1,
            'last_started_at' => $now - CronRepository::LOCK_STALE_AFTER_SECONDS - 1,
        ]]);
        $db->method('fetchOne')->willReturn([
            'last_started_at' => $now - 500,
            'last_finished_at' => null,
            'last_status' => null,
        ]);

        $payload = (new CronStatusService(
            $registry,
            new CronRepository($db),
            $this->settings('web'),
        ))->payload($now);

        self::assertSame('stale', $payload['scheduler']['status']);
        self::assertSame(['failed', 'stale'], array_column($payload['data'], 'status'));
        self::assertSame(1, $payload['summary']['failed']);
        self::assertSame(1, $payload['summary']['overdue']);
    }

    public function testOffModeDisablesSchedulerAndEveryRegisteredTask(): void
    {
        $registry = new CronRegistry();
        $registry->add('probe:task', 'Probe', 60);
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturn([]);

        $payload = (new CronStatusService(
            $registry,
            new CronRepository($db),
            $this->settings('off'),
        ))->payload(1_800_000_000);

        self::assertSame('off', $payload['mode']);
        self::assertSame('disabled', $payload['scheduler']['status']);
        self::assertSame('disabled', $payload['data'][0]['status']);
        self::assertNull($payload['data'][0]['nextRunAt']);
        self::assertSame(0, $payload['summary']['overdue']);
    }
}
