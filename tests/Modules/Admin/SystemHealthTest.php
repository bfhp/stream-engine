<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\Config;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Modules\Admin\AdminController;
use StreamEngine\Repository\SettingsRepository;

final class SystemHealthTest extends TestCase
{
    private array $snapshots = [];
    private array $deliveries = [];
    private bool $available = true;
    private bool $unsubscribed = false;
    private bool $failQueue = false;
    private int $releases = 0;
    private int $probes = 0;

    private function controller(array $responses, int $expectedProbes = 1): AdminController
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturnCallback(function (string $sql, array $params): ?array {
            if (str_contains($sql, 'GET_LOCK')) {
                return ['acquired' => (int) $this->available];
            }
            if (str_contains($sql, 'RELEASE_LOCK')) {
                $this->releases++;
                return null;
            }
            return isset($this->snapshots[$params[0]]) ? ['setting_value' => $this->snapshots[$params[0]]] : null;
        });
        $db->method('fetchAll')->willReturnCallback(fn (string $sql): array =>
            str_contains($sql, 'FROM users') ? [['id' => 9]]
                : ($this->unsubscribed ? [['channel' => 'email', 'delivery' => 'off']] : []));
        $db->method('execute')->willReturnCallback(function (string $sql, array $params): int {
            if (str_contains($sql, 'INSERT INTO settings')) {
                $this->snapshots[$params[0]] = $params[1];
            }
            if (str_contains($sql, 'INSERT INTO notification_deliveries')) {
                if ($this->failQueue) {
                    throw new \RuntimeException('Queue unavailable');
                }
                $this->deliveries[] = $params;
            }
            return 0;
        });
        $before = [];
        $db->method('begin')->willReturnCallback(function () use (&$before): void {
            $before = [$this->snapshots, $this->deliveries];
        });
        $db->method('rollback')->willReturnCallback(function () use (&$before): void {
            [$this->snapshots, $this->deliveries] = $before;
        });
        $config = new Config(['SITE_URL' => 'https://example.test']);
        $tm = new TranslationManager('en');
        $notifications = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($db),
            new NotificationPreferenceRepository($db),
            new UserRepository($db),
            (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
            $tm,
            $config,
        );
        $service = $this->getMockBuilder(AdminController::class)->disableOriginalConstructor()
            ->onlyMethods(['healthProbe'])->getMock();
        foreach (['db' => $db, 'config' => $config, 'tm' => $tm, 'notifications' => $notifications,
            'settingsRepository' => new SettingsRepository($db), 'userRepository' => new UserRepository($db)] as $name => $value) {
            (new \ReflectionProperty(AdminController::class, $name))->setValue($service, $value);
        }
        $index = 0;
        $service->expects(self::exactly($expectedProbes))->method('healthProbe')->willReturnCallback(function () use ($responses, &$index): array {
            $this->probes++;
            $response = $responses[min($index++, count($responses) - 1)];
            if ($response instanceof \Throwable) {
                throw $response;
            }
            return ['status' => $response, 'message' => 'Result: '.$response];
        });
        return $service;
    }

    private function runCheck(AdminController $controller, string $id = 'smtp', string $context = 'cron'): void
    {
        (new \ReflectionMethod(AdminController::class, 'runHealthCheck'))->invoke($controller, $id, $context);
    }

    private function payload(AdminController $controller): array
    {
        return (new \ReflectionMethod(AdminController::class, 'healthPayload'))->invoke($controller);
    }

    private function row(string $context = 'cron', ?string $incident = null, int $age = 0): array
    {
        return ['check_id' => 'smtp', 'context' => $context, 'status' => 'error', 'message' => 'Failed',
            'checked_at' => time() - $age, 'duration_ms' => 1, 'last_succeeded_at' => null,
            'consecutive_failures' => 1, 'incident_id' => $incident];
    }

    private function storedResult(string $context): array
    {
        return json_decode($this->snapshots['system.health'], true, flags: JSON_THROW_ON_ERROR)[$context]['smtp'];
    }

    public function testAlertsOnlyAfterConfirmationAndOnceOnRecovery(): void
    {
        $service = $this->controller(['error', 'error', 'error', 'unconfigured', 'ok', 'ok'], 6);
        $this->runCheck($service);
        self::assertSame([], $this->deliveries);
        $this->runCheck($service);
        self::assertCount(2, $this->deliveries);
        $incident = $this->storedResult('cron')['incident_id'];
        self::assertNotNull($incident);
        $this->runCheck($service);
        $this->runCheck($service);
        self::assertCount(2, $this->deliveries);
        self::assertSame($incident, $this->storedResult('cron')['incident_id']);
        $this->runCheck($service);
        $this->runCheck($service);
        self::assertCount(4, $this->deliveries);
        self::assertNull($this->storedResult('cron')['incident_id']);
        self::assertSame('health:'.$incident.':problem', $this->deliveries[0][6]);
        self::assertSame('health:'.$incident.':recovered', $this->deliveries[2][6]);
    }

    public function testManualResultDoesNotReplaceCronIncidentOrNotify(): void
    {
        $this->snapshots['system.health'] = json_encode(['cron' => ['smtp' => $this->row(incident: 'incident')]]);
        $service = $this->controller(['ok']);
        $this->runCheck($service, 'smtp', 'web');
        self::assertSame('incident', $this->storedResult('cron')['incident_id']);
        self::assertSame('ok', $this->storedResult('web')['status']);
        self::assertSame(['system.health'], array_keys($this->snapshots));
        $payload = $this->payload($service);
        self::assertSame('error', $payload['data'][4]['cron']['status']);
        self::assertSame('ok', $payload['data'][4]['web']['status']);
        self::assertSame([], $this->deliveries);
        self::assertSame(1, $this->releases);
    }

    public function testStaleFailureDoesNotConfirmAnIncidentAndReadingDoesNotProbe(): void
    {
        $this->snapshots['system.health'] = json_encode(['cron' => ['smtp' => $this->row(age: 901)]]);
        $service = $this->controller(['error']);
        self::assertTrue($this->payload($service)['data'][4]['cron']['stale']);
        self::assertSame(0, $this->probes);
        $this->runCheck($service);
        self::assertSame(1, $this->storedResult('cron')['consecutive_failures']);
        self::assertSame([], $this->deliveries);
    }

    public function testUnknownCheckDoesNotProbe(): void
    {
        $this->expectException(NotFoundException::class);
        $this->runCheck($this->controller(['ok'], 0), 'unknown', 'web');
    }

    public function testBusySnapshotRejectsManualRun(): void
    {
        $this->available = false;
        try {
            $this->runCheck($this->controller(['ok'], 0), 'smtp', 'web');
            self::fail('Expected conflict');
        } catch (ValidationException $error) {
            self::assertSame(409, $error->getHttpCode());
            self::assertSame(0, $this->probes);
        }
    }

    public function testExceptionsAreSanitizedAndOtherChecksContinue(): void
    {
        $service = $this->controller([new \RuntimeException('password=supersecret'), 'ok'], 2);
        $this->runCheck($service);
        $this->runCheck($service, 'cache');
        self::assertStringNotContainsString('supersecret', $this->storedResult('cron')['message']);
        $snapshot = json_decode($this->snapshots['system.health'], true)['cron'];
        self::assertSame('ok', $snapshot['cache']['status']);
        self::assertSame(2, $this->releases);
    }

    public function testEmailUnsubscribeStillAllowsInternalNotification(): void
    {
        $this->unsubscribed = true;
        $service = $this->controller(['error'], 2);
        $this->runCheck($service);
        $this->runCheck($service);
        self::assertCount(1, $this->deliveries);
        self::assertSame('messenger', $this->deliveries[0][2]);
    }

    public function testQueueFailureRollsBackIncidentAndCanBeRetried(): void
    {
        $service = $this->controller(['error'], 3);
        $this->runCheck($service);
        $before = $this->snapshots;
        $this->failQueue = true;
        try {
            $this->runCheck($service);
            self::fail('Expected queue failure');
        } catch (\RuntimeException) {
            self::assertSame($before, $this->snapshots);
        }
        $this->failQueue = false;
        $this->runCheck($service);
        self::assertCount(2, $this->deliveries);
    }
}
