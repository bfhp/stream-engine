<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;
use Throwable;

final readonly class FeedViewRepository
{
    public function __construct(private PdoDatabase $db)
    {
    }

    /**
     * Atomically claims and counts a member view when its previous marker is
     * outside the deduplication window. The primary key serializes concurrent
     * requests from the same user for the same feed.
     */
    public function recordForUser(int $feedId, int $userId, int $window): bool
    {
        $this->db->begin();

        try {
            $claimed = $this->db->execute(
                'INSERT INTO feed_views (feed_id, user_id, viewed_at)
                 VALUES (?, ?, UNIX_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    viewed_at = IF(
                        viewed_at <= UNIX_TIMESTAMP() - ?,
                        VALUES(viewed_at),
                        viewed_at
                    )',
                [$feedId, $userId, $window]
            ) > 0;

            if ($claimed) {
                $this->db->execute('UPDATE feeds SET views = views + 1 WHERE id = ?', [$feedId]);
            }

            $this->db->commit();

            return $claimed;
        } catch (Throwable $e) {
            $this->db->rollback();

            throw $e;
        }
    }
}
