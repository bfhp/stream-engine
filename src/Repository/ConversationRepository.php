<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;

final readonly class ConversationRepository
{
    public function __construct(
        private PdoDatabase $db
    ) {

    }

    /**
     * Uses index: uniq_direct_key (direct_key)
     */
    public function findByDirectKey(string $key): ?int
    {
        $row = $this->db->fetchOne(
            "SELECT id FROM conversations WHERE direct_key = ?",
            [$key]
        );

        return $row ? (int)$row['id'] : null;
    }

    public function createDirect(string $key): int
    {
        $this->db->execute(
            "INSERT INTO conversations (direct_key)
             VALUES (?)",
            [$key]
        );

        return $this->db->lastInsertId();
    }

    public function createGroup(?string $title): int
    {
        $this->db->execute(
            "INSERT INTO conversations (title)
             VALUES (?)",
            [$title]
        );

        return $this->db->lastInsertId();
    }

    /**
     * Uses index: PRIMARY(id) on conversations.
     * Uses index: PRIMARY(conversation_id, user_id) on conversation_participants.
     */
    public function getByIdForUser(int $conversationId, int $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT
            c.id,
            c.title,
            c.direct_key,
            c.last_message_id,
            cp.last_read_message_id

         FROM conversations c
         JOIN conversation_participants cp
           ON cp.conversation_id = c.id

         WHERE c.id = ?
           AND cp.user_id = ?",
            [$conversationId, $userId]
        );
    }

    /**
     * Uses index: PRIMARY(id)
     */
    public function getMeta(int $conversationId): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, direct_key
         FROM conversations
         WHERE id = ?",
            [$conversationId]
        );
    }

    /**
     * Uses index: idx_user (user_id, conversation_id) on conversation_participants.
     * Uses index: PRIMARY(id) on conversations and messages joins.
     * Warning: ORDER BY c.last_message_at DESC may require filesort after participant filtering.
     */
    public function getListWithMeta(int $userId, int $limit): array
    {
        return $this->db->fetchAll(
            "SELECT
            c.id,
            c.title,
            c.direct_key,
            c.last_message_id,
            c.last_message_at,

            m.text AS last_message_text,
            m.user_id AS last_message_user_id,
            m.created_at AS last_message_created_at,

            CASE
                WHEN c.last_message_id IS NULL THEN 0
                WHEN cp.last_read_message_id IS NULL THEN c.last_message_id
                -- Both columns are bigint unsigned; a plain subtraction is
                -- evaluated as unsigned arithmetic, so if last_read_message_id
                -- is now ahead of last_message_id (e.g. the last message got
                -- deleted and the cache fell back to an earlier one the user
                -- had already read) it underflows instead of going negative,
                -- and strict mode raises an out-of-range error rather than
                -- wrapping. Casting to signed first lets the subtraction go
                -- negative normally, which GREATEST(..., 0) then clamps.
                ELSE GREATEST(CAST(c.last_message_id AS SIGNED) - CAST(cp.last_read_message_id AS SIGNED), 0)
            END AS unread_count

         FROM conversations c
         JOIN conversation_participants cp
           ON cp.conversation_id = c.id
         LEFT JOIN messages m
           ON m.id = c.last_message_id
         WHERE cp.user_id = ?
         ORDER BY c.last_message_at DESC
         LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * Uses index: PRIMARY(id)
     */
    public function updateCache(int $conversationId, int $messageId): void
    {
        $this->db->execute(
            "UPDATE conversations
                 SET last_message_id = ?, last_message_at = UNIX_TIMESTAMP()
                 WHERE id = ?",
            [$messageId, $conversationId]
        );
    }

    /**
     * Recomputes the cached last-message pointer after a delete - the
     * deleted message might have BEEN the cached one, in which case the
     * conversation list would otherwise keep showing a vanished message's
     * text as the preview until someone sends something new.
     *
     * Uses index: idx_conv_id (conversation_id, id)
     * Uses index: PRIMARY(id) on conversations.
     */
    public function refreshLastMessage(int $conversationId): void
    {
        $latest = $this->db->fetchOne(
            "SELECT id, created_at FROM messages
             WHERE conversation_id = ? AND deleted_at IS NULL
             ORDER BY id DESC
             LIMIT 1",
            [$conversationId]
        );

        $this->db->execute(
            "UPDATE conversations
                 SET last_message_id = ?, last_message_at = ?
                 WHERE id = ?",
            [$latest['id'] ?? null, $latest['created_at'] ?? 0, $conversationId]
        );
    }
}
