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
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Users\BlogPostService;
use StreamEngine\Modules\Users\CommunityService;
use StreamEngine\Modules\Users\UsersController;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\UploadService;
use StreamEngine\Service\UserService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;
use Tests\Support\PhpInputStreamMock;

/**
 * The four community JSON endpoints - two that write and two that page.
 *
 * The guards are the reason to bother. Three of them are the kind that only
 * shows up as a security note in somebody's report:
 *
 * - **`type !== 'community'`.** Both write endpoints take a feed id from the
 *   URL and load it with `getFeedById()`, which happily returns a blog post, a
 *   forum topic or a book. Without the type check, `POST
 *   /api/v1/communities/<a blog post's id>/posts` would file a post inside
 *   somebody's blog post.
 * - **`canPost()`.** Membership is what separates an open community from a
 *   closed one, and this is the only place it is asked about on the write path.
 * - **`findOwnedUpload($id, $user, 'audio/')`.** The attached track is
 *   referenced by id, so without the ownership *and* mime check a post could
 *   claim somebody else's upload - the plain shape of an IDOR.
 *
 * The paging arithmetic is worth pinning for a duller reason: `nextOffset`
 * being wrong by one either hides the last page of members or makes the
 * "Показать ещё" button never go away.
 */
final class UsersControllerCommunityApiTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        $_GET = [];

        PhpInputStreamMock::restore();
        http_response_code(200);

        parent::tearDown();
    }

    private function withCsrf(): void
    {
        $token = str_repeat('a', 64);
        $_COOKIE['csrfToken'] = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
    }

    /**
     * @param array<string, object> $services keyed by property name
     * @param array<string, string> $query the request's query string
     *
     * The query is a *parameter* rather than something a test sets around this
     * call, because RequestContext snapshots `$_GET` when it is constructed and
     * setting it afterwards silently has no effect. Four tests in this file
     * were written that way and two of them passed anyway - their expectation
     * happened to be the same as the empty-query answer - which is exactly the
     * kind of test that stops meaning anything without failing.
     */
    private function makeModule(
        array $services = [],
        ?User $user = null,
        array $query = [],
        ?PdoDatabase $db = null,
    ): UsersController {
        $_GET = $query;

        $db ??= $this->createStub(PdoDatabase::class);
        $reflection = new ReflectionClass(UsersController::class);
        $module = $reflection->newInstanceWithoutConstructor();
        $tm = new TranslationManager('ru', 'en');

        $reflection->getParentClass()->getProperty('db')->setValue($module, $db);
        $reflection->getParentClass()->getProperty('context')->setValue(
            $module,
            new RequestContext(
                $user ?? new User(id: 7, email: 'a@b.co', role: AccessService::ROLE_USER, username: 'anton'),
                new DateTimeZone('UTC'),
                QueryParams::fromGlobals()
            )
        );

        $pageTree = new PageTree([]);
        $notificationService = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($db),
            new NotificationPreferenceRepository($db),
            new UserRepository($db),
            (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
        );
        $reflection->getProperty('pageTree')->setValue($module, $pageTree);
        $reflection->getProperty('urlGenerator')->setValue(
            $module,
            new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache())
        );
        $reflection->getProperty('userService')->setValue(
            $module,
            new UserService(
                new UserRepository($db),
                $db,
                (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
                $tm,
                $notificationService,
                new UserSessionRepository($db),
                new Config([]),
            )
        );
        $reflection->getProperty('tm')->setValue($module, $tm);
        $reflection->getProperty('formatter')->setValue($module, new Formatter($tm, 'ru'));
        $reflection->getProperty('notificationService')->setValue($module, $notificationService);
        $reflection->getProperty('memberships')->setValue($module, new MembershipRepository($db));

        foreach ($services as $name => $service) {
            $reflection->getProperty($name)->setValue($module, $service);
        }

        return $module;
    }

    /**
     * Runs the handler and returns whatever it echoed.
     *
     * `try`/`finally` rather than a bare ob_start()/ob_end_clean() pair: a mock
     * expectation that fails does so *at the call*, between the two, leaving
     * the buffer open - which PHPUnit then reports as a risky test on top of
     * the real failure and makes the actual message harder to find.
     */
    private function capture(callable $run): string
    {
        ob_start();

        try {
            $run();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function apiPage(string $action): Page
    {
        return Page::api(
            id: 60,
            parentId: 10,
            pattern: 'stub',
            requestMethods: ['GET', 'POST'],
            action: $action,
            accessRule: AccessService::ACCESS_AUTHENTICATED,
        );
    }

    private function feed(int $id = 42, string $type = 'community', ?string $title = 'Разработчики'): Feed
    {
        return new Feed(
            id: $id,
            parentId: null,
            ownerId: 7,
            type: $type,
            slug: 'devs',
            title: $title,
            description: null,
            imageUrl: null,
            content: null,
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: '/communities/devs/',
            metadata: ['membership_type' => 'closed'],
        );
    }

    private function feedServiceReturning(?Feed $community, array $page = ['items' => [], 'total' => 0]): FeedService
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($community);
        $feedService->method('getFeedsByTypePage')->willReturn($page);
        $feedService->method('getFeedsByParentAndTypePage')->willReturn($page);

        return $feedService;
    }

    /* ===============================
       The method guard, on all four
    =============================== */

    /**
     * @return array<string, array{string, string, array<int, mixed>}>
     */
    public static function wrongMethodProvider(): array
    {
        return [
            'create a community with GET' => ['community.do-create', 'GET', []],
            'list members with POST' => ['community.members', 'POST', [42]],
            'list posts with POST' => ['community.posts', 'POST', []],
            'anything with PUT' => ['community.do-create', 'PUT', []],
        ];
    }

    /**
     * Checked first, before CSRF and before anything is loaded - so the answer
     * to a wrong verb is 405 rather than a token error about a request that was
     * never going to be served.
     *
     * @param array<int, mixed> $args
     */
    #[DataProvider('wrongMethodProvider')]
    public function testTheWrongMethodIsAFourOhFive(string $action, string $method, array $args): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = $method;

        try {
            $module->callApi($this->apiPage($action), $args === [] ? [] : ['id' => (string) $args[0]]);
            $this->fail('a wrong method must not be served');
        } catch (ValidationException $e) {
            $this->assertSame(405, $e->getHttpCode());
        }
    }

    /* ===============================
       Creating a community
    =============================== */

    public function testCreatingACommunityRequiresACsrfToken(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        $this->expectException(ValidationException::class);

        $module->callApi($this->apiPage('community.do-create'));
    }

    public function testCreatingACommunityRejectsGuestsInTheController(): void
    {
        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->never())->method('createCommunity');
        $module = $this->makeModule(
            ['communityService' => $communityService],
            new User(id: 0, email: '', role: AccessService::ROLE_USER),
        );
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->expectException(ForbiddenException::class);

        $module->callApi($this->apiPage('community.do-create'));
    }

    public function testCommunityMembershipRejectsGuestsInTheController(): void
    {
        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->never())->method('getFeedById');
        $module = $this->makeModule(
            ['feedService' => $feedService],
            new User(id: 0, email: '', role: AccessService::ROLE_USER),
        );
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->expectException(ForbiddenException::class);

        $module->callApi($this->apiPage('community.membership'), ['id' => '42']);
    }

    public function testCommunityManagementRejectsANonOwnerInTheController(): void
    {
        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->never())->method('updateSettings');
        $module = $this->makeModule(
            [
                'feedService' => $this->feedServiceReturning($this->feed()),
                'communityService' => $communityService,
            ],
            new User(id: 8, email: 'member@example.com', role: AccessService::ROLE_USER),
        );
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withCsrf();

        $this->expectException(ForbiddenException::class);

        $module->callApi($this->apiPage('community.manage-settings'), ['id' => '42']);
    }

    public function testCommunityMemberManagementRejectsANonOwnerInTheController(): void
    {
        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->never())->method('approveSubscriber');
        $module = $this->makeModule(
            [
                'feedService' => $this->feedServiceReturning($this->feed()),
                'communityService' => $communityService,
            ],
            new User(id: 8, email: 'member@example.com', role: AccessService::ROLE_USER),
        );
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withCsrf();

        $this->expectException(ForbiddenException::class);

        $module->callApi($this->apiPage('community.manage-member'), ['id' => '42', 'userId' => '9']);
    }

    public function testANewCommunityIsReportedWithItsRedirectTarget(): void
    {
        $community = $this->feed();

        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->once())
            ->method('createCommunity')
            ->with(
                $this->isInstanceOf(User::class),
                'Разработчики',
                'Про код',
                'https://cdn.example.com/c.png',
                'closed'
            )
            ->willReturn($community);

        $module = $this->makeModule(['communityService' => $communityService]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        PhpInputStreamMock::register(json_encode([
            'name' => '  Разработчики  ',
            'description' => 'Про код',
            'imageUrl' => 'https://cdn.example.com/c.png',
            'membershipType' => 'closed',
        ]));

        $payload = json_decode(
            $this->capture(fn () => $module->callApi($this->apiPage('community.do-create'))),
            true
        );

        $this->assertSame(201, http_response_code());
        $this->assertSame(42, $payload['id']);
        // Read off the *created* community, not echoed back from the
        // request - the service is what decides, and a closed community that
        // reported itself as open would mislead the form's next step.
        $this->assertSame('closed', $payload['membershipType']);
        // The form navigates to this, so it is the whole point of the response.
        $this->assertSame('/communities/devs/', $payload['redirectUrl']);
    }

    public function testAMissingMembershipTypeFallsBackToOpen(): void
    {
        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->once())
            ->method('createCommunity')
            ->with($this->anything(), 'Клуб', null, null, CommunityService::MEMBERSHIP_TYPE_OPEN)
            ->willReturn($this->feed());

        $module = $this->makeModule(['communityService' => $communityService]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        // No description key at all, and an empty imageUrl - which becomes
        // null rather than an empty string, so the column stays NULL.
        PhpInputStreamMock::register('{"name":"Клуб","imageUrl":""}');

        $this->capture(fn () => $module->callApi($this->apiPage('community.do-create')));
    }

    public function testAMalformedBodyIsTreatedAsEmptyRatherThanFatal(): void
    {
        // `is_array($input) ? $input : []` - so validation happens in
        // CommunityService against empty values, which is where the message
        // the form shows comes from.
        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->once())
            ->method('createCommunity')
            ->with($this->anything(), '', null, null, CommunityService::MEMBERSHIP_TYPE_OPEN)
            ->willThrowException(new ValidationException('Название обязательно'));

        $module = $this->makeModule(['communityService' => $communityService]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        PhpInputStreamMock::register('<html>502</html>');

        $this->expectException(ValidationException::class);

        $module->callApi($this->apiPage('community.do-create'));
    }

    /* ===============================
       Posting into a community
    =============================== */

    /** A community service that lets everybody post. */
    private function permissiveCommunityService(): CommunityService
    {
        $communityService = $this->createStub(CommunityService::class);
        $communityService->method('canPost')->willReturn(true);

        return $communityService;
    }

    public function testPostingRequiresACsrfToken(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        $this->expectException(ValidationException::class);

        $module->callApi($this->apiPage('community.post-new-create'), ['id' => '42']);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function notACommunityProvider(): array
    {
        return [
            'no such feed' => [null],
            // The guard that matters: getFeedById() returns any feed, so
            // without the type check a post could be filed inside a blog
            // post, a forum topic or a book by passing its id.
            'a blog post' => ['blog-post'],
            'a forum topic' => ['forum-topic'],
            'a publication' => ['publication'],
        ];
    }

    #[DataProvider('notACommunityProvider')]
    public function testPostingIntoSomethingThatIsNotACommunityIsNotFound(?string $type): void
    {
        $module = $this->makeModule([
            'feedService' => $this->feedServiceReturning($type === null ? null : $this->feed(type: $type)),
            'communityService' => $this->permissiveCommunityService(),
        ]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        PhpInputStreamMock::register('{"title":"Пост","content":"<p>Текст</p>"}');

        $this->expectException(NotFoundException::class);

        $module->callApi($this->apiPage('community.post-new-create'), ['id' => '42']);
    }

    public function testAZeroIdIsNotEvenLookedUp(): void
    {
        // `$id > 0 ? getFeedById(...) : null` - so a missing or unparseable
        // path segment is a 404 rather than a query for feed 0.
        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->never())->method('getFeedById');

        $module = $this->makeModule([
            'feedService' => $feedService,
            'communityService' => $this->permissiveCommunityService(),
        ]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        PhpInputStreamMock::register('{}');

        $this->expectException(NotFoundException::class);

        $module->callApi($this->apiPage('community.post-new-create'), ['id' => '0']);
    }

    public function testAVisitorWhoMayNotPostIsRefused(): void
    {
        $communityService = $this->createStub(CommunityService::class);
        $communityService->method('canPost')->willReturn(false);

        $blogPostService = $this->createMock(BlogPostService::class);
        // Refused before anything is written.
        $blogPostService->expects($this->never())->method('createBlogPost');

        $module = $this->makeModule([
            'feedService' => $this->feedServiceReturning($this->feed()),
            'communityService' => $communityService,
            'blogPostService' => $blogPostService,
        ]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        PhpInputStreamMock::register('{"title":"Пост","content":"<p>Текст</p>"}');

        $this->expectException(ForbiddenException::class);

        $module->callApi($this->apiPage('community.post-new-create'), ['id' => '42']);
    }

    /**
     * The attached track is referenced by id, so ownership has to be checked
     * here or a post could claim any upload on the site.
     */
    public function testATrackTheVisitorDoesNotOwnIsRefused(): void
    {
        $uploadService = $this->createMock(UploadService::class);
        $uploadService->expects($this->once())
            ->method('findOwnedUpload')
            // The mime prefix is part of the check: an audio slot must not
            // accept somebody's PDF either.
            ->with(99, $this->isInstanceOf(User::class), 'audio/')
            ->willReturn(null);

        $blogPostService = $this->createMock(BlogPostService::class);
        $blogPostService->expects($this->never())->method('createBlogPost');

        $module = $this->makeModule([
            'feedService' => $this->feedServiceReturning($this->feed()),
            'communityService' => $this->permissiveCommunityService(),
            'uploadService' => $uploadService,
            'blogPostService' => $blogPostService,
        ]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        PhpInputStreamMock::register('{"title":"Пост","content":"x","trackUploadId":99}');

        $this->expectException(ValidationException::class);

        $module->callApi($this->apiPage('community.post-new-create'), ['id' => '42']);
    }

    public function testNoTrackMeansNoOwnershipQuery(): void
    {
        // `! empty($input['trackUploadId'])` - so the common case of a post
        // with no audio does not go looking for upload 0.
        $uploadService = $this->createMock(UploadService::class);
        $uploadService->expects($this->never())->method('findOwnedUpload');

        $blogPostService = $this->createStub(BlogPostService::class);
        $blogPostService->method('createBlogPost')->willReturn($this->feed(id: 900, type: 'blog-post'));

        $module = $this->makeModule([
            'feedService' => $this->feedServiceReturning($this->feed()),
            'communityService' => $this->permissiveCommunityService(),
            'uploadService' => $uploadService,
            'blogPostService' => $blogPostService,
        ]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        PhpInputStreamMock::register('{"title":"Пост","content":"x","trackUploadId":0}');

        $this->capture(fn () => $module->callApi($this->apiPage('community.post-new-create'), ['id' => '42']));
    }

    public function testANewPostIsCreatedInsideTheCommunityAndReported(): void
    {
        $community = $this->feed();
        $post = $this->feed(id: 900, type: 'blog-post', title: 'Пост');

        $blogPostService = $this->createMock(BlogPostService::class);
        $blogPostService->expects($this->once())
            ->method('createBlogPost')
            ->with(
                'Пост',
                '<p>Текст</p>',
                null,
                null,
                $this->isInstanceOf(User::class),
                'members',
                ['php', 'тесты'],
                null,
                // The community is passed as the parent - which is what makes
                // it a community post rather than a personal blog entry.
                $community
            )
            ->willReturn($post);

        $module = $this->makeModule([
            'feedService' => $this->feedServiceReturning($community),
            'communityService' => $this->permissiveCommunityService(),
            'blogPostService' => $blogPostService,
        ]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        PhpInputStreamMock::register(json_encode([
            'title' => '  Пост  ',
            'content' => '<p>Текст</p>',
            'visibility' => 'members',
            'tags' => ['php', 'тесты'],
            'imageUrl' => '',
        ]));

        $payload = json_decode(
            $this->capture(fn () => $module->callApi($this->apiPage('community.post-new-create'), ['id' => '42'])),
            true
        );

        $this->assertSame(201, http_response_code());
        $this->assertSame(900, $payload['id']);
        $this->assertSame('/communities/devs/', $payload['canonicalUrl']);
    }

    public function testPublishedCommunityPostQueuesNotificationsForMembersExceptAuthor(): void
    {
        $deliveries = [];
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'SELECT user_id') && str_contains($sql, 'FROM memberships')) {
                return [['user_id' => '7'], ['user_id' => '8'], ['user_id' => '9']];
            }

            if (str_contains($sql, 'FROM notification_preferences')) {
                return [
                    ['channel' => 'messenger', 'delivery' => 'off'],
                    ['channel' => 'email', 'delivery' => 'instant'],
                ];
            }

            return [];
        });
        $db->method('execute')->willReturnCallback(
            static function (string $sql, array $params) use (&$deliveries): int {
                if (str_contains($sql, 'INSERT INTO notification_deliveries')) {
                    $deliveries[] = $params;
                }

                return 1;
            }
        );

        $community = $this->feed();
        $blogPostService = $this->createStub(BlogPostService::class);
        $blogPostService->method('createBlogPost')->willReturn(
            $this->feed(id: 900, type: 'blog-post', title: 'Пост')
        );
        $module = $this->makeModule(
            [
                'feedService' => $this->feedServiceReturning($community),
                'communityService' => $this->permissiveCommunityService(),
                'blogPostService' => $blogPostService,
            ],
            db: $db,
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        PhpInputStreamMock::register('{"title":"Пост","content":"Текст"}');

        $this->capture(fn () => $module->callApi($this->apiPage('community.post-new-create'), ['id' => '42']));

        self::assertSame([[8, 'community.post'], [9, 'community.post']], array_map(
            static fn (array $params): array => [$params[0], $params[1]],
            $deliveries,
        ));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonArrayTagsProvider(): array
    {
        return [
            'a string' => ['{"title":"t","content":"c","tags":"php"}'],
            'a number' => ['{"title":"t","content":"c","tags":7}'],
            'null' => ['{"title":"t","content":"c","tags":null}'],
            'absent' => ['{"title":"t","content":"c"}'],
        ];
    }

    /**
     * `null`, not `[]`: BlogPostService treats the two differently - null
     * means "leave the tags alone", an empty array means "remove them all".
     * Anything that is not an array has to become null rather than be coerced.
     */
    #[DataProvider('nonArrayTagsProvider')]
    public function testTagsThatAreNotAListAreTreatedAsAbsent(string $body): void
    {
        $blogPostService = $this->createMock(BlogPostService::class);
        $blogPostService->expects($this->once())
            ->method('createBlogPost')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                null,
                $this->anything(),
                $this->anything()
            )
            ->willReturn($this->feed(id: 900, type: 'blog-post'));

        $module = $this->makeModule([
            'feedService' => $this->feedServiceReturning($this->feed()),
            'communityService' => $this->permissiveCommunityService(),
            'blogPostService' => $blogPostService,
        ]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withCsrf();
        PhpInputStreamMock::register($body);

        $this->capture(fn () => $module->callApi($this->apiPage('community.post-new-create'), ['id' => '42']));
    }

    /* ===============================
       Paging the member list
    =============================== */

    /**
     * @param array<string, string> $query
     */
    private function membersModule(array $membersPage, array $query = []): UsersController
    {
        $communityService = $this->createStub(CommunityService::class);
        $communityService->method('getMembers')->willReturn($membersPage);

        return $this->makeModule(
            [
                'feedService' => $this->feedServiceReturning($this->feed()),
                'communityService' => $communityService,
            ],
            query: $query
        );
    }

    private function member(int $id): array
    {
        return ['id' => $id, 'username' => 'u'.$id, 'nick' => 'U'.$id, 'avatarUrl' => '', 'roleLevel' => 1];
    }

    public function testMembersComeBackAsCardsWithATotal(): void
    {
        $module = $this->membersModule(['items' => [$this->member(1), $this->member(2)], 'total' => 2]);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $payload = json_decode(
            $this->capture(fn () => $module->callApi($this->apiPage('community.members'), ['id' => '42'])),
            true
        );

        $this->assertCount(2, $payload['items']);
        $this->assertSame('U1', $payload['items'][0]['displayName']);
        $this->assertSame(2, $payload['meta']['total']);
    }

    public function testThereIsNoNextPageOnceEverybodyHasBeenSent(): void
    {
        // The button has to disappear: `total > offset + count` is false here,
        // so nextOffset is null.
        $module = $this->membersModule(['items' => [$this->member(1)], 'total' => 1]);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $payload = json_decode(
            $this->capture(fn () => $module->callApi($this->apiPage('community.members'), ['id' => '42'])),
            true
        );

        $this->assertNull($payload['meta']['nextOffset']);
    }

    public function testTheNextOffsetIsWhereThisPageEnded(): void
    {
        $module = $this->membersModule(
            ['items' => [$this->member(1), $this->member(2)], 'total' => 30],
            ['offset' => '12']
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $payload = json_decode(
            $this->capture(fn () => $module->callApi($this->apiPage('community.members'), ['id' => '42'])),
            true
        );

        $this->assertSame(14, $payload['meta']['nextOffset']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badOffsetProvider(): array
    {
        return [
            // Clamped, not trusted: a negative offset in a LIMIT clause is a
            // SQL error, and a non-numeric one used to be cast to 0 anyway.
            'negative' => ['-5'],
            'nonsense' => ['abc'],
            'fractional' => ['1.5'],
            'empty' => [''],
        ];
    }

    #[DataProvider('badOffsetProvider')]
    public function testAnUnusableOffsetBecomesZero(string $offset): void
    {
        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->once())
            ->method('getMembers')
            ->with($this->isInstanceOf(Feed::class), 12, 0)
            ->willReturn(['items' => [], 'total' => 0]);

        $module = $this->makeModule(
            [
                'feedService' => $this->feedServiceReturning($this->feed()),
                'communityService' => $communityService,
            ],
            query: ['offset' => $offset]
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture(fn () => $module->callApi($this->apiPage('community.members'), ['id' => '42']));
    }

    public function testListingMembersOfSomethingThatIsNotACommunityIsNotFound(): void
    {
        $module = $this->makeModule([
            'feedService' => $this->feedServiceReturning($this->feed(type: 'blog-post')),
            'communityService' => $this->createStub(CommunityService::class),
        ]);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->expectException(NotFoundException::class);

        $module->callApi($this->apiPage('community.members'), ['id' => '42']);
    }

    /* ===============================
       Paging the post list
    =============================== */

    public function testThePostFeedPassesTheTagFilterThrough(): void
    {
        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->once())
            ->method('getFeedsByTypePage')
            ->with('blog-post', $this->isInstanceOf(User::class), 10, 0, 'php')
            ->willReturn(['items' => [], 'total' => 0]);

        $module = $this->makeModule(['feedService' => $feedService], query: ['tag' => '  php  ']);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture(fn () => $module->callApi($this->apiPage('community.posts')));
    }

    public function testAnEmptyTagMeansNoFilterRatherThanAnEmptyOne(): void
    {
        // null, not '' - the repository branches on null to decide whether to
        // join the term table at all.
        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->once())
            ->method('getFeedsByTypePage')
            ->with('blog-post', $this->anything(), 10, 0, null)
            ->willReturn(['items' => [], 'total' => 0]);

        $module = $this->makeModule(['feedService' => $feedService], query: ['tag' => '   ']);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->capture(fn () => $module->callApi($this->apiPage('community.posts')));
    }

    public function testAnEmptyPostFeedIsAnEmptyListRatherThanNothing(): void
    {
        $module = $this->makeModule(['feedService' => $this->feedServiceReturning(null)]);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $payload = json_decode($this->capture(fn () => $module->callApi($this->apiPage('community.posts'))), true);

        // The client renders `items` unconditionally, so it has to be an
        // array - `[]`, not null and not a missing key.
        $this->assertSame([], $payload['items']);
        $this->assertNull($payload['meta']['nextOffset']);
    }

    public function testACommunitysOwnPostFeedUsesParentScopedPaging(): void
    {
        $post = $this->feed(id: 900, type: 'blog-post', title: 'Первый пост');

        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->with(42, $this->anything())->willReturn($this->feed());
        $feedService->expects($this->once())
            ->method('getFeedsByParentAndTypePage')
            ->with(42, 'blog-post', $this->anything(), 10, 20)
            ->willReturn(['items' => [$post], 'total' => 25]);

        $module = $this->makeModule(['feedService' => $feedService], query: ['offset' => '20']);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $payload = json_decode(
            $this->capture(fn () => $module->callApi($this->apiPage('community.scoped-blog-posts'), ['id' => '42'])),
            true
        );

        $this->assertSame('Первый пост', $payload['items'][0]['title']);
        $this->assertSame(25, $payload['meta']['total']);
        $this->assertSame(21, $payload['meta']['nextOffset']);
    }

    public function testListingACommunitysOwnPostsRequiresACommunity(): void
    {
        $module = $this->makeModule([
            'feedService' => $this->feedServiceReturning($this->feed(type: 'blog-post')),
        ]);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->expectException(NotFoundException::class);

        $module->callApi($this->apiPage('community.scoped-blog-posts'), ['id' => '42']);
    }
}
