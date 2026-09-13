<?php

declare(strict_types=1);

namespace StreamEngine\Core;

/**
 * Feed read-tracking for guests, who have no user_id to key FeedReadRepository
 * rows on. Keeps the same parent_id => {position, read_at} shape as that
 * repository, just persisted client-side as a single cookie instead of in
 * feed_reads.
 *
 * The cookie is capped at MAX_ENTRIES: once a guest has read more parents
 * (books, forum topics, ...) than that, the oldest reads are dropped to keep
 * the cookie well under browsers' ~4KB per-cookie limit. That means a guest's
 * read history is best-effort and can silently roll off - acceptable for
 * "have you seen this" badges, not a fit for anything that needs a durable
 * record. Since entries are now one per parent rather than one per page,
 * MAX_ENTRIES caps how many books/topics a guest can have in progress at
 * once, not how many pages of a single book they can read.
 */
final class GuestFeedReadStore
{
    private const string COOKIE_NAME = 'feed_reads';

    private const int COOKIE_TTL = 60 * 60 * 24 * 365; // 1 year

    private const int MAX_ENTRIES = 100;

    /**
     * Records that the guest has read a parent up to $position, stamping the
     * current time. $position is the read child's feeds.position; omit it
     * for parents with no finer-grained child tracking (forum
     * topics currently only track "seen since last activity").
     *
     * Overwrites any previous entry for the same parent - this tracks the
     * most recently read position, not the furthest ever reached, so
     * re-reading an earlier page intentionally moves the watermark back.
     *
     * Also updates $_COOKIE in-memory (not just the outgoing Set-Cookie
     * header) so a read/write within the same request sees the fresh value,
     * matching Security::getCsrfToken()'s cookie handling.
     */
    public function markAsRead(int $parentId, ?int $position = null): void
    {
        $map = $this->readMap();
        $map[$parentId] = ['position' => $position, 'readAt' => time()];

        $this->writeMap($this->prune($map));
    }

    /**
     * When the guest last read a parent, or null if they never have (or
     * their read has since been pruned from the cookie - see the class
     * docblock).
     */
    public function findReadAt(int $parentId): ?int
    {
        return $this->readMap()[$parentId]['readAt'] ?? null;
    }

    /**
     * Batch form of findReadAt(), for rendering "unread" badges across a
     * list of parents without decoding the cookie once per row.
     *
     * @param int[] $parentIds
     * @return array<int, int> read_at keyed by parent_id; parents never read
     *     (or pruned from the cookie) are absent
     */
    public function findReadAtForFeeds(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }

        $map = array_intersect_key($this->readMap(), array_flip($parentIds));

        return array_map(static fn (array $entry): int => $entry['readAt'], $map);
    }

    /**
     * The full parent_id => {position, readAt} map from the cookie, e.g. to
     * cross-reference against feeds of a specific type when building a
     * "continue reading" widget - the cookie itself doesn't know what type
     * each parent is, so that filtering has to happen elsewhere (see
     * FeedRepository::findByIds()'s type filter).
     *
     * @return array<int, array{position: ?int, readAt: int}>
     */
    public function all(): array
    {
        return $this->readMap();
    }

    /**
     * Removes the read marker for a parent - the guest-side half of
     * FeedReadRepository::deleteForParent(), e.g. "remove this book from
     * continue reading". No-op if the parent was never marked read.
     */
    public function removeForParent(int $parentId): void
    {
        $map = $this->readMap();

        if (! isset($map[$parentId])) {
            return;
        }

        unset($map[$parentId]);

        $this->writeMap($map);
    }

    /**
     * Decodes the cookie into a parent_id => {position, readAt} map.
     * Missing, malformed, or tampered-with cookies (not valid JSON, not an
     * object/array, or containing a malformed entry) decode to an empty map
     * rather than erroring - a guest's read state just resets in that case.
     *
     * @return array<int, array{position: ?int, readAt: int}>
     */
    private function readMap(): array
    {
        $raw = $_COOKIE[self::COOKIE_NAME] ?? '';

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $map = [];

        foreach ($decoded as $parentId => $entry) {
            if (! is_numeric($parentId) || ! is_array($entry) || ! isset($entry['readAt']) || ! is_numeric($entry['readAt'])) {
                continue;
            }

            $position = $entry['position'] ?? null;

            $map[(int) $parentId] = [
                'position' => $position !== null && is_numeric($position) ? (int) $position : null,
                'readAt' => (int) $entry['readAt'],
            ];
        }

        return $map;
    }

    /**
     * Keeps only the MAX_ENTRIES most recently read parents, dropping the
     * oldest ones once a guest exceeds that count.
     *
     * @param array<int, array{position: ?int, readAt: int}> $map
     * @return array<int, array{position: ?int, readAt: int}>
     */
    private function prune(array $map): array
    {
        if (count($map) <= self::MAX_ENTRIES) {
            return $map;
        }

        uasort($map, static fn (array $a, array $b): int => $a['readAt'] <=> $b['readAt']); // Oldest first, keys preserved

        return array_slice($map, count($map) - self::MAX_ENTRIES, null, true);
    }

    /**
     * @param array<int, array{position: ?int, readAt: int}> $map
     */
    private function writeMap(array $map): void
    {
        $encoded = json_encode($map, JSON_FORCE_OBJECT);

        $_COOKIE[self::COOKIE_NAME] = $encoded;

        setcookie(self::COOKIE_NAME, $encoded, [
            'expires' => time() + self::COOKIE_TTL,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
