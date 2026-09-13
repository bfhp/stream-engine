<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;

final readonly class UserSessionRepository
{
    public function __construct(
        private PdoDatabase $db
    ) {

    }

    public function create(
        int    $userId,
        string $tokenHash,
        string $userAgent,
        string $ip,
        int    $expiresAt
    ): void {

        $this->db->execute(
            "
            INSERT INTO user_sessions
            (user_id, token_hash, user_agent, ip_address,
             expires_at, created_at, last_used_at)
            VALUES (?, ?, ?, ?, ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
            ",
            [
                $userId,
                $tokenHash,
                $userAgent,
                $ip,
                $expiresAt
            ]
        );
    }

    /**
     * Uses index: token_hash (token_hash)
     */
    public function findValid(string $tokenHash): ?array
    {
        return $this->db->fetchOne(
            "
            SELECT *
            FROM user_sessions
            WHERE token_hash = ?
              AND expires_at > UNIX_TIMESTAMP()
            ",
            [$tokenHash]
        );
    }

    /**
     * Uses index: token_hash (token_hash)
     */
    public function deleteByToken(string $tokenHash): void
    {
        $this->db->execute(
            "DELETE FROM user_sessions WHERE token_hash = ?",
            [$tokenHash]
        );
    }

    /**
     * Marks a session as still in use - the write half of presence, and the
     * only thing that keeps its owner inside
     * UserService::ONLINE_WINDOW_SECONDS.
     *
     * Only writes if the row is already at least $minAgeSeconds stale.
     * Every writer of this column runs on *every* request from its client -
     * AuthService::currentUser() for members (including each messenger poll
     * and every other API call), UserService::recordGuestPresence() for
     * anonymous visitors - while the column is only ever read at 90-second
     * resolution, so an unthrottled version was one write per request to
     * buy precision nothing reads. The guard lives in the WHERE clause
     * rather than in PHP: a read-then-write would cost an extra query and
     * still race.
     *
     * Session *expiry* is untouched by this. That's `expires_at`, set once
     * at login and never slid forward, so a skipped write can't shorten
     * anyone's session.
     *
     * Uses index: PRIMARY(id)
     */
    public function touchIfStale(int $id, int $minAgeSeconds): void
    {
        $this->db->execute(
            "UPDATE user_sessions
             SET last_used_at = UNIX_TIMESTAMP()
             WHERE id = ?
               AND last_used_at < (UNIX_TIMESTAMP() - ?)",
            [$id, $minAgeSeconds]
        );
    }

    /**
     * Deletes up to $limit rows whose `expires_at` has passed - the whole of
     * session cleanup (see UserService::cleanupExpiredSessions(), which the
     * `users:sessions-cleanup` cron task calls hourly).
     *
     * Nothing reads an expired row: findValid() filters them out, and the
     * presence queries only ever look at the last 90 seconds. So this is
     * pure garbage collection, and losing a row it should have kept would
     * still be harmless - a member with a live cookie whose row vanished is
     * simply logged out, which is what an expired session means anyway.
     *
     * Batched rather than one unbounded DELETE: this table now grows with
     * anonymous traffic, and a first run against a long-neglected table
     * would otherwise be a single statement holding row locks over an
     * unbounded set. The caller loops.
     *
     * ORDER BY costs nothing here - the index already produces that order -
     * and buys two things: batches that drain oldest-first, and a statement
     * that isn't flagged ER_BINLOG_UNSAFE_LIMIT under statement-based
     * replication (a DELETE ... LIMIT with no deterministic order can pick
     * different rows on a replica).
     *
     * Uses index: user_sessions_expires_at_index (expires_at) - a range
     * scan from the oldest row, which also satisfies the ORDER BY;
     * user_expires_idx (user_id, expires_at) can't serve this, its leading
     * column is user_id.
     *
     * The LIMIT is interpolated rather than bound: PdoDatabase::execute()
     * passes every parameter through PDOStatement::execute(array), which
     * binds them as strings, and MariaDB rejects a string parameter in
     * LIMIT - there's no PARAM_INT path through this wrapper. It's an `int`
     * argument run through max(), so there's nothing to inject; same shape
     * as the LIMIT/OFFSET in UserRepository.
     *
     * @return int rows actually deleted, so the caller knows whether to ask
     *             for another batch
     */
    public function deleteExpired(int $limit): int
    {
        return $this->db->execute(
            "DELETE FROM user_sessions
             WHERE expires_at < UNIX_TIMESTAMP()
             ORDER BY expires_at
             LIMIT ".max(1, $limit)
        );
    }

    /**
     * A session row for someone who isn't logged in: `user_id` is NULL (see
     * migrations/20260912000000_initial.sql for
     * why NULL rather than 0), keyed by the visitor's own cookie token the
     * same way a member's session is.
     *
     * An upsert, not a plain INSERT: two requests from the same browser
     * (a page and its XHR) can both find no row and both try to create one,
     * and a visitor whose cookie outlives its row would otherwise collide
     * with the unique `token_hash` on every single request afterwards. Both
     * are ordinary traffic, and PdoDatabase runs in exception mode, so
     * either would be a 500.
     *
     * Uses index: token_hash (token_hash) - via ON DUPLICATE KEY.
     */
    public function createGuest(
        string $tokenHash,
        string $userAgent,
        string $ip,
        int    $expiresAt
    ): void {

        $this->db->execute(
            "
            INSERT INTO user_sessions
            (user_id, bot_name, token_hash, user_agent, ip_address,
             expires_at, created_at, last_used_at)
            VALUES (NULL, NULL, ?, ?, ?, ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                last_used_at = UNIX_TIMESTAMP(),
                expires_at   = VALUES(expires_at)
            ",
            [
                $tokenHash,
                $userAgent,
                $ip,
                $expiresAt
            ]
        );
    }

    /**
     * One row per bot for as long as it keeps visiting - not one per
     * request. (The hourly sweep reclaims it a day after the bot's last
     * visit; if it comes back later it simply gets a fresh row.)
     *
     * Crawlers don't keep cookies, so the "only create a row once the
     * visitor hands our cookie back" rule that keeps guest rows bounded
     * (UserService::recordGuestPresence()) can't apply to them: every hit
     * would otherwise insert a new row. Instead the caller derives
     * $tokenHash from the bot's *name*, so the existing unique constraint
     * on token_hash turns every subsequent visit into an UPDATE of the same
     * row. "Googlebot is on the site right now" is all the presence UI ever
     * asks, and that's exactly what one row per bot answers.
     *
     * $minAgeSeconds throttles the update half the same way touchIfStale()
     * does for guests: an active crawler would otherwise rewrite this one
     * hot row on every request it makes. Every updated column is behind the
     * same guard, not just last_used_at - crawlers rotate IPs and bump user
     * agent versions constantly, so leaving those unconditional would keep
     * rewriting the row anyway and make the throttle decorative.
     * `last_used_at` is assigned last on purpose: MariaDB evaluates the
     * assignments in order, so the three guards above still compare against
     * the old value.
     *
     * Uses index: token_hash (token_hash) - via ON DUPLICATE KEY.
     */
    public function recordBotVisit(
        string $tokenHash,
        string $botName,
        string $userAgent,
        string $ip,
        int    $expiresAt,
        int    $minAgeSeconds = 0
    ): void {

        $this->db->execute(
            "
            INSERT INTO user_sessions
            (user_id, bot_name, token_hash, user_agent, ip_address,
             expires_at, created_at, last_used_at)
            VALUES (NULL, ?, ?, ?, ?, ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                expires_at   = IF(last_used_at < (UNIX_TIMESTAMP() - ?), VALUES(expires_at), expires_at),
                user_agent   = IF(last_used_at < (UNIX_TIMESTAMP() - ?), VALUES(user_agent), user_agent),
                ip_address   = IF(last_used_at < (UNIX_TIMESTAMP() - ?), VALUES(ip_address), ip_address),
                last_used_at = IF(last_used_at < (UNIX_TIMESTAMP() - ?), UNIX_TIMESTAMP(), last_used_at)
            ",
            [
                $botName,
                $tokenHash,
                $userAgent,
                $ip,
                $expiresAt,
                $minAgeSeconds,
                $minAgeSeconds,
                $minAgeSeconds,
                $minAgeSeconds
            ]
        );
    }

    /**
     * Online presence, derived from existing session activity - no separate
     * heartbeat/presence table. `currentUser()` (AuthService) already calls
     * touchIfStale() on every authenticated request, so "online" just means
     * "has a session touched in the last $thresholdSeconds", same idea as the
     * typing indicator's `last_typing_at > NOW() - 5`.
     *
     * Callers outside this layer should go through UserService's presence
     * methods rather than here directly - that's where the site's notion of
     * "online" (window length and the per-user hidePresence filter) is
     * decided. MessageService is the one exception, for the cycle reason its
     * own constructor documents.
     *
     * Needs no `user_id IS NOT NULL` guard, unlike findOnlineUserIds()
     * below: guest and bot rows carry a NULL user_id, and an explicit id
     * list can't match one.
     *
     * Uses index: user_id (user_id)
     *
     * @param list<int> $ids
     * @return array<int, true> set of user ids (from $ids) that are online
     */
    public function getOnlineUserIds(array $ids, int $thresholdSeconds = 90): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $result = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $rows = $this->db->fetchAll(
                "SELECT DISTINCT user_id
             FROM user_sessions
             WHERE user_id IN ($placeholders)
               AND last_used_at > (UNIX_TIMESTAMP() - ?)",
                [...$chunk, $thresholdSeconds]
            );

            foreach ($rows as $row) {
                $result[(int)$row['user_id']] = true;
            }
        }

        return $result;
    }

    /**
     * The open-ended half of getOnlineUserIds(): everyone currently online,
     * without a candidate id list to intersect with. Same definition of
     * "online" (a session touched within $thresholdSeconds), just asked the
     * other way round - "who is here" instead of "is this person here".
     *
     * Deliberately unbounded: the result is already bounded by the window
     * itself (only sessions touched in the last ~90 seconds qualify), so
     * a LIMIT would buy nothing but a wrong count for the caller that wants
     * both a total and the first N names - see UserService::onlineMembers().
     *
     * Ordered most-recently-seen first so a caller showing only part of the
     * list shows the people who are most plausibly still at their keyboard.
     *
     * `user_id IS NOT NULL` is load-bearing, not defensive: guests and bots
     * live in this same table now (see
     * migrations/20260912000000_initial.sql), and
     * without it every anonymous row would collapse into one extra "member"
     * with a NULL id that UserService::onlineMembers() would then try to
     * resolve.
     *
     * Uses index: user_sessions_presence_index (last_used_at, user_id,
     * bot_name) - covering, so the table itself is never touched. The
     * GROUP BY/ORDER BY on top of that range scan still costs a temporary
     * table and a sort; that's affordable precisely because the window keeps
     * the row count small, and it's the reason not to widen the window
     * casually.
     *
     * @return list<int> distinct user ids, most recently active first
     */
    public function findOnlineUserIds(int $thresholdSeconds = 90): array
    {
        $rows = $this->db->fetchAll(
            "SELECT user_id, MAX(last_used_at) AS last_seen_at
             FROM user_sessions
             WHERE last_used_at > (UNIX_TIMESTAMP() - ?)
               AND user_id IS NOT NULL
             GROUP BY user_id
             ORDER BY last_seen_at DESC",
            [$thresholdSeconds]
        );

        return array_map(
            static fn (array $row): int => (int)$row['user_id'],
            $rows
        );
    }

    /**
     * How many anonymous humans are on the site right now - rows with no
     * user_id and no bot_name. One row is one visitor (one browser that has
     * been given our visit cookie), so this is a COUNT, not a
     * COUNT(DISTINCT): there's nothing to deduplicate by.
     *
     * Uses index: user_sessions_presence_index (last_used_at, user_id,
     * bot_name) - covering.
     */
    public function countOnlineGuests(int $thresholdSeconds = 90): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS total
             FROM user_sessions
             WHERE last_used_at > (UNIX_TIMESTAMP() - ?)
               AND user_id IS NULL
               AND bot_name IS NULL",
            [$thresholdSeconds]
        );

        return (int)($row['total'] ?? 0);
    }

    /**
     * Which crawlers are on the site right now, most recently seen first.
     *
     * Names, not a count: there are only ever a handful of them (one row per
     * bot - see recordBotVisit()), and "Googlebot, YandexBot" is both more
     * useful and more honest than a number nobody can interpret.
     *
     * Uses index: user_sessions_presence_index (last_used_at, user_id,
     * bot_name) - covering.
     *
     * @return list<string>
     */
    public function findOnlineBotNames(int $thresholdSeconds = 90): array
    {
        $rows = $this->db->fetchAll(
            "SELECT bot_name, MAX(last_used_at) AS last_seen_at
             FROM user_sessions
             WHERE last_used_at > (UNIX_TIMESTAMP() - ?)
               AND bot_name IS NOT NULL
             GROUP BY bot_name
             ORDER BY last_seen_at DESC",
            [$thresholdSeconds]
        );

        return array_map(
            static fn (array $row): string => (string)$row['bot_name'],
            $rows
        );
    }
}
