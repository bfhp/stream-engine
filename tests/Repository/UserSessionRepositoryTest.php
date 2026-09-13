<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\UserSessionRepository;
use Tests\Support\FakeSessionDatabase;

/**
 * The authentication boundary and the presence layer, in one table.
 *
 * Two kinds of test here, and the split is deliberate rather than tidy:
 *
 * - **Behaviour**, against FakeSessionDatabase, wherever the fake models what
 *   the query filters on. That is the whole of presence - it knows about
 *   `last_used_at`, guest rows and bot rows - so those tests seed rows and
 *   assert on results rather than on SQL text.
 * - **SQL shape**, for the rest. The fake has no notion of `expires_at`, so
 *   "an expired session is not valid" and "cleanup only deletes expired rows"
 *   can only be asserted by looking at the statement. Worse than a behavioural
 *   test and worth replacing if the fake ever grows expiry, but better than
 *   leaving the session-expiry rule unasserted entirely.
 */
final class UserSessionRepositoryTest extends TestCase
{
    private function makeRepository(FakeSessionDatabase $db): UserSessionRepository
    {
        return new UserSessionRepository($db);
    }

    /**
     * @param list<array{0: string, 1: array}> $executed
     * @return array{0: string, 1: array}
     */
    private function statementContaining(array $executed, string $needle): array
    {
        foreach ($executed as $call) {
            if (str_contains($call[0], $needle)) {
                return $call;
            }
        }

        $this->fail(sprintf('no statement containing "%s" was executed', $needle));
    }

    /* ===============================
       Session validity
    =============================== */

    /**
     * An expired row must not authenticate anyone, and the filter lives in the
     * query rather than in PHP - so the query is what gets asserted. A capturing
     * stub rather than FakeSessionDatabase, which records writes but not reads.
     */
    public function testFindValidRefusesExpiredRowsInTheQuery(): void
    {
        $captured = null;
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturnCallback(
            function (string $sql) use (&$captured): ?array {
                $captured = $sql;

                return null;
            }
        );

        (new UserSessionRepository($db))->findValid('hash');

        $this->assertStringContainsString('expires_at > UNIX_TIMESTAMP()', (string) $captured);
    }

    public function testFindValidFindsARowByItsTokenHash(): void
    {
        $db = new FakeSessionDatabase();
        $db->addGuestSession(secondsAgo: 5, tokenHash: 'cookie-hash');

        $found = $this->makeRepository($db)->findValid('cookie-hash');

        $this->assertNotNull($found);
        $this->assertNull($found['user_id']);
    }

    public function testFindValidReturnsNullForAnUnknownToken(): void
    {
        $db = new FakeSessionDatabase();
        $db->addGuestSession(secondsAgo: 5, tokenHash: 'cookie-hash');

        $this->assertNull($this->makeRepository($db)->findValid('someone-elses-hash'));
    }

    /* ===============================
       Writes
    =============================== */

    public function testCreateStoresTheHashItWasGivenAndNothingElse(): void
    {
        $db = new FakeSessionDatabase();

        $this->makeRepository($db)->create(
            userId: 7,
            tokenHash: 'sha256-of-the-token',
            userAgent: 'Firefox',
            ip: '203.0.113.5',
            expiresAt: 1_700_000_000
        );

        [$sql, $params] = $this->statementContaining($db->executed, 'INSERT INTO user_sessions');

        // The raw token never reaches this layer - AuthService hashes before
        // calling - so what this can pin is that the value handed over is the
        // one stored, unmodified, and that expires_at is set from the argument
        // rather than computed here.
        $this->assertSame([7, 'sha256-of-the-token', 'Firefox', '203.0.113.5', 1_700_000_000], $params);
        $this->assertStringContainsString('token_hash', $sql);
    }

    public function testDeleteByTokenTargetsOneRow(): void
    {
        $db = new FakeSessionDatabase();

        $this->makeRepository($db)->deleteByToken('hash');

        [$sql, $params] = $this->statementContaining($db->executed, 'DELETE FROM user_sessions');

        $this->assertStringContainsString('WHERE token_hash = ?', $sql);
        $this->assertSame(['hash'], $params);
    }

    /**
     * The throttle is in the WHERE clause, not in PHP: every authenticated
     * request calls this, while the column is only read at 90-second
     * resolution, so a read-then-write would cost an extra query and still
     * race.
     */
    public function testTouchIfStaleGuardsTheWriteInTheStatement(): void
    {
        $db = new FakeSessionDatabase();

        $this->makeRepository($db)->touchIfStale(42, 60);

        [$sql, $params] = $this->statementContaining($db->executed, 'UPDATE user_sessions');

        $this->assertStringContainsString('last_used_at < (UNIX_TIMESTAMP() - ?)', $sql);
        $this->assertSame([42, 60], $params);

        // Session expiry is set once at login and never slid forward, so a
        // touch must not extend it - a skipped write can't shorten a session
        // and a performed one can't lengthen it.
        $this->assertStringNotContainsString('expires_at', $sql);
    }

    public function testCreateGuestUpsertsSoConcurrentRequestsDontCollide(): void
    {
        $db = new FakeSessionDatabase();

        $this->makeRepository($db)->createGuest('cookie-hash', 'Firefox', '203.0.113.5', 1_700_000_000);

        [$sql, $params] = $this->statementContaining($db->executed, 'INSERT INTO user_sessions');

        // Two requests from one browser can both find no row and both insert;
        // so can a visitor whose cookie outlived its row. Either would be a 500
        // against the unique token_hash without this.
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertSame(['cookie-hash', 'Firefox', '203.0.113.5', 1_700_000_000], $params);
    }

    /**
     * Crawlers keep no cookies, so one row per *bot name* is what keeps this
     * from growing a row per request. The throttle has to cover every column
     * the upsert touches, not just last_used_at: bots rotate IPs and bump user
     * agent versions constantly, so unconditional writes there would rewrite
     * the row anyway and make the guard decorative.
     */
    public function testRecordBotVisitThrottlesEveryColumnItUpdates(): void
    {
        $db = new FakeSessionDatabase();

        $this->makeRepository($db)->recordBotVisit(
            tokenHash: 'bot:googlebot',
            botName: 'Googlebot',
            userAgent: 'Googlebot/2.1',
            ip: '66.249.66.1',
            expiresAt: 1_700_000_000,
            minAgeSeconds: 300
        );

        [$sql, $params] = $this->statementContaining($db->executed, 'INSERT INTO user_sessions');

        foreach (['expires_at', 'user_agent', 'ip_address', 'last_used_at'] as $column) {
            $this->assertMatchesRegularExpression(
                '/'.$column.'\s+= IF\(last_used_at < \(UNIX_TIMESTAMP\(\) - \?\)/',
                $sql,
                $column.' should only be written once the row is stale'
            );
        }

        // Four guards, so the throttle is bound four times - the values after
        // the inserted row's own.
        $this->assertSame(
            ['Googlebot', 'bot:googlebot', 'Googlebot/2.1', '66.249.66.1', 1_700_000_000, 300, 300, 300, 300],
            $params
        );
    }

    /* ===============================
       Cleanup
    =============================== */

    public function testDeleteExpiredOnlyRemovesExpiredRowsOldestFirst(): void
    {
        $db = new FakeSessionDatabase();

        $this->makeRepository($db)->deleteExpired(500);

        [$sql, $params] = $this->statementContaining($db->executed, 'DELETE FROM user_sessions');

        $this->assertStringContainsString('expires_at < UNIX_TIMESTAMP()', $sql);
        // Oldest first, which drains the backlog in order and keeps the
        // statement deterministic under statement-based replication.
        $this->assertStringContainsString('ORDER BY expires_at', $sql);
        $this->assertStringContainsString('LIMIT 500', $sql);

        // The LIMIT is interpolated, not bound - PdoDatabase binds everything
        // as a string and MariaDB rejects a string in LIMIT - so there are no
        // params at all here. That is also why the value must stay an int.
        $this->assertSame([], $params);
    }

    public function testDeleteExpiredNeverAsksForAnUnboundedDelete(): void
    {
        $db = new FakeSessionDatabase();
        $repository = $this->makeRepository($db);

        // max(1, $limit): a caller passing 0 (or a negative, from arithmetic on
        // a remaining-budget counter) must not turn this into "delete
        // everything" or an invalid LIMIT.
        $repository->deleteExpired(0);
        $repository->deleteExpired(-10);

        foreach ($db->executed as [$sql]) {
            $this->assertStringContainsString('LIMIT 1', $sql);
        }
    }

    public function testDeleteExpiredReturnsTheRowCountSoTheCallerCanLoop(): void
    {
        $db = new FakeSessionDatabase();

        // The fake reports 1 row per execute(); the point is that the value is
        // returned rather than swallowed - UserService::cleanupExpiredSessions()
        // keeps asking for batches until this comes back short.
        $this->assertSame(1, $this->makeRepository($db)->deleteExpired(500));
    }

    /* ===============================
       Presence
    =============================== */

    public function testGetOnlineUserIdsAsksNothingForAnEmptyList(): void
    {
        $db = new FakeSessionDatabase();

        // FakeSessionDatabase throws on unrouted SQL, so a query built from an
        // empty IN () - which is a syntax error in MySQL - would surface here.
        $this->assertSame([], $this->makeRepository($db)->getOnlineUserIds([]));
    }

    public function testGetOnlineUserIdsReturnsASetOfTheIdsThatAreOnline(): void
    {
        $db = new FakeSessionDatabase();
        $db->addSession(userId: 7, secondsAgo: 5);
        $db->addSession(userId: 9, secondsAgo: 5000);

        $online = $this->makeRepository($db)->getOnlineUserIds([7, 9, 11]);

        // A set keyed by id, not a list - callers ask "is this one online?".
        $this->assertSame([7 => true], $online);
    }

    public function testGetOnlineUserIdsNormalisesTheCandidateList(): void
    {
        $db = new FakeSessionDatabase();
        $db->addSession(userId: 7, secondsAgo: 5);

        // Duplicates and numeric strings come straight from callers building
        // id lists out of query results.
        $online = $this->makeRepository($db)->getOnlineUserIds([7, 7, '7']);

        $this->assertSame([7 => true], $online);
    }

    public function testGetOnlineUserIdsHonoursTheWindow(): void
    {
        $db = new FakeSessionDatabase();
        // Not 89 and 91: the row is seeded and the window evaluated by two
        // separate time() calls, so a clock tick between them would flip a case
        // sitting exactly on the edge. These margins survive one.
        $db->addSession(userId: 7, secondsAgo: 60);
        $db->addSession(userId: 9, secondsAgo: 120);

        $online = $this->makeRepository($db)->getOnlineUserIds([7, 9], thresholdSeconds: 90);

        $this->assertArrayHasKey(7, $online);
        $this->assertArrayNotHasKey(9, $online);
    }

    /**
     * `user_id IS NOT NULL` is load-bearing rather than defensive: guests and
     * bots share this table, and without it every anonymous row would collapse
     * into one phantom member with a NULL id that the caller would then try to
     * resolve to a profile.
     */
    public function testFindOnlineUserIdsIgnoresGuestsAndBots(): void
    {
        $db = new FakeSessionDatabase();
        $db->addSession(userId: 7, secondsAgo: 5);
        $db->addGuestSession(secondsAgo: 5);
        $db->addBotSession('Googlebot', secondsAgo: 5);

        $this->assertSame([7], $this->makeRepository($db)->findOnlineUserIds());
    }

    public function testFindOnlineUserIdsReturnsEachUserOnceMostRecentFirst(): void
    {
        $db = new FakeSessionDatabase();
        // One person, two devices - one row each, and they must not appear twice.
        $db->addSession(userId: 7, secondsAgo: 60);
        $db->addSession(userId: 7, secondsAgo: 2);
        $db->addSession(userId: 9, secondsAgo: 30);

        // Most recently seen first, so a caller showing only the first few
        // shows the people most plausibly still at their keyboard.
        $this->assertSame([7, 9], $this->makeRepository($db)->findOnlineUserIds());
    }

    public function testCountOnlineGuestsCountsOnlyAnonymousHumans(): void
    {
        $db = new FakeSessionDatabase();
        $db->addGuestSession(secondsAgo: 5);
        $db->addGuestSession(secondsAgo: 5);
        $db->addSession(userId: 7, secondsAgo: 5);
        $db->addBotSession('Googlebot', secondsAgo: 5);
        $db->addGuestSession(secondsAgo: 5000);

        // One row is one visitor, so this is a COUNT rather than a
        // COUNT(DISTINCT) - there is nothing to deduplicate by.
        $this->assertSame(2, $this->makeRepository($db)->countOnlineGuests());
    }

    public function testFindOnlineBotNamesListsEachCrawlerOnceMostRecentFirst(): void
    {
        $db = new FakeSessionDatabase();
        $db->addBotSession('YandexBot', secondsAgo: 60);
        $db->addBotSession('Googlebot', secondsAgo: 2);
        $db->addSession(userId: 7, secondsAgo: 5);
        $db->addGuestSession(secondsAgo: 5);
        $db->addBotSession('AhrefsBot', secondsAgo: 5000);

        $this->assertSame(
            ['Googlebot', 'YandexBot'],
            $this->makeRepository($db)->findOnlineBotNames()
        );
    }
}
