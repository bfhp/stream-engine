<?php

declare(strict_types=1);

namespace Tests\Modules\API;

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Config;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\FileProcessing\FileStorage;
use StreamEngine\Core\FileProcessing\ImageProcessor;
use StreamEngine\Core\FileProcessing\MimeDetector;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\Poll;
use StreamEngine\Domain\PollOption;
use StreamEngine\Domain\User;
use StreamEngine\Modules\API\APIController;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\AuthService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\PollService;
use StreamEngine\Service\SettingsService;
use StreamEngine\Service\UploadService;
use Tests\Support\PhpInputStreamMock;

final class APIControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        $_GET = [];
        http_response_code(200);
    }

    private function makeModule(
        ?PageTree $pageTree = null,
        ?FeedService $feedService = null,
        ?PollService $pollService = null,
        ?Config $config = null,
        ?User $user = null,
    ): APIController {
        $db = $this->createStub(PdoDatabase::class);
        $authService = new AuthService(
            new UserRepository($db),
            new UserSessionRepository($db)
        );
        $uploadService = new UploadService(
            new UploadRepository($db),
            $this->createStub(MimeDetector::class),
            $this->createStub(FileStorage::class),
            $this->createStub(ImageProcessor::class),
            $this->createStub(AccessService::class),
            new TranslationManager('ru', 'en'),
            new SettingsService(new SettingsRepository($db)),
        );

        return new APIController(
            $db,
            // fromGlobals(): the context snapshots $_GET, so a test has to
            // set the query string *before* calling makeModule().
            new RequestContext(
                $user ?? new User(id: 1, email: 'user@example.com', role: AccessService::ROLE_USER),
                new DateTimeZone('UTC'),
                QueryParams::fromGlobals()
            ),
            $authService,
            $this->createStub(AccessService::class),
            $uploadService,
            $feedService ?? $this->createStub(FeedService::class),
            $pollService ?? $this->createStub(PollService::class),
            $pageTree ?? new PageTree([]),
            $config ?? new Config([]),
            new TranslationManager('ru', 'en'),
            $this->silentNotificationService(),
        );
    }

    /** @return array<string, array{string, string, array<string, string>}> */
    public static function authenticatedMutationProvider(): array
    {
        return [
            'create comment' => ['comments.thread', 'POST', ['slug' => '1']],
            'edit comment' => ['comments.item', 'PATCH', ['commentId' => '1']],
            'delete comment' => ['comments.item', 'DELETE', ['commentId' => '1']],
            'rate feed' => ['feed.rating', 'POST', ['slug' => '1']],
            'add favorite' => ['feed.favorite', 'POST', ['slug' => '1']],
            'remove favorite' => ['feed.favorite', 'DELETE', ['slug' => '1']],
            'vote in poll' => ['feed.poll.vote', 'POST', ['slug' => '1']],
            'upload file' => ['uploads.create', 'POST', []],
        ];
    }

    #[DataProvider('authenticatedMutationProvider')]
    public function testAuthenticatedMutationsRejectGuestsInTheController(
        string $action,
        string $method,
        array $args,
    ): void {
        $page = Page::api(1, 0, 'test', [$method], $action);
        $module = $this->makeModule(
            new PageTree([$page]),
            user: new User(id: 0, email: '', role: AccessService::ROLE_USER),
        );
        $_SERVER['REQUEST_METHOD'] = $method;

        $this->expectException(ForbiddenException::class);

        $module->callApi($page, $args);
    }

    private function silentNotificationService(): NotificationService
    {
        $preferenceDb = $this->createStub(PdoDatabase::class);
        $preferenceDb->method('fetchAll')->willReturn([
            ['channel' => 'messenger', 'delivery' => 'off'],
            ['channel' => 'email', 'delivery' => 'off'],
        ]);

        return new NotificationService(
            (new \ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($this->createStub(PdoDatabase::class)),
            new NotificationPreferenceRepository($preferenceDb),
            new UserRepository($this->createStub(PdoDatabase::class)),
            (new \ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
        );
    }

    public function testRegisterApiAddsPagesWithExplicitActions(): void
    {
        $apiV1 = new Page(
            id: 10,
            parentId: 1,
            pattern: 'v1',
            pageName: 'API v1',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'api.v1.index',
        );
        $tree = new PageTree([$apiV1]);

        APIController::registerApi(10, $tree);

        $children = $tree->findChildren(10);
        $actionsByPattern = [];
        foreach ($children as $child) {
            $actionsByPattern[$child->pattern] = $child->action;
        }

        $this->assertSame('feeds.list', $actionsByPattern['feeds']);
        $this->assertSame('auth.session', $actionsByPattern['auth']);
        $this->assertSame('uploads.create', $actionsByPattern['uploads']);
        $this->assertSame('comments.root', $actionsByPattern['comments']);
        $this->assertSame('cron.trigger', $actionsByPattern['cron']);

        $feedsPage = array_values(array_filter($children, static fn (Page $page): bool => $page->pattern === 'feeds'))[0];
        $commentsPage = array_values(array_filter($children, static fn (Page $page): bool => $page->pattern === 'comments'))[0];

        $feedChildren = $tree->findChildren($feedsPage->id);
        $commentChildren = $tree->findChildren($commentsPage->id);

        $this->assertCount(1, $feedChildren);
        $this->assertSame('feed.show', $feedChildren[0]->action);

        $feedIdPage = $feedChildren[0];
        $feedIdChildren = $tree->findChildren($feedIdPage->id);
        $feedIdActionsByPattern = [];
        foreach ($feedIdChildren as $child) {
            $feedIdActionsByPattern[$child->pattern] = $child->action;
        }

        $this->assertSame('feed.rating', $feedIdActionsByPattern['rating']);
        $this->assertSame('feed.favorite', $feedIdActionsByPattern['favorite']);

        $favoritePage = array_values(array_filter($feedIdChildren, static fn (Page $page): bool => $page->pattern === 'favorite'))[0];
        $this->assertSame(['POST', 'DELETE'], $favoritePage->requestMethods);

        $this->assertCount(1, $commentChildren);
        $this->assertSame('comments.thread', $commentChildren[0]->action);
        $this->assertSame(['GET', 'POST'], $commentChildren[0]->requestMethods);
    }

    public function testCallApiUsesActionForApiRoot(): void
    {
        $page = new Page(
            id: 20,
            parentId: 1,
            pattern: 'api',
            pageName: 'API',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'api.index',
        );

        $module = $this->makeModule(new PageTree([$page]));
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        $module->callApi($page);
        $output = ob_get_clean();

        $this->assertJson($output);
        $decoded = json_decode($output, true);
        $this->assertSame('StreamEngine API', $decoded['name']);
        $this->assertSame('/api/v1', $decoded['versions']['v1']);
    }

    public function testCallApiCreatesCommentViaThreadEndpoint(): void
    {
        $page = new Page(
            id: 21,
            parentId: 20,
            pattern: '{slug}',
            pageName: 'Comment Thread',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET', 'POST'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'comments.thread',
        );

        $createdComment = new \StreamEngine\Domain\Feed(
            id: 301,
            parentId: 55,
            ownerId: 1,
            type: 'comment',
            slug: null,
            title: '',
            description: null,
            imageUrl: null,
            content: 'Test comment',
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: null,
            authorDisplayName: 'Author 1',
            authorAvatarUrl: '/favicon.svg',
        );

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createComment', 'getFeedById'])
            ->getMock();

        $feedService
            ->expects($this->once())
            ->method('createComment')
            ->with(
                55,
                'Test comment',
                $this->callback(static fn (User $user): bool => $user->id === 1)
            )
            ->willReturn($createdComment);
        $feedService
            ->expects($this->once())
            ->method('getFeedById')
            ->with(55, $this->callback(static fn (User $user): bool => $user->id === 1))
            ->willReturn(new \StreamEngine\Domain\Feed(
                id: 55,
                parentId: null,
                ownerId: 2,
                type: 'blog-post',
                slug: 'post',
                title: 'Post',
                description: null,
                imageUrl: null,
                content: 'Post body',
                containerId: null,
                visibility: 'public',
                position: 0,
                createdAt: time(),
                relevance: null,
                canonicalUrl: 'https://example.test/post',
            ));

        $module = $this->makeModule(new PageTree([$page]), $feedService);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        PhpInputStreamMock::register(json_encode(['content' => "  Test comment  \n"]));

        try {
            ob_start();
            $module->callApi($page, ['slug' => 55]);
            $output = ob_get_clean();
        } finally {
            PhpInputStreamMock::restore();
        }

        $this->assertSame(201, http_response_code());
        $this->assertJson($output);
        $decoded = json_decode($output, true);
        $this->assertSame(301, $decoded['id']);
        $this->assertSame('comment', $decoded['type']);
    }

    public function testFeedsListAcceptsTypeAndTitleFilters(): void
    {
        $page = new Page(
            id: 22,
            parentId: 20,
            pattern: 'feeds',
            pageName: 'Feeds',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET', 'POST'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'feeds.list',
        );

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['listFeeds'])
            ->getMock();

        $feedService
            ->expects($this->once())
            ->method('listFeeds')
            ->with(
                $this->callback(static fn (User $user): bool => $user->id === 1),
                100,
                0,
                'publication',
                'мастер',
                null,
                null,
                null,
                null,
                null
            )
            ->willReturn([
                'data' => [],
                'meta' => [
                    'limit' => 100,
                    'offset' => 0,
                    'type' => 'publication',
                    'title' => 'мастер',
                ],
            ]);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [
            'type' => 'publication',
            'title' => 'мастер',
        ];

        $module = $this->makeModule(new PageTree([$page]), $feedService);

        ob_start();
        $module->callApi($page);
        $output = ob_get_clean();

        $this->assertJson($output);
        $decoded = json_decode($output, true);
        $this->assertSame('publication', $decoded['meta']['type']);
        $this->assertSame('мастер', $decoded['meta']['title']);
    }

    private function makeFavoritePage(): Page
    {
        return new Page(
            id: 23,
            parentId: 22,
            pattern: 'favorite',
            pageName: 'Feed Favorite',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['POST', 'DELETE'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'feed.favorite',
        );
    }

    public function testCallApiAddsFavoriteViaFavoriteEndpoint(): void
    {
        $page = $this->makeFavoritePage();

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFavorite'])
            ->getMock();

        $feedService
            ->expects($this->once())
            ->method('addFavorite')
            ->with(58, $this->callback(static fn (User $user): bool => $user->id === 1));

        $module = $this->makeModule(new PageTree([$page]), $feedService);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        ob_start();
        $module->callApi($page, ['slug' => 58]);
        $output = ob_get_clean();

        $this->assertJson($output);
        $decoded = json_decode($output, true);
        $this->assertSame(58, $decoded['feedId']);
        $this->assertTrue($decoded['favorited']);
    }

    public function testCallApiRemovesFavoriteViaFavoriteEndpoint(): void
    {
        $page = $this->makeFavoritePage();

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['removeFavorite'])
            ->getMock();

        $feedService
            ->expects($this->once())
            ->method('removeFavorite')
            ->with(58, $this->callback(static fn (User $user): bool => $user->id === 1));

        $module = $this->makeModule(new PageTree([$page]), $feedService);

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        ob_start();
        $module->callApi($page, ['slug' => 58]);
        ob_get_clean();

        $this->assertSame(204, http_response_code());
    }

    public function testCallApiFavoriteRequestFailsWithoutCsrfToken(): void
    {
        $page = $this->makeFavoritePage();

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFavorite'])
            ->getMock();

        $feedService->expects($this->never())->method('addFavorite');

        $module = $this->makeModule(new PageTree([$page]), $feedService);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        // No csrfToken cookie/header set.

        $this->expectException(ValidationException::class);

        $module->callApi($page, ['slug' => 58]);
    }

    /* ===============================
       feed.rating / comments.item / feed.readingProgress
    =============================== */

    private function makeRatingPage(): Page
    {
        return Page::api(
            id: 72,
            parentId: 10,
            pattern: 'rating',
            requestMethods: ['POST'],
            action: 'feed.rating',
        );
    }

    private function makeCommentItemPage(): Page
    {
        return Page::api(
            id: 73,
            parentId: 10,
            pattern: '{commentId}',
            requestMethods: ['PATCH', 'DELETE'],
            action: 'comments.item',
        );
    }

    private function makeReadingProgressPage(): Page
    {
        return Page::api(
            id: 74,
            parentId: 10,
            pattern: 'reading-progress',
            requestMethods: ['DELETE'],
            action: 'feed.readingProgress',
        );
    }

    /**
     * All three verify the token as their first statement, ahead of the method
     * check and of reading any body - so a rejected request never reaches the
     * service. Asserted with a FeedService whose mutating methods must never be
     * called, since "threw" alone wouldn't prove nothing happened first.
     *
     * @param list<string> $neverCalled
     */
    #[DataProvider('csrfGuardedHandlerProvider')]
    public function testCsrfIsRequiredBeforeAnythingElse(
        string $pageFactory,
        string $method,
        array $args,
        array $neverCalled
    ): void {
        $page = $this->{$pageFactory}();

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods($neverCalled)
            ->getMock();

        foreach ($neverCalled as $serviceMethod) {
            $feedService->expects($this->never())->method($serviceMethod);
        }

        $module = $this->makeModule(new PageTree([$page]), $feedService);

        $_SERVER['REQUEST_METHOD'] = $method;
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        $this->expectException(ValidationException::class);

        $module->callApi($page, $args);
    }

    /** @return array<string, array{string, string, array<string, mixed>, list<string>}> */
    public static function csrfGuardedHandlerProvider(): array
    {
        return [
            'rating' => ['makeRatingPage', 'POST', ['slug' => 58], ['rateFeed']],
            'edit a comment' => ['makeCommentItemPage', 'PATCH', ['commentId' => 90], ['editComment']],
            'delete a comment' => ['makeCommentItemPage', 'DELETE', ['commentId' => 90], ['deleteComment']],
            'reading progress' => [
                'makeReadingProgressPage',
                'DELETE',
                ['slug' => 58],
                ['removeReadProgressForFeed'],
            ],
        ];
    }

    /**
     * The method check sits *after* the token check, so a wrong method on a
     * valid request is a 405 rather than silence.
     *
     * @param array<string, mixed> $args
     */
    #[DataProvider('wrongMethodProvider')]
    public function testAWrongMethodIsRefusedWith405(string $pageFactory, string $method, array $args): void
    {
        $page = $this->{$pageFactory}();
        $module = $this->makeModule(new PageTree([$page]));

        $_SERVER['REQUEST_METHOD'] = $method;
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        try {
            $module->callApi($page, $args);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(405, $e->getHttpCode());
        }
    }

    /** @return array<string, array{string, string, array<string, mixed>}> */
    public static function wrongMethodProvider(): array
    {
        return [
            'a comment cannot be POSTed to' => ['makeCommentItemPage', 'POST', ['commentId' => 90]],
            'a comment cannot be GET here' => ['makeCommentItemPage', 'GET', ['commentId' => 90]],
            'reading progress is DELETE-only' => ['makeReadingProgressPage', 'POST', ['slug' => 58]],
        ];
    }

    /**
     * 204 with an **empty body**. It matters on both sides: CMS.api() skips
     * parsing a 204 precisely because a body there would break it (see its own
     * note), and the client's optimistic UI keys off the status.
     *
     * @param array<string, mixed> $args
     */
    #[DataProvider('noContentProvider')]
    public function testDeletionsAnswer204WithNoBody(
        string $pageFactory,
        array $args,
        string $serviceMethod
    ): void {
        $page = $this->{$pageFactory}();

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([$serviceMethod])
            ->getMock();

        $feedService->expects($this->once())->method($serviceMethod);

        $module = $this->makeModule(new PageTree([$page]), $feedService);

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        ob_start();
        $module->callApi($page, $args);
        $body = ob_get_clean();

        $this->assertSame(204, http_response_code());
        $this->assertSame('', $body);
    }

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function noContentProvider(): array
    {
        return [
            'delete a comment' => ['makeCommentItemPage', ['commentId' => 90], 'deleteComment'],
            'clear reading progress' => ['makeReadingProgressPage', ['slug' => 58], 'removeReadProgressForFeed'],
        ];
    }

    public function testRatingReturnsTheRecomputedAverage(): void
    {
        $page = $this->makeRatingPage();

        $feed = new \StreamEngine\Domain\Feed(
            id: 58,
            parentId: null,
            ownerId: 1,
            type: 'publication',
            slug: 'book',
            title: 'Книга',
            description: null,
            imageUrl: null,
            content: null,
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: null,
            relevance: null,
            canonicalUrl: null,
            ratingSum: 9,
            ratingCount: 2,
        );

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['rateFeed'])
            ->getMock();

        $feedService
            ->expects($this->once())
            ->method('rateFeed')
            // The value is cast to int here, so a string "4" from the client
            // arrives as 4 and a missing one as 0 for the service to reject.
            ->with(58, 4, $this->anything())
            ->willReturn($feed);

        $module = $this->makeModule(new PageTree([$page]), $feedService);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        PhpInputStreamMock::register(json_encode(['value' => '4']));
        ob_start();

        try {
            $module->callApi($page, ['slug' => 58]);
        } finally {
            $body = ob_get_clean();
            PhpInputStreamMock::restore();
        }

        $payload = json_decode($body, true);

        $this->assertSame(58, $payload['id']);
        $this->assertSame(9, $payload['ratingSum']);
        $this->assertSame(2, $payload['ratingCount']);
        // 9/2 - computed by the domain object, not stored.
        $this->assertSame(4.5, $payload['ratingAverage']);
    }

    public function testRatingTreatsAMissingValueAsZero(): void
    {
        $page = $this->makeRatingPage();

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['rateFeed'])
            ->getMock();

        // 0 is out of range, so the service rejects it - the handler's job is
        // only to pass a well-typed value along rather than guess a default.
        $feedService
            ->expects($this->once())
            ->method('rateFeed')
            ->with(58, 0, $this->anything())
            ->willThrowException(new ValidationException('Invalid rating value'));

        $module = $this->makeModule(new PageTree([$page]), $feedService);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        PhpInputStreamMock::register('{}');

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($page, ['slug' => 58]);
        } finally {
            PhpInputStreamMock::restore();
        }
    }

    /* ===============================
       auth.session
    =============================== */

    private function makeAuthPage(): Page
    {
        return Page::api(
            id: 70,
            parentId: 10,
            pattern: 'auth',
            requestMethods: ['GET', 'POST', 'DELETE'],
            action: 'auth.session',
        );
    }

    public function testCallApiAuthLoginFailsWithoutCsrfToken(): void
    {
        $page = $this->makeAuthPage();
        $module = $this->makeModule(new PageTree([$page]));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        // Checked before the body is read, so a rejected login never touches
        // the credentials it was sent.
        $this->expectException(ValidationException::class);

        $module->callApi($page, []);
    }

    public function testCallApiAuthLogoutFailsWithoutCsrfToken(): void
    {
        $page = $this->makeAuthPage();
        $module = $this->makeModule(new PageTree([$page]));

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        $this->expectException(ValidationException::class);

        $module->callApi($page, []);
    }

    /**
     * Wrong credentials are a ValidationException, not a `false` in the
     * payload - the client branches on the status, and an "authenticated:
     * false" 200 would read as success to anything that only checks response.ok.
     */
    public function testCallApiAuthRejectsBadCredentials(): void
    {
        $page = $this->makeAuthPage();
        $module = $this->makeModule(new PageTree([$page]));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        // makeModule()'s AuthService runs over a stubbed PdoDatabase, so
        // findByEmail() finds nobody and login() returns false.
        PhpInputStreamMock::register(json_encode(['email' => 'nobody@example.com', 'password' => 'x']));

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($page, []);
        } finally {
            PhpInputStreamMock::restore();
        }
    }

    public function testCallApiAuthReportsSessionStateOnGet(): void
    {
        $page = $this->makeAuthPage();
        // makeModule()'s context is a real user (roleLevel 1), not a guest.
        $module = $this->makeModule(new PageTree([$page]));

        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        ob_start();
        $module->callApi($page, []);
        $body = ob_get_clean();

        // No token required to read state - the login form needs this before it
        // has one.
        $this->assertSame(['authenticated' => true], json_decode($body, true));
    }

    public function testCallApiAuthRejectsAnUnsupportedMethod(): void
    {
        $page = $this->makeAuthPage();
        $module = $this->makeModule(new PageTree([$page]));

        $_SERVER['REQUEST_METHOD'] = 'PATCH';

        try {
            $module->callApi($page, []);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(405, $e->getHttpCode());
        }
    }

    /* ===============================
       feed.poll.vote
    =============================== */

    private function makePollVotePage(): Page
    {
        return Page::api(
            id: 71,
            parentId: 10,
            pattern: 'vote',
            requestMethods: ['POST'],
            action: 'feed.poll.vote',
        );
    }

    private function makePoll(int $votersCount = 3): Poll
    {
        return new Poll(
            id: 11,
            feedId: 58,
            question: 'Какой ритуал?',
            maxChoices: 1,
            allowRevote: false,
            resultsVisibility: Poll::VISIBILITY_AFTER_VOTE,
            closesAt: null,
            votersCount: $votersCount,
            createdAt: 0,
            updatedAt: 0,
            options: [
                new PollOption(id: 1, pollId: 11, text: 'Первый', position: 0, votesCount: 2),
                new PollOption(id: 2, pollId: 11, text: 'Второй', position: 1, votesCount: 1),
            ],
        );
    }

    public function testCallApiPollVoteFailsWithoutCsrfToken(): void
    {
        $page = $this->makePollVotePage();

        $pollService = $this->createMock(PollService::class);
        $pollService->expects($this->never())->method('vote');

        $module = $this->makeModule(new PageTree([$page]), pollService: $pollService);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        $this->expectException(ValidationException::class);

        $module->callApi($page, ['slug' => 58]);
    }

    public function testCallApiPollVoteReportsAFeedWithNoPollAsNotFound(): void
    {
        $page = $this->makePollVotePage();

        $pollService = $this->createStub(PollService::class);
        $pollService->method('getPollForFeed')->willReturn(null);

        $module = $this->makeModule(new PageTree([$page]), pollService: $pollService);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        try {
            $module->callApi($page, ['slug' => 58]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(404, $e->getHttpCode());
            $this->assertSame('not_found', $e->getCodeName());
        }
    }

    /**
     * A body without a usable `optionIds` becomes an empty selection rather than
     * an error - which is how a vote is retracted, and what stops a malformed
     * payload 500ing on an array function.
     */
    #[DataProvider('malformedVoteBodyProvider')]
    public function testCallApiPollVotePassesAnEmptySelectionForAMalformedBody(string $body): void
    {
        $page = $this->makePollVotePage();
        $poll = $this->makePoll();

        $pollService = $this->createMock(PollService::class);
        $pollService->method('getPollForFeed')->willReturn($poll);
        $pollService->method('canSeeResults')->willReturn(true);
        $pollService->method('getUserVotes')->willReturn([]);
        $pollService
            ->expects($this->once())
            ->method('vote')
            ->with(11, [], $this->anything())
            ->willReturn($poll);

        $module = $this->makeModule(new PageTree([$page]), pollService: $pollService);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        PhpInputStreamMock::register($body);
        ob_start();

        try {
            $module->callApi($page, ['slug' => 58]);
        } finally {
            ob_end_clean();
            PhpInputStreamMock::restore();
        }
    }

    /** @return array<string, array{string}> */
    public static function malformedVoteBodyProvider(): array
    {
        return [
            'empty body' => [''],
            'not json' => ['nope'],
            'no optionIds' => ['{}'],
            'optionIds is a string' => ['{"optionIds":"1"}'],
            'optionIds is null' => ['{"optionIds":null}'],
        ];
    }

    /**
     * Hidden results are `null`, never `0`. The distinction carries the whole
     * meaning: the client renders "скрыто" for null and a real bar for 0, so
     * collapsing them would announce "nobody voted" for every poll whose results
     * aren't visible yet.
     */
    public function testCallApiPollVoteHidesCountsRatherThanZeroingThem(): void
    {
        $page = $this->makePollVotePage();
        $poll = $this->makePoll(votersCount: 3);

        $pollService = $this->createStub(PollService::class);
        $pollService->method('getPollForFeed')->willReturn($poll);
        $pollService->method('vote')->willReturn($poll);
        $pollService->method('getUserVotes')->willReturn([1]);
        $pollService->method('canSeeResults')->willReturn(false);

        $module = $this->makeModule(new PageTree([$page]), pollService: $pollService);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        PhpInputStreamMock::register(json_encode(['optionIds' => [1]]));
        ob_start();

        try {
            $module->callApi($page, ['slug' => 58]);
        } finally {
            $body = ob_get_clean();
            PhpInputStreamMock::restore();
        }

        $payload = json_decode($body, true);

        $this->assertFalse($payload['canSeeResults']);
        $this->assertNull($payload['votersCount']);
        $this->assertNull($payload['options'][0]['votesCount']);
        $this->assertNull($payload['options'][1]['votesCount']);

        // The visible half is unaffected - the question and options still render.
        $this->assertSame('Какой ритуал?', $payload['question']);
        $this->assertSame('Первый', $payload['options'][0]['text']);
        // And the voter's own choice comes back regardless, since it is theirs.
        $this->assertSame([1], $payload['userVotes']);
    }

    public function testCallApiPollVoteReportsCountsWhenResultsAreVisible(): void
    {
        $page = $this->makePollVotePage();
        $poll = $this->makePoll(votersCount: 3);

        $pollService = $this->createStub(PollService::class);
        $pollService->method('getPollForFeed')->willReturn($poll);
        $pollService->method('vote')->willReturn($poll);
        $pollService->method('getUserVotes')->willReturn([1]);
        $pollService->method('canSeeResults')->willReturn(true);

        $module = $this->makeModule(new PageTree([$page]), pollService: $pollService);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        PhpInputStreamMock::register(json_encode(['optionIds' => [1]]));
        ob_start();

        try {
            $module->callApi($page, ['slug' => 58]);
        } finally {
            $body = ob_get_clean();
            PhpInputStreamMock::restore();
        }

        $payload = json_decode($body, true);

        // The other side of the test above: without this, an implementation
        // that returned null unconditionally would pass it.
        $this->assertTrue($payload['canSeeResults']);
        $this->assertSame(3, $payload['votersCount']);
        $this->assertSame(2, $payload['options'][0]['votesCount']);
        $this->assertSame(1, $payload['options'][1]['votesCount']);
    }

    private function makeCronPage(): Page
    {
        return Page::api(
            id: 60,
            parentId: 10,
            pattern: 'cron',
            requestMethods: ['GET'],
            action: 'cron.trigger',
        );
    }

    /**
     * Only the refusals are covered: the accepting path ends in
     * StreamEngine::triggerCronProcess(), a static that spawns a real process.
     * Injecting a CronTrigger is the seam that would make it testable - see
     * docs/TODO.md.
     *
     * These became testable at all only because the guards now throw instead of
     * `http_response_code(400); exit;`, which took the PHPUnit process with it.
     */
    public function testCallApiCronRefusesWhenNoKeyIsConfigured(): void
    {
        $page = $this->makeCronPage();
        // Config::cronKey() returns '' for an unset CRON_KEY, and the old
        // `$_GET['key'] !== $this->config->cronKey()` then let `?key=` through -
        // the two empty strings matched, so an unconfigured deployment had a
        // cron endpoint anyone could trigger.
        $_GET['key'] = '';

        $module = $this->makeModule(new PageTree([$page]), config: new Config([]));

        $this->expectException(ValidationException::class);

        $module->callApi($page, []);
    }

    public function testCallApiCronRefusesAMissingKey(): void
    {
        $page = $this->makeCronPage();
        unset($_GET['key']);

        $module = $this->makeModule(
            new PageTree([$page]),
            config: new Config(['CRON_KEY' => str_repeat('k', 32)])
        );

        $this->expectException(ValidationException::class);

        $module->callApi($page, []);
    }

    public function testCallApiCronRefusesAWrongKey(): void
    {
        $page = $this->makeCronPage();
        // Same length, so this is decided by the comparison rather than by any
        // shape check in front of it.
        $_GET['key'] = str_repeat('k', 31).'x';

        $module = $this->makeModule(
            new PageTree([$page]),
            config: new Config(['CRON_KEY' => str_repeat('k', 32)])
        );

        $this->expectException(ValidationException::class);

        $module->callApi($page, []);
    }

    /**
     * `?key[]=x` makes $_GET['key'] an array, and hash_equals() is typed
     * `string, string` - a TypeError, i.e. a 500, if the array ever reached
     * it. QueryParams::string() answers '' for a non-string, so it never
     * does, and the request is refused like any other wrong key.
     */
    public function testCallApiCronRefusesANonStringKeyInsteadOfCrashing(): void
    {
        $page = $this->makeCronPage();
        $_GET['key'] = [str_repeat('k', 32)];

        $module = $this->makeModule(
            new PageTree([$page]),
            config: new Config(['CRON_KEY' => str_repeat('k', 32)])
        );

        $this->expectException(ValidationException::class);

        $module->callApi($page, []);
    }

    private function makeFeedsPage(): Page
    {
        return Page::api(
            id: 31,
            parentId: 10,
            pattern: 'feeds',
            requestMethods: ['GET', 'POST'],
            action: 'feeds.list',
        );
    }

    private function makeFeedItemPage(): Page
    {
        return Page::api(
            id: 32,
            parentId: 31,
            pattern: '{slug}',
            requestMethods: ['GET', 'PATCH'],
            action: 'feed.show',
        );
    }

    /**
     * Creating and updating a feed are admin-only but were not CSRF-checked -
     * the admin SPA must supply a token as well. isAdmin() answers "who",
     * not "did they mean to".
     */
    public function testCallApiFeedCreateFailsWithoutCsrfToken(): void
    {
        $page = $this->makeFeedsPage();

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createFeed'])
            ->getMock();

        $feedService->expects($this->never())->method('createFeed');

        $module = $this->makeModule(new PageTree([$page]), $feedService);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        $this->expectException(ValidationException::class);

        $module->callApi($page, []);
    }

    public function testCallApiFeedUpdateFailsWithoutCsrfToken(): void
    {
        $page = $this->makeFeedItemPage();

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['updateFeed'])
            ->getMock();

        $feedService->expects($this->never())->method('updateFeed');

        $module = $this->makeModule(new PageTree([$page]), $feedService);

        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        $this->expectException(ValidationException::class);

        $module->callApi($page, ['slug' => 58]);
    }

    /**
     * Reading a feed is public, so the check has to sit inside the mutating
     * branches - requiring a token on GET would break every reader.
     */
    public function testCallApiFeedReadDoesNotRequireCsrfToken(): void
    {
        $page = $this->makeFeedItemPage();

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFeedById'])
            ->getMock();

        $feedService->expects($this->once())->method('getFeedById')->willReturn(null);

        $module = $this->makeModule(new PageTree([$page]), $feedService);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        ob_start();
        $module->callApi($page, ['slug' => 58]);
        ob_end_clean();
    }

    private function makeUploadsPage(): Page
    {
        return Page::api(
            id: 30,
            parentId: 10,
            pattern: 'uploads',
            requestMethods: ['POST'],
            action: 'uploads.create',
            accessRule: AccessService::ACCESS_AUTHENTICATED,
        );
    }

    /**
     * /api/v1/uploads was the one mutating endpoint in this controller with no
     * CSRF check, because it was also the only one no client reached through
     * CMS.api() - every caller hand-rolled a fetch with a FormData body and so
     * never sent the header.
     */
    public function testCallApiUploadsRequestFailsWithoutCsrfToken(): void
    {
        $page = $this->makeUploadsPage();
        $module = $this->makeModule(new PageTree([$page]));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        // No csrfToken cookie/header, and deliberately no $_FILES either: the
        // check has to come before the "No file" guard, so a rejected request
        // never looks at the upload at all. A 400 here instead of the
        // exception would mean the ordering slipped.
        unset($_FILES['file']);

        $this->expectException(ValidationException::class);

        $module->callApi($page, []);
    }

    /**
     * The other half - a matching token must get past the check, or every
     * upload on the site is broken. Asserted via the "No file" branch just
     * behind it, which needs no upload pipeline to reach.
     */
    public function testCallApiUploadsRequestPassesCsrfCheckWithMatchingToken(): void
    {
        $page = $this->makeUploadsPage();
        $module = $this->makeModule(new PageTree([$page]));

        $token = str_repeat('c', 64);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        unset($_FILES['file']);

        ob_start();
        $module->callApi($page, []);
        $body = ob_get_clean();

        $this->assertSame(400, http_response_code());
        $this->assertSame(['error' => 'No file'], json_decode($body, true));
    }
}
