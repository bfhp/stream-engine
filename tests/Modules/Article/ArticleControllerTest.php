<?php

declare(strict_types=1);

namespace Tests\Modules\Article;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Article\ArticleController;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;

/**
 * The three page renderers behind `article.show`, `articles.list` and
 * `sections.list`.
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
    private function makeController(FeedService $feedService, string $role = AccessService::ROLE_USER): ArticleController
    {
        return new ArticleController(
            $this->createStub(PdoDatabase::class),
            new RequestContext(new User(3, 'a@b.c', $role), new DateTimeZone('UTC')),
            $feedService,
        );
    }

    private function makePage(
        string $action,
        string $pattern = 'articles',
        ?int $feedId = null,
        ?string $listFeedType = null,
        ?bool $commentsEnabled = false,
    ): Page {
        return new Page(
            id: 5,
            parentId: 1,
            pattern: $pattern,
            pageName: 'Статьи',
            settings: null,
            feedType: 'article',
            listFeedType: $listFeedType,
            feedId: $feedId,
            commentsEnabled: $commentsEnabled,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action,
        );
    }

    private function makeFeed(int $id, string $slug, ?int $parentId = null): Feed
    {
        return new Feed(
            id: $id,
            parentId: $parentId,
            ownerId: 1,
            type: 'article',
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
        $feedService->method('getFeedBySlug')->willThrowException(
            new RuntimeException('the slug lookup should not have been used')
        );

        $view = $this->makeController($feedService)
            ->show($this->makePage('article.show', pattern: 'about', feedId: 12), ['slug' => 'anything']);

        $this->assertNotNull($view);
        $this->assertSame('modules/article/article.show.twig', $view->template);
        $this->assertSame(12, $view->data['feed']->id);
    }

    public function testASlugPageResolvesFromTheUrlSegment(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$this->makeFeed(12, 'privet')]);

        $view = $this->makeController($feedService)
            ->show($this->makePage('article.show', pattern: '{slug}'), ['slug' => 'privet']);

        $this->assertNotNull($view);
        $this->assertSame('privet', $view->data['feed']->slug);
    }

    /**
     * Neither a feed id nor a slug pattern means the page row says nothing
     * about what to render - a configuration error, and loud.
     */
    public function testAPageThatNamesNoFeedAtAllFailsLoudly(): void
    {
        $controller = $this->makeController($this->createStub(FeedService::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Feed not defined');

        $controller->show($this->makePage('article.show', pattern: 'about'));
    }

    /* ===============================
       showArticlePage
    =============================== */

    public function testTheArticleFeedDrivesTheSeoFields(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeFeed(12, 'about'));

        $view = $this->makeController($feedService)
            ->show($this->makePage('article.show', feedId: 12));

        // Title, description, image and canonical all come off the feed - the
        // page row supplies none of them, so a renderer that used $page->
        // pageName would give every article the same title.
        $this->assertSame('Заголовок 12', $view->data['title']);
        $this->assertSame('Описание 12', $view->data['description']);
        $this->assertSame('/img/12.jpg', $view->data['image']);
        $this->assertSame('/articles/about/', $view->data['canonical']);
    }

    /**
     * A slug nobody publishes, or one the visitor cannot see - the ACL is
     * inside FeedService, so both arrive here identically as "no feed", and
     * both have to refuse rather than render an empty page.
     */
    public function testAnArticleThatDoesNotResolveIsRefused(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([]);

        $controller = $this->makeController($feedService);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Article feed not found');

        $controller->show($this->makePage('article.show', pattern: '{slug}'), ['slug' => 'нет-такой']);
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

        $view = $this->makeController($withParent)->show($this->makePage('article.show', feedId: 12));
        $this->assertNotNull($view->data['siblings']);

        $topLevel = $this->createStub(FeedService::class);
        $topLevel->method('getFeedById')->willReturn($this->makeFeed(12, 'about'));
        $topLevel->method('getPrevNext')->willThrowException(
            new RuntimeException('prev/next should not be fetched for a top-level article')
        );

        $view = $this->makeController($topLevel)->show($this->makePage('article.show', feedId: 12));
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
            ->show($this->makePage('article.show', feedId: 12, commentsEnabled: true));
        $this->assertNotNull($view->data['comments']);

        $disabled = $this->createStub(FeedService::class);
        $disabled->method('getFeedById')->willReturn($this->makeFeed(12, 'about'));
        $disabled->method('getComments')->willThrowException(
            new RuntimeException('comments should not be fetched when the page disables them')
        );

        $view = $this->makeController($disabled)
            ->show($this->makePage('article.show', feedId: 12, commentsEnabled: false));
        $this->assertNull($view->data['comments']);
    }

    /* ===============================
       showSectionsListPage
    =============================== */

    public function testSectionsAreListedWithTheirArticlesNested(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeFeed(1, 'articles'));
        $feedService->method('getFeedsByParentAndType')->willReturnCallback(
            fn (?int $parentId, string $type): array => match ($type) {
                'article-section' => [$this->makeFeed(2, 'section-a', parentId: 1)],
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
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$this->makeFeed(2, 'section-a')]);
        $feedService->method('getFeedsByParentAndType')->willReturn([
            $this->makeFeed(3, 'article-a', parentId: 2),
        ]);

        $view = $this->makeController($feedService)
            ->show($this->makePage('articles.list', listFeedType: 'article'), ['slug' => 'section-a']);

        $this->assertSame('modules/article/articles.list.twig', $view->template);
        $this->assertSame(2, $view->data['feed']->id);
        $this->assertCount(1, $view->data['subFeeds']);
    }

    /**
     * `list_feed_type` is what the page row says its children are. Unset, the
     * list renders empty rather than guessing a type - a wrong guess would
     * show a section's *sections* as if they were articles.
     */
    public function testAListPageWithoutAChildTypeShowsNoChildren(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$this->makeFeed(2, 'section-a')]);
        $feedService->method('getFeedsByParentAndType')->willThrowException(
            new RuntimeException('children should not be fetched without a list_feed_type')
        );

        $view = $this->makeController($feedService)
            ->show($this->makePage('articles.list'), ['slug' => 'section-a']);

        $this->assertNull($view->data['subFeeds']);
    }

    public function testAListPageWhoseRootDoesNotResolveIsRefused(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([]);

        $controller = $this->makeController($feedService);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Root feed not found');

        $controller->show($this->makePage('articles.list'), ['slug' => 'нет-такой']);
    }

    /**
     * getFeedBySlug() returns the whole ancestor chain and the renderer takes
     * the *last* element - the deepest match, which is the feed the URL names.
     * Taking the first would render the section for every article under it.
     */
    public function testTheDeepestFeedInTheChainIsTheOneRendered(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([
            $this->makeFeed(1, 'articles'),
            $this->makeFeed(2, 'section-a', parentId: 1),
            $this->makeFeed(3, 'article-a', parentId: 2),
        ]);

        $view = $this->makeController($feedService)
            ->show($this->makePage('article.show', pattern: '{slug}'), ['slug' => 'article-a']);

        $this->assertSame(3, $view->data['feed']->id);
    }

    /* ===============================
       getBreadcrumb
    =============================== */

    public function testABreadcrumbForAPageWithoutASlugFallsBackToThePageName(): void
    {
        $page = $this->makePage('articles.list');

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
        $feedService->method('getFeedBySlug')->willReturn([$this->makeFeed(3, 'article-a')]);

        $page = $this->makePage('article.show', pattern: '{slug}');
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
        $feedService->method('getFeedBySlug')->willReturn([]);

        $page = $this->makePage('article.show', pattern: '{slug}');
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
        $feedService->method('getFeedBySlug')->willThrowException(
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
