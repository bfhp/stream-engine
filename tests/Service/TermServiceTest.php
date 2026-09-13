<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Repository\FeedTermRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\TermService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

/**
 * Five methods that all do the same two things: ask the repository, then
 * decorate what comes back with canonical URLs.
 *
 * The decoration is the part worth testing. FeedTerm arrives from the database
 * with `canonicalUrl` null and the templates link terms with it, so a path that
 * forgets to decorate renders tags as plain text with no href - silently, and
 * only on that one page. The service exists precisely so no caller has to
 * remember; four of its five methods are a repository call plus that call, and
 * the fifth (`countTermsByVocabulary`) is a pass-through with nothing to
 * decorate.
 *
 * Both collaborators are `final`, so neither can be mocked: the repository is
 * driven through a stubbed PdoDatabase and the UrlGenerator is real, over a
 * two-page tree. That makes these tests of the whole read path rather than of
 * TermService's delegation alone, which is the more useful thing to know.
 */
final class TermServiceTest extends TestCase
{
    private const string VOCABULARY = 'tags';

    /** @var list<string> the statements the service caused, in order */
    private array $queries = [];

    /** @param array<string, mixed> $extra */
    private static function termRow(int $id, string $slug, array $extra = []): array
    {
        return $extra + [
            'id' => $id,
            'parent_id' => null,
            'vocabulary' => self::VOCABULARY,
            'name' => 'Тег '.$id,
            'slug' => $slug,
            'updated_at' => 1_700_000_000,
        ];
    }

    /**
     * @param list<array<string, mixed>> $listRows rows for the fetchAll paths
     * @param array<string, mixed>|null $singleRow row for the fetchOne paths
     */
    private function makeService(array $listRows = [], ?array $singleRow = null, int $count = 0): TermService
    {
        $db = $this->createStub(PdoDatabase::class);

        $db->method('fetchAll')->willReturnCallback(
            function (string $sql) use ($listRows): array {
                $this->queries[] = $sql;

                return $listRows;
            }
        );

        $db->method('fetchOne')->willReturnCallback(
            function (string $sql) use ($singleRow, $count): ?array {
                $this->queries[] = $sql;

                return str_contains($sql, 'COUNT(*)') ? ['total' => (string) $count] : $singleRow;
            }
        );

        return new TermService(new FeedTermRepository($db), $this->makeUrlGenerator());
    }

    /**
     * A real UrlGenerator over `/` + `/tags/{slug}/`. "The URL is right" is
     * half of what decoration means, so a fake that returned a fixed string
     * would test nothing.
     */
    private function makeUrlGenerator(): UrlGenerator
    {
        $page = static fn (
            int $id,
            ?int $parentId,
            string $pattern,
            ?string $vocabulary = null
        ): Page => new Page(
            id: $id,
            parentId: $parentId,
            pattern: $pattern,
            pageName: 'Page '.$id,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            termVocabulary: $vocabulary,
        );

        return new UrlGenerator(
            new PageTree([
                $page(1, null, ''),
                $page(2, 1, 'tags/{slug}', self::VOCABULARY),
            ]),
            new FakeFeedRepository([]),
            new ArrayCache()
        );
    }

    /* ===============================
       The four decorating methods
    =============================== */

    public function testTermsFromAVocabularyComeBackLinkable(): void
    {
        $service = $this->makeService([
            self::termRow(1, 'php'),
            self::termRow(2, 'sql'),
        ]);

        $terms = $service->getTermsByVocabulary(self::VOCABULARY);

        $this->assertSame(['/tags/php/', '/tags/sql/'], array_column($terms, 'canonicalUrl'));
        // And the row itself is hydrated, not just decorated.
        $this->assertSame('php', $terms[0]->slug);
        $this->assertSame(self::VOCABULARY, $terms[0]->vocabulary);
    }

    public function testChildTermsAreDecoratedToo(): void
    {
        $service = $this->makeService([self::termRow(3, 'pdo', ['parent_id' => 1])]);

        $terms = $service->getChildTerms(self::VOCABULARY, 1);

        $this->assertSame('/tags/pdo/', $terms[0]->canonicalUrl);
        $this->assertSame(1, $terms[0]->parentId);
    }

    public function testASingleTermLookedUpBySlugIsDecorated(): void
    {
        $service = $this->makeService(singleRow: self::termRow(1, 'php'));

        $term = $service->getTermByVocabularyAndSlug(self::VOCABULARY, 'php');

        $this->assertNotNull($term);
        $this->assertSame('/tags/php/', $term->canonicalUrl);
    }

    /**
     * The null guard around the single lookup: decorating an array holding null
     * would be a TypeError on a URL nobody routes - which is to say, on a
     * mistyped tag slug.
     */
    public function testAMissingTermIsNullRatherThanAFailedDecoration(): void
    {
        $service = $this->makeService(singleRow: null);

        $this->assertNull($service->getTermByVocabularyAndSlug(self::VOCABULARY, 'nope'));
    }

    /**
     * The nested one, and the only method here where a plausible implementation
     * gets it wrong: the repository groups rows into feed id => *list* of
     * terms, so decoration has to descend a level. Iterating the outer map as
     * if it held terms would decorate nothing and fail only in the template.
     */
    public function testEveryTermInEveryFeedsListIsDecorated(): void
    {
        $service = $this->makeService([
            self::termRow(1, 'php') + ['feed_id' => 11, 'position' => 0],
            self::termRow(2, 'sql') + ['feed_id' => 11, 'position' => 1],
            self::termRow(3, 'pdo') + ['feed_id' => 12, 'position' => 0],
        ]);

        $byFeed = $service->getTermsByFeedIdsAndVocabulary([11, 12], self::VOCABULARY);

        // The feed ids are preserved as keys - callers index by them.
        $this->assertSame([11, 12], array_keys($byFeed));

        $this->assertSame(['/tags/php/', '/tags/sql/'], array_column($byFeed[11], 'canonicalUrl'));
        $this->assertSame(['/tags/pdo/'], array_column($byFeed[12], 'canonicalUrl'));
    }

    /**
     * A feed with no terms is *absent* from the map rather than present with an
     * empty list - the grouping only creates keys it sees rows for. Callers
     * must therefore use `?? []`, which is easy to get wrong precisely because
     * the common case has terms.
     */
    public function testAFeedWithNoTermsIsMissingFromTheMapEntirely(): void
    {
        $service = $this->makeService([
            self::termRow(1, 'php') + ['feed_id' => 11, 'position' => 0],
        ]);

        $byFeed = $service->getTermsByFeedIdsAndVocabulary([11, 12], self::VOCABULARY);

        $this->assertSame([11], array_keys($byFeed));
        $this->assertArrayNotHasKey(12, $byFeed);
    }

    /**
     * No ids means no query at all - the IN clause would be `IN ()`, a syntax
     * error, and the guard is what keeps a feed list with nothing to tag from
     * taking the page down.
     */
    public function testNoFeedIdsAsksTheDatabaseNothing(): void
    {
        $service = $this->makeService([self::termRow(1, 'php') + ['feed_id' => 11, 'position' => 0]]);

        $this->assertSame([], $service->getTermsByFeedIdsAndVocabulary([], self::VOCABULARY));
        $this->assertSame([], $this->queries);
    }

    public function testAnEmptyResultDecoratesNothingAndReturnsAnEmptyList(): void
    {
        $this->assertSame([], $this->makeService([])->getTermsByVocabulary('nothing'));
    }

    /**
     * A vocabulary with no page routing it has no URL, and the term is still
     * returned - the name renders, only the link is missing. Better than an
     * exception, since a vocabulary can exist in the database before anyone
     * adds a page for it.
     */
    public function testATermFromAnUnroutedVocabularyKeepsANullUrl(): void
    {
        $service = $this->makeService([
            self::termRow(1, 'php', ['vocabulary' => 'unrouted']),
        ]);

        $terms = $service->getTermsByVocabulary('unrouted');

        $this->assertCount(1, $terms);
        $this->assertNull($terms[0]->canonicalUrl);
    }

    /* ===============================
       The pass-through
    =============================== */

    public function testTheCountIsHandedStraightBack(): void
    {
        // Nothing to decorate, and nothing added on top - it exists so callers
        // depend on TermService rather than reaching for the repository and
        // then forgetting to decorate somewhere else.
        $this->assertSame(42, $this->makeService(count: 42)->countTermsByVocabulary(self::VOCABULARY));
    }

    public function testAnAbsentCountRowIsZero(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(null);

        $service = new TermService(new FeedTermRepository($db), $this->makeUrlGenerator());

        $this->assertSame(0, $service->countTermsByVocabulary(self::VOCABULARY));
    }
}
