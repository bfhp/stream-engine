<?php

declare(strict_types=1);

namespace Tests\Modules\Article;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Article\ArticleController;
use StreamEngine\Repository\FeedTermRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\TermService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

/**
 * The page renderers behind `article.show-id`, `article.show-slug`,
 * `articles.list-slug`, `articles.list-id` and `sections.list`.
 *
 * They are thin, and that is the point: each one resolves a feed, refuses if it
 * cannot, and hands the rest to a template. So the value here is in the
 * refusals and in the *shape* of the data handed over - a renderer that
 * silently passes null where the template expects a feed is a fatal in Twig,
 * which is a 500 rather than the 404 the situation actually is.
 *
 * `show()`'s dispatch is tested alongside them because it decides which of the
 * two ways an article can be addressed applies: pinned to a feed id on the
 * page row, or looked up by the `{slug}` in the URL.
 */
final class ArticleControllerTest extends TestCase
{
    /** @param list<Page> $pages */
    private function makeController(
        FeedService $feedService,
        string $role = AccessService::ROLE_USER,
        array $pages = [],
        ?QueryParams $query = null,
        ?array $termRow = null,
    ): ArticleController {
        $pageTree = new PageTree($pages);
        $termDb = $this->createStub(PdoDatabase::class);
        $termDb->method('fetchOne')->willReturn($termRow);

        return new ArticleController(
            $this->createStub(PdoDatabase::class),
            new RequestContext(new User(3, 'a@b.c', $role), new DateTimeZone('UTC'), $query ?? new QueryParams()),
            $feedService,
            $pageTree,
            new TermService(
                new FeedTermRepository($termDb),
                new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache()),
            ),
        );
    }

    private function makePage(
        string $action,
        string $pattern = 'articles',
        ?int $feedId = null,
        ?string $listFeedType = null,
        bool $commentsEnabled = false,
        int $id = 5,
        ?int $parentId = null,
        ?string $feedType = 'article',
        array $params = [],
        ?object $settings = null,
        ?string $termVocabulary = null,
    ): Page {
        if ($commentsEnabled) {
            $settings = (object) [...(array) ($settings ?? []), 'commentsEnabled' => true];
        }

        $page = new Page(
            id: $id,
            parentId: $parentId,
            pattern: $pattern,
            pageName: 'Статьи',
            settings: $settings,
            feedType: $feedType,
            listFeedType: $listFeedType,
            feedId: $feedId,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action,
            termVocabulary: $termVocabulary,
        );

        $page->params = $params;

        return $page;
    }

    private function makeFeed(int $id, string $slug, ?int $parentId = null, string $type = 'article'): Feed
    {
        return new Feed(
            id: $id,
            parentId: $parentId,
            ownerId: 1,
            type: $type,
            slug: $slug,
            title: 'Заголовок '.$id,
            description: 'Описание '.$id,
            imageUrl: '/img/'.$id.'.jpg',
            content: 'Текст',
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: 1_700_000_000,
            relevance: null,
            canonicalUrl: '/articles/'.$slug.'/',
        );
    }

    /* ===============================
       show() - dispatch
    =============================== */

    public function testAnUnknownActionIsRefused(): void
    {
        // No default arm that renders something empty: a page row wired to an
        // action this controller doesn't handle must not render at all.
        $controller = $this->makeController($this->createStub(FeedService::class));

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Unknown page action');

        $controller->show($this->makePage('articles.unknown'));
    }

    /**
     * The two addressing modes. A page pinned to a feed id ignores the URL
     * entirely; only a `{slug}` page consults it - which is what stops a
     * fixed page like "About" from being addressable as any other article.
     */
    public function testAPinnedPageResolvesByFeedIdAndIgnoresTheUrl(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeFeed(12, 'about'));
        $feedService->method('getFeedByParentAndSlug')->willThrowException(
            new RuntimeException('the slug lookup should not have been used')
        );

        $view = $this->makeController($feedService)
            ->show($this->makePage('article.show-id', pattern: 'about', feedId: 12, feedType: null), ['slug' => 'anything']);

        $this->assertNotNull($view);
        $this->assertSame('modules/article/article.show.twig', $view->template);
        $this->assertSame(12, $view->data['feed']->id);
    }

    public function testASlugPageResolvesFromTheUrlSegment(): void
    {
        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->once())
            ->method('getFeedByParentAndSlug')
            ->with(null, 'privet', $this->isInstanceOf(User::class), 'article')
            ->willReturn($this->makeFeed(12, 'privet'));

        $view = $this->makeController($feedService)
            ->show($this->makePage('article.show-slug', pattern: '{slug}'), ['slug' => 'privet']);

        $this->assertNotNull($view);
        $this->assertSame('privet', $view->data['feed']->slug);
        $this->assertSame('contained', $view->data['feedContentWidth']);
    }

    public function testAnIdPageWithoutAFeedIdIsRefused(): void
    {
        $controller = $this->makeController($this->createStub(FeedService::class));

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Article feed not found');

        $controller->show($this->makePage('article.show-id', pattern: 'about', feedType: null));
    }

    /* ===============================
       showArticlePage
    =============================== */

    public function testTheArticleFeedDrivesTheSeoFields(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeFeed(12, 'about'));

        $view = $this->makeController($feedService)
            ->show($this->makePage('article.show-id', feedId: 12, feedType: null));

        // Title, description, image and canonical all come off the feed - the
        // page row supplies none of them, so a renderer that used $page->
        // pageName would give every article the same title.
        $this->assertSame('Заголовок 12', $view->data['title']);
        $this->assertSame('Описание 12', $view->data['description']);
        $this->assertSame('/img/12.jpg', $view->data['image']);
        $this->assertSame('/articles/about/', $view->data['canonical']);
    }

    public function testArticleContentIsContainedByDefaultAndCanBeFullWidth(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeFeed(12, 'about'));

        $contained = $this->makeController($feedService)->show($this->makePage(
            'article.show-id',
            feedId: 12,
            feedType: null,
        ));
        $full = $this->makeController($feedService)->show($this->makePage(
            'article.show-id',
            feedId: 12,
            feedType: null,
            settings: (object) ['feedContentWidth' => 'full'],
        ));
        $invalid = $this->makeController($feedService)->show($this->makePage(
            'article.show-id',
            feedId: 12,
            feedType: null,
            settings: (object) ['feedContentWidth' => 'wide'],
        ));

        self::assertSame('contained', $contained->data['feedContentWidth']);
        self::assertSame('full', $full->data['feedContentWidth']);
        self::assertSame('contained', $invalid->data['feedContentWidth']);
    }

    /**
     * A slug nobody publishes, or one the visitor cannot see - the ACL is
     * inside FeedService, so both arrive here identically as "no feed", and
     * both have to refuse rather than render an empty page.
     */
    public function testAnArticleThatDoesNotResolveIsRefused(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedByParentAndSlug')->willReturn(null);

        $controller = $this->makeController($feedService);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Article feed not found');

        $controller->show($this->makePage('article.show-slug', pattern: '{slug}'), ['slug' => 'нет-такой']);
    }

    /**
     * Prev/next is only meaningful inside a section, so it is fetched only for
     * an article that has a parent. A top-level article gets null, and the
     * template branches on that.
     */
    public function testSiblingsAreOnlyFetchedForAnArticleInsideASection(): void
    {
        $withParent = $this->createStub(FeedService::class);
        $withParent->method('getFeedById')->willReturn($this->makeFeed(12, 'about', parentId: 4));
        $withParent->method('getPrevNext')->willReturn(['prev' => null, 'next' => null]);

        $view = $this->makeController($withParent)->show($this->makePage('article.show-id', feedId: 12, feedType: null));
        $this->assertNotNull($view->data['siblings']);

        $topLevel = $this->createStub(FeedService::class);
        $topLevel->method('getFeedById')->willReturn($this->makeFeed(12, 'about'));
        $topLevel->method('getPrevNext')->willThrowException(
            new RuntimeException('prev/next should not be fetched for a top-level article')
        );

        $view = $this->makeController($topLevel)->show($this->makePage('article.show-id', feedId: 12, feedType: null));
        $this->assertNull($view->data['siblings']);
    }

    /**
     * Comments are a per-page setting, and the guard is what keeps a page with
     * them switched off from paying for the query.
     */
    public function testCommentsAreFetchedOnlyWhenThePageEnablesThem(): void
    {
        $enabled = $this->createStub(FeedService::class);
        $enabled->method('getFeedById')->willReturn($this->makeFeed(12, 'about'));
        $enabled->method('getComments')->willReturn(['items' => [], 'cursor' => null]);

        $view = $this->makeController($enabled)
            ->show($this->makePage('article.show-id', feedId: 12, commentsEnabled: true, feedType: null));
        $this->assertNotNull($view->data['comments']);

        $disabled = $this->createStub(FeedService::class);
        $disabled->method('getFeedById')->willReturn($this->makeFeed(12, 'about'));
        $disabled->method('getComments')->willThrowException(
            new RuntimeException('comments should not be fetched when the page disables them')
        );

        $view = $this->makeController($disabled)
            ->show($this->makePage('article.show-id', feedId: 12, commentsEnabled: false, feedType: null));
        $this->assertNull($view->data['comments']);
    }

    public function testRelatedArticlesComeFromTheSameParentAndExcludeTheCurrentArticle(): void
    {
        $current = $this->makeFeed(12, 'current', parentId: 4);
        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->willReturn($current);
        $feedService->method('getPrevNext')->willReturn(['prev' => null, 'next' => null]);
        $feedService->expects($this->once())
            ->method('getFeedsByParentAndType')
            ->with(4, 'article', $this->isInstanceOf(User::class), 4)
            ->willReturn([
                $this->makeFeed(10, 'first', parentId: 4),
                $current,
                $this->makeFeed(13, 'second', parentId: 4),
                $this->makeFeed(14, 'third', parentId: 4),
            ]);

        $view = $this->makeController($feedService)->show(
            $this->makePage(
                'article.show-id',
                feedId: 12,
                feedType: null,
                settings: (object) ['relatedArticlesEnabled' => true],
            ),
        );

        $this->assertSame([10, 13, 14], array_column($view->data['subFeeds'], 'id'));
    }

    public function testRelatedArticlesAreDisabledByDefault(): void
    {
        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeFeed(12, 'current'));
        $feedService->expects($this->never())->method('getFeedsByParentAndType');

        $view = $this->makeController($feedService)->show(
            $this->makePage('article.show-id', feedId: 12, feedType: null),
        );

        $this->assertSame([], $view->data['subFeeds']);
    }

    /* ===============================
       showSectionsListPage
    =============================== */

    public function testSectionsAreListedWithTheirArticlesNested(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeFeed(1, 'articles'));
        $feedService->method('getFeedsByParentAndTypePage')->willReturn([
            'items' => [$this->makeFeed(2, 'section-a', parentId: 1, type: 'article-section')],
            'total' => 1,
        ]);
        $feedService->method('getFeedsByParentAndType')->willReturnCallback(
            fn (?int $parentId, string $type): array => match ($type) {
                'article' => [$this->makeFeed(3, 'article-a', parentId: 2)],
                default => [],
            }
        );

        $view = $this->makeController($feedService)->show($this->makePage('sections.list', feedId: 1));

        $this->assertSame('modules/article/sections.list.twig', $view->template);
        $this->assertCount(1, $view->data['sections']);

        // Each section carries its own articles - two queries per section, but
        // the template renders one list without a second controller round.
        $this->assertCount(1, $view->data['sections'][0]->children);
        $this->assertSame(3, $view->data['sections'][0]->children[0]->id);
    }

    public function testSectionsListIsPaginated(): void
    {
        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeFeed(1, 'articles'));
        $feedService->expects($this->once())
            ->method('getFeedsByParentAndTypePage')
            ->with(1, 'article-section', $this->isInstanceOf(User::class), 10, 10)
            ->willReturn([
                'items' => [$this->makeFeed(12, 'section-12', parentId: 1, type: 'article-section')],
                'total' => 21,
            ]);
        $feedService->method('getFeedsByParentAndType')->willReturn([]);

        $view = $this->makeController(
            $feedService,
            query: new QueryParams(['page' => '2']),
        )->show($this->makePage(
            'sections.list',
            feedId: 1,
            settings: (object) ['sectionsPerPage' => '10'],
        ));

        $this->assertSame('/articles/articles/?page=2', $view->data['canonical']);
        $this->assertSame(
            ['current' => 2, 'total' => 3, 'baseUrl' => '/articles/articles/'],
            $view->data['pagination'],
        );
    }

    /**
     * A misconfigured page row - `sections.list` with no feed_id - used to
     * reach `getFeedById(null, ...)`, which is a TypeError against a
     * non-nullable int and therefore a 500. It is a page with nothing to show,
     * so it is a 404.
     */
    public function testASectionsPageWithNoFeedIdIsANotFoundRatherThanAFatal(): void
    {
        $controller = $this->makeController($this->createStub(FeedService::class));

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Root feed not defined');

        $controller->show($this->makePage('sections.list'));
    }

    public function testASectionsPageWhoseRootDoesNotResolveIsRefused(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn(null);

        $controller = $this->makeController($feedService);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Root feed not found');

        $controller->show($this->makePage('sections.list', feedId: 1));
    }

    /* ===============================
       showArticleListPage
    =============================== */

    public function testAnArticleListRendersItsRootAndChildren(): void
    {
        $feedService = $this->createMock(FeedService::class);
        $rootPage = $this->makePage('sections.list', feedId: 1, id: 1);
        $sectionPage = $this->makePage(
            'articles.list-slug',
            pattern: '{slug}',
            listFeedType: 'article',
            id: 2,
            parentId: 1,
            feedType: 'article-section',
        );
        $feedService->method('getFeedById')->willReturn($this->makeFeed(1, 'articles'));
        $feedService->expects($this->once())
            ->method('getFeedByParentAndSlug')
            ->with(1, 'section-a', $this->isInstanceOf(User::class), 'article-section')
            ->willReturn($this->makeFeed(2, 'section-a', parentId: 1, type: 'article-section'));
        $feedService->method('getFeedsByParentAndTypePage')->willReturn([
            'items' => [$this->makeFeed(3, 'article-a', parentId: 2)],
            'total' => 1,
        ]);

        $view = $this->makeController($feedService, pages: [$rootPage])
            ->show($sectionPage, ['slug' => 'section-a']);

        $this->assertSame('modules/article/articles.list.twig', $view->template);
        $this->assertSame(2, $view->data['feed']->id);
        $this->assertCount(1, $view->data['subFeeds']);
    }

    public function testAFixedArticleListResolvesItsSectionByFeedId(): void
    {
        $feedService = $this->createMock(FeedService::class);
        $section = $this->makeFeed(2, 'section-a', parentId: 1, type: 'article-section');

        $feedService->expects($this->once())
            ->method('getFeedById')
            ->with(2, $this->isInstanceOf(User::class))
            ->willReturn($section);
        $feedService->expects($this->once())
            ->method('getFeedsByParentAndTypePage')
            ->with(2, 'article', $this->isInstanceOf(User::class), 20, 0)
            ->willReturn([
                'items' => [$this->makeFeed(3, 'article-a', parentId: 2)],
                'total' => 1,
            ]);
        $feedService->expects($this->never())->method('getFeedByParentAndSlug');

        $view = $this->makeController($feedService)->show($this->makePage(
            'articles.list-id',
            pattern: 'section-a',
            feedId: 2,
            listFeedType: 'article',
            feedType: null,
        ));

        $this->assertSame('modules/article/articles.list.twig', $view->template);
        $this->assertSame(2, $view->data['feed']->id);
        $this->assertCount(1, $view->data['subFeeds']);
    }

    public function testArticleListIsPaginated(): void
    {
        $feedService = $this->createMock(FeedService::class);
        $section = $this->makeFeed(2, 'section-a', parentId: 1, type: 'article-section');
        $feedService->method('getFeedById')->willReturn($section);
        $feedService->expects($this->once())
            ->method('getFeedsByParentAndTypePage')
            ->with(2, 'article', $this->isInstanceOf(User::class), 10, 10)
            ->willReturn([
                'items' => [$this->makeFeed(13, 'article-13', parentId: 2)],
                'total' => 21,
            ]);

        $view = $this->makeController(
            $feedService,
            query: new QueryParams(['page' => '2']),
        )->show($this->makePage(
            'articles.list-id',
            feedId: 2,
            listFeedType: 'article',
            feedType: null,
            settings: (object) ['articlesPerPage' => '10'],
        ));

        $this->assertSame('/articles/section-a/?page=2', $view->data['canonical']);
        $this->assertSame(
            ['current' => 2, 'total' => 3, 'baseUrl' => '/articles/section-a/'],
            $view->data['pagination'],
        );
    }

    public function testArticleIndexListsAllArticlesWithPagination(): void
    {
        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->once())
            ->method('getFeedsByTypePage')
            ->with('article', $this->isInstanceOf(User::class), 10, 10)
            ->willReturn([
                'items' => [$this->makeFeed(21, 'article-21')],
                'total' => 41,
            ]);
        $page = $this->makePage(
            'articles.index',
            pattern: 'articles',
            feedType: null,
            settings: (object) ['articlesPerPage' => '10'],
        );

        $view = $this->makeController(
            $feedService,
            query: new QueryParams(['page' => '2']),
        )->show($page);

        $this->assertSame('modules/article/articles.index.twig', $view->template);
        $this->assertSame(21, $view->data['articles'][0]->id);
        $this->assertSame('/articles/?page=2', $view->data['canonical']);
        $this->assertSame(
            ['current' => 2, 'total' => 5, 'baseUrl' => '/articles/'],
            $view->data['pagination'],
        );
    }

    public function testArticleIndexRejectsAPageBeyondTheLastPage(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedsByTypePage')->willReturn(['items' => [], 'total' => 20]);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Page not found');

        $this->makeController(
            $feedService,
            query: new QueryParams(['page' => '2']),
        )->show($this->makePage('articles.index', feedType: null));
    }

    public function testArticlesByTermResolveTheTermAndPaginateArticles(): void
    {
        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->once())
            ->method('getFeedsByTerm')
            ->with('tags', 'php', $this->isInstanceOf(User::class), 'article', 10, 10)
            ->willReturn([$this->makeFeed(21, 'article-21')]);
        $feedService->expects($this->once())
            ->method('countFeedsGroupedByTermsAndVocabulary')
            ->with('tags', $this->isInstanceOf(User::class), 'article')
            ->willReturn([7 => 21]);
        $page = $this->makePage(
            'articles.by-term',
            pattern: 'articles/{slug}',
            feedType: null,
            settings: (object) ['articlesPerPage' => '10'],
            termVocabulary: 'tags',
        );

        $controller = $this->makeController(
            $feedService,
            pages: [$page],
            query: new QueryParams(['page' => '2']),
            termRow: [
                'id' => 7,
                'parent_id' => null,
                'vocabulary' => 'tags',
                'name' => 'PHP',
                'slug' => 'php',
                'updated_at' => 1,
            ],
        );
        $view = $controller->show($page, ['slug' => 'php']);

        $this->assertSame('PHP', $view->data['title']);
        $this->assertSame('/articles/php/?page=2', $view->data['canonical']);
        $this->assertSame(21, $view->data['articles'][0]->id);
        $this->assertSame(
            ['current' => 2, 'total' => 3, 'baseUrl' => '/articles/php/'],
            $view->data['pagination'],
        );
    }

    public function testArticlesByTermRejectAnUnknownTerm(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Term not found');

        $this->makeController($this->createStub(FeedService::class))->show(
            $this->makePage('articles.by-term', feedType: null, termVocabulary: 'tags'),
            ['slug' => 'unknown'],
        );
    }

    /**
     * `list_feed_type` is what the page row says its children are. Unset, the
     * list renders empty rather than guessing a type - a wrong guess would
     * show a section's *sections* as if they were articles.
     */
    public function testAListPageWithoutAChildTypeShowsNoChildren(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedByParentAndSlug')->willReturn(
            $this->makeFeed(2, 'section-a', type: 'article-section')
        );
        $feedService->method('getFeedsByParentAndTypePage')->willThrowException(
            new RuntimeException('children should not be fetched without a list_feed_type')
        );

        $view = $this->makeController($feedService)
            ->show(
                $this->makePage('articles.list-slug', feedType: 'article-section'),
                ['slug' => 'section-a']
            );

        $this->assertNull($view->data['subFeeds']);
    }

    public function testAListPageWhoseRootDoesNotResolveIsRefused(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedByParentAndSlug')->willReturn(null);

        $controller = $this->makeController($feedService);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Root feed not found');

        $controller->show(
            $this->makePage('articles.list-slug', feedType: 'article-section'),
            ['slug' => 'нет-такой']
        );
    }

    /**
     * Each dynamic segment is resolved inside the feed selected by the
     * preceding route segment. This is what makes duplicate article slugs in
     * different sections unambiguous.
     */
    public function testAnArticleIsResolvedThroughItsSectionAndPinnedRoot(): void
    {
        $feedService = $this->createMock(FeedService::class);
        $rootPage = $this->makePage('sections.list', pattern: 'articles', feedId: 1, id: 1);
        $sectionPage = $this->makePage(
            'articles.list-slug',
            pattern: '{slug}',
            id: 2,
            parentId: 1,
            feedType: 'article-section',
            params: ['slug' => 'section-a'],
        );
        $articlePage = $this->makePage(
            'article.show-slug',
            pattern: '{slug}',
            id: 3,
            parentId: 2,
            feedType: 'article',
        );

        $feedService->method('getFeedById')->with(1)
            ->willReturn($this->makeFeed(1, 'articles'));
        $feedService->expects($this->exactly(2))
            ->method('getFeedByParentAndSlug')
            ->willReturnCallback(fn (?int $parentId, string $slug, User $user, ?string $type): ?Feed => match (
                [$parentId, $slug, $type]
            ) {
                [1, 'section-a', 'article-section'] => $this->makeFeed(
                    2,
                    'section-a',
                    parentId: 1,
                    type: 'article-section'
                ),
                [2, 'article-a', 'article'] => $this->makeFeed(3, 'article-a', parentId: 2),
                default => null,
            });

        $view = $this->makeController($feedService, pages: [$rootPage, $sectionPage])
            ->show($articlePage, ['slug' => 'article-a']);

        $this->assertSame(3, $view->data['feed']->id);
    }

    /* ===============================
       getBreadcrumb
    =============================== */

    public function testABreadcrumbForAPageWithoutASlugFallsBackToThePageName(): void
    {
        $page = $this->makePage('articles.list-slug');

        $breadcrumb = $this->makeController($this->createStub(FeedService::class))->getBreadcrumb($page);

        $this->assertNotNull($breadcrumb);
        $this->assertSame('Статьи', $breadcrumb->title);
        $this->assertSame('articles', $breadcrumb->slug);
    }

    /**
     * With a slug the crumb names the *feed*, not the page - otherwise every
     * article in a section would show the section's name in its own crumb.
     */
    public function testABreadcrumbForASlugPageNamesTheFeed(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedByParentAndSlug')->willReturn($this->makeFeed(3, 'article-a'));

        $page = $this->makePage('article.show-slug', pattern: '{slug}');
        $page->params = ['slug' => 'article-a'];

        $breadcrumb = $this->makeController($feedService)->getBreadcrumb($page);

        $this->assertSame('Заголовок 3', $breadcrumb->title);
        $this->assertSame('article-a', $breadcrumb->slug);
    }

    /**
     * A crumb whose feed does not resolve throws - and StreamEngine builds
     * breadcrumbs *outside* the try/catch that wraps show(), so this escapes to
     * the global handler as a 500 rather than rendering the 404 page. Recorded
     * rather than fixed here: the fix belongs in handleRequest(), which should
     * treat a failed crumb as a missing crumb.
     */
    public function testAnUnresolvableBreadcrumbThrows(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedByParentAndSlug')->willReturn(null);

        $page = $this->makePage('article.show-slug', pattern: '{slug}');
        $page->params = ['slug' => 'нет-такой'];

        $controller = $this->makeController($feedService);

        $this->expectException(ForbiddenException::class);

        $controller->getBreadcrumb($page);
    }

    public function testABreadcrumbForAnUnrelatedActionUsesThePageName(): void
    {
        // sections.list isn't one of the two feed-backed actions, so no lookup
        // happens at all.
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedByParentAndSlug')->willThrowException(
            new RuntimeException('no feed lookup should happen for sections.list')
        );

        $page = $this->makePage('sections.list');
        $page->params = ['slug' => 'irrelevant'];

        $this->assertSame(
            'Статьи',
            $this->makeController($feedService)->getBreadcrumb($page)->title
        );
    }
}
