<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\User;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\AuthenticationRateLimiter;
use StreamEngine\Service\AuthService;
use StreamEngine\Service\UserService;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\ServiceResolver;
use StreamEngine\Extension\ExtensionRegistry;
use Tests\Support\Extensions\PasswordFallbackExtension;
use Tests\Support\InMemoryRateLimitStore;

require_once __DIR__.'/../Support/ExtensionFixtures.php';

final class AuthServiceTest extends TestCase
{
    private array $cookieBackup = [];
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cookieBackup = $_COOKIE;
        $this->serverBackup = $_SERVER;

        $_COOKIE = [];
        $_SERVER = [];

        PasswordFallbackExtension::$handler = null;
        PasswordFallbackExtension::$requests = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookieBackup;
        $_SERVER = $this->serverBackup;

        parent::tearDown();
    }

    private function makeService(
        ?PdoDatabase $userDb = null,
        ?PdoDatabase $sessionDb = null,
        ?AuthenticationRateLimiter $rateLimiter = null,
    ): AuthService {
        $rateLimiter ??= new AuthenticationRateLimiter(
            new InMemoryRateLimitStore(),
            new TranslationManager('en', 'en'),
        );

        return new AuthService(
            new UserRepository($userDb ?? $this->createStub(PdoDatabase::class)),
            new UserSessionRepository($sessionDb ?? $this->createStub(PdoDatabase::class)),
            $rateLimiter,
        );
    }

    public function testLoginFailsClosedWhenRateLimiterIsNotConfigured(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $userDb->expects($this->never())->method('fetchOne');

        $service = new AuthService(
            new UserRepository($userDb),
            new UserSessionRepository($this->createStub(PdoDatabase::class)),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rate limiter is not configured');

        $service->login('user@example.com', 'irrelevant');
    }

    public function testRateLimitedLoginDoesNotLookUpUserOrCreateSession(): void
    {
        $store = new InMemoryRateLimitStore();
        $limiter = new AuthenticationRateLimiter($store, new TranslationManager('en', 'en'));
        for ($attempt = 1; $attempt <= AuthenticationRateLimiter::LOGIN_ACCOUNT_LIMIT; $attempt++) {
            $limiter->consumeLogin('user@example.com', '192.0.2.'.$attempt);
        }

        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77';
        $userDb = $this->createMock(PdoDatabase::class);
        $sessionDb = $this->createMock(PdoDatabase::class);
        $userDb->expects($this->never())->method('fetchOne');
        $sessionDb->expects($this->never())->method('execute');

        $this->expectException(\StreamEngine\Core\Exceptions\ValidationException::class);
        $this->expectExceptionMessage('Too many attempts');

        $this->makeService($userDb, $sessionDb, $limiter)
            ->login('user@example.com', 'irrelevant');
    }

    public function testLoginRateLimitUsesRemoteAddressAndIgnoresForwardedFor(): void
    {
        $store = new InMemoryRateLimitStore();
        $limiter = new AuthenticationRateLimiter($store, new TranslationManager('en', 'en'));
        for ($attempt = 1; $attempt <= AuthenticationRateLimiter::LOGIN_IP_LIMIT; $attempt++) {
            $limiter->consumeLogin('user'.$attempt.'@example.com', '198.51.100.9');
        }

        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77';
        $userDb = $this->createMock(PdoDatabase::class);
        $sessionDb = $this->createMock(PdoDatabase::class);
        $userDb->expects($this->never())->method('fetchOne');
        $sessionDb->expects($this->never())->method('execute');

        $this->expectException(\StreamEngine\Core\Exceptions\ValidationException::class);
        $this->expectExceptionMessage('Too many attempts');

        $this->makeService($userDb, $sessionDb, $limiter)
            ->login('fresh@example.com', 'irrelevant');
    }

    public function testLoginReturnsFalseWhenUserDoesNotExist(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $sessionDb = $this->createMock(PdoDatabase::class);

        $userDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('WHERE u.email = ?'), ['missing@example.com'])
            ->willReturn(null);

        $sessionDb->expects($this->never())->method('execute');

        $service = $this->makeService($userDb, $sessionDb);

        $this->assertFalse($service->login('missing@example.com', 'secret'));
    }

    public function testLoginReturnsFalseForInactiveUser(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $sessionDb = $this->createMock(PdoDatabase::class);

        $userDb
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn([
                'id' => 7,
                'email' => 'user@example.com',
                'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
                'role' => 'user',
                'is_active' => 0,
            ]);

        $sessionDb->expects($this->never())->method('execute');

        $service = $this->makeService($userDb, $sessionDb);

        $this->assertFalse($service->login('user@example.com', 'secret'));
    }

    public function testSystemAccountCannotLogInEvenWithMatchingPassword(): void
    {
        $password = 'known-system-password';
        $userDb = $this->createMock(PdoDatabase::class);
        $sessionDb = $this->createMock(PdoDatabase::class);
        $userDb->expects($this->once())->method('fetchOne')->willReturn([
            'id' => User::SYSTEM_USER_ID,
            'email' => 'system@localhost.invalid',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => AccessService::ROLE_USER,
            'is_active' => 1,
        ]);
        $sessionDb->expects($this->never())->method('execute');

        self::assertFalse($this->makeService($userDb, $sessionDb)->login('system@localhost.invalid', $password));
    }

    public function testLoginReturnsFalseForInvalidPassword(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $sessionDb = $this->createMock(PdoDatabase::class);

        $userDb
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn([
                'id' => 7,
                'email' => 'user@example.com',
                'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
                'role' => 'user',
                'is_active' => 1,
            ]);

        $sessionDb->expects($this->never())->method('execute');

        $service = $this->makeService($userDb, $sessionDb);

        $this->assertFalse($service->login('user@example.com', 'wrong-password'));
    }

    public function testLoginCreatesSessionForValidCredentials(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $sessionDb = $this->createMock(PdoDatabase::class);

        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $userDb
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn([
                'id' => 7,
                'email' => 'user@example.com',
                'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
                'role' => 'user',
                'is_active' => 1,
            ]);

        $sessionDb
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO'),
                    $this->stringContains('user_sessions')
                ),
                $this->callback(static function (array $params): bool {
                    return $params[0] === 7
                        && strlen($params[1]) === 64
                        && $params[2] === 'PHPUnit'
                        && $params[3] === '127.0.0.1'
                        && is_int($params[4]);
                })
            )
            ->willReturn(1);

        $service = $this->makeService($userDb, $sessionDb);

        $this->assertTrue($service->login('user@example.com', 'secret'));
    }

    public function testLoginByUserIdCreatesSessionWithoutCredentialCheck(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $sessionDb = $this->createMock(PdoDatabase::class);

        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $userDb->expects($this->never())->method('fetchOne');

        $sessionDb
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO'),
                    $this->stringContains('user_sessions')
                ),
                $this->callback(static function (array $params): bool {
                    return $params[0] === 42
                        && strlen($params[1]) === 64
                        && $params[2] === 'PHPUnit'
                        && $params[3] === '127.0.0.1'
                        && is_int($params[4]);
                })
            )
            ->willReturn(1);

        $service = $this->makeService($userDb, $sessionDb);

        $service->loginByUserId(42);
    }

    public function testSystemAccountCannotBeLoggedInById(): void
    {
        $sessionDb = $this->createMock(PdoDatabase::class);
        $sessionDb->expects($this->never())->method('execute');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('system account cannot start');
        $this->makeService(sessionDb: $sessionDb)->loginByUserId(User::SYSTEM_USER_ID);
    }

    public function testCurrentUserReturnsGuestWhenNoAuthCookie(): void
    {
        $service = $this->makeService();

        $user = $service->currentUser();

        $this->assertTrue($user->isGuest());
        $this->assertSame(0, $user->id);
    }

    public function testCurrentUserReturnsGuestWhenSessionIsInvalid(): void
    {
        $sessionDb = $this->createMock(PdoDatabase::class);
        $_COOKIE['SE_AUTH'] = 'bad-token';

        $sessionDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('FROM user_sessions'),
                [hash('sha256', 'bad-token')]
            )
            ->willReturn(null);

        $service = $this->makeService(sessionDb: $sessionDb);
        $user = $service->currentUser();

        $this->assertTrue($user->isGuest());
    }

    public function testCurrentUserReturnsResolvedUserAndTouchesSession(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $sessionDb = $this->createMock(PdoDatabase::class);
        $_COOKIE['SE_AUTH'] = 'good-token';

        $sessionDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('FROM user_sessions'),
                [hash('sha256', 'good-token')]
            )
            ->willReturn([
                'id' => 99,
                'user_id' => 7,
            ]);

        $sessionDb
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('UPDATE user_sessions'),
                    $this->stringContains('SET last_used_at = UNIX_TIMESTAMP()'),
                    // Throttled by UserService::PRESENCE_TOUCH_INTERVAL_SECONDS -
                    // a row touched seconds ago isn't rewritten.
                    $this->stringContains('AND last_used_at < (UNIX_TIMESTAMP() - ?)'),
                ),
                [99, UserService::PRESENCE_TOUCH_INTERVAL_SECONDS]
            )
            ->willReturn(1);

        $userDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('WHERE u.id = ?'),
                [7]
            )
            ->willReturn([
                'id' => 7,
                'email' => 'user@example.com',
                'role' => 'moderator',
                'nick' => 'User',
                'bio' => '',
                'homepage' => '',
                'gender' => '',
                'avatar_url' => '',
            ]);

        $service = $this->makeService($userDb, $sessionDb);
        $user = $service->currentUser();

        $this->assertFalse($user->isGuest());
        $this->assertSame(7, $user->id);
        $this->assertSame('user@example.com', $user->email);
    }

    public function testCurrentUserReturnsGuestAndTouchesSessionWhenUserIsOrphaned(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $sessionDb = $this->createMock(PdoDatabase::class);
        $_COOKIE['SE_AUTH'] = 'orphaned-token';

        $sessionDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('FROM user_sessions'),
                [hash('sha256', 'orphaned-token')]
            )
            ->willReturn([
                'id' => 99,
                'user_id' => 404,
            ]);

        $sessionDb
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('UPDATE user_sessions'),
                    $this->stringContains('SET last_used_at = UNIX_TIMESTAMP()'),
                    // Throttled by UserService::PRESENCE_TOUCH_INTERVAL_SECONDS -
                    // a row touched seconds ago isn't rewritten.
                    $this->stringContains('AND last_used_at < (UNIX_TIMESTAMP() - ?)'),
                ),
                [99, UserService::PRESENCE_TOUCH_INTERVAL_SECONDS]
            )
            ->willReturn(1);

        $userDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('WHERE u.id = ?'),
                [404]
            )
            ->willReturn(null);

        $service = $this->makeService($userDb, $sessionDb);
        $user = $service->currentUser();

        $this->assertTrue($user->isGuest());
    }

    public function testLogoutDeletesSessionByCookieToken(): void
    {
        $sessionDb = $this->createMock(PdoDatabase::class);
        $_COOKIE['SE_AUTH'] = 'logout-token';

        $sessionDb
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('DELETE'),
                    $this->stringContains('FROM user_sessions'),
                ),
                [hash('sha256', 'logout-token')]
            )
            ->willReturn(1);

        $service = $this->makeService(sessionDb: $sessionDb);
        $service->logout();
    }

    public function testGetUserTimezoneReturnsUtcForMissingOrInvalidCookie(): void
    {
        $service = $this->makeService();

        $this->assertSame('UTC', $service->getUserTimezone()->getName());

        $_COOKIE['tz'] = 'Invalid/Timezone';
        $this->assertSame('UTC', $service->getUserTimezone()->getName());

        $_COOKIE['tz'] = '../../etc/passwd';
        $this->assertSame('UTC', $service->getUserTimezone()->getName());
    }

    public function testGetUserTimezoneReturnsValidTimezoneFromCookie(): void
    {
        $_COOKIE['tz'] = 'Europe/Berlin';

        $service = $this->makeService();

        $this->assertSame('Europe/Berlin', $service->getUserTimezone()->getName());
    }

    public function testGetUserTimezoneUsesStoredTimezoneWithoutCookie(): void
    {
        $service = $this->makeService();
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, timezone: 'Europe/Nicosia');

        $this->assertSame('Europe/Nicosia', $service->getUserTimezone($user)->getName());
    }

    public function testGetUserTimezoneUsesStoredTimezoneForInvalidCookie(): void
    {
        $_COOKIE['tz'] = 'Invalid/Timezone';

        $service = $this->makeService();
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, timezone: 'Europe/Nicosia');

        $this->assertSame('Europe/Nicosia', $service->getUserTimezone($user)->getName());
    }

    public function testGetUserTimezonePersistsChangedBrowserTimezone(): void
    {
        $_COOKIE['tz'] = 'Europe/Berlin';

        $userDb = $this->createMock(PdoDatabase::class);
        $userDb
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE users SET timezone = ? WHERE id = ?'),
                ['Europe/Berlin', 7]
            )
            ->willReturn(1);

        $service = $this->makeService(userDb: $userDb);
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, timezone: 'UTC');

        $this->assertSame('Europe/Berlin', $service->getUserTimezone($user)->getName());
    }

    public function testGetUserTimezoneDoesNotRewriteUnchangedTimezone(): void
    {
        $_COOKIE['tz'] = 'Europe/Berlin';

        $userDb = $this->createMock(PdoDatabase::class);
        $userDb->expects($this->never())->method('execute');

        $service = $this->makeService(userDb: $userDb);
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, timezone: 'Europe/Berlin');

        $this->assertSame('Europe/Berlin', $service->getUserTimezone($user)->getName());
    }

    private function makeServiceWithFallback(PdoDatabase $userDb, PdoDatabase $sessionDb): AuthService
    {
        $extensions = new ExtensionRegistry([PasswordFallbackExtension::class]);
        $extensions->bind(new ServiceResolver());

        return new AuthService(
            new UserRepository($userDb),
            new UserSessionRepository($sessionDb),
            new AuthenticationRateLimiter(new InMemoryRateLimitStore(), new TranslationManager('en', 'en')),
            $extensions,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function userDbReturning(array $overrides = []): PdoDatabase
    {
        $userDb = $this->createStub(PdoDatabase::class);
        $userDb->method('fetchOne')->willReturn($overrides + [
            'id' => 7,
            'email' => 'user@example.com',
            'password_hash' => password_hash('engine-secret', PASSWORD_DEFAULT),
            'role' => 'user',
            'is_active' => 1,
        ]);

        return $userDb;
    }

    public function testFallbackIsNotAskedWhenTheEnginePasswordMatches(): void
    {
        PasswordFallbackExtension::$handler = static fn (): bool => false;
        $sessionDb = $this->createStub(PdoDatabase::class);
        $sessionDb->method('execute')->willReturn(1);

        $service = $this->makeServiceWithFallback($this->userDbReturning(), $sessionDb);

        self::assertTrue($service->login('user@example.com', 'engine-secret'));
        self::assertSame([], PasswordFallbackExtension::$requests);
    }

    public function testAcceptedFallbackLogsTheUserIn(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        PasswordFallbackExtension::$handler = static fn (int $userId, string $email, string $password): bool => $password === 'legacy-secret';
        $sessionDb = $this->createMock(PdoDatabase::class);
        $sessionDb->expects($this->once())
            ->method('execute')
            ->with($this->stringContains('user_sessions'), $this->callback(static fn (array $p): bool => $p[0] === 7))
            ->willReturn(1);

        $service = $this->makeServiceWithFallback($this->userDbReturning(), $sessionDb);
        $log = $this->captureLog(static fn () => self::assertTrue($service->login('user@example.com', 'legacy-secret')));

        self::assertCount(1, PasswordFallbackExtension::$requests);
        $request = PasswordFallbackExtension::$requests[0];
        self::assertSame([
            'userId' => 7,
            'email' => 'user@example.com',
            'password' => 'legacy-secret',
            'clientIp' => '203.0.113.9',
        ], $request);
        self::assertStringContainsString('auth.fallback_accepted user=7', $log);
        self::assertStringNotContainsString('legacy-secret', $log);
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function refusingFallbacks(): iterable
    {
        yield 'abstains' => [static fn (): ?bool => null];
        yield 'rejects' => [static fn (): bool => false];
        yield 'throws' => [static fn (): bool => throw new \RuntimeException('legacy system is down')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusingFallbacks')]
    public function testRefusingOrFailingFallbackKeepsTheLoginRefused(\Closure $handler): void
    {
        PasswordFallbackExtension::$handler = $handler;
        $sessionDb = $this->createMock(PdoDatabase::class);
        $sessionDb->expects($this->never())->method('execute');

        $service = $this->makeServiceWithFallback($this->userDbReturning(), $sessionDb);

        $this->captureLog(static fn () => self::assertFalse($service->login('user@example.com', 'wrong')));
        self::assertCount(1, PasswordFallbackExtension::$requests);
    }

    /** @return iterable<string, array{array<string, mixed>|null}> */
    public static function usersTheFallbackMustNotSee(): iterable
    {
        yield 'unknown' => [null];
        yield 'inactive' => [['is_active' => 0]];
        yield 'system' => [['id' => User::SYSTEM_USER_ID]];
    }

    /** @param array<string, mixed>|null $row */
    #[\PHPUnit\Framework\Attributes\DataProvider('usersTheFallbackMustNotSee')]
    public function testFallbackIsNotAskedForUsersThatCannotLogIn(?array $row): void
    {
        PasswordFallbackExtension::$handler = static fn (): bool => true;
        $userDb = $row === null ? $this->createStub(PdoDatabase::class) : $this->userDbReturning($row);
        if ($row === null) {
            $userDb->method('fetchOne')->willReturn(null);
        }

        $service = $this->makeServiceWithFallback($userDb, $this->createStub(PdoDatabase::class));

        self::assertFalse($service->login('user@example.com', 'anything'));
        self::assertSame([], PasswordFallbackExtension::$requests);
    }

    /** Silences the error_log() the login path writes, and hands it back. */
    private function captureLog(callable $run): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'auth');
        $previous = ini_set('error_log', $file);

        try {
            $run();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $written = (string) file_get_contents($file);
        unlink($file);

        return $written;
    }
}
