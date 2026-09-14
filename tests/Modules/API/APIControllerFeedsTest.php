<?php

declare(strict_types=1);

namespace Tests\Modules\API;

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Config;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\Upload;
use StreamEngine\Domain\User;
use StreamEngine\Modules\API\APIController;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\AuthService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\PollService;
use StreamEngine\Service\UploadService;
use Tests\Support\PhpInputStreamMock;

/**
 * The read and write halves of `/api/v1/feeds` - the group `APIControllerTest`
 * left alone. Between them `handleFeedIdRequest`, `handleFeedsRequest`,
 * `handleCommentsRequest`, `handleCommentItemRequest`, `handleUploadsRequest`
 * and `handleApiV1Request` were 85 of that controller's 105 uncovered lines,
 * and the CSRF guards in front of them were the only part anything asserted.
 *
 * The handlers are thin by design - verify the token, branch on the method,
 * hand the arguments to a service - so what these tests are actually about is
 * the argument marshalling in between, which is where the surprises are:
 *
 * - **PATCH preserves omitted fields.** `handleFeedIdRequest` forwards only
 *   keys actually present in the JSON object; `FeedService::updateFeed()`
 *   merges those over the stored feed before calling the whole-row repository
 *   update. Explicit null remains distinct from an omitted key.
 * - List and search limits have distinct defaults but share the same bounds.
 * - Structured comment content is rejected before PHP can cast it to `Array`.
 * - Every action rejects methods outside the page contract with a 405.
 */
final class APIControllerFeedsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken'], $_FILES['file']);
        $_GET = [];
        http_response_code(200);
        PhpInputStreamMock::restore();

        parent::tearDown();
    }

    /**
     * Distinct from `APIControllerTest::makeModule()` in one respect that
     * matters here: `isAdmin` is settable, because both writes on this group
     * are admin-gated and a plain stub answers false.
     */
    private function makeModule(
        ?FeedService $feedService = null,
        ?UploadService $uploadService = null,
        bool $isAdmin = false,
        ?PageTree $pageTree = null,
    ): APIController {
        $db = $this->createStub(PdoDatabase::class);

        $accessService = $this->createStub(AccessService::class);
        $accessService->method('isAdmin')->willReturn($isAdmin);

        return new APIController(
            $db,
            // The context snapshots $_GET, so a test sets the query string
            // *before* calling this.
            new RequestContext(
                new User(id: 1, email: 'user@example.com', role: AccessService::ROLE_USER),
                new DateTimeZone('UTC'),
                QueryParams::fromGlobals()
            ),
            // A real one, over the stubbed database: AuthService is final, so
            // it cannot be doubled - and nothing in this group touches it.
            new AuthService(new UserRepository($db), new UserSessionRepository($db)),
            $accessService,
            $uploadService ?? $this->createStub(UploadService::class),
            $feedService ?? $this->createStub(FeedService::class),
            $this->createStub(PollService::class),
            $pageTree ?? new PageTree([]),
            new Config([]),
            new TranslationManager('ru', 'en'),
            (new \ReflectionClass(NotificationService::class))->newInstanceWithoutConstructor(),
        );
    }

    /**
     * @param list<string> $methods the FeedService methods this test drives
     */
    private function feedServiceMock(array $methods): FeedService
    {
        return $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
    }

    /**
     * For the tests that only need `updateFeed`'s argument, not a record of the
     * call: a mock without expectations is a stub, and PHPUnit says so.
     *
     * @param-out array<string, mixed>|null $received the `$data` it was handed
     */
    private function feedServiceCapturing(?array &$received): FeedService
    {
        $stub = $this->createStub(FeedService::class);
        $stub->method('updateFeed')->willReturnCallback(
            function (int $id, array $data) use (&$received): Feed {
                $received = $data;

                return $this->feed();
            }
        );

        return $stub;
    }

    private function feed(int $id = 58, string $type = 'publication', ?string $title = 'Мастер'): Feed
    {
        return new Feed(
            id: $id,
            parentId: 7,
            ownerId: 1,
            type: $type,
            slug: 'master',
            title: $title,
            description: 'Описание',
            imageUrl: null,
            content: 'Текст',
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: 1700000000,
            relevance: null,
            canonicalUrl: '/publication/master',
        );
    }

    private function withToken(string $method): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';
    }

    /** @return array<string, mixed> the decoded body the handler echoed */
    private function capture(APIController $module, Page $page, array $args = []): array
    {
        ob_start();

        try {
            $module->callApi($page, $args);
        } finally {
            $body = ob_get_clean();
        }

        self::assertJson($body);

        return json_decode($body, true);
    }

    /* ===============================
       Pages
    =============================== */

    private function feedsPage(): Page
    {
        return Page::api(
            id: 22,
            parentId: 10,
            pattern: 'feeds',
            requestMethods: ['GET', 'POST'],
            action: 'feeds.list',
        );
    }

    private function feedItemPage(): Page
    {
        return Page::api(
            id: 23,
            parentId: 22,
            pattern: '{slug}',
            requestMethods: ['GET', 'PATCH'],
            action: 'feed.show',
        );
    }

    private function commentsThreadPage(): Page
    {
        return Page::api(
            id: 24,
            parentId: 21,
            pattern: '{slug}',
            requestMethods: ['GET', 'POST'],
            action: 'comments.thread',
        );
    }

    private function commentItemPage(): Page
    {
        return Page::api(
            id: 25,
            parentId: 24,
            pattern: '{commentId}',
            requestMethods: ['PATCH', 'DELETE'],
            action: 'comments.item',
        );
    }

    private function uploadsPage(): Page
    {
        return Page::api(
            id: 26,
            parentId: 10,
            pattern: 'uploads',
            requestMethods: ['POST'],
            action: 'uploads.create',
            accessRule: AccessService::ACCESS_AUTHENTICATED,
        );
    }

    /* ===============================
       GET /api/v1/feeds/{id}
    =============================== */

    public function testAFeedIsReadWithoutATokenOrAnAdminCheck(): void
    {
        $feedService = $this->feedServiceMock(['getFeedById']);
        $feedService->expects($this->once())
            ->method('getFeedById')
            ->with(58, $this->callback(static fn (User $u): bool => $u->id === 1))
            ->willReturn($this->feed());

        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        // isAdmin false: reads are for everyone the ACL inside
        // getFeedById() lets through, and the admin gate belongs to PATCH.
        $decoded = $this->capture($this->makeModule($feedService), $this->feedItemPage(), ['slug' => 58]);

        self::assertSame(58, $decoded['id']);
        self::assertSame('Мастер', $decoded['title']);
    }

    public function testTheFeedIdComesFromTheRouteRatherThanTheBody(): void
    {
        $feedService = $this->feedServiceMock(['getFeedById']);
        $feedService->expects($this->once())->method('getFeedById')->with(58)->willReturn($this->feed());

        $_SERVER['REQUEST_METHOD'] = 'GET';

        // A body on a GET is ignored outright - the id is the URL's, so a
        // client cannot read feed 58's row while asking about 99.
        PhpInputStreamMock::register(json_encode(['id' => 99]));

        $this->capture($this->makeModule($feedService), $this->feedItemPage(), ['slug' => 58]);
    }

    public function testAMissingRouteIdIsAskedAboutAsZero(): void
    {
        // `(int) ($args['slug'] ?? 0)` - unreachable through the router, which
        // only dispatches this action for a matched {slug}, but it decides what
        // happens if someone registers the action on a static pattern: the
        // service is asked about feed 0 and answers 404, rather than the
        // controller crashing before it gets there.
        $feedService = $this->feedServiceMock(['getFeedById']);
        $feedService->expects($this->once())->method('getFeedById')->with(0)->willReturn($this->feed());

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->feedItemPage());
    }

    /* ===============================
       PATCH /api/v1/feeds/{id}
    =============================== */

    public function testANonAdminCannotEditAFeedEvenWithAValidToken(): void
    {
        $feedService = $this->feedServiceMock(['updateFeed']);
        // The point of the assertion: refused *before* the service, so the
        // admin check is a real gate and not a second opinion after the write.
        $feedService->expects($this->never())->method('updateFeed');

        $this->withToken('PATCH');
        PhpInputStreamMock::register(json_encode(['title' => 'Новое']));

        $this->expectException(ForbiddenException::class);

        $this->makeModule($feedService)->callApi($this->feedItemPage(), ['slug' => 58]);
    }

    public function testAnAdminEditPassesEveryFieldThrough(): void
    {
        $feedService = $this->feedServiceMock(['updateFeed']);
        $feedService->expects($this->once())
            ->method('updateFeed')
            ->with(
                58,
                [
                    'title' => 'Новое имя',
                    'slug' => 'novoe-imya',
                    'parentId' => 7,
                    'type' => 'publication',
                    'description' => 'Описание',
                    'imageUrl' => '/uploads/a.jpg',
                    'content' => 'Текст',
                ],
                $this->callback(static fn (User $u): bool => $u->id === 1)
            )
            ->willReturn($this->feed(title: 'Новое имя'));

        $this->withToken('PATCH');
        PhpInputStreamMock::register(json_encode([
            'title' => 'Новое имя',
            'slug' => 'novoe-imya',
            'parentId' => 7,
            'type' => 'publication',
            'description' => 'Описание',
            'imageUrl' => '/uploads/a.jpg',
            'content' => 'Текст',
        ]));

        $decoded = $this->capture(
            $this->makeModule($feedService, isAdmin: true),
            $this->feedItemPage(),
            ['slug' => 58]
        );

        self::assertSame('Новое имя', $decoded['title']);
    }

    public function testAPartialPatchForwardsOnlyFieldsPresentInTheBody(): void
    {
        $received = null;
        $feedService = $this->feedServiceCapturing($received);

        $this->withToken('PATCH');
        PhpInputStreamMock::register(json_encode(['title' => 'Только заголовок']));

        $this->capture($this->makeModule($feedService, isAdmin: true), $this->feedItemPage(), ['slug' => 58]);

        self::assertSame('Только заголовок', $received['title']);
        self::assertSame(['title'], array_keys($received));
    }

    public function testMetadataIsOnlyForwardedWhenTheBodyMentionsIt(): void
    {
        // `array_key_exists`, not `??`: FeedService::updateFeed() reads the
        // key's presence to decide between replacing the metadata and leaving
        // it, so forwarding a default `[]` would delete every row a book has.
        $received = null;
        $feedService = $this->feedServiceCapturing($received);

        $this->withToken('PATCH');
        PhpInputStreamMock::register(json_encode(['title' => 'Без метаданных']));

        $this->capture($this->makeModule($feedService, isAdmin: true), $this->feedItemPage(), ['slug' => 58]);

        self::assertArrayNotHasKey('metadata', $received);
    }

    public function testAnExplicitlyEmptyMetadataListIsForwardedAsADeletion(): void
    {
        $received = null;
        $feedService = $this->feedServiceCapturing($received);

        $this->withToken('PATCH');
        // The distinction the key test above protects: `[]` sent on purpose
        // means "this book has no metadata any more", and must survive.
        PhpInputStreamMock::register(json_encode(['title' => 'Пусто', 'metadata' => []]));

        $this->capture($this->makeModule($feedService, isAdmin: true), $this->feedItemPage(), ['slug' => 58]);

        self::assertArrayHasKey('metadata', $received);
        self::assertSame([], $received['metadata']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedBodyProvider(): array
    {
        return [
            'not JSON at all' => ['<html>'],
            'a JSON scalar' => ['"just a string"'],
            'a JSON null' => ['null'],
            'an empty body' => [''],
        ];
    }

    /** A malformed body becomes an empty, non-destructive patch. */
    #[DataProvider('malformedBodyProvider')]
    public function testAMalformedEditBodyIsTreatedAsAnEmptyOne(string $body): void
    {
        $received = null;
        $feedService = $this->feedServiceCapturing($received);

        $this->withToken('PATCH');
        PhpInputStreamMock::register($body);

        $this->capture($this->makeModule($feedService, isAdmin: true), $this->feedItemPage(), ['slug' => 58]);

        self::assertSame([], $received);
    }

    public function testAnUnsupportedMethodOnAFeedIs405(): void
    {
        $this->withToken('PUT');

        try {
            $this->makeModule(isAdmin: true)->callApi($this->feedItemPage(), ['slug' => 58]);
            self::fail('expected a 405');
        } catch (ValidationException $e) {
            self::assertSame(405, $e->getHttpCode());
        }
    }

    /* ===============================
       GET /api/v1/feeds
    =============================== */

    public function testTheListDefaultsToAHundredFromTheTop(): void
    {
        $feedService = $this->feedServiceMock(['listFeeds']);
        $feedService->expects($this->once())
            ->method('listFeeds')
            ->with($this->anything(), 100, 0, null, null, null, null, null, null, null)
            ->willReturn(['data' => [], 'meta' => []]);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->feedsPage());
    }

    public function testPagingComesOffTheQueryString(): void
    {
        $feedService = $this->feedServiceMock(['listFeeds']);
        $feedService->expects($this->once())
            ->method('listFeeds')
            ->with($this->anything(), 25, 50, null, null, null, null, null, null, null)
            ->willReturn(['data' => [], 'meta' => []]);

        $_GET = ['limit' => '25', 'offset' => '50'];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->feedsPage());
    }

    public function testTheListLimitIsCappedAtOneHundred(): void
    {
        $feedService = $this->feedServiceMock(['listFeeds']);
        $feedService->expects($this->once())
            ->method('listFeeds')
            ->with($this->anything(), 100, 0, null, null, null, null, null, null, null)
            ->willReturn(['data' => [], 'meta' => []]);

        $_GET = ['limit' => '100000'];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->feedsPage());
    }

    /**
     * @return array<string, array{array<string, string>, int, int}>
     */
    public static function unusablePagingProvider(): array
    {
        return [
            // Invalid values use the endpoint default; numeric values are
            // clamped to the supported range.
            'a non-numeric limit' => [['limit' => 'many'], 100, 0],
            'an empty limit' => [['limit' => ''], 100, 0],
            'a deliberate zero limit' => [['limit' => '0'], 1, 0],
            'a negative limit' => [['limit' => '-10'], 1, 0],
            'a negative offset' => [['offset' => '-10'], 100, -10],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('unusablePagingProvider')]
    public function testUnusablePagingValuesFallBackRatherThanFail(array $query, int $limit, int $offset): void
    {
        $feedService = $this->feedServiceMock(['listFeeds']);
        $feedService->expects($this->once())
            ->method('listFeeds')
            ->with($this->anything(), $limit, $offset, null, null, null, null, null, null, null)
            ->willReturn(['data' => [], 'meta' => []]);

        $_GET = $query;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->feedsPage());
    }

    public function testBlankFiltersBecomeNullRatherThanEmptyStrings(): void
    {
        // `$type !== '' ? $type : null` - an empty string would be a filter for
        // feeds whose type is '', which matches nothing, instead of no filter.
        $feedService = $this->feedServiceMock(['listFeeds']);
        $feedService->expects($this->once())
            ->method('listFeeds')
            ->with($this->anything(), 100, 0, null, null, null, null, null, null, null)
            ->willReturn(['data' => [], 'meta' => []]);

        $_GET = ['type' => '   ', 'title' => ''];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->feedsPage());
    }

    public function testIndexedListFiltersAreForwardedToTheRegularList(): void
    {
        $feedService = $this->feedServiceMock(['listFeeds']);
        $feedService->expects($this->once())
            ->method('listFeeds')
            ->with(
                $this->callback(static fn (User $u): bool => $u->id === 1),
                50,
                100,
                'chapter',
                null,
                90,
                'book-one',
                58,
                7,
                'position_asc'
            )
            ->willReturn(['data' => [], 'meta' => []]);

        $_GET = [
            'id' => '90',
            'slug' => ' book-one ',
            'owner_id' => '7',
            'parent_id' => '58',
            'type' => ' chapter ',
            'sort' => ' position_asc ',
            'limit' => '50',
            'offset' => '100',
        ];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->feedsPage());
    }

    public function testASearchGoesToSearchRatherThanTheList(): void
    {
        $feedService = $this->feedServiceMock(['search', 'listFeeds']);
        $feedService->expects($this->never())->method('listFeeds');
        $feedService->expects($this->once())
            ->method('search')
            ->with('мастер', 20, null, $this->callback(static fn (User $u): bool => $u->id === 1))
            ->willReturn(['data' => [], 'meta' => []]);

        $_GET = ['search' => '  мастер  '];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->feedsPage());
    }

    public function testTheSearchRespectsABoundedRequestedLimit(): void
    {
        $feedService = $this->feedServiceMock(['search']);
        $feedService->expects($this->once())
            ->method('search')
            ->with('мастер', 75, null, $this->anything())
            ->willReturn(['data' => [], 'meta' => []]);

        $_GET = ['search' => 'мастер', 'limit' => '75'];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->feedsPage());
    }

    public function testTheSearchLimitIsCappedAtOneHundred(): void
    {
        $feedService = $this->feedServiceMock(['search']);
        $feedService->expects($this->once())
            ->method('search')
            ->with('мастер', 100, null, $this->anything())
            ->willReturn(['data' => [], 'meta' => []]);

        $_GET = ['search' => 'мастер', 'limit' => '100000'];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->feedsPage());
    }

    public function testTheSearchCursorIsForwardedWhenThereIsOne(): void
    {
        $feedService = $this->feedServiceMock(['search']);
        $feedService->expects($this->once())
            ->method('search')
            ->with('мастер', 20, 'eyJpZCI6NX0=', $this->anything())
            ->willReturn(['data' => [], 'meta' => []]);

        $_GET = ['search' => 'мастер', 'cursor' => 'eyJpZCI6NX0='];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->feedsPage());
    }

    public function testAnEmptyCursorIsNullRatherThanAnEmptyString(): void
    {
        // `?: null` - search() decodes a non-null cursor and throws
        // 'feed.cursor_invalid' on one it cannot read, so passing '' through
        // would turn "first page" into an error.
        $feedService = $this->feedServiceMock(['search']);
        $feedService->expects($this->once())
            ->method('search')
            ->with('мастер', 20, null, $this->anything())
            ->willReturn(['data' => [], 'meta' => []]);

        $_GET = ['search' => 'мастер', 'cursor' => ''];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->feedsPage());
    }

    public function testAWhitespaceOnlySearchIsAListing(): void
    {
        // trimmed(), then truthiness: '   ' is not a search term, and treating
        // it as one would run a LIKE '%%' scan.
        $feedService = $this->feedServiceMock(['search', 'listFeeds']);
        $feedService->expects($this->never())->method('search');
        $feedService->expects($this->once())->method('listFeeds')->willReturn(['data' => [], 'meta' => []]);

        $_GET = ['search' => '   '];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->feedsPage());
    }

    /* ===============================
       GET /api/v1/comments/{parentId}
    =============================== */

    public function testACommentThreadIsReadWithoutAToken(): void
    {
        $feedService = $this->feedServiceMock(['getComments']);
        $feedService->expects($this->once())
            ->method('getComments')
            ->with(55, null, $this->callback(static fn (User $u): bool => $u->id === 1), 5, 3)
            ->willReturn(['data' => [], 'meta' => []]);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        // The CSRF check is inside the POST arm only - a thread is public
        // reading, and requiring a token would break the first page load.
        $this->capture($this->makeModule($feedService), $this->commentsThreadPage(), ['slug' => 55]);
    }

    /**
     * @return array<string, array{array<string, string>, int, int}>
     */
    public static function commentLimitProvider(): array
    {
        return [
            'the defaults' => [[], 5, 3],
            'as asked' => [['limit' => '20', 'child_limit' => '5'], 20, 5],
            // max(1, min($limit, 50)) / max(0, min($childLimit, 10)) - the
            // ceiling this endpoint has and the feed list does not.
            'over the ceiling' => [['limit' => '500', 'child_limit' => '99'], 50, 10],
            'under the floor' => [['limit' => '0', 'child_limit' => '-1'], 1, 0],
            'a negative limit' => [['limit' => '-5'], 1, 3],
            // A childLimit of 0 is legal - the thread's roots with no replies
            // expanded - which is why its floor is 0 and the parent's is 1.
            'no children wanted' => [['child_limit' => '0'], 5, 0],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('commentLimitProvider')]
    public function testCommentPagingIsClampedAtBothEnds(array $query, int $limit, int $childLimit): void
    {
        $feedService = $this->feedServiceMock(['getComments']);
        $feedService->expects($this->once())
            ->method('getComments')
            ->with(55, null, $this->anything(), $limit, $childLimit)
            ->willReturn(['data' => [], 'meta' => []]);

        $_GET = $query;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->commentsThreadPage(), ['slug' => 55]);
    }

    public function testTheCommentCursorIsForwarded(): void
    {
        $feedService = $this->feedServiceMock(['getComments']);
        $feedService->expects($this->once())
            ->method('getComments')
            ->with(55, 'eyJpZCI6OX0=', $this->anything(), 5, 3)
            ->willReturn(['data' => [], 'meta' => []]);

        $_GET = ['cursor' => 'eyJpZCI6OX0='];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture($this->makeModule($feedService), $this->commentsThreadPage(), ['slug' => 55]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedThreadMethodProvider(): array
    {
        return [
            'PATCH' => ['PATCH'],
            'DELETE' => ['DELETE'],
            'PUT' => ['PUT'],
        ];
    }

    /**
     * Unlike the two above, this handler has the `else`: anything that is not
     * POST or GET is a 405 rather than silence. The three endpoints in this
     * file disagree about that, which is the point of testing all three.
     */
    #[DataProvider('unsupportedThreadMethodProvider')]
    public function testAnUnsupportedMethodOnAThreadIs405(string $method): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;

        try {
            $this->makeModule()->callApi($this->commentsThreadPage(), ['slug' => 55]);
            self::fail('expected a 405');
        } catch (ValidationException $e) {
            self::assertSame(405, $e->getHttpCode());
        }
    }

    public function testARequestForTheCommentsRootIsRefused(): void
    {
        // 'comments.root' is a `throw` in the match arm itself: /api/v1/comments
        // with no parent id is not a listing of every comment on the site.
        $page = Page::api(
            id: 21,
            parentId: 10,
            pattern: 'comments',
            requestMethods: ['GET'],
            action: 'comments.root',
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Comment parent id is required');

        $this->makeModule()->callApi($page);
    }

    /* ===============================
       PATCH /api/v1/comments/{parentId}/{commentId}
    =============================== */

    public function testEditingACommentSendsTheTrimmedContent(): void
    {
        $feedService = $this->feedServiceMock(['editComment']);
        $feedService->expects($this->once())
            ->method('editComment')
            ->with(90, 'Исправлено', $this->callback(static fn (User $u): bool => $u->id === 1))
            ->willReturn($this->feed(id: 90, type: 'comment', title: null));

        $this->withToken('PATCH');
        PhpInputStreamMock::register(json_encode(['content' => "  Исправлено \n"]));

        $decoded = $this->capture($this->makeModule($feedService), $this->commentItemPage(), ['commentId' => 90]);

        self::assertSame(90, $decoded['id']);
        // 200 with the edited comment, not 204: the client re-renders the
        // bubble from this body rather than from what it sent.
        self::assertSame(200, http_response_code());
    }

    public function testAnEmptyEditReachesTheServiceRatherThanBeingSwallowed(): void
    {
        // Ownership, the edit window and the empty-content rule all live in
        // FeedService::editComment(), so the controller must not pre-empt any
        // of them by refusing here - it would answer the wrong error.
        $feedService = $this->feedServiceMock(['editComment']);
        $feedService->expects($this->once())
            ->method('editComment')
            ->with(90, '', $this->anything())
            ->willThrowException(new ValidationException('Comment is empty'));

        $this->withToken('PATCH');
        PhpInputStreamMock::register(json_encode(['content' => '   ']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Comment is empty');

        $this->makeModule($feedService)->callApi($this->commentItemPage(), ['commentId' => 90]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonStringContentProvider(): array
    {
        return [
            'a number' => ['{"content": 5}', '5'],
            'a float' => ['{"content": 1.5}', '1.5'],
            'a boolean' => ['{"content": true}', '1'],
            'a null' => ['{"content": null}', ''],
            'no content key' => ['{}', ''],
        ];
    }

    /**
     * `trim((string) ($input['content'] ?? ''))` - the cast is what keeps a
     * number or a boolean from being a TypeError against `editComment(string)`,
     * the same shape of fatal `UsersController` was
     * carrying. What the cast produces is not always sensible (`true` becomes
     * the string `1`), but it reaches the service's own validation instead of
     * crashing in front of it.
     */
    #[DataProvider('nonStringContentProvider')]
    public function testANonStringContentIsCastRatherThanFatal(string $body, string $expected): void
    {
        $feedService = $this->feedServiceMock(['editComment']);
        $feedService->expects($this->once())
            ->method('editComment')
            ->with(90, $expected, $this->anything())
            ->willReturn($this->feed(id: 90, type: 'comment', title: null));

        $this->withToken('PATCH');
        PhpInputStreamMock::register($body);

        $this->capture($this->makeModule($feedService), $this->commentItemPage(), ['commentId' => 90]);
    }

    #[DataProvider('structuredContentProvider')]
    public function testStructuredContentIsRejectedWithoutCallingTheService(string $body): void
    {
        $feedService = $this->feedServiceMock(['editComment']);
        $feedService->expects($this->never())->method('editComment');

        $this->withToken('PATCH');
        PhpInputStreamMock::register($body);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Comment content must be a scalar value');

        $this->makeModule($feedService)->callApi($this->commentItemPage(), ['commentId' => 90]);
    }

    /** @return array<string, array{string}> */
    public static function structuredContentProvider(): array
    {
        return [
            'array' => ['{"content": ["a"]}'],
            'object' => ['{"content": {"text": "a"}}'],
        ];
    }

    /* ===============================
       POST /api/v1/uploads
    =============================== */

    private function upload(): Upload
    {
        return new Upload(
            id: 404,
            userId: 1,
            path: '2026/08/abc.jpg',
            mime: 'image/jpeg',
            size: 2048,
            originalName: 'кот.jpg',
            createdAt: 1700000000,
        );
    }

    public function testAnUploadWithNoFileIs400WithABodyRatherThanAThrow(): void
    {
        $this->withToken('POST');
        unset($_FILES['file']);

        $decoded = $this->capture($this->makeModule(), $this->uploadsPage());

        // The one handler in this file that answers an error by echoing it
        // instead of throwing. Same wire shape as ApiErrorResponse's, so the
        // client cannot tell - but it does mean this path is not logged.
        self::assertSame(400, http_response_code());
        self::assertSame('No file', $decoded['error']);
    }

    public function testAFailedUploadIsAValidationError(): void
    {
        $this->withToken('POST');
        // UPLOAD_ERR_INI_SIZE - the one a user actually meets, by attaching
        // something bigger than upload_max_filesize.
        $_FILES['file'] = ['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'name' => 'big.jpg'];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Upload failed');

        $this->makeModule()->callApi($this->uploadsPage());
    }

    public function testASuccessfulUploadAnswersTheFieldsEveryCallerReads(): void
    {
        $uploadService = $this->getMockBuilder(UploadService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['uploadForUser', 'uploadAvatarForUser'])
            ->getMock();

        $uploadService->expects($this->never())->method('uploadAvatarForUser');
        $uploadService->expects($this->once())
            ->method('uploadForUser')
            ->with($this->callback(static fn (User $u): bool => $u->id === 1), $this->anything())
            ->willReturn($this->upload());

        $this->withToken('POST');
        $_FILES['file'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/x', 'name' => 'кот.jpg'];

        $decoded = $this->capture($this->makeModule(uploadService: $uploadService), $this->uploadsPage());

        self::assertSame(404, $decoded['id']);
        // Built by concatenation onto '/uploads/', so the stored path must not
        // carry a leading slash - every img src on the site depends on it.
        self::assertSame('/uploads/2026/08/abc.jpg', $decoded['url']);
        self::assertSame('image/jpeg', $decoded['mime']);
        // size/originalName were added for the forum topic form's attachment
        // list; asserted so a later cleanup does not drop them.
        self::assertSame(2048, $decoded['size']);
        self::assertSame('кот.jpg', $decoded['originalName']);
    }

    public function testTheOriginalNameSurvivesAsUtf8(): void
    {
        // A stub, not a mock: this test asserts on the encoded bytes, never on
        // how the service was called.
        $uploadService = $this->createStub(UploadService::class);
        $uploadService->method('uploadForUser')->willReturn($this->upload());

        $this->withToken('POST');
        $_FILES['file'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/x', 'name' => 'кот.jpg'];

        ob_start();

        try {
            $this->makeModule(uploadService: $uploadService)->callApi($this->uploadsPage());
        } finally {
            $raw = ob_get_clean();
        }

        // Formatter::json()'s JSON_UNESCAPED_UNICODE, asserted on the bytes:
        // the filename is echoed as itself rather than as кот, and
        // JSON_UNESCAPED_SLASHES means the path is not \/-escaped either.
        self::assertStringContainsString('кот.jpg', $raw);
        self::assertStringContainsString('/uploads/2026/08/abc.jpg', $raw);
        self::assertStringNotContainsString('\\u', $raw);
        self::assertStringNotContainsString('\\/', $raw);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function uploadVariantProvider(): array
    {
        return [
            'the avatar variant' => ['avatar', 'uploadAvatarForUser'],
            'anything else' => ['banner', 'uploadForUser'],
            'an empty variant' => ['', 'uploadForUser'],
        ];
    }

    /**
     * `?variant=avatar` picks a different service method - the avatar one
     * crops and re-encodes, and it is chosen by an exact string match, so
     * every other value is an ordinary upload rather than an error.
     */
    #[DataProvider('uploadVariantProvider')]
    public function testTheVariantChoosesWhichUploadRuns(string $variant, string $expected): void
    {
        $uploadService = $this->getMockBuilder(UploadService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['uploadForUser', 'uploadAvatarForUser'])
            ->getMock();

        $other = $expected === 'uploadForUser' ? 'uploadAvatarForUser' : 'uploadForUser';
        $uploadService->expects($this->never())->method($other);
        $uploadService->expects($this->once())->method($expected)->willReturn($this->upload());

        $_GET = ['variant' => $variant];
        $this->withToken('POST');
        $_FILES['file'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/x', 'name' => 'x.jpg'];

        $this->capture($this->makeModule(uploadService: $uploadService), $this->uploadsPage());
    }

    /* ===============================
       GET /api/v1
    =============================== */

    public function testTheV1IndexListsItsOwnChildren(): void
    {
        $v1 = Page::api(id: 10, parentId: 1, pattern: 'v1', requestMethods: ['GET'], action: 'api.v1.index');
        $tree = new PageTree([$v1]);
        APIController::registerApi(10, $tree);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $decoded = $this->capture($this->makeModule(pageTree: $tree), $v1);

        // Direct children only, keyed by pattern - so the index is a map of
        // what is registered rather than a hand-maintained list that drifts.
        self::assertSame('/api/v1/feeds', $decoded['resources']['feeds']);
        self::assertSame('/api/v1/auth', $decoded['resources']['auth']);
        self::assertSame('/api/v1/uploads', $decoded['resources']['uploads']);
        self::assertSame('/api/v1/cron', $decoded['resources']['cron']);
        // Nested ones are not flattened into it: {slug} lives under feeds.
        self::assertArrayNotHasKey('{slug}', $decoded['resources']);
    }

    public function testAnIndexWithNoChildrenIsAnEmptyMap(): void
    {
        $v1 = Page::api(id: 10, parentId: 1, pattern: 'v1', requestMethods: ['GET'], action: 'api.v1.index');

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $decoded = $this->capture($this->makeModule(pageTree: new PageTree([$v1])), $v1);

        self::assertSame([], $decoded['resources']);
    }
}
