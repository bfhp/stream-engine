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
use StreamEngine\Modules\Users\CommunityService;
use StreamEngine\Modules\Users\UsersController;
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

/**
 * The last of the community group: the two form pages and the post-card
 * builder.
 *
 * `resolveCommunityFeed()` is the interesting one, and it is shared by every
 * community *page*. It carries the same guard the JSON endpoints do -
 * `type !== 'community'` - which matters here for a different reason: these
 * pages are reached by a top-level, typed slug lookup. The type guard remains
 * defensive: a malformed repository/service result must not turn another
 * feed type into a community form target.
 *
 * The rest is view assembly, where the two things worth pinning are the ones
 * with consequences outside the page: `apiUrl` (get it wrong and the form
 * posts into the wrong community, or into the author's own blog) and
 * `isCommunityPost`, the single flag that makes the `members` visibility
 * option mean "only members" rather than "only friends".
 */
final class UsersControllerCommunityPagesTest extends TestCase
{
    private function makeModule(array $services = [], ?User $user = null, array $pages = []): UsersController
    {
        $db = $this->createStub(PdoDatabase::class);
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

        $pageTree = new PageTree($pages);
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
        if (! isset($services['uploadService'])) {
            $limit = 500 * 1024 * 1024;
            $uploadService = $this->createStub(UploadService::class);
            $uploadService->method('getUserStorageUsage')->willReturn([
                'used' => 64 * 1024 * 1024,
                'limit' => $limit,
                'remaining' => $limit - 64 * 1024 * 1024,
            ]);
            $reflection->getProperty('uploadService')->setValue($module, $uploadService);
        }

        foreach ($services as $name => $service) {
            $reflection->getProperty($name)->setValue($module, $service);
        }

        return $module;
    }

    private function call(UsersController $module, string $method, mixed ...$args): mixed
    {
        return (new ReflectionClass(UsersController::class))->getMethod($method)->invoke($module, ...$args);
    }

    private function page(string $action, string $pattern = 'new', ?int $feedId = null): Page
    {
        return new Page(
            id: 70,
            parentId: null,
            pattern: $pattern,
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: $feedId,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_AUTHENTICATED,
            action: $action,
        );
    }

    private function feed(
        int $id = 42,
        string $type = 'community',
        ?string $slug = 'devs',
        ?string $title = 'Разработчики',
        ?string $description = null,
        ?string $content = null,
    ): Feed {
        return new Feed(
            id: $id,
            parentId: null,
            ownerId: 7,
            type: $type,
            slug: $slug,
            title: $title,
            description: $description,
            imageUrl: null,
            content: $content,
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: 1700000000,
            relevance: null,
            canonicalUrl: '/communities/devs/post/',
            authorDisplayName: 'Аноним',
            authorAvatarUrl: '',
        );
    }

    private function feedService(?Feed $bySlug = null, ?Feed $byId = null): FeedService
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedByParentAndSlug')->willReturn($bySlug);
        $feedService->method('getFeedById')->willReturn($byId);
        $feedService->method('countCommentsForFeeds')->willReturn([]);

        return $feedService;
    }

    private function permissive(): CommunityService
    {
        $communityService = $this->createStub(CommunityService::class);
        $communityService->method('canPost')->willReturn(true);

        return $communityService;
    }

    /* ===============================
       Resolving the community
    =============================== */

    public function testACommunityIsFoundByItsSlug(): void
    {
        $community = $this->feed();
        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->once())
            ->method('getFeedByParentAndSlug')
            ->with(null, 'devs', $this->anything(), 'community')
            ->willReturn($community);
        $module = $this->makeModule(['feedService' => $feedService]);

        $resolved = $this->call(
            $module,
            'resolveCommunityFeed',
            $this->page('community.post-new'),
            ['slug' => 'devs'],
            new User(0, '', AccessService::ROLE_USER)
        );

        $this->assertSame(42, $resolved->id);
    }

    public function testAPageBoundToAFeedIdDoesNotNeedASlug(): void
    {
        // Some community pages are pinned to one feed in the `pages` table;
        // those take the id branch and ignore the URL entirely.
        $module = $this->makeModule(['feedService' => $this->feedService(byId: $this->feed())]);

        $resolved = $this->call(
            $module,
            'resolveCommunityFeed',
            $this->page('community.post-new', feedId: 42),
            [],
            new User(0, '', AccessService::ROLE_USER)
        );

        $this->assertSame(42, $resolved->id);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function unresolvableProvider(): array
    {
        return [
            'no slug in the url' => [[]],
            'an empty slug' => [['slug' => '']],
            'whitespace only' => [['slug' => '   ']],
            'nothing with that slug' => [['slug' => 'ghosts']],
        ];
    }

    /**
     * @param array<string, string> $args
     */
    #[DataProvider('unresolvableProvider')]
    public function testAnUnresolvableCommunityIsNotFound(array $args): void
    {
        $module = $this->makeModule(['feedService' => $this->feedService()]);

        $this->expectException(NotFoundException::class);

        $this->call($module, 'resolveCommunityFeed', $this->page('community.post-new'), $args, new User(0, '', AccessService::ROLE_USER));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wrongTypeProvider(): array
    {
        return [
            'a publication' => ['publication'],
            'a blog post' => ['blog-post'],
            'a forum' => ['forum'],
            'an article' => ['article'],
        ];
    }

    /**
     * Defensive guard for a malformed result from the typed lookup: another
     * feed type must never become a community form target.
     */
    #[DataProvider('wrongTypeProvider')]
    public function testSomethingThatIsNotACommunityIsNotFoundEitherWay(string $type): void
    {
        $module = $this->makeModule([
            'feedService' => $this->feedService(bySlug: $this->feed(type: $type)),
        ]);

        $this->expectException(NotFoundException::class);

        $this->call(
            $module,
            'resolveCommunityFeed',
            $this->page('community.post-new'),
            ['slug' => 'devs'],
            new User(0, '', AccessService::ROLE_USER)
        );
    }

    /* ===============================
       The new-post form
    =============================== */

    private function postFormModule(?CommunityService $communityService = null, array $pages = []): UsersController
    {
        return $this->makeModule(
            [
                'feedService' => $this->feedService(bySlug: $this->feed()),
                'communityService' => $communityService ?? $this->permissive(),
            ],
            pages: $pages
        );
    }

    public function testAVisitorWhoMayNotPostCannotOpenTheForm(): void
    {
        // The same gate the POST endpoint applies, on the page that leads to
        // it - otherwise the form renders and the submit fails, which reads as
        // a broken site rather than as a closed community.
        $communityService = $this->createStub(CommunityService::class);
        $communityService->method('canPost')->willReturn(false);

        $this->expectException(ForbiddenException::class);

        $this->postFormModule($communityService)->show($this->page('community.post-new'), ['slug' => 'devs']);
    }

    public function testTheFormPostsToThisCommunitysOwnEndpoint(): void
    {
        $view = $this->postFormModule()->show($this->page('community.post-new'), ['slug' => 'devs']);

        // The community's id, not its slug: get this wrong and the post lands
        // in a different community or in the author's personal blog.
        $this->assertSame('/api/v1/communities/42/blog-posts', $view->data['apiUrl']);
    }

    public function testTheFormIsToldItIsACommunityPost(): void
    {
        $view = $this->postFormModule()->show($this->page('community.post-new'), ['slug' => 'devs']);

        // One flag, and the whole meaning of the `members` visibility option
        // hangs off it: "только участники" rather than "только друзья".
        $this->assertTrue($view->data['isCommunityPost']);
    }

    public function testTheFormNamesTheCommunityItIsFor(): void
    {
        $view = $this->postFormModule()->show($this->page('community.post-new'), ['slug' => 'devs']);

        $this->assertStringContainsString('Разработчики', $view->data['description']);
        $this->assertSame('modules/users/blog-post-form.twig', $view->template);
        $this->assertSame('64.0 МБ', $view->data['uploadStorageUsedLabel']);
        $this->assertSame('436.0 МБ', $view->data['uploadStorageRemainingLabel']);
    }

    public function testCancellingGoesBackToTheCommunity(): void
    {
        $communityShow = $this->page('community.show', '{slug}');

        $view = $this->postFormModule(pages: [$communityShow])->show(
            $this->page('community.post-new'),
            ['slug' => 'devs']
        );

        $this->assertSame('/devs/', $view->data['cancelUrl']);
    }

    public function testWithoutACommunityShowPageThereIsNoCancelLink(): void
    {
        // Rather than a link to nowhere. The template hides the button when
        // this is null.
        $view = $this->postFormModule()->show($this->page('community.post-new'), ['slug' => 'devs']);

        $this->assertNull($view->data['cancelUrl']);
    }

    public function testTheFormLoadsTheEditorAndItsStyles(): void
    {
        // trix.css is linked explicitly because Vite splits it into a shared
        // chunk once another entry imports it - the note in the source says so,
        // and this is what would catch its removal.
        $headExt = implode("\n", $this->postFormModule()->show(
            $this->page('community.post-new'),
            ['slug' => 'devs']
        )->data['head_ext']);

        $this->assertStringContainsString('/assets/css/trix.css', $headExt);
        $this->assertStringContainsString('/assets/js/blog-post-form.js', $headExt);
        // The bundle imports shared code, so a classic script would not run -
        // see Tests\Core\AssetBundlesTest.
        $this->assertStringContainsString('type="module"', $headExt);
    }

    /* ===============================
       The create-community form
    =============================== */

    public function testTheCreateFormOffersBothMembershipTypes(): void
    {
        $view = $this->makeModule()->show($this->page('community.create'));

        // Passed to the template rather than hard-coded in it, so the radio
        // values and what CommunityService accepts cannot drift apart.
        $this->assertSame(CommunityService::MEMBERSHIP_TYPE_OPEN, $view->data['membershipTypeOpen']);
        $this->assertSame(CommunityService::MEMBERSHIP_TYPE_APPROVAL, $view->data['membershipTypeApproval']);
    }

    public function testTheCreateFormPostsToTheCommunitiesCollection(): void
    {
        $view = $this->makeModule()->show($this->page('community.create'));

        $this->assertSame('/api/v1/communities', $view->data['apiUrl']);
        $this->assertSame('/api/v1/uploads', $view->data['uploadsApiUrl']);
        $this->assertSame('modules/users/community-create.twig', $view->template);
    }

    public function testTheCreateFormCancelsToTheCommunityIndex(): void
    {
        $view = $this->makeModule(pages: [$this->page('community.main', 'communities')])
            ->show($this->page('community.create'));

        $this->assertSame('/communities/', $view->data['cancelUrl']);
    }

    /**
     * A canonical URL with a `{` in it is an ancestor pattern that never got
     * filled in - so it is dropped rather than emitted. Low stakes here (the
     * page is role-gated and marked noindex) but a `<link rel="canonical"
     * href="/{slug}/new/">` would be nonsense in the markup.
     */
    public function testAnUnfilledCanonicalPatternIsDroppedRatherThanEmitted(): void
    {
        $view = $this->makeModule()->show($this->page('community.create', '{slug}'));

        $this->assertNull($view->data['canonical']);
        $this->assertStringNotContainsString('canonical', implode("\n", $view->data['head_ext']));
    }

    public function testAResolvedCanonicalIsPassedToTheLayout(): void
    {
        $view = $this->makeModule()->show($this->page('community.create', 'new-community'));

        $this->assertSame('/new-community/', $view->data['canonical']);
        $this->assertStringNotContainsString('rel="canonical"', implode("\n", $view->data['head_ext']));
    }

    /* ===============================
       Post cards
    =============================== */

    public function testNoPostsIsNoCards(): void
    {
        // The early return skips three queries - two of which would be
        // `WHERE id IN ()` with an empty list.
        $module = $this->makeModule(['feedService' => $this->feedService()]);

        $this->assertSame([], $this->call($module, 'buildCommunityPostCards', []));
    }

    public function testAPostCardCarriesWhatTheGridShows(): void
    {
        $post = $this->feed(id: 900, type: 'blog-post', title: 'Мой пост', content: '<p>Немного текста тут</p>');

        $cards = $this->call(
            $this->makeModule(['feedService' => $this->feedService()]),
            'buildCommunityPostCards',
            [$post]
        );

        $this->assertSame('Мой пост', $cards[0]['title']);
        $this->assertSame('/communities/devs/post/', $cards[0]['url']);
        $this->assertSame('Немного текста тут', $cards[0]['excerpt']);
        $this->assertSame('1 мин', $cards[0]['readTimeLabel']);
        // No comment rows came back, so zero rather than a missing key.
        $this->assertSame(0, $cards[0]['commentCount']);
        $this->assertSame([], $cards[0]['tags']);
    }

    public function testTheDescriptionIsPreferredOverTheBodyForTheExcerpt(): void
    {
        // An author-written description beats the first 160 characters of the
        // post, which is the whole reason the field exists.
        $post = $this->feed(
            id: 900,
            type: 'blog-post',
            description: 'Своими словами',
            content: '<p>А тут длинный текст, который в карточку не нужен</p>'
        );

        $cards = $this->call(
            $this->makeModule(['feedService' => $this->feedService()]),
            'buildCommunityPostCards',
            [$post]
        );

        $this->assertSame('Своими словами', $cards[0]['excerpt']);
    }

    /**
     * The author fallback. `findPublicUsersByIds()` returns only *active*
     * users, so a post by somebody since deactivated has no row - and the card
     * falls back to the denormalised name stored on the feed rather than
     * rendering blank.
     */
    public function testAPostByAMissingAuthorStillShowsAName(): void
    {
        $post = $this->feed(id: 900, type: 'blog-post');

        $cards = $this->call(
            $this->makeModule(['feedService' => $this->feedService()]),
            'buildCommunityPostCards',
            [$post]
        );

        $this->assertSame('Аноним', $cards[0]['authorName']);
        // And no link, because there is no profile to link to.
        $this->assertNull($cards[0]['authorUrl']);
        // The avatar is still resolved, so the row does not render a broken
        // image.
        $this->assertNotSame('', $cards[0]['authorAvatarUrl']);
    }

    public function testEveryPostBecomesACard(): void
    {
        $posts = [
            $this->feed(id: 900, type: 'blog-post', title: 'Раз'),
            $this->feed(id: 901, type: 'blog-post', title: 'Два'),
        ];

        $cards = $this->call(
            $this->makeModule(['feedService' => $this->feedService()]),
            'buildCommunityPostCards',
            $posts
        );

        $this->assertSame(['Раз', 'Два'], array_column($cards, 'title'));
    }

    /* ===============================
       Reading time
    =============================== */

    /**
     * @return array<string, array{string, string}>
     */
    public static function readingTimeProvider(): array
    {
        return [
            // Never zero: "0 мин" reads as broken, and every post takes some
            // reading.
            'empty' => ['', '1 мин'],
            'a few words' => ['<p>Три слова тут</p>', '1 мин'],
            'exactly one minute' => [str_repeat('слово ', 150), '1 мин'],
            'just over' => [str_repeat('слово ', 151), '2 мин'],
            'ten minutes' => [str_repeat('слово ', 1500), '10 мин'],
            // Markup is not words.
            'markup only' => ['<hr><br><img src="x">', '1 мин'],
        ];
    }

    #[DataProvider('readingTimeProvider')]
    public function testReadingTimeRoundsUpAndNeverReachesZero(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->call($this->makeModule(), 'estimateReadingTime', $html));
    }
}
