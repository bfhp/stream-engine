<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;

final readonly class RateLimitRepository implements RateLimitStore
{
    public function __construct(private PdoDatabase $db)
    {
    }

    /**
     * Atomically consumes one attempt in a fixed window and returns the new
     * count. The counter is capped one above the limit: callers only need to
     * distinguish allowed from rejected, and an attacker cannot overflow it.
     */
    public function consume(
        string $scope,
        string $subjectHash,
        int $limit,
        int $windowSeconds,
        int $now,
    ): int {
        $windowEndsAt = $now + $windowSeconds;

        $this->db->execute(
            '
            INSERT INTO auth_rate_limits
                (scope, subject_hash, attempts, window_ends_at)
            VALUES (?, ?, LAST_INSERT_ID(1), ?)
            ON DUPLICATE KEY UPDATE
                attempts = LAST_INSERT_ID(
                    IF(
                        window_ends_at <= ?,
                        1,
                        LEAST(attempts + 1, ?)
                    )
                ),
                window_ends_at = IF(
                    window_ends_at <= ?,
                    VALUES(window_ends_at),
                    window_ends_at
                )
            ',
            [$scope, $subjectHash, $windowEndsAt, $now, $limit + 1, $now]
        );

        // LAST_INSERT_ID(expr) is connection-local. It returns the count this
        // upsert produced even if another connection increments the same row
        // before this SELECT runs.
        $row = $this->db->fetchOne('SELECT LAST_INSERT_ID() AS attempts');

        // A successful upsert must leave a row. Treat an impossible absence
        // as a storage failure rather than silently allowing the request.
        if ($row === null) {
            throw new \RuntimeException('Rate-limit counter was not persisted.');
        }

        return (int) $row['attempts'];
    }

    public function delete(string $scope, string $subjectHash): void
    {
        $this->db->execute(
            'DELETE FROM auth_rate_limits WHERE scope = ? AND subject_hash = ?',
            [$scope, $subjectHash]
        );
    }
}
