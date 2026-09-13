<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\MenuRepository;
use StreamEngine\Repository\PageRepository;
use StreamEngine\Service\AccessService;

/**
 * The two whole-table loads that happen on every request before anything else:
 * `pages` becomes the routing table, `menu` becomes the navigation.
 *
 * Neither does any filtering - that is deliberate and documented - so all
 * there is to get wrong is hydration, and hydration is where a wrong default
 * turns into "this page is unreachable" rather than into an error. The two
 * live in one file because they are the same shape and the same moment in the
 * request.
 */
final class RoutingRepositoriesTest extends TestCase
{
    /** @var list<string> */
    private array $queries = [];

    /** @param list<array<string, mixed>> $rows */
    private function db(array $rows): PdoDatabase
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql) use ($rows): array {
                $this->queries[] = $sql;

                return $rows;
            }
        );

        return $db;
    }

    /* ===============================
       PageRepository
    =============================== */

    /** @param array<string, mixed> $overrides */
    private static function pageRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 4,
            'parent' => 1,
            'pattern' => 'forum',
            'page_name' => 'Форум',
            'settings' => null,
            'feed_type' => null,
            'list_feed_type' => null,
            'feed_id' => null,
            'access_rule' => 'public',
            'action' => 'forums.list',
            'changefreq' => null,
            'updated' => null,
            'term_vocabulary' => null,
        ];
    }

    private function pages(array ...$rows): array
    {
        return (new PageRepository($this->db($rows)))->findAll();
    }

    public function testAPageRowBecomesARoutablePage(): void
    {
        [$page] = $this->pages(self::pageRow());

        $this->assertSame(4, $page->id);
        $this->assertSame(1, $page->parentId);
        $this->assertSame('forum', $page->pattern);
        $this->assertSame('forums.list', $page->action);
        $this->assertFalse($page->commentsEnabled);
    }

    /**
     * Every page loaded from the database is a GET/HTML page - the values are
     * hardcoded here, not columns. API pages exist only because controllers
     * register them in code (`Page::api()`), which is what keeps a row in
     * `pages` from ever becoming a mutating endpoint.
     */
    public function testDatabasePagesAreAlwaysGetAndHtml(): void
    {
        [$page] = $this->pages(self::pageRow());

        $this->assertSame(['GET'], $page->requestMethods);
        $this->assertSame('html', $page->responseType);
    }

    /**
     * A root page has no parent, and null has to survive as null: the router
     * indexes children by parent id starting from 0, so a null flattened to 0
     * would make every root page a child of the root page.
     */
    public function testANullParentStaysNullWhileZeroStaysZero(): void
    {
        [$root, $child] = $this->pages(
            self::pageRow(['parent' => null]),
            self::pageRow(['parent' => 0]),
        );

        $this->assertNull($root->parentId);
        $this->assertSame(0, $child->parentId);
    }

    public function testSettingsAreDecodedIntoAnObject(): void
    {
        [$page] = $this->pages(self::pageRow([
            'settings' => '{"type":"horo-love","perPage":20}',
        ]));

        // An object, not an array - the templates and controllers read
        // `settings->type`.
        $this->assertIsObject($page->settings);
        $this->assertSame('horo-love', $page->settings->type);
        $this->assertSame(20, $page->settings->perPage);
    }

    public function testAPageWithoutSettingsHasNoneRatherThanAnEmptyObject(): void
    {
        // Callers guard with `$page->settings->type ?? null`, which needs the
        // null to be a null.
        $this->assertNull($this->pages(self::pageRow(['settings' => null]))[0]->settings);
    }

    /**
     * Malformed JSON decodes to null, and `(object) null` is an *empty*
     * stdClass - so a corrupt settings column comes back looking configured
     * but blank, rather than as "no settings". Recorded because the two are
     * indistinguishable to a caller that only checks `!== null`, and the
     * failure it produces (a generated page with no type, say) points at the
     * consumer rather than at the row.
     */
    public function testMalformedSettingsBecomeAnEmptyObjectRatherThanNull(): void
    {
        [$page] = $this->pages(self::pageRow(['settings' => 'not json at all']));

        $this->assertIsObject($page->settings);
        $this->assertSame([], get_object_vars($page->settings));
    }

    public function testTheOptionalRoutingColumnsSurviveAsThemselves(): void
    {
        [$page] = $this->pages(self::pageRow([
            'feed_id' => '12',
            'feed_type' => 'article',
            'list_feed_type' => 'blog-post',
            'access_rule' => 'admin',
            'term_vocabulary' => 'tags',
            'changefreq' => 'daily',
            'updated' => '1700000000',
            'settings' => '{"commentsEnabled":true}',
        ]));

        // Numeric columns are cast, while the stored audience remains a string.
        $this->assertSame(12, $page->feedId);
        $this->assertSame(AccessService::ACCESS_ADMIN, $page->accessRule);
        $this->assertSame(1_700_000_000, $page->updated);
        $this->assertSame('article', $page->feedType);
        $this->assertSame('blog-post', $page->listFeedType);
        $this->assertSame('tags', $page->termVocabulary);
        $this->assertSame('daily', $page->changefreq);
        $this->assertTrue($page->commentsEnabled);
    }

    public function testAnEmptyPagesTableLoadsWithoutFailing(): void
    {
        // The bootstrap runs before any migration has necessarily inserted a
        // row; an empty routing table is a 404 site, not a fatal one.
        $this->assertSame([], $this->pages());
    }

    /* ===============================
       MenuRepository
    =============================== */

    /** @param array<string, mixed> $overrides */
    private static function menuRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 3,
            'parent' => null,
            'menu_group' => 'main',
            'type' => 'internal',
            'page_id' => 4,
            'url' => null,
            'action' => null,
            'label' => 'Форум',
            'access_rule' => 'public',
            'sort_order' => 10,
        ];
    }

    private function menu(array ...$rows): array
    {
        return (new MenuRepository($this->db($rows)))->findAll();
    }

    public function testMenuRowsAreHydratedIntoItems(): void
    {
        [$item] = $this->menu(self::menuRow());

        $this->assertSame(3, $item->id);
        $this->assertSame('main', $item->menuGroup);
        $this->assertSame('Форум', $item->label);
        $this->assertTrue($item->isLink());
    }

    /**
     * The ordering is done in SQL and only by `sort_order` - no tie-break on
     * id, despite the index being (sort_order, id). Two items sharing a
     * sort_order therefore come back in whatever order the storage engine
     * chose, which is stable in practice and unspecified in principle.
     * Asserted as "the query orders by sort_order" rather than as an order over
     * equal keys, which would be asserting a coincidence.
     */
    public function testTheOrderingIsLeftToTheDatabase(): void
    {
        $this->menu(self::menuRow());

        $this->assertStringContainsString('ORDER BY sort_order', $this->queries[0]);
    }

    public function testTheRepositoryDoesNotFilterByRole(): void
    {
        // Deliberate: MenuService does the filtering, because it needs the full
        // tree to decide which parents survive their children being hidden.
        $items = $this->menu(
            self::menuRow(['id' => 1, 'access_rule' => 'public']),
            self::menuRow(['id' => 2, 'access_rule' => 'admin']),
        );

        $this->assertCount(2, $items);
        $this->assertStringNotContainsStringIgnoringCase('access_rule', $this->queries[0]);
    }

    public function testAnEmptyMenuTableIsAnEmptyMenu(): void
    {
        $this->assertSame([], $this->menu());
    }
}
