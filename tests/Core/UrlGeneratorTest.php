<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\FeedTerm;
use StreamEngine\Domain\Page;
use StreamEngine\Service\AccessService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

final class UrlGeneratorTest extends TestCase
{
    private UrlGenerator $urlGenerator;

    private static function makeFeed(
        int $id,
        string $type,
        ?int $parentId = null,
        ?string $slug = null,
        ?string $containerType = null,
    ): Feed {
        return new Feed(
            id: $id,
            parentId: $parentId ?? null,
            ownerId: 0,
            type: $type,
            slug: $slug,
            title: '',
            description: null,
            imageUrl: null,
            content: null,
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: null,
            relevance: null,
            canonicalUrl: null,
            containerType: $containerType,
        );
    }

    private static function makePage(
        int $id,
        ?int $parentId,
        string $pattern,
        ?int $feedId = null,
        ?string $feedType = null,
        ?string $termVocabulary = null,
    ): Page {
        return new Page(
            id: $id,
            parentId: $parentId,
            pattern: $pattern,
            pageName: 'Page '.$id,
            settings: null,
            feedType: $feedType,
            listFeedType: null,
            feedId: $feedId,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'raw',
            accessRule: AccessService::ACCESS_PUBLIC,
            termVocabulary: $termVocabulary
        );
    }

    protected function setUp(): void
    {
        // Pages

        // Feeds
        $feeds = [
            // Article
            2 => self::makeFeed(
                id: 2,
                type: 'article',
            ),
            // Book
            3 => self::makeFeed(
                id: 3,
                type: 'publication',
                slug: 'master-i-margarita',
            ),
            // Book page 1
            4 => self::makeFeed(
                id: 4,
                type: 'chapter',
                parentId: 3,
                slug: '1',
            ),
            // Comment
            6 => self::makeFeed(
                id: 6,
                type: 'comment',
                parentId: 3,
            ),
            7 => self::makeFeed(
                id: 7,
                type: 'chapter',
                parentId: 4,
                slug: '1a',
            ),
        ];

        $repository = new FakeFeedRepository($feeds);
        $cache = new ArrayCache();

        $this->urlGenerator = new UrlGenerator(
            $this->getPageTree(),
            $repository,
            $cache
        );
    }

    private function getPageTree(): PageTree
    {
        $pages = [
            // /rules/
            self::makePage(
                id: 2,
                parentId: 1,
                pattern: 'rules',
                feedId: 2,
            ),

            // /publication/
            self::makePage(
                id: 3,
                parentId: 1,
                pattern: 'publication',
            ),

            // /publication/{slug}/
            self::makePage(
                id: 4,
                parentId: 3,
                pattern: '{slug}',
                feedType: 'publication',
            ),

            // /publication/{slug}/{slug}/
            self::makePage(
                id: 5,
                parentId: 4,
                pattern: '{slug}',
                feedType: 'chapter',
            ),

            // /publication/{slug}/comments/
            self::makePage(
                id: 6,
                parentId: 4,
                pattern: 'comments',
                feedType: 'comment',
            ),
        ];

        return new PageTree($pages);
    }

    public function testPageWithFeedId(): void
    {
        $feed = self::makeFeed(
            id: 2,
            type: 'article',
        );

        $url = $this->urlGenerator->feed($feed);

        $this->assertEquals('/rules/', $url);
    }

    public function testSimpleFeedType(): void
    {
        $feed = self::makeFeed(
            id: 3,
            type: 'publication',
            slug: 'master-i-margarita',
        );

        $url = $this->urlGenerator->feed($feed);

        $this->assertEquals('/publication/master-i-margarita/', $url);
    }

    public function testNestedFeed(): void
    {
        $feed = self::makeFeed(
            id: 4,
            type: 'chapter',
            parentId: 3,
            slug: '1',
        );

        $url = $this->urlGenerator->feed($feed);

        $this->assertEquals('/publication/master-i-margarita/1/', $url);
    }

    public function testNestedFeedCanStartFromParentFeedPageAnchor(): void
    {
        $feeds = [
            15 => self::makeFeed(
                id: 15,
                type: 'article',
            ),
            16 => self::makeFeed(
                id: 16,
                type: 'article-section',
                parentId: 15,
                slug: 'basic',
            ),
            17 => self::makeFeed(
                id: 17,
                type: 'article',
                parentId: 16,
                slug: 'intro',
            ),
        ];

        $pages = [
            self::makePage(
                id: 1,
                parentId: null,
                pattern: '',
            ),
            self::makePage(
                id: 9,
                parentId: 1,
                pattern: 'study',
            ),
            self::makePage(
                id: 28,
                parentId: 9,
                pattern: 'lectures',
                feedId: 15,
            ),
            self::makePage(
                id: 29,
                parentId: 28,
                pattern: '{slug}',
                feedType: 'article-section',
            ),
            self::makePage(
                id: 30,
                parentId: 29,
                pattern: '{slug}',
                feedType: 'article',
            ),
        ];

        $generator = new UrlGenerator(
            new PageTree($pages),
            new FakeFeedRepository($feeds),
            new ArrayCache()
        );

        $this->assertEquals('/study/lectures/basic/', $generator->feed($feeds[16]));
        $this->assertEquals('/study/lectures/basic/intro/', $generator->feed($feeds[17]));
    }

    public function testStaticChildSegment(): void
    {
        $feed = self::makeFeed(
            id: 6,
            type: 'comment',
            parentId: 3,
        );

        $url = $this->urlGenerator->feed($feed);

        $this->assertEquals('/publication/master-i-margarita/comments/', $url);
    }

    public function testBatchMode(): void
    {
        $feeds = [
            self::makeFeed(
                id: 3,
                type: 'publication',
                slug: 'master-i-margarita',
            ),
            self::makeFeed(
                id: 4,
                type: 'chapter',
                parentId: 3,
                slug: '1',
            )
        ];

        $urls = $this->urlGenerator->feeds($feeds);

        $this->assertEquals('/publication/master-i-margarita/', $urls[3]);
        $this->assertEquals('/publication/master-i-margarita/1/', $urls[4]);
    }

    // The build errors below are RuntimeExceptions thrown inside buildFeedUrl(),
    // but feeds() catches them, logs via error_log() and drops the feed from the
    // result. The observable contract is therefore: feed() returns null AND an
    // error is logged. expectErrorLog() guards the logging side effect.

    public function testMissingSlugReturnsNullAndLogs(): void
    {
        $repo = new FakeFeedRepository([
            100 => self::makeFeed(
                id: 100,
                type: 'publication',
            )
        ]);

        $cache = new ArrayCache();

        $generator = new UrlGenerator(
            $this->getPageTree(),
            $repo,
            $cache
        );

        $this->expectErrorLog();

        $this->assertNull($generator->feed(self::makeFeed(
            id: 100,
            type: 'publication',
        )));
    }

    public function testMissingPageForFeedTypeReturnsNullAndLogs(): void
    {
        $this->expectErrorLog();

        $feed = self::makeFeed(
            id: 99,
            type: 'unknown',
            slug: 'slug',
        );

        $this->assertNull($this->urlGenerator->feed($feed));
    }

    public function testMissingChildPageReturnsNullAndLogs(): void
    {
        $this->expectErrorLog();

        $feed = self::makeFeed(
            id: 10,
            type: 'publication-subpage',
            parentId: 3,
            slug: 'x',
        );

        $this->assertNull($this->urlGenerator->feed($feed));
    }

    public function testUnknownFeedReturnsNullAndLogs(): void
    {
        $this->expectErrorLog();

        $feed = self::makeFeed(
            id: 999,
            type: 'publication',
            slug: 'ghost',
        );

        $this->assertNull($this->urlGenerator->feed($feed));
    }

    /**
     * A 'chapter' nested under another 'chapter' (feed 7 under feed 4,
     * both type 'chapter') is a self-referential run - the exact same
     * structural shape as a forum nested under another forum - and only
     * one page is registered for that type (id 5, '/publication/{slug}/{slug}/'),
     * with no further child page for a second chapter level.
     *
     * This used to be treated as a configuration error (null + a logged
     * warning): UrlGenerator required a *distinct* page per chain level,
     * which a recursive type can never satisfy. Now that
     * collapseConsecutiveSameType() collapses a same-type run down to its
     * deepest entry before any type-to-page matching happens, this
     * resolves like any other feed: the one page registered for
     * 'chapter' is reused, filled with the deepest (leaf) feed's own
     * slug - the intermediate 'chapter' (feed 4) contributes nothing to
     * the URL, same as an intermediate subforum wouldn't.
     */
    public function testDeepNestedFeedResolvesToDeepestSameTypeSlug(): void
    {
        $feed = self::makeFeed(
            id: 7,
            type: 'chapter',
            parentId: 4,
            slug: '1a',
        );

        $url = $this->urlGenerator->feed($feed);

        $this->assertEquals('/publication/master-i-margarita/1a/', $url);
    }

    public function testBatchWithPartialCache(): void
    {
        $feed1 = self::makeFeed(
            id: 3,
            type: 'publication',
            slug: 'master-i-margarita',
        );
        $feed2 = self::makeFeed(
            id: 4,
            type: 'chapter',
            parentId: 3,
            slug: '1',
        );

        // warming up cache for the first one
        $this->urlGenerator->feed($feed1);

        $urls = $this->urlGenerator->feeds([$feed1, $feed2]);

        $this->assertEquals('/publication/master-i-margarita/', $urls[3]);
        $this->assertEquals('/publication/master-i-margarita/1/', $urls[4]);
    }

    public function testFeedTermUrl(): void
    {
        $pages = [
            self::makePage(1, null, ''),
            self::makePage(7, 1, 'biblioteka'),
            self::makePage(41, 7, 'authors'),
            self::makePage(42, 41, '{slug}', termVocabulary: 'author'),
        ];

        $generator = new UrlGenerator(
            new PageTree($pages),
            new FakeFeedRepository([]),
            new ArrayCache()
        );

        $term = new FeedTerm(
            id: 2,
            vocabulary: 'author',
            name: 'Кандыба Виктор Михайлович',
            slug: 'kandyba_viktor_mihajilovich',
        );

        $this->assertSame('/biblioteka/authors/kandyba_viktor_mihajilovich/', $generator->feedTerm($term));
    }

    /**
     * Regression test for the reported bug: 'user.show' is registered with
     * feed_type='blog' directly - it doubles as the personal-blog "root"
     * page, same as community.show for a community feed - and its own
     * pattern is {username}, not {slug}. buildUrlFromPageChain() used to
     * only ever fill the literal '{slug}' token, so this page's placeholder
     * was left untouched no matter what. fillFeedPlaceholder() now fills
     * whatever single placeholder a feed-typed page's pattern has from that
     * feed's own slug column - which is why
     * BlogPostService::getOrCreateUserBlogFeed() sets the personal blog
     * feed's own slug to its owner's username instead of leaving it null.
     */
    public function testPersonalBlogPostFillsUsernamePlaceholderFromBlogFeedSlug(): void
    {
        $feeds = [
            50 => self::makeFeed(
                id: 50,
                type: 'blog',
                slug: 'alice',
            ),
            51 => self::makeFeed(
                id: 51,
                type: 'blog-post',
                parentId: 50,
                slug: 'my-post',
            ),
        ];

        $pages = [
            self::makePage(id: 1, parentId: null, pattern: ''),
            self::makePage(id: 2, parentId: 1, pattern: 'users'),
            $this->makeActionPage(id: 3, parentId: 2, pattern: '{username}', action: 'user.show', feedType: 'blog'),
            $this->makeActionPage(id: 4, parentId: 3, pattern: '{slug}', action: 'user.post-show', feedType: 'blog-post'),
        ];

        $generator = new UrlGenerator(
            new PageTree($pages),
            new FakeFeedRepository($feeds),
            new ArrayCache()
        );

        $this->assertSame('/users/alice/my-post/', $generator->feed($feeds[51]));
    }

    /**
     * A blog feed with no slug of its own (created before slug-on-create
     * shipped, or otherwise missing one) has nothing for
     * fillFeedPlaceholder() to fill {username} with - same failure as any
     * other feed-typed page whose feed has no slug: null + a logged
     * warning, never a URL with a literal unfilled placeholder (the actual
     * shape of the reported bug).
     */
    public function testPersonalBlogPostReturnsNullAndLogsWithoutBlogFeedSlug(): void
    {
        $feeds = [
            50 => self::makeFeed(
                id: 50,
                type: 'blog',
            ),
            51 => self::makeFeed(
                id: 51,
                type: 'blog-post',
                parentId: 50,
                slug: 'my-post',
            ),
        ];

        $pages = [
            self::makePage(id: 1, parentId: null, pattern: ''),
            self::makePage(id: 2, parentId: 1, pattern: 'users'),
            $this->makeActionPage(id: 3, parentId: 2, pattern: '{username}', action: 'user.show', feedType: 'blog'),
            $this->makeActionPage(id: 4, parentId: 3, pattern: '{slug}', action: 'user.post-show', feedType: 'blog-post'),
        ];

        $generator = new UrlGenerator(
            new PageTree($pages),
            new FakeFeedRepository($feeds),
            new ArrayCache()
        );

        $this->expectErrorLog();

        $this->assertNull($generator->feed($feeds[51]));
    }

    /**
     * Regression test for the reported bug: a community post used to
     * resolve to the personal-blog URL shape instead of /comm/{slug}/{slug}/
     * - unlike the personal-blog case above, 'community' pages ARE
     * registered by feed_type (community.show, slug-bearing), so this
     * exercises buildPageChainFromRootFeedType()'s normal (non-fallback)
     * path plus appendFeedTypePages() consuming the child blog-post feed
     * by feed type/position - confirming the two 'blog-post' pages sharing
     * feed_type='blog-post' (community.post-show vs user.post-show) don't
     * collide here the way UrlGenerator::page() can when two ancestor
     * pages share a placeholder *name*.
     */
    public function testCommunityPostUsesCommunityFeedTypePage(): void
    {
        $feeds = [
            60 => self::makeFeed(
                id: 60,
                type: 'community',
                slug: 'my-community',
            ),
            61 => self::makeFeed(
                id: 61,
                type: 'blog-post',
                parentId: 60,
                slug: 'my-post',
                containerType: 'community',
            ),
        ];

        $pages = [
            self::makePage(id: 1, parentId: null, pattern: ''),
            self::makePage(id: 2, parentId: 1, pattern: 'comm'),
            $this->makeActionPage(id: 3, parentId: 2, pattern: '{slug}', action: 'community.show', feedType: 'community'),
            $this->makeActionPage(id: 4, parentId: 3, pattern: '{slug}', action: 'community.post-show', feedType: 'blog-post'),
        ];

        $generator = new UrlGenerator(
            new PageTree($pages),
            new FakeFeedRepository($feeds),
            new ArrayCache()
        );

        $this->assertSame('/comm/my-community/my-post/', $generator->feed($feeds[61]));
    }

    /**
     * Regression test for the originally reported bug: a subforum (a
     * `type='forum'` feed nested under another `type='forum'` feed) used
     * to throw "No child page for feed type 'forum'" inside
     * appendFeedTypePages() - there's only one page registered for
     * feed_type 'forum', and it has no child page of that same type to
     * represent a second level - so `feed()` silently returned null for
     * every subforum. collapseConsecutiveSameType() now drops every
     * non-deepest 'forum' in the chain before type-to-page matching runs,
     * so the subforum resolves through that one page using its OWN slug -
     * not its parent forum's - matching the forum breadcrumb mockup's
     * URLs (a subforum's URL never grows with nesting depth).
     */
    public function testSubforumResolvesUsingItsOwnSlug(): void
    {
        $feeds = [
            70 => self::makeFeed(id: 70, type: 'forum', slug: 'magiya-i-ritualy'),
            71 => self::makeFeed(id: 71, type: 'forum', parentId: 70, slug: 'prakticheskaya-magiya'),
        ];

        $pages = [
            self::makePage(id: 1, parentId: null, pattern: ''),
            self::makePage(id: 2, parentId: 1, pattern: 'forums'),
            $this->makeActionPage(id: 3, parentId: 2, pattern: '{slug}', action: 'forums.topic-list', feedType: 'forum'),
        ];

        $generator = new UrlGenerator(
            new PageTree($pages),
            new FakeFeedRepository($feeds),
            new ArrayCache()
        );

        $this->assertSame('/forums/prakticheskaya-magiya/', $generator->feed($feeds[71]));
    }

    /**
     * Same self-referential 'forum' chain as above, but with a topic
     * (type 'forum-post' - a *different* type) at the leaf. Distinct-typed
     * children already worked before this fix (same shape as publication ->
     * chapter, or community -> blog-post above) - collapse only removes
     * the intermediate same-type 'forum' entries, it never touches the
     * topic, so a topic in a subforum gets a clean two-segment URL: the
     * subforum's own slug, then the topic's own slug.
     */
    public function testTopicInSubforumUsesSubforumAndTopicOwnSlugs(): void
    {
        $feeds = [
            70 => self::makeFeed(id: 70, type: 'forum', slug: 'magiya-i-ritualy'),
            71 => self::makeFeed(id: 71, type: 'forum', parentId: 70, slug: 'prakticheskaya-magiya'),
            72 => self::makeFeed(id: 72, type: 'forum-post', parentId: 71, slug: 'svecha-gasnet'),
        ];

        $pages = [
            self::makePage(id: 1, parentId: null, pattern: ''),
            self::makePage(id: 2, parentId: 1, pattern: 'forums'),
            $this->makeActionPage(id: 3, parentId: 2, pattern: '{slug}', action: 'forums.topic-list', feedType: 'forum'),
            $this->makeActionPage(id: 4, parentId: 3, pattern: '{slug}', action: 'forums.topic-view', feedType: 'forum-post'),
        ];

        $generator = new UrlGenerator(
            new PageTree($pages),
            new FakeFeedRepository($feeds),
            new ArrayCache()
        );

        $this->assertSame('/forums/prakticheskaya-magiya/svecha-gasnet/', $generator->feed($feeds[72]));
    }

    private function makeActionPage(
        int $id,
        ?int $parentId,
        string $pattern,
        string $action,
        ?string $feedType = null,
    ): Page {
        return new Page(
            id: $id,
            parentId: $parentId,
            pattern: $pattern,
            pageName: 'Page '.$id,
            settings: null,
            feedType: $feedType,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'raw',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action,
        );
    }

    public function testFeedTermUrlReturnsNullWithoutVocabularyPage(): void
    {
        $generator = new UrlGenerator(
            new PageTree([self::makePage(1, null, '')]),
            new FakeFeedRepository([]),
            new ArrayCache()
        );

        $term = new FeedTerm(
            id: 2,
            vocabulary: 'author',
            name: 'Кандыба Виктор Михайлович',
            slug: 'kandyba_viktor_mihajilovich',
        );

        $this->assertNull($generator->feedTerm($term));
    }
}
