<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use RuntimeException;
use StreamEngine\Core\PdoDatabase;
use Throwable;

final readonly class NotificationPreferenceRepository
{
    public function __construct(
        private PdoDatabase $db,
    ) {
    }

    /**
     * @return array<string, array{delivery:string}> keyed by channel
     */
    public function findForUserAndType(int $userId, string $notificationType): array
    {
        $rows = $this->db->fetchAll(
            "
            SELECT notification_type, channel, delivery
            FROM notification_preferences
            WHERE user_id = ? AND notification_type IN (?, '*')
            ORDER BY notification_type = '*'
            ",
            [$userId, $notificationType]
        );

        $preferences = [];
        foreach ($rows as $row) {
            $preferences[(string) $row['channel']] = [
                'delivery' => (string) $row['delivery'],
            ];
        }

        return $preferences;
    }

    /** @return list<int> */
    public function findUnreadDigestRecipients(int $afterId, int $limit = 500): array
    {
        $rows = $this->db->fetchAll(
            "SELECT p.user_id
             FROM notification_preferences p
             WHERE p.notification_type = 'message.unread_digest'
               AND p.channel = 'email' AND p.delivery = 'daily' AND p.user_id > ?
               AND NOT EXISTS (
                   SELECT 1 FROM notification_preferences blocked
                   WHERE blocked.user_id = p.user_id AND blocked.notification_type = '*'
                     AND blocked.channel = 'email' AND blocked.delivery = 'off'
               )
             ORDER BY p.user_id LIMIT ?",
            [$afterId, $limit],
        );

        return array_map(static fn (array $row): int => (int) $row['user_id'], $rows);
    }

    public function getOrCreateEmailUnsubscribeToken(int $userId): string
    {
        $existing = $this->db->fetchOne(
            'SELECT token FROM notification_unsubscribe_tokens WHERE user_id = ?',
            [$userId]
        );
        if ($existing !== null) {
            return (string) $existing['token'];
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $inserted = $this->db->execute(
            "
            INSERT INTO notification_unsubscribe_tokens (user_id, token)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE token = token
            ",
            [$userId, $token]
        );

        if ($inserted === 1) {
            return $token;
        }

        $existing = $this->db->fetchOne(
            'SELECT token FROM notification_unsubscribe_tokens WHERE user_id = ?',
            [$userId]
        );

        if ($existing === null) {
            throw new RuntimeException('Unable to create notification unsubscribe token');
        }

        return (string) $existing['token'];
    }

    public function findUserIdByEmailUnsubscribeToken(string $token): ?int
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT user_id FROM notification_unsubscribe_tokens WHERE token = ?',
            [$token]
        );

        return $row !== null ? (int) $row['user_id'] : null;
    }

    public function unsubscribeUserFromEmail(int $userId): void
    {
        $this->save($userId, '*', 'email', 'off');
    }

    /**
     * @param array<string, array{messenger: string, email: string}> $preferences
     */
    public function saveForUser(int $userId, array $preferences, string $digestDeliveryTime): void
    {
        $this->db->begin();

        try {
            $this->db->execute(
                "
                DELETE FROM notification_preferences
                WHERE user_id = ? AND notification_type = '*' AND channel = 'email'
                ",
                [$userId]
            );

            foreach ($preferences as $notificationType => $preference) {
                $this->save($userId, $notificationType, 'messenger', $preference['messenger']);
                $this->save(
                    $userId,
                    $notificationType,
                    'email',
                    $preference['email'],
                );
            }

            $this->db->execute(
                'UPDATE users SET digest_delivery_time = ? WHERE id = ?',
                [$digestDeliveryTime, $userId],
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();

            throw $e;
        }
    }

    public function save(
        int $userId,
        string $notificationType,
        string $channel,
        string $delivery,
    ): void {
        $this->db->execute(
            "
            INSERT INTO notification_preferences (
                user_id, notification_type, channel, delivery
            )
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                delivery = VALUES(delivery)
            ",
            [$userId, $notificationType, $channel, $delivery]
        );
    }
}
