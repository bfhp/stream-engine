<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;

/**
 * Read tracking as a per-parent watermark, not a per-item log: one row per
 * (parent, user), where the parent feed is tracked as a unit rather than each
 * of its individual children. Reading child 40 overwrites that parent's one
 * row rather than adding a 40th; a parent with a thousand children still
 * costs one row.
 */
final readonly class FeedReadRepository
{
    private const int IN_CHUNK_SIZE = 1000;

    public function __construct(
        private PdoDatabase $db
    ) {

    }

    /**
     * Records that a user has read a parent up to $position, stamping the
     * current time. $position is the read child's feeds.position; omit it
     * for parents with no
     * finer-grained child tracking (forum topics currently only track "seen
     * since last activity", not which reply).
     *
     * Idempotent and always fresh: reading an already-read parent again just
     * overwrites its position/read_at, so callers can call this on every
     * view without checking the current state first. This intentionally
     * tracks the most recently read position, not the furthest ever
     * reached - re-reading an earlier page moves the watermark back on
     * purpose, matching "pick up where you left off" semantics.
     *
     * Uses index: PRIMARY(parent_id, user_id)
     */
    public function markAsRead(int $parentId, int $userId, ?int $position = null): void
    {
        $this->db->execute(
            '
            INSERT INTO feed_reads (parent_id, user_id, position, read_at)
            VALUES (?, ?, ?, UNIX_TIMESTAMP())
            ON DUPLICATE KEY UPDATE position = VALUES(position), read_at = VALUES(read_at)
            ',
            [$parentId, $userId, $position]
        );
    }

    /**
     * Bulk form of markAsRead(), for "mark all read" actions that can span
     * hundreds or thousands of parents at once (every topic in a subforum,
     * or in the whole forum) - one multi-row INSERT per chunk instead of one
     * query per parent. Always marks "read now", with no per-parent
     * position: a batch spanning many different parents at once has no
     * single meaningful position to record (use markAsRead() directly for a
     * single parent that needs one).
     *
     * Uses index: PRIMARY(parent_id, user_id)
     *
     * @param int[] $parentIds
     */
    public function markManyAsRead(array $parentIds, int $userId): void
    {
        $parentIds = array_values(array_unique(array_map('intval', $parentIds)));

        if ($parentIds === []) {
            return;
        }

        foreach (array_chunk($parentIds, self::IN_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, NULL, UNIX_TIMESTAMP())'));

            $params = [];
            foreach ($chunk as $parentId) {
                $params[] = $parentId;
                $params[] = $userId;
            }

            $this->db->execute(
                "
                INSERT INTO feed_reads (parent_id, user_id, position, read_at)
                VALUES $placeholders
                ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)
                ",
                $params
            );
        }
    }

    /**
     * When a user last read a parent, or null if they never have.
     *
     * Uses index: PRIMARY(parent_id, user_id)
     */
    public function findReadAt(int $parentId, int $userId): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT read_at FROM feed_reads WHERE parent_id = ? AND user_id = ?',
            [$parentId, $userId]
        );

        return $row !== null ? (int) $row['read_at'] : null;
    }

    /**
     * Batch form of findReadAt(), for rendering "unread" badges across a list
     * of parents (e.g. a forum's topic list, a shelf of books) without one
     * query per row.
     *
     * Uses index: PRIMARY(parent_id, user_id)
     *
     * @param int[] $parentIds
     * @return array<int, int> read_at keyed by parent_id; parents never read are absent
     */
    public function findReadAtForFeeds(array $parentIds, int $userId): array
    {
        if ($parentIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($parentIds), '?'));

        $rows = $this->db->fetchAll(
            "SELECT parent_id, read_at FROM feed_reads WHERE user_id = ? AND parent_id IN ($placeholders)",
            [$userId, ...$parentIds]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['parent_id']] = (int) $row['read_at'];
        }

        return $result;
    }

    /**
     * Lists the ids of parents a user has read, most recently read first.
     * Intended for future "reading history" / "continue reading" views.
     *
     * Uses index: feed_reads_user_id_read_at_index (user_id, read_at)
     *
     * @return int[]
     */
    public function findParentIdsForUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->db->fetchAll(
            '
            SELECT parent_id FROM feed_reads
            WHERE user_id = ?
            ORDER BY read_at DESC
            LIMIT '.$limit.' OFFSET '.$offset.'
            ',
            [$userId]
        );

        return array_map(static fn (array $row): int => (int) $row['parent_id'], $rows);
    }

    /**
     * A user's read parents of a given type, most recently read first, each
     * with the position they last got to - the registered-user half of the
     * "continue reading" widget. Unlike the old per-page design, this needs
     * no join to recover parent_id/position: they live on the feed_reads row
     * itself, since the row already *is* per-parent.
     *
     * $limit caps how many of the user's most recent reads (of any type) get
     * scanned to find candidates of $type, not how many parents come back -
     * a prolific reader's stalest in-progress books can fall out of
     * consideration once they've read past $limit other parents, which is
     * fine for a "pick up where you left off" widget that only cares about
     * what's fresh anyway.
     *
     * Uses index: feed_reads_user_id_read_at_index (user_id, read_at)
     *
     * @return list<array{parentId: int, position: ?int}>
     */
    public function findReadParentsByType(int $userId, string $type, int $limit = 500): array
    {
        $rows = $this->db->fetchAll(
            '
            SELECT fr.parent_id, fr.position
            FROM feed_reads fr
            JOIN feeds f ON f.id = fr.parent_id
            WHERE fr.user_id = ? AND f.type = ?
            ORDER BY fr.read_at DESC
            LIMIT '.$limit.'
            ',
            [$userId, $type]
        );

        return array_map(
            static fn (array $row): array => [
                'parentId' => (int) $row['parent_id'],
                'position' => $row['position'] !== null ? (int) $row['position'] : null,
            ],
            $rows
        );
    }

    /**
     * Clears a user's read marker for a parent - the "remove this book from
     * continue reading" action. Idempotent: a parent with no read marker is
     * silently skipped.
     *
     * Uses index: PRIMARY(parent_id, user_id)
     */
    public function deleteForParent(int $userId, int $parentId): void
    {
        $this->db->execute(
            'DELETE FROM feed_reads WHERE user_id = ? AND parent_id = ?',
            [$userId, $parentId]
        );
    }
}
