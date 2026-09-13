<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;

final readonly class FeedFavoriteRepository
{
    public function __construct(
        private PdoDatabase $db
    ) {

    }

    /**
     * Uses index: PRIMARY(feed_id, user_id)
     */
    public function isFavorited(int $feedId, int $userId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 FROM feed_favorites WHERE feed_id = ? AND user_id = ?',
            [$feedId, $userId]
        );

        return $row !== null;
    }

    /**
     * Marks a feed as favorited by a user. Idempotent: favoriting an
     * already-favorited feed is a no-op rather than an error, so callers
     * (e.g. a double click on the "add to favorites" button) don't need to
     * check the current state first.
     *
     * Uses index: PRIMARY(feed_id, user_id)
     */
    public function add(int $feedId, int $userId): void
    {
        $this->db->execute(
            '
            INSERT INTO feed_favorites (feed_id, user_id, created_at)
            VALUES (?, ?, UNIX_TIMESTAMP())
            ON DUPLICATE KEY UPDATE feed_id = feed_id
            ',
            [$feedId, $userId]
        );
    }

    /**
     * Removes a feed from a user's favorites. Idempotent: removing a favorite
     * that doesn't exist is a no-op rather than an error.
     *
     * Uses index: PRIMARY(feed_id, user_id)
     */
    public function remove(int $feedId, int $userId): void
    {
        $this->db->execute(
            'DELETE FROM feed_favorites WHERE feed_id = ? AND user_id = ?',
            [$feedId, $userId]
        );
    }

    /**
     * Lists the ids of feeds a user has favorited, most recently favorited first.
     * Intended for future forum/blog "my favorites" views.
     *
     * Uses index: feed_favorites_user_id_created_at_index (user_id, created_at)
     *
     * @return int[]
     */
    public function findFeedIdsForUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->db->fetchAll(
            '
            SELECT feed_id FROM feed_favorites
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT '.$limit.' OFFSET '.$offset.'
            ',
            [$userId]
        );

        return array_map(static fn (array $row): int => (int) $row['feed_id'], $rows);
    }

    /**
     * The reverse of findFeedIdsForUser(): every user id who favorited a
     * given feed, unbounded - used to notify a forum topic's "followers"
     * when it gets a new reply (see
     * Modules\Forums\ForumsController::notifyTopicFollowers()). A forum
     * topic realistically has, at most, a few hundred followers - nowhere
     * near needing findFeedIdsForUser()'s pagination.
     *
     * Uses index: PRIMARY(feed_id, user_id) - feed_id is the leading
     * column, so this is a direct index lookup, not a scan.
     *
     * @return int[]
     */
    public function findUserIdsForFeed(int $feedId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT user_id FROM feed_favorites WHERE feed_id = ?',
            [$feedId]
        );

        return array_map(static fn (array $row): int => (int) $row['user_id'], $rows);
    }

}
