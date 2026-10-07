<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;

final readonly class MentionRepository
{
    public function __construct(private PdoDatabase $db)
    {
    }

    /** @return array<int, array{userId:int, usernameSnapshot:string, active:bool, currentUsername:?string}> */
    public function findForFeed(int $feedId): array
    {
        return $this->findForTarget('feed_id', $feedId);
    }

    /** @return array<int, array{userId:int, usernameSnapshot:string, active:bool, currentUsername:?string}> */
    public function findForMessage(int $messageId): array
    {
        return $this->findForTarget('message_id', $messageId);
    }

    /**
     * Resolves every distinct username in one indexed query.
     *
     * @param list<string> $usernames
     * @return array<string, array{id:int, username:string}>
     */
    public function findActiveUsersByUsernames(array $usernames): array
    {
        $usernames = array_values(array_unique($usernames));
        if ($usernames === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($usernames), '?'));
        $rows = $this->db->fetchAll(
            "SELECT id, username FROM users
             WHERE is_active = 1 AND username IN ({$placeholders})",
            $usernames,
        );

        $result = [];
        foreach ($rows as $row) {
            $username = (string) $row['username'];
            $result[strtolower($username)] = ['id' => (int) $row['id'], 'username' => $username];
        }

        return $result;
    }

    /** @param array<int, string> $desired user id => spelling used in content */
    public function reconcileFeed(int $feedId, array $desired): void
    {
        $this->reconcile('feed_id', $feedId, $desired);
    }

    /** @param array<int, string> $desired user id => spelling used in content */
    public function reconcileMessage(int $messageId, array $desired): void
    {
        $this->reconcile('message_id', $messageId, $desired);
    }

    public function deactivateMessage(int $messageId): void
    {
        $this->deactivate('message_id', $messageId);
    }

    public function isActiveForFeedUser(int $feedId, int $userId): bool
    {
        return $this->isActiveForUser('feed_id', $feedId, $userId);
    }

    public function isActiveForMessageUser(int $messageId, int $userId): bool
    {
        return $this->isActiveForUser('message_id', $messageId, $userId);
    }

    /** @return array<int, array{userId:int, usernameSnapshot:string, active:bool, currentUsername:?string}> */
    private function findForTarget(string $column, int $id): array
    {
        $rows = $this->db->fetchAll(
            "SELECT m.user_id, m.username_snapshot, m.active,
                    IF(u.is_active = 1, u.username, NULL) AS current_username
             FROM mentions m
             LEFT JOIN users u ON u.id = m.user_id
             WHERE m.{$column} = ?",
            [$id],
        );

        $result = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $result[$userId] = [
                'userId' => $userId,
                'usernameSnapshot' => (string) $row['username_snapshot'],
                'active' => (bool) $row['active'],
                'currentUsername' => isset($row['current_username']) && trim((string) $row['current_username']) !== ''
                    ? (string) $row['current_username']
                    : null,
            ];
        }

        return $result;
    }

    /** @param array<int, string> $desired */
    private function reconcile(string $column, int $id, array $desired): void
    {
        $ids = array_keys($desired);
        if ($ids === []) {
            $this->deactivate($column, $id);
            return;
        }

        $values = [];
        $params = [];
        foreach ($desired as $userId => $snapshot) {
            $values[] = '(?, ?, ?, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())';
            array_push($params, $id, (int) $userId, $snapshot);
        }

        $this->db->execute(
            "INSERT INTO mentions ({$column}, user_id, username_snapshot, active, first_mentioned_at, updated_at)
             VALUES ".implode(', ', $values)."
             ON DUPLICATE KEY UPDATE username_snapshot = VALUES(username_snapshot),
                                     active = 1, updated_at = UNIX_TIMESTAMP()",
            $params,
        );

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->execute(
            "UPDATE mentions SET active = 0, updated_at = UNIX_TIMESTAMP()
             WHERE {$column} = ? AND active = 1 AND user_id NOT IN ({$placeholders})",
            [$id, ...$ids],
        );
    }

    private function deactivate(string $column, int $id): void
    {
        $this->db->execute(
            "UPDATE mentions SET active = 0, updated_at = UNIX_TIMESTAMP()
             WHERE {$column} = ? AND active = 1",
            [$id],
        );
    }

    private function isActiveForUser(string $column, int $id, int $userId): bool
    {
        return $this->db->fetchOne(
            "SELECT 1 FROM mentions
             WHERE {$column} = ? AND user_id = ? AND active = 1",
            [$id, $userId],
        ) !== null;
    }
}
