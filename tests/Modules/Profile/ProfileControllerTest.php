<?php

declare(strict_types=1);

namespace Tests\Modules\Profile;

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Profile\ProfileController;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;
use Tests\Support\PhpInputStreamMock;

/**
 * The avatar path guard.
 *
 * It used to be `~^/uploads/[a-zA-Z0-9/_.-]+$~` inline, which allows both `.`
 * and `/` and therefore matched `/uploads/../../etc/passwd`. Not a file read -
 * the value is stored and rendered back as an `<img src>`, and a leading single
 * `/` keeps the browser on this origin - but it did let a profile point its
 * avatar at any same-origin path, which is nothing the field is for.
 *
 * isStoredUploadPath() is a pure static, so it is reachable through reflection
 * without building the controller - which would otherwise need PageTree and
 * UrlGenerator wired up for a check that touches neither. The HTTP-level tests
 * below now cover the main profile writes; remaining branches are tracked in
 * docs/TODO.md and should be chosen from a fresh coverage report.
 */
final class ProfileControllerTest extends TestCase
{
    private function isStoredUploadPath(string $path): bool
    {
        $method = new ReflectionMethod(ProfileController::class, 'isStoredUploadPath');

        return $method->invoke(null, $path);
    }

    /** @return array<string, array{string}> */
    public static function acceptedProvider(): array
    {
        return [
            'a plain file' => ['/uploads/a.png'],
            'dated directories' => ['/uploads/2026/08/photo_1.jpeg'],
            'hyphens and underscores' => ['/uploads/av-1_small.webp'],
            'several segments deep' => ['/uploads/nested/dir/file.png'],
        ];
    }

    #[DataProvider('acceptedProvider')]
    public function testAcceptsPathsUploadsActuallyProduces(string $path): void
    {
        $this->assertTrue($this->isStoredUploadPath($path));
    }

    /** @return array<string, array{string}> */
    public static function rejectedProvider(): array
    {
        return [
            // The regression: `..` passed the character class.
            'traversal' => ['/uploads/../../etc/passwd'],
            'traversal mid-path' => ['/uploads/a/../b.png'],
            'a bare parent segment' => ['/uploads/..'],
            'a current-directory segment' => ['/uploads/./x.png'],
            // An empty segment, which is how `//` reads.
            'a doubled slash' => ['/uploads//x.png'],
            'nothing after the prefix' => ['/uploads/'],
            'a different directory' => ['/storage/x.png'],
            'an absolute url' => ['https://example.com/uploads/x.png'],
            'a protocol-relative url' => ['//example.com/uploads/x.png'],
            'a percent-encoded traversal' => ['/uploads/..%2f..%2fx'],
            'a null byte' => ["/uploads/x.png\0.txt"],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function testRejectsAnythingElse(string $path): void
    {
        $this->assertFalse($this->isStoredUploadPath($path));
    }

    /* ===============================
       profile.update
    =============================== */

    protected function tearDown(): void
    {
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_X_CSRF_TOKEN'],
            $_COOKIE['csrfToken'],
        );
        $_GET = [];

        PhpInputStreamMock::restore();
        http_response_code(200);

        parent::tearDown();
    }

    /**
     * @param list<array{0: string, 1: array}> $writes filled in with every execute()
     */
    private function makeController(User $user, array &$writes = []): ProfileController
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$writes): int {
                $writes[] = [$sql, $params];

                return 1;
            }
        );

        $pageTree = new PageTree([]);

        return new ProfileController(
            $db,
            new RequestContext($user, new DateTimeZone('UTC'), QueryParams::fromGlobals()),
            new TranslationManager('ru', 'en'),
            $pageTree,
            new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache()),
            new NotificationService(
                (new \ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
                new NotificationDeliveryRepository($db),
                new NotificationPreferenceRepository($db),
                new UserRepository($db),
                (new \ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
                translationManager: new \StreamEngine\Core\TranslationManager('ru', 'en'),
                config: new \StreamEngine\Core\Config(['SITE_URL' => 'https://example.test']),
            ),
        );
    }

    private function makeUpdatePage(string $action = 'profile.update'): Page
    {
        return Page::api(
            id: 80,
            parentId: 10,
            pattern: 'profile',
            requestMethods: ['POST'],
            action: $action,
            accessRule: AccessService::ACCESS_AUTHENTICATED,
        );
    }

    public function testShowDispatchesProfilePageToItsOwnHandler(): void
    {
        $view = $this->makeController(new User(7, 'user@example.com', AccessService::ROLE_USER))->show(
            $this->makeUpdatePage('profile.show')
        );

        self::assertSame('modules/profile/page.twig', $view->template);
        self::assertSame('Ваш профиль', $view->data['title']);
        self::assertCount(7, $view->data['tabs']);
        self::assertCount(11, $view->data['notificationPreferences']);
        self::assertSame('09:00', $view->data['notificationDigestTime']);
    }

    public function testShowRejectsUnknownPageAction(): void
    {
        $controller = $this->makeController(new User(7, 'user@example.com', AccessService::ROLE_USER));

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Unknown page action');

        $controller->show($this->makeUpdatePage('profile.unknown'));
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<array{0: string, 1: array}> $writes
     */
    private function post(array $payload, array &$writes = [], string $action = 'profile.update'): void
    {
        $controller = $this->makeController(
            new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER),
            $writes
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        PhpInputStreamMock::register(json_encode($payload));

        // ob_start() outside the try and the close inside `finally`: most calls
        // here are *expected* to throw, and with the close in the try body the
        // buffer stayed open on every one of them - which PHPUnit reports as a
        // risky test rather than as the failure it looks like.
        ob_start();

        try {
            $controller->callApi($this->makeUpdatePage($action), []);
        } finally {
            ob_end_clean();
            PhpInputStreamMock::restore();
        }
    }

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'nick' => 'Аня',
            'bio' => 'Пара слов',
            'homepage' => 'https://example.com',
            'gender' => 'female',
            'birth_date' => '1990-02-03',
            'avatar_url' => '/uploads/a.png',
        ], $overrides);
    }

    /**
     * Guests are refused before the CSRF check, so someone with no session is
     * told they aren't logged in rather than handed an error about a token they
     * were never issued. Both writes share verifiedProfileInput(), so both are
     * covered.
     */
    #[DataProvider('profileWriteActionProvider')]
    public function testProfileWritesRefuseAGuest(string $action): void
    {
        $controller = $this->makeController(new User(id: 0, email: '', role: AccessService::ROLE_USER));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        $this->expectException(ForbiddenException::class);

        $controller->callApi($this->makeUpdatePage($action), []);
    }

    /** @return array<string, array{string}> */
    public static function profileWriteActionProvider(): array
    {
        return [
            'personal details' => ['profile.update'],
            'forum signature' => ['profile.forum.update'],
            'privacy settings' => ['profile.privacy.update'],
            'notification settings' => ['profile.mailings.update'],
        ];
    }

    #[DataProvider('profileWriteActionProvider')]
    public function testProfileWritesRequireACsrfToken(string $action): void
    {
        $controller = $this->makeController(new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        $this->expectException(ValidationException::class);

        $controller->callApi($this->makeUpdatePage($action), []);
    }

    public function testANonJsonBodyIsRefused(): void
    {
        $controller = $this->makeController(new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        PhpInputStreamMock::register('not json');

        try {
            $this->expectException(ValidationException::class);
            $controller->callApi($this->makeUpdatePage(), []);
        } finally {
            PhpInputStreamMock::restore();
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidProfileFieldProvider')]
    public function testInvalidFieldsAreRefusedAndNothingIsSaved(array $overrides): void
    {
        $writes = [];

        try {
            $this->post($this->validPayload($overrides), $writes);
            $this->fail('expected ValidationException');
        } catch (ValidationException) {
            $this->assertSame([], $writes);
        }
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidProfileFieldProvider(): array
    {
        return [
            'nick over 50' => [['nick' => str_repeat('я', 51)]],
            'bio over 2000' => [['bio' => str_repeat('я', 2001)]],
            'homepage that is not a url' => [['homepage' => 'не ссылка']],
            'gender outside the allowlist' => [['gender' => 'other-ish']],
            // createFromFormat with a leading ! plus a round-trip check, so a
            // date that PHP would happily roll over is caught.
            'a day that does not exist' => [['birth_date' => '2026-02-30']],
            // Same date, sloppy format - the round-trip rejects it.
            'an unpadded date' => [['birth_date' => '2026-2-3']],
            'an avatar outside uploads' => [['avatar_url' => '/etc/passwd']],
            'an avatar with traversal' => [['avatar_url' => '/uploads/../../etc/passwd']],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('validProfileFieldProvider')]
    public function testValidFieldsAreSaved(array $overrides): void
    {
        $writes = [];

        $this->post($this->validPayload($overrides), $writes);

        $this->assertCount(1, $writes);
        $this->assertStringContainsString('UPDATE users', $writes[0][0]);
        // Trailing id, per UserRepository::updateProfilePersonal()'s params.
        $this->assertSame(7, $writes[0][1][6]);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function validProfileFieldProvider(): array
    {
        return [
            'the boundary nick' => [['nick' => str_repeat('я', 50)]],
            'the boundary bio' => [['bio' => str_repeat('я', 2000)]],
            'an empty homepage' => [['homepage' => '']],
            'an empty gender' => [['gender' => '']],
            'a real date' => [['birth_date' => '2026-02-03']],
            'no date at all' => [['birth_date' => '']],
            'a nested avatar path' => [['avatar_url' => '/uploads/2026/08/a.png']],
        ];
    }

    public function testAnEmptyBirthDateIsStoredAsNullRatherThanAnEmptyString(): void
    {
        $writes = [];

        $this->post($this->validPayload(['birth_date' => '']), $writes);

        // birth_date is the fifth column; '' would be an invalid DATE.
        $this->assertNull($writes[0][1][4]);
    }

    public function testPrivacySettingsAreSaved(): void
    {
        $writes = [];

        $this->post([
            'show_gender_publicly' => '1',
            'show_homepage_publicly' => '1',
            'show_hidden_profile_to_friends' => '1',
        ], $writes, 'profile.privacy.update');

        $this->assertCount(1, $writes);
        $this->assertStringContainsString('UPDATE users', $writes[0][0]);
        $this->assertSame([1, 0, 1, 0, 1, 7], $writes[0][1]);
    }

    public function testMailingSettingsAreSavedAsOneCompleteSet(): void
    {
        $writes = [];

        $this->post([
            'digest_delivery_time' => '08:30',
            'message_unread_digest_email' => 'daily',
            'friend_request_messenger' => 'instant',
            'friend_request_email' => 'daily',
            'friend_mutual_messenger' => 'off',
            'friend_mutual_email' => 'instant',
            'forum_reply_messenger' => 'instant',
            'forum_reply_email' => 'off',
            'content_comment_messenger' => 'instant',
            'content_comment_email' => 'daily',
            'comment_reply_messenger' => 'instant',
            'comment_reply_email' => 'daily',
            'friend_post_messenger' => 'off',
            'friend_post_email' => 'daily',
            'community_join_request_messenger' => 'instant',
            'community_join_request_email' => 'daily',
            'community_join_approved_messenger' => 'instant',
            'community_join_approved_email' => 'daily',
            'community_membership_changed_messenger' => 'instant',
            'community_membership_changed_email' => 'daily',
            'community_post_messenger' => 'off',
            'community_post_email' => 'daily',
        ], $writes, 'profile.mailings.update');

        self::assertCount(24, $writes);
        self::assertSame([7, 'message.unread_digest', 'email', 'daily'], $writes[22][1]);
        self::assertSame(['08:30', 7], $writes[23][1]);
        self::assertStringContainsString('DELETE FROM notification_preferences', $writes[0][0]);
        self::assertSame([7], $writes[0][1]);
        self::assertSame([7, 'friend.request', 'messenger', 'instant'], $writes[1][1]);
        self::assertSame([7, 'friend.request', 'email', 'daily'], $writes[2][1]);
        self::assertSame([7, 'friend.mutual', 'email', 'instant'], $writes[4][1]);
        self::assertSame([7, 'forum.reply', 'email', 'off'], $writes[6][1]);
        self::assertSame([7, 'content.comment', 'email', 'daily'], $writes[8][1]);
        self::assertSame([7, 'comment.reply', 'email', 'daily'], $writes[10][1]);
        self::assertSame([7, 'friend.post', 'email', 'daily'], $writes[12][1]);
        self::assertSame([7, 'community.join_request', 'messenger', 'instant'], $writes[13][1]);
        self::assertSame([7, 'community.join_request', 'email', 'daily'], $writes[14][1]);
        self::assertSame([7, 'community.join_approved', 'messenger', 'instant'], $writes[15][1]);
        self::assertSame([7, 'community.join_approved', 'email', 'daily'], $writes[16][1]);
        self::assertSame([7, 'community.membership_changed', 'messenger', 'instant'], $writes[17][1]);
        self::assertSame([7, 'community.membership_changed', 'email', 'daily'], $writes[18][1]);
        self::assertSame([7, 'community.post', 'email', 'daily'], $writes[20][1]);
    }

    public function testMailingSettingsRejectUnknownDeliveryModeBeforeWriting(): void
    {
        $writes = [];

        try {
            $this->post([
                'digest_delivery_time' => '09:00',
                'friend_request_messenger' => 'sometimes',
                'friend_request_email' => 'off',
            ], $writes, 'profile.mailings.update');
            self::fail('expected ValidationException');
        } catch (ValidationException) {
            self::assertSame([], $writes);
        }
    }

    public function testMailingSettingsRejectInvalidDigestTimeBeforeWriting(): void
    {
        $writes = [];

        try {
            $this->post([
                'digest_delivery_time' => '25:00',
            ], $writes, 'profile.mailings.update');
            self::fail('expected ValidationException');
        } catch (ValidationException) {
            self::assertSame([], $writes);
        }
    }
}
