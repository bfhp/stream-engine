<?php

declare(strict_types=1);

namespace Tests\Modules\Sitemap;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StreamEngine\Core\Config;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Sitemap\SitemapController;
use StreamEngine\Repository\FeedTermRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\TermService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

final class SitemapControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST']);
    }

    private static function page(
        int $id,
        ?int $parentId,
        string $pattern,
        string $responseType = 'html',
        string $accessRule = AccessService::ACCESS_PUBLIC,
        ?string $feedType = null,
        ?int $feedId = null,
        ?array $requestMethods = ['GET'],
        ?string $action = null,
        ?string $changefreq = 'weekly',
        ?int $updated = 1700000000,
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
            requestMethods: $requestMethods,
            responseType: $responseType,
            accessRule: $accessRule,
            action: $action,
            changefreq: $changefreq,
            updated: $updated,
            termVocabulary: $termVocabulary,
        );
    }

    private static function feed(int $id, string $type, string $slug, ?string $canonicalUrl = null): Feed
    {
        return new Feed(
            id: $id,
            parentId: null,
            ownerId: 1,
            type: $type,
            slug: $slug,
            title: 'Feed '.$id,
            description: null,
            imageUrl: null,
            content: null,
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: 1700000000,
            relevance: null,
            canonicalUrl: $canonicalUrl,
            updatedAt: 1700086400,
        );
    }

    private function makeModule(PageTree $pageTree, FeedService $feedService, ?PdoDatabase $db = null): SitemapController
    {
        $db ??= $this->createStub(PdoDatabase::class);
        if ($pageTree->findByAction('sitemap.sitemap') === null) {
            $pageTree->add(self::page(9999, 1, 'sitemap-{slug}.xml', 'raw', action: 'sitemap.sitemap'));
        }
        $urlGenerator = new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache());

        return new SitemapController(
            $db,
            new RequestContext(new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER), new \DateTimeZone('UTC')),
            $pageTree,
            $urlGenerator,
            $feedService,
            new TermService(new FeedTermRepository($db), $urlGenerator),
            new Config(['SITE_URL' => 'https://example.test']),
        );
    }

    public function testIndexIncludesPagesAndGuestVisibleFeedTypes(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.test';

        $pageTree = new PageTree([
            self::page(1, null, ''),
            self::page(2, 1, 'articles'),
            self::page(3, 2, '{slug}', feedType: 'article'),
            self::page(6, 1, 'biblioteka'),
            self::page(7, 6, 'authors'),
            self::page(8, 7, '{slug}', termVocabulary: 'author'),
            self::page(4, 1, 'private'),
            self::page(5, 4, '{slug}', accessRule: AccessService::ACCESS_AUTHENTICATED, feedType: 'secret'),
        ]);

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['countFeedsByType'])
            ->getMock();
        $feedService
            ->expects($this->once())
            ->method('countFeedsByType')
            ->with('article', $this->callback(static fn (User $user): bool => $user->isGuest()))
            ->willReturn(30001);

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('FROM feed_terms'), ['author'])
            ->willReturn(['total' => 30001]);

        $module = $this->makeModule($pageTree, $feedService, $db);

        ob_start();
        $module->callApi(self::page(100, 1, 'sitemap-{slug}.xml', 'raw', action: 'sitemap.sitemap'), ['slug' => 'index']);
        $output = ob_get_clean();

        $this->assertStringContainsString('<sitemapindex', $output);
        $this->assertStringContainsString('https://example.test/sitemap-pages.xml', $output);
        $this->assertStringContainsString('https://example.test/sitemap-article.xml', $output);
        $this->assertStringContainsString('https://example.test/sitemap-article-2.xml', $output);
        $this->assertStringContainsString('https://example.test/sitemap-term-author.xml', $output);
        $this->assertStringContainsString('https://example.test/sitemap-term-author-2.xml', $output);
        $this->assertStringNotContainsString('sitemap-secret.xml', $output);
    }

    public function testPagesSitemapContainsOnlyGuestHtmlStaticPages(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.test';

        $pageTree = new PageTree([
            self::page(1, null, ''),
            self::page(2, 1, 'about'),
            self::page(3, 1, 'admin', accessRule: AccessService::ACCESS_ADMIN),
            self::page(4, 1, 'api', responseType: 'json'),
            self::page(5, 1, 'article'),
            self::page(6, 5, '{slug}', feedType: 'article'),
            self::page(7, 1, 'search', changefreq: 'noindex'),
        ]);

        $module = $this->makeModule($pageTree, $this->createStub(FeedService::class));

        ob_start();
        $module->callApi(self::page(100, 1, 'sitemap-{slug}.xml', 'raw', action: 'sitemap.sitemap'), ['slug' => 'pages']);
        $output = ob_get_clean();

        $this->assertStringContainsString('<urlset', $output);
        $this->assertStringContainsString('https://example.test/', $output);
        $this->assertStringContainsString('https://example.test/about/', $output);
        $this->assertStringContainsString('https://example.test/article/', $output);
        $this->assertStringContainsString('<lastmod>2023-11-14</lastmod>', $output);
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $output);
        $this->assertStringNotContainsString('admin', $output);
        $this->assertStringNotContainsString('api', $output);
        $this->assertStringNotContainsString('search', $output);
        $this->assertStringNotContainsString('{slug}', $output);
    }

    public function testFeedTypeSitemapUsesGuestFeedsAndCanonicalUrls(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.test';

        $pageTree = new PageTree([
            self::page(1, null, ''),
            self::page(2, 1, 'articles'),
            self::page(3, 2, '{slug}', feedType: 'article'),
        ]);

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFeedsByType'])
            ->getMock();
        $feedService
            ->expects($this->once())
            ->method('getFeedsByType')
            ->with('article', $this->callback(static fn (User $user): bool => $user->isGuest()), 30000, 0)
            ->willReturn([
                self::feed(10, 'article', 'hello', '/articles/hello/'),
            ]);

        $module = $this->makeModule($pageTree, $feedService);

        ob_start();
        $module->callApi(self::page(100, 1, 'sitemap-{slug}.xml', 'raw', action: 'sitemap.sitemap'), ['slug' => 'article']);
        $output = ob_get_clean();

        $this->assertStringContainsString('<urlset', $output);
        $this->assertStringContainsString('https://example.test/articles/hello/', $output);
        $this->assertStringContainsString('<lastmod>2023-11-15</lastmod>', $output);
    }

    public function testFeedTypeSitemapCanRenderSecondChunk(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.test';

        $pageTree = new PageTree([
            self::page(1, null, ''),
            self::page(2, 1, 'lectures'),
            self::page(3, 2, '{slug}', feedType: 'article-section'),
        ]);

        $feedService = $this->getMockBuilder(FeedService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFeedsByType'])
            ->getMock();
        $feedService
            ->expects($this->once())
            ->method('getFeedsByType')
            ->with('article-section', $this->callback(static fn (User $user): bool => $user->isGuest()), 30000, 30000)
            ->willReturn([
                self::feed(30001, 'article-section', 'next', '/lectures/next/'),
            ]);

        $module = $this->makeModule($pageTree, $feedService);

        ob_start();
        $module->callApi(self::page(100, 1, 'sitemap-{slug}.xml', 'raw', action: 'sitemap.sitemap'), ['slug' => 'article-section-2']);
        $output = ob_get_clean();

        $this->assertStringContainsString('https://example.test/lectures/next/', $output);
    }

    public function testFeedTermSitemapUsesVocabularyPage(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.test';

        $pageTree = new PageTree([
            self::page(1, null, ''),
            self::page(7, 1, 'biblioteka'),
            self::page(41, 7, 'authors'),
            self::page(42, 41, '{slug}', termVocabulary: 'author'),
        ]);

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('FROM feed_terms'), ['author'])
            ->willReturn([
                [
                    'id' => 2,
                    'vocabulary' => 'author',
                    'name' => 'Кандыба Виктор Михайлович',
                    'slug' => 'kandyba_viktor_mihajilovich',
                    'updated_at' => 1700086400,
                ],
            ]);

        $module = $this->makeModule($pageTree, $this->createStub(FeedService::class), $db);

        ob_start();
        $module->callApi(self::page(100, 1, 'sitemap-{slug}.xml', 'raw', action: 'sitemap.sitemap'), ['slug' => 'term-author']);
        $output = ob_get_clean();

        $this->assertStringContainsString('<urlset', $output);
        $this->assertStringContainsString('https://example.test/biblioteka/authors/kandyba_viktor_mihajilovich/', $output);
        $this->assertStringContainsString('<lastmod>2023-11-15</lastmod>', $output);
    }

    public function testRenderUrlSetTruncatesAtThirtyThousandUrls(): void
    {
        $module = $this->makeModule(
            new PageTree([self::page(1, null, '')]),
            $this->createStub(FeedService::class)
        );

        $entries = [];
        for ($i = 1; $i <= 30001; $i++) {
            $entries[] = ['loc' => "https://example.test/page-$i/"];
        }

        $output = (new ReflectionMethod(SitemapController::class, 'renderUrlSet'))->invoke($module, $entries);

        $this->assertSame(30000, substr_count($output, '<url>'));
        $this->assertStringContainsString('https://example.test/page-30000/', $output);
        $this->assertStringNotContainsString('https://example.test/page-30001/', $output);
    }

    public function testRobotsTxtRespondsWithDisallowRulesAndSitemapLink(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.test';
        unset($_SERVER['HTTP_IF_MODIFIED_SINCE']);

        $module = $this->makeModule(
            new PageTree([self::page(1, null, '')]),
            $this->createStub(FeedService::class)
        );

        ob_start();
        $module->callApi(self::page(100, 1, 'robots.txt', 'raw', action: 'sitemap.robots'));
        $output = ob_get_clean();

        $this->assertStringContainsString('User-agent: *', $output);
        $this->assertStringContainsString('Disallow: /admin/', $output);
        $this->assertStringContainsString('Disallow: /api/', $output);
        $this->assertStringContainsString('Sitemap: https://example.test/sitemap-index.xml', $output);
    }

    // NOTE: the If-Modified-Since 304 short-circuit in handleRobotsTxtRequest()
    // calls exit() directly, which would terminate the PHPUnit process itself —
    // there's no way to assert on it without a source-level refactor (e.g.
    // returning instead of exiting and letting the front controller exit), which
    // is out of scope for a point test fix. Left untested; see TESTS_AUDIT.md.
}
