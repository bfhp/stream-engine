<?php

declare(strict_types=1);

namespace Tests\Modules\Search;

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Search\SearchController;
use StreamEngine\Service\AccessService;

/**
 * The search page renders no results.
 *
 * That is the whole design and it is easy to misread: the controller only
 * emits the shell plus the query, and `search.js` fetches the results from
 * the API. So what this class is actually responsible for is small and
 * entirely about the query string - reading it, normalising it, and putting
 * it in three places (the title, the crumb, the field the script reads) -
 * plus one thing that matters more than any of it: the `noindex` meta.
 *
 * Without that meta every search anyone ever ran becomes an indexable URL,
 * which is how a site ends up with thousands of thin duplicate pages in a
 * search engine, some of them carrying whatever text a stranger put in the
 * query.
 */
final class SearchControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];

        parent::tearDown();
    }

    private function makeController(?string $query): SearchController
    {
        if ($query !== null) {
            $_GET['q'] = $query;
        }

        return new SearchController(
            $this->createStub(PdoDatabase::class),
            // fromGlobals() so the $_GET set just above lands in the context,
            // exactly as StreamEngine::handleRequest() snapshots the request.
            new RequestContext(new User(0, '', AccessService::ROLE_USER), new DateTimeZone('UTC'), QueryParams::fromGlobals()),
        );
    }

    private function makePage(): Page
    {
        return new Page(
            id: 7,
            parentId: 1,
            pattern: 'search',
            pageName: 'Поиск',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'search.results',
        );
    }

    /* ===============================
       The query
    =============================== */

    #[DataProvider('queryProvider')]
    public function testTheQueryIsNormalisedBeforeItIsUsed(?string $raw, string $expected): void
    {
        $view = $this->makeController($raw)->show($this->makePage());

        $this->assertSame($expected, $view->data['search_string']);
    }

    /** @return array<string, array{?string, string}> */
    public static function queryProvider(): array
    {
        return [
            'a plain query' => ['булгаков', 'булгаков'],
            'surrounding space is trimmed' => ['  булгаков  ', 'булгаков'],
            // A bare GET /search: QueryParams::trimmed() is total, so an
            // absent key is the empty query rather than a null against the
            // non-nullable string property.
            'no q at all' => [null, ''],
            'an empty q' => ['', ''],
            'whitespace only' => ["  \t ", ''],
            // Inner whitespace is *not* collapsed - the API decides what to
            // do with it, and collapsing here would silently change a
            // phrase query.
            'inner whitespace survives' => ['мастер   и   маргарита', 'мастер   и   маргарита'],
        ];
    }

    public function testTheQueryReachesTheTitleAndTheField(): void
    {
        $view = $this->makeController('булгаков')->show($this->makePage());

        $this->assertSame('modules/search/page.twig', $view->template);
        $this->assertSame('Search: булгаков', $view->data['title']);
        $this->assertSame('булгаков', $view->data['search_string']);
    }

    /**
     * The query is passed through raw - it is data, and the template
     * escapes on output. Pinned so nobody adds htmlspecialchars() here and
     * produces double-escaped searches, which is the usual result of
     * escaping in two places.
     */
    public function testTheQueryIsNotEscapedByTheController(): void
    {
        $view = $this->makeController('<b>жирный</b>')->show($this->makePage());

        $this->assertSame('<b>жирный</b>', $view->data['search_string']);
    }

    /* ===============================
       The head
    =============================== */

    /**
     * The one thing on this page with consequences outside it. A search
     * results URL is unbounded and user-authored; indexing it produces
     * thin duplicate pages carrying arbitrary text.
     */
    public function testSearchResultsAreNeverIndexed(): void
    {
        $view = $this->makeController('булгаков')->show($this->makePage());

        $head = implode("\n", $view->data['head_ext']);

        $this->assertStringContainsString('name="robots"', $head);
        $this->assertStringContainsString('noindex', $head);
        // follow, not nofollow: the links out of the page are ordinary
        // site links and should still be crawled.
        $this->assertStringContainsString('follow', $head);
    }

    public function testTheClientScriptIsLoadedDeferred(): void
    {
        $view = $this->makeController(null)->show($this->makePage());

        $head = implode("\n", $view->data['head_ext']);

        // The results come from search.js, not from this controller - so
        // the page is useless without it.
        $this->assertStringContainsString('/assets/js/search.js', $head);
        $this->assertStringContainsString('defer', $head);
    }

    /**
     * Rendered identically with and without a query: an empty search is the
     * landing state of the page, not an error.
     */
    public function testAnEmptyQueryStillRendersThePage(): void
    {
        $view = $this->makeController(null)->show($this->makePage());

        $this->assertSame('modules/search/page.twig', $view->template);
        $this->assertSame('Search: ', $view->data['title']);
        $this->assertCount(2, $view->data['head_ext']);
    }

    /* ===============================
       getBreadcrumb
    =============================== */

    public function testTheCrumbCarriesTheQuery(): void
    {
        $breadcrumb = $this->makeController('булгаков')->getBreadcrumb($this->makePage());

        $this->assertSame('Поиск: булгаков', $breadcrumb->title);
        $this->assertSame('search', $breadcrumb->slug);
    }

    /**
     * With no query the crumb still renders, trailing separator and all.
     * Cosmetic, but it is what the page shows on a bare /search, so it is
     * written down rather than left to be discovered.
     */
    public function testTheCrumbKeepsItsSeparatorOnAnEmptyQuery(): void
    {
        $this->assertSame('Поиск: ', $this->makeController(null)->getBreadcrumb($this->makePage())->title);
    }
}
