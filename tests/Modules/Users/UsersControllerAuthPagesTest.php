<?php

declare(strict_types=1);

namespace Tests\Modules\Users;

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\Config;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Users\UsersController;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\UserService;
use Tests\Support\PhpInputStreamMock;

/**
 * Registration and password recovery: the four entry points a stranger can
 * reach without an account.
 *
 * Untested until now for a mechanical reason - every branch is chosen by a
 * query parameter, and these were read with `filter_input(INPUT_GET, …)`,
 * which under the CLI SAPI returns null for everything no matter what a test
 * does. Now that the query string comes off `RequestContext`, the branches are
 * ordinary code. This file is the payoff for that seam.
 *
 * They deserve the attention: `showRegisterPage()` verifies an email token and
 * *logs the visitor in* on the strength of it, and `showRetrievePage()` decides
 * whether a password-reset form is shown at all. The register API is the one
 * public endpoint that creates an account.
 *
 * Not covered here, deliberately: the successful email-verification branch
 * ends in `header()` then `exit`, which would take the test runner with it.
 * That needs the same `Response` value object `handleRequest()` wants - see
 * docs/TODO.md.
 */
final class UsersControllerAuthPagesTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        $_GET = [];

        PhpInputStreamMock::restore();
        http_response_code(200);

        parent::tearDown();
    }

    /**
     * The controller with a real UserService over a stubbed database, so the
     * token lookups are driven by what fetchOne() returns.
     *
     * `$_GET` has to be set *before* this: RequestContext snapshots the query
     * string, which is the whole point of it.
     */
    private function makeModule(?PdoDatabase $db = null, ?User $user = null): UsersController
    {
        $db ??= $this->createStub(PdoDatabase::class);

        $reflection = new ReflectionClass(UsersController::class);
        $module = $reflection->newInstanceWithoutConstructor();

        $reflection->getParentClass()->getProperty('db')->setValue($module, $db);
        $reflection->getParentClass()->getProperty('context')->setValue(
            $module,
            new RequestContext(
                $user ?? new User(0, '', AccessService::ROLE_USER),
                new DateTimeZone('UTC'),
                QueryParams::fromGlobals()
            )
        );

        $tm = new TranslationManager('ru', 'en');

        $reflection->getProperty('userService')->setValue(
            $module,
            new UserService(
                new UserRepository($db),
                $db,
                (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
                $tm,
                new NotificationService(
                    (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
                    new NotificationDeliveryRepository($db),
                    new NotificationPreferenceRepository($db),
                    new UserRepository($db),
                    (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
                ),
                new UserSessionRepository($db),
                new Config([]),
            )
        );
        $reflection->getProperty('tm')->setValue($module, $tm);
        $reflection->getProperty('formatter')->setValue($module, new Formatter($tm, 'ru'));

        return $module;
    }

    private function page(string $action): Page
    {
        return new Page(
            id: 40,
            parentId: 1,
            pattern: $action === 'user.register' ? 'register' : 'retrieve',
            pageName: 'Страница',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action,
        );
    }

    private function withCsrf(): void
    {
        $token = str_repeat('a', 64);
        $_COOKIE['csrfToken'] = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
    }

    /* ===============================
       The registration page
    =============================== */

    public function testABareVisitGetsTheRegistrationForm(): void
    {
        $view = $this->makeModule()->show($this->page('user.register'));

        $this->assertSame('modules/users/register1.twig', $view->template);
    }

    public function testAfterSubmittingTheVisitorIsToldToCheckTheirMail(): void
    {
        // `?success=1` is set by the redirect the form's own POST performs.
        $_GET['success'] = '1';

        $view = $this->makeModule()->show($this->page('user.register'));

        $this->assertSame('modules/users/register2.twig', $view->template);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notSuccessProvider(): array
    {
        return [
            // An exact string comparison, so anything else is a bare visit.
            'zero' => ['0'],
            'true' => ['true'],
            'empty' => [''],
            'yes' => ['yes'],
        ];
    }

    #[DataProvider('notSuccessProvider')]
    public function testOnlyTheLiteralOneCountsAsSuccess(string $value): void
    {
        $_GET['success'] = $value;

        $this->assertSame(
            'modules/users/register1.twig',
            $this->makeModule()->show($this->page('user.register'))->template
        );
    }

    /**
     * A dead verification link shows a page rather than an error.
     *
     * `verifyEmail()` throws for a token that does not exist or has expired,
     * and this is the branch that catches it - which matters because the
     * commonest way to arrive here is clicking yesterday's link, or clicking
     * today's twice (the row is deleted on success).
     */
    public function testAnUnusableVerificationTokenShowsTheVerifyPage(): void
    {
        $_GET['token'] = 'expired-or-fake';

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(null);

        $view = $this->makeModule($db)->show($this->page('user.register'));

        $this->assertSame('modules/users/verify.twig', $view->template);
    }

    public function testAnAlreadyAuthenticatedVisitorIsRefused(): void
    {
        // Not merely pointless: the success branch would log them in *as
        // somebody else*, whoever the token belongs to.
        $module = $this->makeModule(null, new User(id: 5, email: 'a@b.c', role: AccessService::ROLE_USER));

        $this->expectException(ForbiddenException::class);

        $module->show($this->page('user.register'));
    }

    /* ===============================
       The recovery page
    =============================== */

    public function testWithoutATokenTheVisitorIsAskedForTheirEmail(): void
    {
        $view = $this->makeModule()->show($this->page('user.retrieve'));

        $this->assertSame('modules/users/retrieve.twig', $view->template);
        $this->assertSame('', $view->data['token']);
    }

    public function testAValidTokenGetsTheNewPasswordForm(): void
    {
        $_GET['token'] = 'a-live-token';

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['token' => 'a-live-token', 'created_at' => time() - 60]);

        $view = $this->makeModule($db)->show($this->page('user.retrieve'));

        $this->assertSame('modules/users/reset.twig', $view->template);
        // The form posts it back, so it has to survive into the view.
        $this->assertSame('a-live-token', $view->data['token']);
    }

    public function testAnUnknownTokenIsNotFound(): void
    {
        $_GET['token'] = 'never-issued';

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Invalid token');

        $this->makeModule($db)->show($this->page('user.retrieve'));
    }

    /**
     * One hour. Without the expiry check a token would stay usable for as long
     * as the row survived, and these rows are only swept by `users:cleanup`.
     */
    public function testAnExpiredTokenIsRefused(): void
    {
        $_GET['token'] = 'stale';

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['created_at' => time() - 3601]);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Token expired');

        $this->makeModule($db)->show($this->page('user.retrieve'));
    }

    public function testATokenAtFiftyNineMinutesStillWorks(): void
    {
        // The boundary, from the usable side: the window is generous enough
        // that a visitor reading their mail an hour later is not sent back to
        // the start for nothing.
        $_GET['token'] = 'nearly-stale';

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['created_at' => time() - 3540]);

        $this->assertSame(
            'modules/users/reset.twig',
            $this->makeModule($db)->show($this->page('user.retrieve'))->template
        );
    }

    public function testTheRecoveryPageIsForGuestsOnly(): void
    {
        $module = $this->makeModule(null, new User(id: 5, email: 'a@b.c', role: AccessService::ROLE_USER));

        $this->expectException(ForbiddenException::class);

        $module->show($this->page('user.retrieve'));
    }

    /* ===============================
       The registration endpoint
    =============================== */

    private function registerPage(): Page
    {
        return Page::api(
            id: 41,
            parentId: 10,
            pattern: 'users',
            requestMethods: ['GET', 'POST'],
            action: 'users.collection',
        );
    }

    /**
     * The same fall-through MessagesController had, and the same fix: an
     * unsupported verb reaches an explicit 405 rather than running off the end
     * of the handler and returning an empty 200. Unreachable from outside -
     * handleRequest() checks the route's `requestMethods` first - so this is
     * defence against those two lists drifting apart.
     */
    #[DataProvider('unsupportedMethodProvider')]
    public function testAnUnsupportedMethodOnTheUsersCollectionIsAFourOhFive(string $method): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = $method;

        try {
            $module->callApi($this->registerPage());
            $this->fail('an unhandled method must not return normally');
        } catch (ValidationException $e) {
            $this->assertSame(405, $e->getHttpCode());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedMethodProvider(): array
    {
        return [
            'PUT' => ['PUT'],
            'DELETE' => ['DELETE'],
            'PATCH' => ['PATCH'],
        ];
    }

    public function testRegisteringRequiresACsrfToken(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        $this->expectException(ValidationException::class);

        $module->callApi($this->registerPage());
    }

    public function testAnAuthenticatedVisitorCannotRegisterAgain(): void
    {
        $module = $this->makeModule(null, new User(id: 5, email: 'a@b.c', role: AccessService::ROLE_USER));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();

        // Before the CSRF check, so somebody already signed in gets told the
        // truth rather than a token error.
        $this->expectException(ForbiddenException::class);

        $module->callApi($this->registerPage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedBodyProvider(): array
    {
        return [
            'not json' => ['<html>502</html>'],
            'empty' => [''],
            'json null' => ['null'],
            'a bare string' => ['"x"'],
            'a list' => ['[]'],
        ];
    }

    /**
     * The bug this file's source change was for. `array_key_exists('login',
     * null)` is a TypeError, so a non-JSON body on the account-creation
     * endpoint was a 500 - logged as a bug, answered as one - rather than the
     * 400 it is.
     */
    #[DataProvider('malformedBodyProvider')]
    public function testAMalformedBodyIsRefusedRatherThanFatal(string $body): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        PhpInputStreamMock::register($body);

        $this->expectException(ValidationException::class);

        $module->callApi($this->registerPage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function honeypotProvider(): array
    {
        return [
            // A bot that fills every field it can see.
            'filled in' => ['{"login":"admin","email":"a@b.co","password":"longenough1"}'],
            // Absent means the submission did not come from our form.
            'absent' => ['{"email":"a@b.co","password":"longenough1"}'],
            'null' => ['{"login":null,"email":"a@b.co","password":"longenough1"}'],
            // An array used to reach mb_strlen() and be a fatal.
            'an array' => ['{"login":[],"email":"a@b.co","password":"longenough1"}'],
            'a number' => ['{"login":0,"email":"a@b.co","password":"longenough1"}'],
        ];
    }

    #[DataProvider('honeypotProvider')]
    public function testTheHoneypotMustBePresentAndEmpty(string $body): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        PhpInputStreamMock::register($body);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Login must be a valid username');

        $module->callApi($this->registerPage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badCredentialsProvider(): array
    {
        return [
            'no email' => ['{"login":"","password":"longenough1"}'],
            'not an email' => ['{"login":"","email":"nonsense","password":"longenough1"}'],
            // Arrays again: these reached trim() and register() as arrays.
            'email as an array' => ['{"login":"","email":[],"password":"longenough1"}'],
            'password as an array' => ['{"login":"","email":"a@b.co","password":[]}'],
            'no password' => ['{"login":"","email":"a@b.co"}'],
            'password too short' => ['{"login":"","email":"a@b.co","password":"short"}'],
        ];
    }

    /**
     * Past the honeypot, UserService validates - and each of these used to be
     * a different kind of nothing: a fatal for the arrays, and a 403 for the
     * rest before ApiErrorResponse.
     */
    #[DataProvider('badCredentialsProvider')]
    public function testCredentialsAreValidatedBeforeAnythingIsWritten(string $body): void
    {
        $db = $this->createMock(PdoDatabase::class);
        // Nothing is inserted for a request that never gets past validation.
        $db->expects($this->never())->method('execute');

        $module = $this->makeModule($db);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        PhpInputStreamMock::register($body);

        $this->expectException(ValidationException::class);

        $module->callApi($this->registerPage());
    }

    /* ===============================
       Two small formatters
    =============================== */

    private function call(UsersController $module, string $method, mixed ...$args): mixed
    {
        $m = (new ReflectionClass(UsersController::class))->getMethod($method);

        return $m->invoke($module, ...$args);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function fileSizeProvider(): array
    {
        return [
            'bytes' => [0, '0 Б'],
            'just under a kilobyte' => [1023, '1023 Б'],
            'exactly a kilobyte' => [1024, '1 КБ'],
            'rounded to whole kilobytes' => [1536, '2 КБ'],
            'just under a megabyte' => [1048575, '1024 КБ'],
            'exactly a megabyte' => [1048576, '1.0 МБ'],
            // One decimal for megabytes, none for kilobytes - a 2.5 MB
            // attachment is worth distinguishing from a 2.0 MB one, a 15 KB
            // one is not.
            'megabytes keep a decimal' => [2621440, '2.5 МБ'],
        ];
    }

    #[DataProvider('fileSizeProvider')]
    public function testAttachmentSizesAreHumanReadable(int $bytes, string $expected): void
    {
        $this->assertSame($expected, $this->call($this->makeModule(), 'formatFileSize', $bytes));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function excerptProvider(): array
    {
        return [
            'markup is stripped' => ['<p>Привет <b>мир</b></p>', 'Привет мир'],
            'whitespace is collapsed' => ["<p>Привет\n\n   мир</p>", 'Привет мир'],
            'edges are trimmed' => ['<p>   Привет   </p>', 'Привет'],
            'nothing at all' => ['', ''],
            'markup only' => ['<hr><br>', ''],
        ];
    }

    #[DataProvider('excerptProvider')]
    public function testAnExcerptIsPlainCollapsedText(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->call($this->makeModule(), 'buildExcerpt', $html));
    }

    public function testALongExcerptIsCutAtAWordBoundary(): void
    {
        $text = str_repeat('слово ', 60);

        $excerpt = $this->call($this->makeModule(), 'buildExcerpt', $text, 20);

        // Cut on a space and ellipsised, so it does not end mid-word - this is
        // the meta description and the card teaser.
        $this->assertSame('слово слово слово…', $excerpt);
    }

    public function testAnExcerptShorterThanTheLimitGetsNoEllipsis(): void
    {
        $this->assertSame('Привет', $this->call($this->makeModule(), 'buildExcerpt', 'Привет', 20));
    }

    public function testASingleWordLongerThanTheLimitIsStillCut(): void
    {
        // No space to cut at, so the whole limit is used and ellipsised.
        $excerpt = $this->call($this->makeModule(), 'buildExcerpt', str_repeat('а', 50), 10);

        $this->assertSame(str_repeat('а', 10).'…', $excerpt);
    }
}
