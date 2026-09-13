<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\User;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\AuthService;
use StreamEngine\Service\UserService;

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
    ): AuthService {
        return new AuthService(
            new UserRepository($userDb ?? $this->createStub(PdoDatabase::class)),
            new UserSessionRepository($sessionDb ?? $this->createStub(PdoDatabase::class)),
        );
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
}
