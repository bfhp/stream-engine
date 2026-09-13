<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use JsonException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\Notification;

final readonly class NotificationDeliveryRepository
{
    private const int CLAIM_TIMEOUT_SECONDS = 15 * 60;
    private const int MAX_ATTEMPTS = 3;
    private const int RETRY_DELAY_SECONDS = 5 * 60;

    public function __construct(
        private PdoDatabase $db,
    ) {
    }

    /**
     * Returns null when a delivery with the same non-null deduplication key
     * already exists.
     *
     * @throws JsonException
     */
    public function create(
        Notification $notification,
        string $channel,
        string $delivery,
        int $scheduledAt,
    ): ?int {
        $payload = json_encode(
            $notification->payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $inserted = $this->db->execute(
            "
            INSERT INTO notification_deliveries (
                recipient_user_id, notification_type, channel, delivery,
                payload, message_text, deduplication_key, scheduled_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id = id
            ",
            [
                $notification->recipientUserId,
                $notification->type,
                $channel,
                $delivery,
                $payload,
                $notification->messengerText,
                $notification->deduplicationKey,
                $scheduledAt,
            ]
        );

        return $inserted === 1 ? $this->db->lastInsertId() : null;
    }

    /**
     * Atomically reserves due deliveries for one worker invocation.
     *
     * @return list<array{
     *     id: int,
     *     recipientUserId: int,
     *     notificationType: string,
     *     delivery: string,
     *     payload: array,
     *     messageText: string,
     *     scheduledAt: int,
     *     email: string
     * }>
     * @throws JsonException
     */
    public function claimDue(string $channel, string $delivery, int $limit): array
    {
        if (! in_array($channel, ['messenger', 'email'], true)
            || ! in_array($delivery, ['instant', 'daily'], true)
            || ($channel === 'messenger' && $delivery !== 'instant')) {
            return [];
        }

        $limit = max(1, min($limit, 1000));
        $claimToken = bin2hex(random_bytes(16));

        $this->db->execute(
            "
            UPDATE notification_deliveries
            SET status = 'pending', claim_token = NULL, claimed_at = NULL
            WHERE channel = ? AND status = 'processing'
              AND claimed_at < UNIX_TIMESTAMP() - ?
            ",
            [$channel, self::CLAIM_TIMEOUT_SECONDS]
        );

        $claimed = $this->db->execute(
            "
            UPDATE notification_deliveries
            SET status = 'processing', claim_token = ?, claimed_at = UNIX_TIMESTAMP()
            WHERE status = 'pending' AND channel = ? AND delivery = ?
              AND scheduled_at <= UNIX_TIMESTAMP()
            ORDER BY scheduled_at, id
            LIMIT {$limit}
            ",
            [$claimToken, $channel, $delivery]
        );

        if ($claimed === 0) {
            return [];
        }

        $rows = $this->db->fetchAll(
            "
            SELECT d.id, d.recipient_user_id, d.notification_type,
                   d.delivery, d.payload, d.message_text, d.scheduled_at,
                   u.email
            FROM notification_deliveries d
            INNER JOIN users u ON u.id = d.recipient_user_id
            WHERE d.claim_token = ?
            ORDER BY d.recipient_user_id, d.scheduled_at, d.id
            ",
            [$claimToken]
        );

        return array_map(static function (array $row): array {
            $payload = json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);

            return [
                'id' => (int) $row['id'],
                'recipientUserId' => (int) $row['recipient_user_id'],
                'notificationType' => (string) $row['notification_type'],
                'delivery' => (string) $row['delivery'],
                'payload' => is_array($payload) ? $payload : [],
                'messageText' => (string) ($row['message_text'] ?? ''),
                'scheduledAt' => (int) $row['scheduled_at'],
                'email' => (string) $row['email'],
            ];
        }, $rows);
    }

    /** @param non-empty-list<int> $ids */
    public function markSent(array $ids): void
    {
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $this->db->execute(
            "
            UPDATE notification_deliveries
            SET status = 'sent', sent_at = UNIX_TIMESTAMP(),
                attempt_count = attempt_count + 1, last_error = NULL,
                claim_token = NULL, claimed_at = NULL
            WHERE id IN ({$placeholders}) AND status = 'processing'
            ",
            $ids
        );
    }

    /** @param non-empty-list<int> $ids */
    public function markFailed(array $ids, string $error): void
    {
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $this->db->execute(
            "
            UPDATE notification_deliveries
            SET status = IF(attempt_count >= ?, 'failed', 'pending'),
                scheduled_at = IF(attempt_count >= ?, scheduled_at, UNIX_TIMESTAMP() + ?),
                attempt_count = attempt_count + 1, last_error = ?,
                claim_token = NULL, claimed_at = NULL
            WHERE id IN ({$placeholders}) AND status = 'processing'
            ",
            [
                self::MAX_ATTEMPTS - 1,
                self::MAX_ATTEMPTS - 1,
                self::RETRY_DELAY_SECONDS,
                mb_substr($error, 0, 1000),
                ...$ids,
            ]
        );
    }

    /** Current unread incoming direct messages, excluding system notifications. */
    public function unreadPersonalMessageCount(int $userId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS unread_count
             FROM conversation_participants cp
             JOIN conversations c ON c.id = cp.conversation_id AND c.direct_key IS NOT NULL
             JOIN messages m ON m.conversation_id = c.id
               AND m.id > COALESCE(cp.last_read_message_id, 0)
             WHERE cp.user_id = ? AND m.user_id != cp.user_id
               AND m.user_id != ? AND m.deleted_at IS NULL',
            [$userId, \StreamEngine\Domain\User::SYSTEM_USER_ID],
        );

        return (int) ($row['unread_count'] ?? 0);
    }

    public function cancel(int $id): void
    {
        $this->db->execute(
            "UPDATE notification_deliveries
             SET status = 'cancelled', claim_token = NULL, claimed_at = NULL
             WHERE id = ? AND status = 'processing'",
            [$id],
        );
    }

    public function cancelPendingEmailForUser(int $userId): void
    {
        $this->db->execute(
            "
            UPDATE notification_deliveries
            SET status = 'cancelled', claim_token = NULL, claimed_at = NULL
            WHERE recipient_user_id = ? AND channel = 'email'
              AND status IN ('pending', 'processing')
            ",
            [$userId]
        );
    }
}
