<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use RuntimeException;
use StreamEngine\Core\PdoDatabase;
use Throwable;

final readonly class FeedRatingRepository
{
    public function __construct(
        private PdoDatabase $db
    ) {

    }

    /**
     * Uses index: PRIMARY(feed_id, user_id)
     */
    public function findUserValue(int $feedId, int $userId): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT value FROM feed_ratings WHERE feed_id = ? AND user_id = ?',
            [$feedId, $userId]
        );

        return $row !== null ? (int) $row['value'] : null;
    }

    /**
     * Batched form of findUserValue() - one query for a whole page of feeds
     * (e.g. forums.topic-view's page of posts) instead of one per row.
     *
     * @param int[] $feedIds
     * @return array<int, int> feed id => the given user's own vote, missing
     *     key means they haven't rated that feed
     *
     * Uses index: PRIMARY(feed_id, user_id) - feed_id is the leading column,
     * so the IN(...) still hits the index rather than scanning the table.
     */
    public function findUserValues(array $feedIds, int $userId): array
    {
        if ($feedIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($feedIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT feed_id, value FROM feed_ratings WHERE user_id = ? AND feed_id IN ($placeholders)",
            [$userId, ...$feedIds]
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(int) $row['feed_id']] = (int) $row['value'];
        }

        return $values;
    }

    /**
     * Records (or updates) a single user's vote for a feed and keeps
     * feeds.rating_sum/rating_count/rating_avg in sync in the same transaction.
     * A user can change their vote later; rating_count only changes on
     * the first vote, subsequent votes only adjust rating_sum/rating_avg by the delta.
     *
     * rating_avg is a persisted, indexed column (see feeds_type_rating_avg_id_index)
     * so "top rated" listings can ORDER BY it directly instead of computing
     * rating_sum/rating_count on every row at read time.
     *
     * Uses index: PRIMARY(feed_id, user_id) on feed_ratings - the SELECT ... FOR UPDATE
     * locks this row (or the gap, for a first-time vote) so two concurrent votes from the
     * same user can't both read "no existing vote" and double-increment rating_count.
     * Uses index: PRIMARY(id) on feeds for the aggregate UPDATE.
     */
    public function submit(int $feedId, int $userId, int $value): void
    {
        $this->db->begin();

        try {
            $existing = $this->db->fetchOne(
                'SELECT value FROM feed_ratings WHERE feed_id = ? AND user_id = ? FOR UPDATE',
                [$feedId, $userId]
            );

            if ($existing === null) {
                $this->db->execute(
                    '
                    INSERT INTO feed_ratings (feed_id, user_id, value, created_at, updated_at)
                    VALUES (?, ?, ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
                    ',
                    [$feedId, $userId, $value]
                );

                // rating_sum/rating_count are reassigned first, so rating_avg's expression
                // below reads their already-updated values (MySQL/MariaDB evaluates an
                // UPDATE's SET list left to right within the same statement).
                $this->db->execute(
                    '
                    UPDATE feeds
                    SET
                        rating_sum = rating_sum + ?,
                        rating_count = rating_count + 1,
                        rating_avg = rating_sum / rating_count
                    WHERE id = ?
                    ',
                    [$value, $feedId]
                );
            } else {
                $oldValue = (int) $existing['value'];

                if ($oldValue !== $value) {
                    $this->db->execute(
                        '
                        UPDATE feed_ratings
                        SET value = ?, updated_at = UNIX_TIMESTAMP()
                        WHERE feed_id = ? AND user_id = ?
                        ',
                        [$value, $feedId, $userId]
                    );

                    $this->db->execute(
                        '
                        UPDATE feeds
                        SET
                            rating_sum = rating_sum + ?,
                            rating_avg = rating_sum / rating_count
                        WHERE id = ?
                        ',
                        [$value - $oldValue, $feedId]
                    );
                }
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw new RuntimeException("Unable to submit feed. {$exception->getMessage()}");
        }
    }
}
