<?php

declare(strict_types=1);

namespace Tests\Service;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use StreamEngine\Controllers\DashboardProbeController;
use StreamEngine\Controllers\FailingDashboardProbeController;
use StreamEngine\Controllers\UnsafeDashboardProbeController;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Admin\DashboardLayoutCookieStore;
use StreamEngine\Service\AccessService;
use StreamEngine\Modules\Admin\AdminDashboardService;

require_once __DIR__.'/../Support/ControllerFactoryFixtures.php';

final class AdminDashboardServiceTest extends TestCase
{
    private array $originalCookies;

    protected function setUp(): void
    {
        $this->originalCookies = $_COOKIE;
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->originalCookies;
    }

    public function testMalformedCookieFallsBackToDefaultLayout(): void
    {
        [$service] = $this->service([]);
        foreach (['invalid', base64_encode('{"version":1,"items":"invalid"}'), base64_encode('{"version":2,"items":[]}')] as $value) {
            $_COOKIE[(new DashboardLayoutCookieStore())->cookieName(7)] = $value;
            self::assertNotEmpty($service->payload($this->context())['layout']['items']);
        }
    }

    public function testOversizedLayoutDoesNotReplaceExistingCookie(): void
    {
        $store = new DashboardLayoutCookieStore();
        $store->save(7, 1, []);
        try {
            $store->save(7, 1, [['id' => str_repeat('x', 4000), 'size' => 'small', 'position' => 0]]);
            self::fail('Oversized layout was accepted.');
        } catch (ValidationException) {
            self::assertSame(['version' => 1, 'items' => []], $store->find(7));
        }
    }

    public function testDefaultLayoutIncludesPermittedCoreAndModuleCards(): void
    {
        [$service] = $this->service([DashboardProbeController::class]);

        $payload = $service->payload($this->context());
        $ids = array_column($payload['layout']['items'], 'id');

        self::assertContains('admin.users-summary', $ids);
        self::assertContains('admin.content-summary', $ids);
        self::assertContains('admin.shortcuts', $ids);
        self::assertContains('probe.summary', $ids);
        self::assertSame(AdminDashboardService::LAYOUT_VERSION, $payload['version']);
        self::assertArrayNotHasKey('provider', $payload['catalog'][0]);
        self::assertArrayNotHasKey('permission', $payload['catalog'][0]);
    }

    public function testStoredLayoutIgnoresMissingCardsAndRepairsAnInvalidSize(): void
    {
        [$service] = $this->service(
            [DashboardProbeController::class],
            stored: [
                ['id' => 'missing.card', 'size' => 'small', 'position' => 0],
                ['id' => 'probe.summary', 'size' => 'medium', 'position' => 10],
            ],
        );

        $payload = $service->payload($this->context());

        self::assertSame([[
            'id' => 'probe.summary',
            'size' => 'small',
            'position' => 10,
        ]], $payload['layout']['items']);
        self::assertSame('ready', $payload['cards'][0]['status']);
    }

    public function testOneFailingProviderDoesNotBreakOtherCards(): void
    {
        [$service] = $this->service([
            DashboardProbeController::class,
            FailingDashboardProbeController::class,
        ]);

        $payload = $service->payload($this->context());
        $states = array_column($payload['cards'], 'status', 'id');

        self::assertSame('error', $states['failing.summary']);
        self::assertSame('ready', $states['probe.summary']);
        self::assertSame('ready', $states['admin.shortcuts']);
    }

    public function testMalformedOrUnsafeProviderDataBecomesAnIsolatedError(): void
    {
        [$service] = $this->service([
            DashboardProbeController::class,
            UnsafeDashboardProbeController::class,
        ]);

        $payload = $service->payload($this->context());
        $states = array_column($payload['cards'], 'status', 'id');

        self::assertSame('error', $states['unsafe.shortcuts']);
        self::assertSame('ready', $states['probe.summary']);
    }

    public function testCardsAreFilteredByTheirServerSidePermission(): void
    {
        [$service] = $this->service([DashboardProbeController::class]);

        $payload = $service->payload($this->context(role: AccessService::ROLE_MODERATOR));

        self::assertSame([], $payload['catalog']);
        self::assertSame([], $payload['layout']['items']);
        self::assertSame([], $payload['cards']);
    }

    public function testOnePermittedCardCanBeRefreshedIndependently(): void
    {
        [$service] = $this->service([DashboardProbeController::class]);

        $result = $service->card('probe.summary', $this->context());

        self::assertSame('probe.summary', $result['id']);
        self::assertSame('ready', $result['status']);
    }

    public function testUnknownAndUnauthorizedIndividualCardsAreNotDisclosed(): void
    {
        [$service] = $this->service([DashboardProbeController::class]);

        foreach ([
            ['missing.card', $this->context()],
            ['probe.summary', $this->context(role: AccessService::ROLE_MODERATOR)],
        ] as [$id, $context]) {
            try {
                $service->card($id, $context);
                self::fail('Unavailable card was disclosed.');
            } catch (NotFoundException $e) {
                self::assertSame('Dashboard card not found', $e->getMessage());
            }
        }
    }

    public function testValidLayoutIsSavedForTheCurrentAdministrator(): void
    {
        [$service, $tracker] = $this->service(
            [DashboardProbeController::class],
            stored: [['id' => 'probe.summary', 'size' => 'wide', 'position' => 0]],
        );

        $payload = $service->save($this->context(42), [
            'version' => AdminDashboardService::LAYOUT_VERSION,
            'items' => [['id' => 'probe.summary', 'size' => 'wide', 'position' => 0]],
        ]);

        self::assertSame([], $tracker->writes);
        self::assertSame($payload['layout'], (new DashboardLayoutCookieStore())->find(42));
        self::assertSame('probe.summary', $payload['layout']['items'][0]['id']);
    }

    public function testUnknownCardsDuplicatePositionsAndOldVersionsAreRejected(): void
    {
        [$service] = $this->service([DashboardProbeController::class]);

        foreach ([
            ['version' => 2, 'items' => []],
            ['version' => 1, 'items' => [['id' => 'missing.card', 'size' => 'small', 'position' => 0]]],
            ['version' => 1, 'items' => [
                ['id' => 'admin.shortcuts', 'size' => 'small', 'position' => 0],
                ['id' => 'probe.summary', 'size' => 'small', 'position' => 0],
            ]],
        ] as $invalid) {
            try {
                $service->save($this->context(), $invalid);
                self::fail('Invalid layout was accepted.');
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testResetDeletesOnlyTheCurrentAdministratorsLayout(): void
    {
        [$service, $tracker] = $this->service([]);

        $store = new DashboardLayoutCookieStore();
        $store->save(42, 1, []);
        $store->save(7, 1, []);
        $payload = $service->reset($this->context(42));

        self::assertNull($store->find(42));
        self::assertNotNull($store->find(7));
        self::assertNotEmpty($payload['layout']['items']);
        self::assertSame([], $tracker->writes);
    }

    /**
     * @param list<class-string> $fixtures
     * @param list<array{id:string,size:string,position:int}>|null $stored
     * @return array{AdminDashboardService, object{writes:list<array{string,list<mixed>}>}}
     */
    private function service(array $fixtures, ?array $stored = null): array
    {
        if ($stored !== null) {
            (new DashboardLayoutCookieStore())->save(7, AdminDashboardService::LAYOUT_VERSION, $stored);
        }
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturnCallback(
            static function (string $sql, array $params = []): ?array {
                if (str_contains($sql, 'FROM users')) {
                    return ['total' => 4, 'active' => 3, 'inactive' => 1];
                }
                if (str_contains($sql, 'FROM feeds')) {
                    return ['total' => 8, 'types' => 2];
                }

                return null;
            },
        );
        $tracker = new class () {
            /** @var list<array{string,list<mixed>}> */
            public array $writes = [];
        };
        $db->method('execute')->willReturnCallback(
            static function (string $sql, array $params = []) use ($tracker): int {
                $tracker->writes[] = [$sql, $params];

                return 1;
            },
        );
        $registry = \StreamEngine\Controllers\registryWithFixtures($fixtures);

        return [
            new AdminDashboardService(new DashboardLayoutCookieStore(), $registry, $db),
            $tracker,
        ];
    }

    private function context(int $userId = 7, string $role = AccessService::ROLE_ADMIN): RequestContext
    {
        return new RequestContext(
            new User($userId, 'admin@example.com', $role),
            new DateTimeZone('UTC'),
        );
    }
}
