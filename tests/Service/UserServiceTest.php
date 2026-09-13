<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\Config;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\User;
use StreamEngine\Repository\ConversationRepository;
use StreamEngine\Repository\MessageRepository;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\ParticipantRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\UserService;
use Tests\Support\FakePdoDatabase;
use Tests\Support\FakeSessionDatabase;

final class UserServiceTest extends TestCase
{
    private array $serverBackup = [];

    private array $cookieBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $_SERVER = [];
        // recordGuestPresence() reads and writes $_COOKIE (see its own note
        // on why it talks to the request directly, like AuthService does).
        $this->cookieBackup = $_COOKIE;
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_COOKIE = $this->cookieBackup;

        parent::tearDown();
    }

    private function makeMailServiceStub(): MailService
    {
        return (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor();
    }

    /**
     * MessageService is `final`, so it can't be createStub()'d - and its
     * notify() now rethrows any failure as a RuntimeException instead of
     * swallowing it (see its own source), so the old "reflection without
     * constructor" no-op trick no longer works as a safe default: calling
     * notify() on an instance whose collaborators were never initialized
     * would itself throw and fail register() outright. Built as a real,
     * working MessageService around Tests\Support\FakePdoDatabase instead
     * - the same in-memory messenger harness FriendServiceTest/
     * MessageServiceTest already use - so a plain register() call
     * actually succeeds end-to-end for tests here that don't care about
     * the notification's content.
     */
    private function makeMessageServiceStub(): MessageService
    {
        $db = new FakePdoDatabase();

        return new MessageService(
            $db,
            new MessageRepository($db),
            new ParticipantRepository($db),
            new ConversationRepository($db),
            new UserRepository($db),
            new UserSessionRepository($db),
            new UploadRepository($db)
        );
    }

    private function makeService(
        ?PdoDatabase $userDb = null,
        ?PdoDatabase $db = null,
        ?MailService $mailService = null,
        ?MessageService $messageService = null,
        ?PdoDatabase $sessionDb = null,
    ): UserService {
        return new UserService(
            new UserRepository($userDb ?? $this->createStub(PdoDatabase::class)),
            $db ?? $this->createStub(PdoDatabase::class),
            $mailService ?? $this->makeMailServiceStub(),
            new TranslationManager('ru', 'en'),
            new NotificationService(
                $messageService ?? $this->makeMessageServiceStub(),
                new NotificationDeliveryRepository(new FakePdoDatabase()),
                new NotificationPreferenceRepository(new FakePdoDatabase()),
                new UserRepository(new FakePdoDatabase()),
                $mailService ?? $this->makeMailServiceStub(),
            ),
            new UserSessionRepository($sessionDb ?? $this->createStub(PdoDatabase::class)),
            // A real secret, so the visit-cookie signature the presence
            // tests round-trip is actually exercised.
            new Config(['APP_SECRET' => 'test-secret']),
        );
    }

    /**
     * The presence half of this service (see "Presence" at the bottom of
     * UserService) wants both the session table and the users table to
     * answer from the same place, so it gets its own builder over
     * Tests\Support\FakeSessionDatabase - a real UserRepository and a real
     * UserSessionRepository over one in-memory database, rather than the
     * unconfigured stubs makeService() defaults to.
     */
    private function makeServiceWithPresenceDb(FakeSessionDatabase $db): UserService
    {
        return $this->makeService(userDb: $db, sessionDb: $db);
    }

    private function trans(string $key, array $params = []): string
    {
        return (new TranslationManager('ru', 'en'))->trans($key, $params);
    }

    /**
     * resolveAvatarUrl() is a pure function of its argument - the single
     * place a user's avatar fallback is decided (mirrors CommunityService::
     * resolveImageUrl() for a community's own image). Every controller/
     * template that shows an avatar is expected to call this (or read an
     * already-resolved value a controller built via it) rather than
     * hardcode a fallback path of its own.
     */
    public function testResolveAvatarUrlReturnsOwnAvatarWhenSet(): void
    {
        $service = $this->makeService();

        $this->assertSame('/uploads/avatar-1.webp', $service->resolveAvatarUrl('/uploads/avatar-1.webp'));
    }

    public function testResolveAvatarUrlFallsBackToDefaultWhenEmpty(): void
    {
        $service = $this->makeService();

        $this->assertSame(UserService::DEFAULT_AVATAR_URL, $service->resolveAvatarUrl(''));
        $this->assertSame('/assets/img/default-avatar.svg', $service->resolveAvatarUrl(''));
    }

    public function testNormalizePublicUsersListFiltersFallsBackToDefaults(): void
    {
        $service = $this->makeService();

        $this->assertSame(
            ['q' => 'Але', 'sort' => 'registered', 'direction' => 'desc'],
            $service->normalizePublicUsersListFilters([
                'q' => ' Але ',
                'sort' => 'unknown',
                'direction' => 'sideways',
            ])
        );
    }

    public function testArrayFiltersNormalizeToTheSameDefaultsAsMissingFilters(): void
    {
        $this->assertSame(
            ['q' => '', 'sort' => 'registered', 'direction' => 'desc'],
            $this->makeService()->normalizePublicUsersListFilters(['q' => ['x'], 'sort' => ['name'], 'direction' => ['asc']])
        );
    }

    public function testGetPublicUsersListPayloadBuildsPaginatedResult(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $service = $this->makeService($userDb);

        $userDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('COUNT(*) AS total'), ['Але%'])
            ->willReturn(['total' => 23]);
        $userDb
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->logicalAnd(
                $this->stringContains('FROM users u'),
                $this->stringContains('ORDER BY u.nick ASC, u.id ASC'),
                $this->stringContains('LIMIT 20 OFFSET 20')
            ), ['Але%'])
            ->willReturn([
                [
                    'id' => 7,
                    'nick' => 'Алексей',
                    'username' => 'alexey7',
                    'avatar_url' => '',
                    'created_at' => 1778167990,
                ],
            ]);

        $payload = $service->getPublicUsersListPayload([
            'q' => 'Але',
            'sort' => 'name',
            'direction' => 'asc',
            'page' => '2',
        ]);

        $this->assertSame(
            [
                [
                    'id' => 7,
                    'displayName' => 'Алексей',
                    'username' => 'alexey7',
                    'avatarUrl' => '',
                    'createdAt' => 1778167990,
                ],
            ],
            $payload['data']
        );
        $this->assertSame(['q' => 'Але', 'sort' => 'name', 'direction' => 'asc'], $payload['filters']);
        $this->assertSame(['currentPage' => 2, 'totalPages' => 2, 'total' => 23, 'limit' => 20], $payload['pagination']);
    }

    public function testFindPublicUserByUsernameReturnsUser(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $service = $this->makeService($userDb);

        $userDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('WHERE u.username = ? AND u.is_active = 1'),
                ['nicky42']
            )
            ->willReturn([
                'id' => 7,
                'email' => 'user@example.com',
                'role' => 'user',
                'nick' => 'Nicky',
                'username' => 'nicky42',
                'bio' => '',
                'signature' => '',
                'homepage' => '',
                'gender' => '',
                'birth_date' => null,
                'avatar_url' => '',
            ]);

        $user = $service->findPublicUserByUsername(' nicky42 ');

        $this->assertNotNull($user);
        $this->assertSame(7, $user->id);
    }

    public function testFindPublicUserByUsernameReturnsNullForEmptyInput(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $service = $this->makeService($userDb);

        $userDb->expects($this->never())->method('fetchOne');

        $this->assertNull($service->findPublicUserByUsername('   '));
    }

    public function testRegisterRejectsInvalidEmail(): void
    {
        $service = $this->makeService();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('user.email_invalid'));

        $service->register('not-an-email', 'very-secure-password');
    }

    public function testRegisterRejectsShortPassword(): void
    {
        $service = $this->makeService();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('user.password_too_short', ['min' => 10]));

        $service->register('user@example.com', 'short');
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $service = $this->makeService($userDb);

        $userDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT 1 FROM users WHERE email = ? LIMIT 1', ['user@example.com'])
            ->willReturn(['1' => 1]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('user.email_already_used'));

        $service->register('user@example.com', 'very-secure-password');
    }

    public function testRegisterHashesPasswordPersistsUserAndSendsActivationEmail(): void
    {
        $_SERVER['SERVER_NAME'] = 'example.com';

        $userDb = $this->createMock(PdoDatabase::class);
        $db = $this->createMock(PdoDatabase::class);
        $mailService = $this->createMock(MailService::class);
        $service = $this->makeService($userDb, $db, $mailService);

        $plainPassword = 'very-secure-password';

        $userDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT 1 FROM users WHERE email = ? LIMIT 1', ['user@example.com'])
            ->willReturn(null);

        $userDb
            ->expects($this->exactly(2))
            ->method('execute')
            ->with(
                $this->logicalOr(
                    $this->stringContains('INSERT INTO users'),
                    $this->stringContains('UPDATE users SET username = ?')
                ),
                $this->callback(static function (array $params) use ($plainPassword): bool {
                    if (count($params) === 3) {
                        return $params[0] === 'user@example.com'
                            && password_verify($plainPassword, $params[1])
                            && $params[2] === 'user';
                    }

                    return $params === ['user42', 42];
                })
            );

        $userDb
            ->expects($this->once())
            ->method('lastInsertId')
            ->willReturn(42);

        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('INSERT INTO email_verifications'),
                $this->callback(static function (array $params): bool {
                    return $params[0] === 42 && strlen($params[1]) === 64;
                })
            );

        $mailService
            ->expects($this->once())
            ->method('send')
            ->with(
                'user@example.com',
                $this->trans('user.mail.registration_subject'),
                'users/welcome',
                $this->callback(static function (array $context): bool {
                    return isset($context['activationLink'])
                        && str_starts_with($context['activationLink'], 'https://example.com/register/?token=');
                })
            );

        $userId = $service->register(' USER@Example.com ', $plainPassword);

        $this->assertSame(42, $userId);
    }

    public function testCreateEmailVerificationStoresHashedToken(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $service = $this->makeService(db: $db);

        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('INSERT INTO email_verifications'),
                $this->callback(static function (array $params): bool {
                    return $params[0] === 7
                        && strlen($params[1]) === 64;
                })
            )
            ->willReturn(1);

        $token = $service->createEmailVerification(7);

        $this->assertSame(64, strlen($token));
    }

    public function testVerifyEmailActivatesUserAndDeletesToken(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $service = $this->makeService(db: $db);

        $token = str_repeat('a', 64);

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('FROM email_verifications'),
                [hash('sha256', $token)]
            )
            ->willReturn(['user_id' => 7]);

        $db
            ->expects($this->exactly(2))
            ->method('execute')
            ->with(
                $this->logicalOr(
                    $this->equalTo('UPDATE users SET is_active = 1 WHERE id = ?'),
                    $this->equalTo('DELETE FROM email_verifications WHERE user_id = ?')
                ),
                [7]
            )
            ->willReturn(1);

        $this->assertSame(7, $service->verifyEmail($token));
    }

    public function testVerifyEmailRejectsInvalidToken(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $service = $this->makeService(db: $db);

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid token');

        $service->verifyEmail(str_repeat('b', 64));
    }

    public function testCreatePasswordResetTokenDoesNothingForUnknownEmail(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $db = $this->createMock(PdoDatabase::class);
        $service = $this->makeService($userDb, $db);

        $userDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('WHERE u.email = ?'),
                ['missing@example.com']
            )
            ->willReturn(null);

        $db->expects($this->never())->method('execute');

        $service->createPasswordResetToken('missing@example.com');

        $this->assertTrue(true);
    }

    public function testCreatePasswordResetTokenPersistsTokenAndSendsMail(): void
    {
        $_SERVER['SERVER_NAME'] = 'example.com';

        $userDb = $this->createMock(PdoDatabase::class);
        $db = $this->createMock(PdoDatabase::class);
        $mailService = $this->createMock(MailService::class);
        $service = $this->makeService($userDb, $db, $mailService);

        $userDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('WHERE u.email = ?'),
                ['user@example.com']
            )
            ->willReturn([
                'id' => 7,
                'email' => 'user@example.com',
                'password_hash' => 'irrelevant-hash',
                'role' => 'user',
                'is_active' => 1,
            ]);

        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('INSERT INTO password_resets'),
                $this->callback(static function (array $params): bool {
                    return $params[0] === 7
                        && strlen($params[1]) === 64
                        && is_int($params[2]);
                })
            );

        $mailService
            ->expects($this->once())
            ->method('send')
            ->with(
                'user@example.com',
                $this->trans('user.mail.password_reset_subject'),
                'users/retrieve',
                $this->callback(static function (array $context): bool {
                    return isset($context['retrieveLink'])
                        && str_starts_with($context['retrieveLink'], 'https://example.com/forgot-password/?token=');
                })
            );

        $service->createPasswordResetToken('user@example.com');

        $this->assertTrue(true);
    }

    public function testGetPasswordResetTokenReturnsDatabaseRow(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $service = $this->makeService(db: $db);

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT * FROM password_resets WHERE token = ?', ['token-123'])
            ->willReturn([
                'user_id' => 7,
                'token' => 'token-123',
                'created_at' => time(),
            ]);

        $row = $service->getPasswordResetToken('token-123');

        $this->assertSame(7, $row['user_id']);
    }

    public function testGetPasswordResetTokenReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $service = $this->makeService(db: $db);

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT * FROM password_resets WHERE token = ?', ['unknown-token'])
            ->willReturn(null);

        $this->assertNull($service->getPasswordResetToken('unknown-token'));
    }

    /**
     * The length rule used to live in the controller as an ad-hoc
     * `mb_strlen($password) < 6` - four characters under
     * MIN_PASSWORD_LENGTH, and enforced nowhere else, so a reset link was a
     * way around the site's own policy. It is now this method's business.
     *
     * Checked *before* the token lookup, which is worth pinning for a second
     * reason: it means a caller probing tokens with a junk password learns
     * nothing about which tokens exist.
     */
    public function testResetPasswordByTokenEnforcesTheMinimumBeforeTouchingTheToken(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchOne');
        $db->expects($this->never())->method('execute');

        $service = $this->makeService(db: $db);

        $this->expectException(ValidationException::class);

        // Nine characters: past the old check, short of the real one.
        $service->resetPasswordByToken('valid-token', '123456789');
    }

    public function testResetPasswordByTokenAcceptsAPasswordAtTheMinimum(): void
    {
        // Exactly ten - the boundary the register form and the reset form's
        // own minlength="10" both advertise.
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->willReturn(null);

        $service = $this->makeService(db: $db);

        // It gets as far as the token lookup, which is the assertion; the
        // token itself is not the point here.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('user.password_reset_invalid'));

        $service->resetPasswordByToken('missing', '0123456789');
    }

    public function testResetPasswordByTokenRejectsMissingToken(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $service = $this->makeService(db: $db);

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('user.password_reset_invalid'));

        $service->resetPasswordByToken('missing', 'very-secure-password');
    }

    public function testResetPasswordByTokenRejectsExpiredToken(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $service = $this->makeService(db: $db);

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn([
                'user_id' => 7,
                'token' => 'expired-token',
                'created_at' => time() - 3700,
            ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('user.password_reset_expired'));

        $service->resetPasswordByToken('expired-token', 'very-secure-password');
    }

    public function testResetPasswordByTokenUpdatesPasswordConsumesTokenAndNotifiesUser(): void
    {
        $userDb = $this->createMock(PdoDatabase::class);
        $db = $this->createMock(PdoDatabase::class);
        $mailService = $this->createMock(MailService::class);
        $service = $this->makeService($userDb, $db, $mailService);

        $plainPassword = 'very-secure-password';

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT * FROM password_resets WHERE token = ?', ['valid-token'])
            ->willReturn([
                'user_id' => 7,
                'token' => 'valid-token',
                'created_at' => time(),
            ]);

        $db
            ->expects($this->exactly(2))
            ->method('execute')
            ->with(
                $this->logicalOr(
                    $this->equalTo('UPDATE users SET password_hash = ? WHERE id = ?'),
                    $this->equalTo('DELETE FROM password_resets WHERE token = ?')
                ),
                $this->callback(static function (array $params) use ($plainPassword): bool {
                    // UPDATE call: [$hash, $userId]; DELETE call: [$token]
                    if (count($params) === 2) {
                        return password_verify($plainPassword, $params[0]) && $params[1] === 7;
                    }

                    return $params === ['valid-token'];
                })
            );

        $userDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('FROM users u'), [7])
            ->willReturn([
                'id' => 7,
                'email' => 'user@example.com',
                'password_hash' => 'irrelevant-hash',
                'role' => 'user',
                'is_active' => 1,
            ]);

        $mailService
            ->expects($this->once())
            ->method('send')
            ->with(
                'user@example.com',
                $this->trans('user.mail.password_reset_subject'),
                'users/reset'
            );

        $service->resetPasswordByToken('valid-token', $plainPassword);
    }

    /* ------------------------------------------------------------------ */
    /* Presence - "who is online right now" (see UserService's own note)   */
    /* ------------------------------------------------------------------ */

    public function testOnlineMembersCountsOnlyUsersInsideTheWindow(): void
    {
        $db = new FakeSessionDatabase();
        $db->addUser(10, 'Ann', 'ann');
        $db->addUser(11, 'Bob', 'bob');
        $db->addSession(10, 5);
        // Stale by more than UserService::ONLINE_WINDOW_SECONDS - logged in
        // once, closed the tab, still has a valid session row.
        $db->addSession(11, UserService::ONLINE_WINDOW_SECONDS + 60);

        $result = $this->makeServiceWithPresenceDb($db)->onlineMembers();

        $this->assertSame(1, $result['total']);
        $this->assertSame(0, $result['hidden']);
        $this->assertSame([10], array_map(static fn (User $u): int => $u->id, $result['users']));
    }

    public function testOnlineMembersOrdersMostRecentlySeenFirst(): void
    {
        $db = new FakeSessionDatabase();
        $db->addUser(10, 'Ann', 'ann');
        $db->addUser(11, 'Bob', 'bob');
        $db->addUser(12, 'Cid', 'cid');
        $db->addSession(11, 60);
        $db->addSession(10, 30);
        $db->addSession(12, 1);

        $result = $this->makeServiceWithPresenceDb($db)->onlineMembers();

        $this->assertSame(
            [12, 10, 11],
            array_map(static fn (User $u): int => $u->id, $result['users'])
        );
    }

    public function testOnlineMembersCollapsesSeveralSessionsOfTheSameUser(): void
    {
        $db = new FakeSessionDatabase();
        $db->addUser(10, 'Ann', 'ann');
        // Phone and laptop - one person, one entry, ranked by the fresher
        // of the two.
        $db->addSession(10, 80);
        $db->addSession(10, 2);

        $result = $this->makeServiceWithPresenceDb($db)->onlineMembers();

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['users']);
    }

    public function testOnlineMembersKeepsTheTotalExactWhileCappingTheNameList(): void
    {
        $db = new FakeSessionDatabase();
        foreach (range(10, 14) as $i) {
            $db->addUser($i, 'User'.$i, 'user'.$i);
            $db->addSession($i, 1);
        }

        $result = $this->makeServiceWithPresenceDb($db)->onlineMembers(2);

        $this->assertSame(5, $result['total']);
        $this->assertCount(2, $result['users']);
        $this->assertSame(3, $result['hidden']);
    }

    public function testOnlineMembersSkipsDeactivatedAccountsAndTheSystemUser(): void
    {
        $db = new FakeSessionDatabase();
        $db->addUser(10, 'Ann', 'ann');
        $db->addUser(11, 'Banned', 'banned', active: false);
        $db->addUser(User::SYSTEM_USER_ID, 'Система', 'system');
        $db->addSession(10, 1);
        $db->addSession(11, 1);
        $db->addSession(User::SYSTEM_USER_ID, 1);

        $result = $this->makeServiceWithPresenceDb($db)->onlineMembers();

        $this->assertSame(1, $result['total']);
        $this->assertSame([10], array_map(static fn (User $u): int => $u->id, $result['users']));
    }

    public function testOnlineMembersOnAnEmptySiteReturnsZeroesRatherThanQueryingUsers(): void
    {
        $result = $this->makeServiceWithPresenceDb(new FakeSessionDatabase())->onlineMembers();

        $this->assertSame(['total' => 0, 'users' => [], 'hidden' => 0], $result);
    }

    public function testIsOnlineFollowsTheSameWindowAsTheList(): void
    {
        $db = new FakeSessionDatabase();
        $db->addUser(10, 'Ann', 'ann');
        $db->addUser(11, 'Bob', 'bob');
        $db->addSession(10, 5);
        $db->addSession(11, UserService::ONLINE_WINDOW_SECONDS + 60);

        $service = $this->makeServiceWithPresenceDb($db);

        $this->assertTrue($service->isOnline(10));
        $this->assertFalse($service->isOnline(11));
        $this->assertFalse($service->isOnline(999));
    }

    public function testFilterOnlineReturnsAnIssetFriendlySetOfTheGivenIds(): void
    {
        $db = new FakeSessionDatabase();
        $db->addUser(10, 'Ann', 'ann');
        $db->addUser(12, 'Cid', 'cid');
        $db->addSession(10, 5);
        $db->addSession(12, 5);

        $online = $this->makeServiceWithPresenceDb($db)->filterOnline([10, 11]);

        $this->assertTrue(isset($online[10]));
        $this->assertFalse(isset($online[11]));
        // 12 is online, but wasn't asked about - a filter, not a listing.
        $this->assertFalse(isset($online[12]));
    }

    public function testFilterOnlineWithNoIdsSkipsTheQueryEntirely(): void
    {
        $this->assertSame([], $this->makeServiceWithPresenceDb(new FakeSessionDatabase())->filterOnline([]));
    }


    /* ------------------------------------------------------------------ */
    /* Presence: guests and bots                                          */
    /* ------------------------------------------------------------------ */

    public function testDetectBotRecognizesKnownCrawlersCaseInsensitively(): void
    {
        $service = $this->makeService();

        $this->assertSame(
            'Googlebot',
            $service->detectBot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
        );
        $this->assertSame('YANDEX', $service->detectBot('Mozilla/5.0 (compatible; YANDEXBOT/3.0)'));
    }

    public function testDetectBotUsesCrawlerDetectDatabase(): void
    {
        $service = $this->makeService();

        $this->assertSame(
            'Discordbot',
            $service->detectBot('Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)')
        );
    }

    public function testDetectBotTreatsUnknownAndMissingUserAgentsAsHuman(): void
    {
        $service = $this->makeService();

        $this->assertNull($service->detectBot('Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0 Safari/537.36'));
        // No User-Agent at all is suspicious, but there's no name to show
        // and a mislabelled human is the worse error - see detectBot().
        $this->assertNull($service->detectBot(''));
    }

    public function testRecordGuestPresenceUpsertsASingleRowPerBot(): void
    {
        $db = new FakeSessionDatabase();
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1)';

        $this->makeServiceWithPresenceDb($db)->recordGuestPresence();

        // One row per bot for its whole existence, not one per request -
        // crawlers keep no cookies, so the "no row until you hand the
        // cookie back" rule can't bound them. Asserted on the bound
        // parameters rather than on 'ON DUPLICATE KEY UPDATE', which the
        // guest write also uses.
        $this->assertCount(1, $db->executed);
        $this->assertContains('Googlebot', $db->executed[0][1]);
        $this->assertSame([], $_COOKIE, 'a bot should not be handed a visit cookie');
    }

    public function testRecordGuestPresenceWritesNothingOnAVisitorsFirstRequest(): void
    {
        $db = new FakeSessionDatabase();
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0';

        $this->makeServiceWithPresenceDb($db)->recordGuestPresence();

        // The whole anti-flood rule: a client that ignores cookies never
        // gets past this step, so it can't grow the table per request.
        $this->assertSame([], $db->executed);
        $this->assertNotSame('', $_COOKIE['SE_VISIT'] ?? '');
    }

    public function testRecordGuestPresenceInsertsARowOnceOurOwnCookieComesBack(): void
    {
        $db = new FakeSessionDatabase();
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0';

        $service = $this->makeServiceWithPresenceDb($db);
        // Request one hands out the signed cookie (and writes nothing);
        // startVisit() puts it straight into $_COOKIE, so calling again is
        // exactly what the visitor's second request looks like.
        $service->recordGuestPresence();
        $service->recordGuestPresence();

        // The guest INSERT specifically: bot_name NULL, and the token hash
        // as its first bound value.
        $this->assertCount(1, $db->executed);
        $this->assertTrue($db->didExecute('VALUES (NULL, NULL, ?, ?, ?, ?'));
        $this->assertSame(
            hash('sha256', (string) $_COOKIE['SE_VISIT']),
            $db->executed[0][1][0]
        );
    }

    public function testRecordGuestPresenceIgnoresACookieItDidNotIssue(): void
    {
        $db = new FakeSessionDatabase();
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0';
        $_COOKIE['SE_VISIT'] = 'made.up';

        $this->makeServiceWithPresenceDb($db)->recordGuestPresence();

        // Without this check, `curl -b SE_VISIT=$RANDOM` in a loop would
        // insert a row per request, faster than any sweep can reclaim.
        $this->assertSame([], $db->executed);
        $this->assertNotSame('made.up', $_COOKIE['SE_VISIT']);
    }

    public function testRecordGuestPresenceIgnoresANonStringCookie(): void
    {
        $db = new FakeSessionDatabase();
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0';
        // `Cookie: SE_VISIT[]=x` - PHP hands us an array, and hash() would
        // warn and read "Array" for every such client.
        $_COOKIE['SE_VISIT'] = ['x'];

        $this->makeServiceWithPresenceDb($db)->recordGuestPresence();

        $this->assertSame([], $db->executed);
        $this->assertIsString($_COOKIE['SE_VISIT']);
    }

    public function testRecordGuestPresenceOnlyTouchesAnExistingVisitorsRow(): void
    {
        $db = new FakeSessionDatabase();
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0';

        $service = $this->makeServiceWithPresenceDb($db);
        // First request issues the cookie; seed the row it would have
        // created so this stands in for the visitor's third page view.
        $service->recordGuestPresence();
        $db->addGuestSession(5, hash('sha256', (string) $_COOKIE['SE_VISIT']));

        $service->recordGuestPresence();

        $this->assertFalse($db->didExecute('INSERT INTO user_sessions'));
        // Throttled, not unconditional: the UPDATE carries its own
        // "only if already stale" guard (see touchIfStale()).
        $this->assertTrue($db->didExecute('SET last_used_at = UNIX_TIMESTAMP()'));
        $this->assertTrue($db->didExecute('last_used_at < (UNIX_TIMESTAMP() - ?)'));
    }

    public function testOnlineGuestCountCountsOnlyAnonymousHumansInsideTheWindow(): void
    {
        $db = new FakeSessionDatabase();
        $db->addUser(10, 'Ann', 'ann');
        $db->addSession(10, 5);
        $db->addGuestSession(5);
        $db->addGuestSession(20);
        $db->addGuestSession(UserService::ONLINE_WINDOW_SECONDS + 60);
        $db->addBotSession('Googlebot', 5);

        $this->assertSame(2, $this->makeServiceWithPresenceDb($db)->onlineGuestCount());
    }

    public function testOnlineBotNamesListsCrawlersMostRecentlySeenFirst(): void
    {
        $db = new FakeSessionDatabase();
        $db->addBotSession('Googlebot', 60);
        $db->addBotSession('YandexBot', 2);
        $db->addBotSession('Bingbot', UserService::ONLINE_WINDOW_SECONDS + 60);
        $db->addGuestSession(1);

        $this->assertSame(
            ['YandexBot', 'Googlebot'],
            $this->makeServiceWithPresenceDb($db)->onlineBotNames()
        );
    }

    /**
     * The regression the `AND user_id IS NOT NULL` in
     * UserSessionRepository::findOnlineUserIds() exists to prevent: guests
     * and bots share the table, and without it every anonymous row would
     * group into one phantom "member" with a NULL id.
     */
    public function testOnlineMembersIgnoresGuestAndBotRows(): void
    {
        $db = new FakeSessionDatabase();
        $db->addUser(10, 'Ann', 'ann');
        $db->addSession(10, 5);
        $db->addGuestSession(1);
        $db->addGuestSession(2);
        $db->addBotSession('Googlebot', 1);

        $result = $this->makeServiceWithPresenceDb($db)->onlineMembers();

        $this->assertSame(1, $result['total']);
        $this->assertSame([10], array_map(static fn (User $u): int => $u->id, $result['users']));
    }

    /* ------------------------------------------------------------------ */
    /* Session cleanup (the `users:sessions-cleanup` cron task)            */
    /* ------------------------------------------------------------------ */

    public function testCleanupExpiredSessionsDeletesExpiredRowsInOneBatchWhenThereAreFew(): void
    {
        $sessionDb = $this->createMock(PdoDatabase::class);
        $sessionDb
            ->expects($this->once())
            ->method('execute')
            ->with($this->logicalAnd(
                $this->stringContains('DELETE FROM user_sessions'),
                $this->stringContains('WHERE expires_at < UNIX_TIMESTAMP()'),
                // Bounded: never one unbounded DELETE, however neglected
                // the table is.
                $this->stringContains('LIMIT 1000')
            ))
            ->willReturn(12);

        $service = $this->makeService(sessionDb: $sessionDb);

        // A short batch means there was nothing more to delete, so the loop
        // stops rather than issuing a second pointless DELETE.
        $this->assertSame(12, $service->cleanupExpiredSessions());
    }

    public function testCleanupExpiredSessionsStopsAfterItsBatchCapAndLeavesTheRestForNextTime(): void
    {
        $sessionDb = $this->createMock(PdoDatabase::class);
        // Every batch comes back full, i.e. there is always more to delete -
        // the first run against a table nobody ever swept. CLEANUP_MAX_BATCHES
        // (20) x CLEANUP_BATCH_SIZE (1000) is the ceiling for one run.
        $sessionDb
            ->expects($this->exactly(20))
            ->method('execute')
            ->willReturn(1000);

        $service = $this->makeService(sessionDb: $sessionDb);

        $this->assertSame(20000, $service->cleanupExpiredSessions());
    }

    public function testCleanupExpiredSessionsOnACleanTableIssuesExactlyOneDelete(): void
    {
        $sessionDb = $this->createMock(PdoDatabase::class);
        $sessionDb
            ->expects($this->once())
            ->method('execute')
            ->willReturn(0);

        $service = $this->makeService(sessionDb: $sessionDb);

        $this->assertSame(0, $service->cleanupExpiredSessions());
    }

    /*
     * Forum signatures. sanitizeSignature() is the whole security boundary for
     * users.signature - all write paths go through it, and forums.topic-view prints the
     * result with |raw - so most of these are about what it refuses. Static
     * methods over a pure purifier, hence no makeService() here.
     */

    public function testSanitizeSignatureKeepsInlineFormattingAndLinks(): void
    {
        $html = UserService::sanitizeSignature(
            '<b>Аня</b>, <i>читатель</i> — <a href="https://example.com">блог</a>'
        );

        $this->assertStringContainsString('<b>Аня</b>', $html);
        $this->assertStringContainsString('<i>читатель</i>', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    /**
     * Signature links leave the site in a new tab and carry no ranking weight,
     * whether or not the author asked for that - see signaturePurifier()'s
     * config.
     */
    public function testSanitizeSignatureForcesSafeLinkAttributes(): void
    {
        $html = UserService::sanitizeSignature(
            '<a href="https://example.com" rel="dofollow" target="_self">блог</a>'
        );

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('nofollow', $html);
        $this->assertStringNotContainsString('_self', $html);
    }

    public function testSanitizeSignatureKeepsImagesWithPrivacyAttributes(): void
    {
        $html = UserService::sanitizeSignature('<img src="https://example.com/sig.png" alt="баннер">');

        $this->assertStringContainsString('src="https://example.com/sig.png"', $html);
        $this->assertStringContainsString('alt="баннер"', $html);
        // Don't hand the reader's referrer to whoever hosts the image, and
        // don't fetch it before it's on screen.
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    /**
     * An image repeats under every post its author made, so its size can't be
     * the author's decision. CSS does the clamping (.forum-signature img);
     * these are the server-side backstops.
     */
    public function testSanitizeSignatureDoesNotLetImagesDictateTheirSize(): void
    {
        $html = UserService::sanitizeSignature(
            '<img src="https://example.com/sig.png" width="5000" height="5000" style="width:5000px">'
        );

        $this->assertStringNotContainsString('5000', $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    public function testSanitizeSignatureRejectsNonHttpImageSources(): void
    {
        // data: would let an "image" carry inline payloads; neither scheme is
        // in URI.AllowedSchemes, so the src - and with it the tag - goes.
        $data = UserService::sanitizeSignature('<img src="data:text/html;base64,PHNjcmlwdD4=">');
        $this->assertStringNotContainsString('data:', $data);

        $javascript = UserService::sanitizeSignature('<img src="javascript:alert(1)">');
        $this->assertStringNotContainsString('javascript:', $javascript);
    }

    public function testSanitizeSignatureStripsScriptsAndEventHandlers(): void
    {
        $this->assertSame('', UserService::sanitizeSignature('<script>alert(1)</script>'));

        $onerror = UserService::sanitizeSignature('<img src="https://example.com/x.png" onerror="alert(1)">');
        $this->assertStringNotContainsString('onerror', $onerror);
        $this->assertStringNotContainsString('alert(1)', $onerror);

        $link = UserService::sanitizeSignature('<a href="javascript:alert(1)">клик</a>');
        $this->assertStringNotContainsString('javascript:', $link);

        // The classic strip_tags() bypass - nested delimiters that reassemble
        // into a live tag once the outer pair is removed. A purifier parses
        // rather than pattern-matches, so it doesn't fall for it.
        $nested = UserService::sanitizeSignature('<<script>script>alert(1)<</script>/script>');
        $this->assertStringNotContainsString('<script', $nested);
    }

    /**
     * A signature repeats under every post its author has made, so it isn't
     * allowed to bring block-level layout with it.
     */
    public function testSanitizeSignatureStripsBlockLevelMarkupButKeepsItsText(): void
    {
        $html = UserService::sanitizeSignature('<div><h1>Заголовок</h1><p>текст</p></div>');

        $this->assertStringNotContainsString('<div', $html);
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringNotContainsString('<p', $html);
        $this->assertStringContainsString('Заголовок', $html);
        $this->assertStringContainsString('текст', $html);
    }

    public function testSanitizeSignatureEscapesPlainTextSpecialCharacters(): void
    {
        $html = UserService::sanitizeSignature('5 < 6 & это всё');

        $this->assertStringContainsString('&lt;', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    public function testSanitizeSignatureIsEmptyForBlankAndMarkupOnlyInput(): void
    {
        $this->assertSame('', UserService::sanitizeSignature(''));
        $this->assertSame('', UserService::sanitizeSignature("   \n  "));
        $this->assertSame('', UserService::sanitizeSignature('<style>body{}</style>'));
    }

    /**
     * Sanitizing keeps the author's newlines as newlines, so the profile
     * textarea shows the signature the way it was typed; converting them is
     * renderSignature()'s only job.
     */
    public function testOnlyRenderSignatureConvertsNewlines(): void
    {
        $raw = "первая\nвторая";

        $this->assertSame($raw, UserService::sanitizeSignature($raw));
        $this->assertStringContainsString('<br', UserService::renderSignature($raw));
    }

    /**
     * renderSignature() trusts what it's given (see sanitizeSignature()) - the
     * only thing it decides on its own is the '' that forums.topic-view treats
     * as "this author has no signature".
     */
    public function testRenderSignaturePassesStoredHtmlThroughAndBlanksEmptyInput(): void
    {
        $stored = '<b>Аня</b> — <a href="https://example.com" rel="nofollow" target="_blank">блог</a>';

        $this->assertSame($stored, UserService::renderSignature($stored));
        $this->assertSame('', UserService::renderSignature(''));
        $this->assertSame('', UserService::renderSignature("  \n "));
    }
}
