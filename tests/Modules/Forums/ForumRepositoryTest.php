<?php

declare(strict_types=1);

namespace Tests\Modules\Forums;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Modules\Forums\ForumRepository;

/**
 * The busiest read path on the site, and until now only ever exercised through
 * ForumsControllerTest's SQL-substring router - which asserts on canned results,
 * not on what these queries actually compute.
 *
 * What is asserted here, and what isn't: the PHP around the SQL (the empty-input
 * guards, the chunking, the hydration, the short circuits) is asserted
 * behaviourally, because that is where the bugs with real consequences live -
 * an unchunked IN() list is a query that stops working past a certain forum
 * count, and a missing empty-input guard is a syntax error. The statements
 * themselves are asserted only where a rule is legible in them: the tie-break,
 * the CASE that picks a "last poster", the GREATEST that keeps a topic with no
 * replies from sorting to the bottom. Nobody should read this file as proof the
 * queries return the right rows - that needs a database.
 */
final class ForumRepositoryTest extends TestCase
{
    /** @var list<array{0: string, 1: array}> */
    private array $reads = [];

    /**
     * @param list<array<string, mixed>>|array<string, mixed>|null $rows what
     *        fetchAll returns; fetchOne gets the first, or $one when given
     * @param array<string, mixed>|null $one
     */
    private function db(?array $rows = null, ?array $one = null): PdoDatabase
    {
        $db = $this->createStub(PdoDatabase::class);

        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, array $params = []) use ($rows): array {
                $this->reads[] = [$sql, $params];

                return $rows ?? [];
            }
        );

        $db->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params = []) use ($one): ?array {
                $this->reads[] = [$sql, $params];

                return $one;
            }
        );

        return $db;
    }

    /* ===============================
       Empty input
    =============================== */

    /**
     * Every method taking a list of ids builds an `IN (...)` out of it, and an
     * empty `IN ()` is a syntax error rather than an empty result - so the
     * guard is correctness, not politeness. Asserted by the query never being
     * issued at all.
     *
     * @param callable(ForumRepository): mixed $call
     */
    #[DataProvider('emptyIdListProvider')]
    public function testAnEmptyIdListIssuesNoQuery(callable $call, mixed $expected): void
    {
        $repository = new ForumRepository($this->db());

        $this->assertSame($expected, $call($repository));
        $this->assertSame([], $this->reads);
    }

    /** @return array<string, array{callable, mixed}> */
    public static function emptyIdListProvider(): array
    {
        return [
            'countTopicsAndPosts' => [fn (ForumRepository $r) => $r->countTopicsAndPosts([]), []],
            'findLastActivity' => [fn (ForumRepository $r) => $r->findLastActivity([]), []],
            'findTopicIdsForForums' => [fn (ForumRepository $r) => $r->findTopicIdsForForums([]), []],
            'countUserForumPosts' => [fn (ForumRepository $r) => $r->countUserForumPosts([]), []],
            'findTopicActivity' => [fn (ForumRepository $r) => $r->findTopicActivity([]), []],
        ];
    }

    /**
     * Ids arrive from callers that built them out of query results and route
     * parameters, so they are deduplicated and cast before they reach a
     * placeholder list - one placeholder per *distinct* id.
     */
    public function testIdListsAreDeduplicatedAndCast(): void
    {
        (new ForumRepository($this->db()))->findTopicIdsForForums([5, '5', 7]);

        [$sql, $params] = $this->reads[0];

        $this->assertSame([5, 7], $params);
        $this->assertStringContainsString('IN (?, ?)', $sql);
    }

    /* ===============================
       Chunking
    =============================== */

    /**
     * A single `IN (...)` list of every id would eventually hit
     * max_allowed_packet or the optimiser's limits, so these split at
     * IN_CHUNK_SIZE and merge the results. The merge is the part worth
     * asserting: a chunked query that dropped or overwrote a chunk's rows would
     * look fine at small sizes and lose data at large ones.
     */
    public function testFindTopicIdsForForumsChunksAndMergesEveryChunk(): void
    {
        $ids = range(1, 1001);

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, array $params = []): array {
                $this->reads[] = [$sql, $params];

                // One topic per forum, so the merged result is countable.
                return array_map(
                    static fn (int $forumId): array => ['id' => $forumId * 10],
                    $params
                );
            }
        );

        $topicIds = (new ForumRepository($db))->findTopicIdsForForums($ids);

        // 1001 ids at a chunk size of 1000.
        $this->assertCount(2, $this->reads);
        $this->assertCount(1000, $this->reads[0][1]);
        $this->assertCount(1, $this->reads[1][1]);

        // Nothing lost in the merge, and hydrated to ints.
        $this->assertCount(1001, $topicIds);
        $this->assertSame(10, $topicIds[0]);
        $this->assertSame(10010, $topicIds[1000]);
    }

    public function testCountTopicsAndPostsMergesChunksKeyedByForum(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, array $params = []): array {
                $this->reads[] = [$sql, $params];

                return array_map(
                    static fn (int $forumId): array => [
                        'forum_id' => $forumId,
                        'topics_total' => '2',
                        'replies_total' => '3',
                    ],
                    $params
                );
            }
        );

        $counts = (new ForumRepository($db))->countTopicsAndPosts([5, 7]);

        // "posts" is topics + replies, not replies alone - the forum list shows
        // one number for "сообщений" and it includes the opening posts.
        $this->assertSame(['topics' => 2, 'posts' => 5], $counts[5]);
        $this->assertSame(['topics' => 2, 'posts' => 5], $counts[7]);
    }

    /* ===============================
       findTopicsPage
    =============================== */

    /**
     * The short circuit matters for more than tidiness: the main query is a
     * two-CTE window function over every topic and comment in the forum, and an
     * empty forum is the common case for a freshly created one.
     */
    public function testFindTopicsPageSkipsTheHeavyQueryForAnEmptyForum(): void
    {
        $repository = new ForumRepository($this->db(one: ['total' => '0']));

        $page = $repository->findTopicsPage(5, 20, 0);

        $this->assertSame(['topicIds' => [], 'stats' => [], 'total' => 0], $page);
        // Only the count ran - no CTE.
        $this->assertCount(1, $this->reads);
        $this->assertStringNotContainsString('WITH last_comment', $this->reads[0][0]);
    }

    public function testFindTopicsPageReportsTheTotalEvenWhenThePageIsEmpty(): void
    {
        // An offset past the end: the page is empty but the total is not, which
        // is what lets the pager say "страница 9 из 3" rather than "нет тем".
        $repository = new ForumRepository($this->db(rows: [], one: ['total' => '42']));

        $page = $repository->findTopicsPage(5, 20, 1000);

        $this->assertSame([], $page['topicIds']);
        $this->assertSame(42, $page['total']);
    }

    public function testFindTopicsPageHydratesStatsKeyedByTopic(): void
    {
        $repository = new ForumRepository($this->db(
            rows: [
                ['topic_id' => '11', 'reply_count' => '3', 'last_owner_id' => '7', 'last_activity_at' => '1700000000'],
                ['topic_id' => '12', 'reply_count' => '0', 'last_owner_id' => '9', 'last_activity_at' => '1699999999'],
            ],
            one: ['total' => '2']
        ));

        $page = $repository->findTopicsPage(5, 20, 0);

        // Order is the query's, and the ids list preserves it - the stats map is
        // keyed for lookup, the list is what the view iterates.
        $this->assertSame([11, 12], $page['topicIds']);
        $this->assertSame(
            ['replyCount' => 3, 'lastOwnerId' => 7, 'lastActivityAt' => 1700000000],
            $page['stats'][11]
        );
        $this->assertSame(0, $page['stats'][12]['replyCount']);
    }

    /**
     * Three rules that are only legible in the statement, and each of which is
     * wrong in a way that looks plausible:
     *
     * - GREATEST(t.created_at, COALESCE(lc.created_at, t.created_at)): a topic
     *   with no replies sorts by its own creation time rather than by NULL,
     *   which would bury it.
     * - the CASE guarded on `lc.created_at >= t.created_at`: the "last poster"
     *   falls back to the topic's author when the newest comment is somehow
     *   older than the topic itself - clock skew, or an imported row.
     * - `ORDER BY last_activity_at DESC, t.id DESC`: without the tie-break,
     *   topics sharing a timestamp can swap places between pages, which
     *   duplicates and drops rows across a paginated list.
     */
    public function testFindTopicsPageOrdersAndFallsBackAsDocumented(): void
    {
        $repository = new ForumRepository($this->db(rows: [], one: ['total' => '1']));

        $repository->findTopicsPage(5, 20, 0);

        $sql = $this->reads[1][0];

        $this->assertStringContainsString(
            'GREATEST(t.created_at, COALESCE(lc.created_at, t.created_at))',
            $sql
        );
        $this->assertStringContainsString('lc.created_at >= t.created_at', $sql);
        $this->assertStringContainsString('ORDER BY last_activity_at DESC, t.id DESC', $sql);
        // The newest comment per topic, not just any - ROW_NUMBER + rn = 1.
        $this->assertStringContainsString('ROW_NUMBER() OVER', $sql);
        $this->assertStringContainsString('lc.rn = 1', $sql);
    }

    /**
     * LIMIT/OFFSET are interpolated rather than bound - PdoDatabase binds
     * everything as a string and MariaDB rejects that in LIMIT - so the forum
     * id is the only parameter, bound once per CTE plus once for the outer
     * query.
     */
    public function testFindTopicsPageBindsOnlyTheForumId(): void
    {
        $repository = new ForumRepository($this->db(rows: [], one: ['total' => '1']));

        $repository->findTopicsPage(5, 20, 40);

        [$sql, $params] = $this->reads[1];

        $this->assertSame([5, 5, 5], $params);
        $this->assertStringContainsString('LIMIT 20 OFFSET 40', $sql);
    }

    /* ===============================
       Counts
    =============================== */

    public function testCountRepliesDefaultsToZeroWhenTheRowIsMissing(): void
    {
        $this->assertSame(0, (new ForumRepository($this->db()))->countReplies(11));
    }

    public function testCountRepliesCountsOnlyComments(): void
    {
        $repository = new ForumRepository($this->db(one: ['total' => '7']));

        $this->assertSame(7, $repository->countReplies(11));

        [$sql, $params] = $this->reads[0];
        $this->assertStringContainsString("type = 'comment'", $sql);
        $this->assertSame([11], $params);
    }

    /**
     * 'comment' is not a forum-only type - blog posts and library pages use it
     * too - so a plain `type IN ('forum-post', 'comment')` count would fold in
     * every other module's comments. The EXISTS is what scopes replies to
     * comments whose parent is actually a topic.
     */
    public function testCountPostsSinceScopesRepliesToTopicsWithAnExists(): void
    {
        $repository = new ForumRepository($this->db(one: ['total' => '0']));

        $repository->countPostsSince(1_700_000_000);

        // Two queries, deliberately: topics and replies can't be counted in one
        // without double-counting other modules' comments.
        $this->assertCount(2, $this->reads);
        $this->assertStringContainsString('EXISTS', $this->reads[1][0]);
        $this->assertStringContainsString("t.type = 'forum-post'", $this->reads[1][0]);
    }

    public function testFindAllTopicIdsHydratesToInts(): void
    {
        $repository = new ForumRepository($this->db(rows: [['id' => '11'], ['id' => '12']]));

        $this->assertSame([11, 12], $repository->findAllTopicIds());
    }

    /* ===============================
       Topic view
    =============================== */

    /**
     * `LIMIT 0` is legal SQL that returns nothing, but `LIMIT -1` is a syntax
     * error - and both are interpolated here rather than bound, so a limit
     * arriving as 0 or negative from arithmetic on a remaining-budget counter
     * has to be caught before it reaches the statement.
     *
     * @param callable(ForumRepository): mixed $call
     */
    #[DataProvider('nonPositiveLimitProvider')]
    public function testANonPositiveLimitIssuesNoQuery(callable $call, mixed $expected): void
    {
        $repository = new ForumRepository($this->db());

        $this->assertSame($expected, $call($repository));
        $this->assertSame([], $this->reads);
    }

    /** @return array<string, array{callable, mixed}> */
    public static function nonPositiveLimitProvider(): array
    {
        return [
            'findTopicPostsPage, zero' => [fn (ForumRepository $r) => $r->findTopicPostsPage(11, 0, 0), []],
            'findTopicPostsPage, negative' => [fn (ForumRepository $r) => $r->findTopicPostsPage(11, -5, 0), []],
            'findTopicParticipants, zero' => [
                fn (ForumRepository $r) => $r->findTopicParticipants(11, 0),
                ['ids' => [], 'total' => 0],
            ],
        ];
    }

    public function testFindTopicPostsPageOrdersOldestFirstWithATieBreak(): void
    {
        $repository = new ForumRepository($this->db(rows: [['id' => '20'], ['id' => '21']]));

        $this->assertSame([20, 21], $repository->findTopicPostsPage(11, 20, 40));

        [$sql, $params] = $this->reads[0];

        // A topic reads top to bottom, unlike the topic *list* which is newest
        // first - and the id tie-break keeps two replies posted in the same
        // second from swapping between pages.
        $this->assertStringContainsString('ORDER BY created_at ASC, id ASC', $sql);
        $this->assertStringContainsString('LIMIT 20 OFFSET 40', $sql);
        $this->assertSame([11], $params);
    }

    /**
     * A participant is anyone who wrote in the topic, including its author -
     * hence the UNION of the topic row itself with its comments, and MIN() so
     * they are ordered by when they first spoke rather than most recently.
     */
    public function testFindTopicParticipantsCountsTheAuthorAndOrdersByFirstPost(): void
    {
        $repository = new ForumRepository($this->db(rows: [
            ['owner_id' => '7', 'total_count' => '3'],
            ['owner_id' => '9', 'total_count' => '3'],
        ]));

        $result = $repository->findTopicParticipants(11, 2);

        // The window function's total is the count *before* the limit, so the
        // view can say "и ещё 1" rather than just showing two names.
        $this->assertSame(['ids' => [7, 9], 'total' => 3], $result);

        [$sql, $params] = $this->reads[0];
        $this->assertStringContainsString('MIN(created_at) AS first_at', $sql);
        $this->assertStringContainsString('ORDER BY first_at ASC', $sql);
        // The topic id twice: once for the topic row, once for its comments.
        $this->assertSame([11, 11], $params);
    }

    public function testFindTopicParticipantsReportsNoTotalWhenNobodyPosted(): void
    {
        $repository = new ForumRepository($this->db(rows: []));

        // Not a total of 0 alongside a non-empty list, and not a missing key -
        // the caller reads both unconditionally.
        $this->assertSame(['ids' => [], 'total' => 0], $repository->findTopicParticipants(11, 20));
    }

    public function testCountParticipantsAcceptsOneForumOrMany(): void
    {
        $repository = new ForumRepository($this->db(one: ['total' => '4']));

        $this->assertSame(4, $repository->countParticipants(5));

        // The ids are bound twice - once for the topics arm of the UNION, once
        // for the subquery that scopes the comments arm.
        $this->assertSame([5, 5], $this->reads[0][1]);

        $this->reads = [];

        $many = new ForumRepository($this->db(one: ['total' => '9']));
        $this->assertSame(9, $many->countParticipants([5, 7]));
        $this->assertSame([5, 7, 5, 7], $this->reads[0][1]);
    }

    public function testCountParticipantsDefaultsToZero(): void
    {
        $this->assertSame(0, (new ForumRepository($this->db()))->countParticipants([]));
        // An empty list short-circuits before the query, like the others.
        $this->assertSame([], $this->reads);
    }
}
