<?php

declare(strict_types=1);

namespace Tests\Modules\Profile;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\Config;
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
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\UserService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;
use Tests\Support\PhpInputStreamMock;

/**
 * The "Email and password" tab's endpoint and the page its confirmation
 * link opens. What each change does is UserServiceTest's business; these
 * pin the wiring: the routes exist, the endpoint goes through the shared
 * profile checks, and the confirmation page only changes the account on
 * POST.
 */
final class ProfileSecurityTest extends TestCase
{
    private const string TOKEN = 'mailed-token';

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        PhpInputStreamMock::restore();
        http_response_code(200);

        parent::tearDown();
    }

    public function testRegistersTheSecurityEndpointAndThePublicConfirmationPage(): void
    {
        $tree = new PageTree([$this->rootPage()]);

        ProfileController::registerApi(100, $tree);

        $endpoint = $tree->findByAction('profile.security.update');
        self::assertNotNull($endpoint);
        self::assertSame('security', $endpoint->pattern);
        self::assertSame(['POST'], $endpoint->requestMethods);
        self::assertSame($tree->findByAction('profile.update')?->id, $endpoint->parentId);

        $confirm = $tree->findByAction('profile.email.confirm');
        self::assertNotNull($confirm);
        self::assertSame(1, $confirm->parentId);
        self::assertSame('confirm-email', $confirm->pattern);
        self::assertSame(['GET', 'POST'], $confirm->requestMethods);
        self::assertSame('html', $confirm->responseType);
        self::assertSame(AccessService::ACCESS_PUBLIC, $confirm->accessRule);
    }

    public function testTheEndpointRefusesAChangeWithoutTheCurrentPassword(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('execute');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';
        PhpInputStreamMock::register(json_encode(['email' => 'user@example.com', 'password' => 'new-password-123']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage((new TranslationManager('ru', 'en'))->trans('profile.current_password_required'));

        ob_start();
        try {
            $this->controller($db, new User(7, 'user@example.com'))->callApi($this->page('profile.security.update'), []);
        } finally {
            ob_end_clean();
        }
    }

    public function testGetShowsThePendingAddressWithoutChangingTheAccount(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['user_id' => 7, 'new_email' => 'new@example.com']);
        $db->expects($this->never())->method('execute');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $view = $this->controller($db)->show($this->page('profile.email.confirm'));

        self::assertSame('modules/profile/email-confirm.twig', $view->template);
        self::assertSame('new@example.com', $view->data['pendingEmail']);
        self::assertNull($view->data['confirmedEmail']);
    }

    public function testPostAppliesTheChangeWithoutASignedInSession(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturnCallback(
            static fn (string $sql): ?array => match (true) {
                str_contains($sql, 'FROM email_verifications') => ['user_id' => 7, 'new_email' => 'new@example.com'],
                str_contains($sql, 'id <> ?') => null,
                default => ['id' => 7, 'email' => 'user@example.com', 'role' => 'user'],
            }
        );
        $statements = [];
        $db->method('execute')->willReturnCallback(
            function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 1;
            }
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $view = $this->controller($db)->show($this->page('profile.email.confirm'));

        self::assertSame('new@example.com', $view->data['confirmedEmail']);
        self::assertContains('UPDATE users SET email = ? WHERE id = ?', $statements);
    }

    public function testAnUnknownLinkAnswersNotFound(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(null);
        $db->expects($this->never())->method('execute');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $view = $this->controller($db)->show($this->page('profile.email.confirm'));

        self::assertNull($view->data['pendingEmail']);
        self::assertNull($view->data['confirmedEmail']);
        self::assertSame(404, http_response_code());
    }

    private function controller(PdoDatabase $db, ?User $user = null): ProfileController
    {
        $tree = new PageTree([]);
        $tm = new TranslationManager('ru', 'en');
        $mail = $this->createStub(MailService::class);

        return new ProfileController(
            $db,
            new RequestContext(
                $user ?? new User(0, '', AccessService::ROLE_USER),
                new DateTimeZone('UTC'),
                new QueryParams(['token' => self::TOKEN]),
            ),
            $tm,
            $tree,
            new UrlGenerator($tree, new FakeFeedRepository([]), new ArrayCache()),
            (new ReflectionClass(NotificationService::class))->newInstanceWithoutConstructor(),
            new UserService(
                new UserRepository($db),
                $db,
                $mail,
                $tm,
                (new ReflectionClass(NotificationService::class))->newInstanceWithoutConstructor(),
                new UserSessionRepository($db),
                new Config(['APP_SECRET' => 'test-secret', 'SITE_URL' => 'https://example.com']),
            ),
        );
    }

    private function page(string $action): Page
    {
        return new Page(
            id: 2,
            parentId: 1,
            pattern: $action === 'profile.email.confirm' ? 'confirm-email' : 'security',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            requestMethods: ['GET', 'POST'],
            responseType: $action === 'profile.email.confirm' ? 'html' : 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action,
        );
    }

    private function rootPage(): Page
    {
        return new Page(
            id: 1,
            parentId: null,
            pattern: '',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
        );
    }
}
