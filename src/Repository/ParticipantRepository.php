<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;

final readonly class ParticipantRepository
{
    public function __construct(
        private PdoDatabase $db
    ) {

    }
    /**
     * Uses index: PRIMARY(conversation_id, user_id)
     */
    public function isParticipant(int $conversationId, int $userId): bool
    {
        $row = $this->db->fetchOne(
            "SELECT 1
             FROM conversation_participants
             WHERE conversation_id = ? AND user_id = ?",
            [$conversationId, $userId]
        );

        return $row !== null;
    }

    /**
     * Uses index: PRIMARY(conversation_id, user_id)
     */
    public function getUserIds(int $conversationId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT user_id
         FROM conversation_participants
         WHERE conversation_id = ?",
            [$conversationId]
        );

        return array_map(fn ($r) => (int)$r['user_id'], $rows);
    }

    public function addUsersIgnore(int $conversationId, array $userIds): void
    {
        if (!$userIds) {
            return;
        }

        $placeholders = [];
        $params = [];

        foreach ($userIds as $userId) {
            $placeholders[] = "(?, ?)";
            $params[] = $conversationId;
            $params[] = (int)$userId;
        }

        $sql = "INSERT IGNORE INTO conversation_participants (conversation_id, user_id)
            VALUES " . implode(',', $placeholders);

        $this->db->execute($sql, $params);
    }

    /**
     * Uses index: PRIMARY(conversation_id, user_id)
     */
    public function removeUser(int $conversationId, int $userId): void
    {
        $this->db->execute(
            "DELETE FROM conversation_participants
         WHERE conversation_id = ? AND user_id = ?",
            [$conversationId, $userId]
        );
    }

    /**
     * Uses index: PRIMARY(conversation_id, user_id)
     */
    public function markAsRead(int $conversationId, int $userId, int $messageId): void
    {
        $this->db->execute(
            "UPDATE conversation_participants
         SET last_read_message_id = GREATEST(IFNULL(last_read_message_id, 0), ?)
         WHERE conversation_id = ? AND user_id = ?",
            [$messageId, $conversationId, $userId]
        );
    }

    /**
     * Read state of every participant in the conversation, keyed by user id.
     * This is the whole read-receipts data model: one row per (conversation,
     * user), not one row per (message, user) - a message is "read by user X"
     * simply when message.id <= readStates[X]. No per-message log to write
     * or grow.
     *
     * Uses index: PRIMARY(conversation_id, user_id)
     */
    public function getReadStates(int $conversationId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT user_id, last_read_message_id
         FROM conversation_participants
         WHERE conversation_id = ?",
            [$conversationId]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['user_id']] = (int)($row['last_read_message_id'] ?? 0);
        }

        return $result;
    }

    /**
     * Uses index: conversation_participants_conversation_typing_index (conversation_id, last_typing_at, user_id)
     */
    public function getTypingUsers(int $conversationId, int $excludeUserId): array
    {
        return $this->db->fetchAll(
            "SELECT user_id
         FROM conversation_participants
         WHERE conversation_id = ?
           AND user_id != ?
           AND last_typing_at IS NOT NULL
           AND last_typing_at > (UNIX_TIMESTAMP() - 5)",
            [$conversationId, $excludeUserId]
        );
    }

    /**
     * Uses index: PRIMARY(conversation_id, user_id)
     */
    public function updateTyping(int $conversationId, int $userId): void
    {
        $this->db->execute(
            "UPDATE conversation_participants
         SET last_typing_at = UNIX_TIMESTAMP()
         WHERE conversation_id = ? AND user_id = ?",
            [$conversationId, $userId]
        );
    }
}
