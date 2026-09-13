<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;

final readonly class MessageRepository
{
    public function __construct(
        private PdoDatabase $db
    ) {

    }

    public function insert(
        int $conversationId,
        int $userId,
        string $text,
        ?int $replyToMessageId = null,
        ?int $attachmentUploadId = null
    ): int {
        $this->db->execute(
            "INSERT INTO messages (conversation_id, user_id, text, reply_to_message_id, attachment_upload_id)
             VALUES (?, ?, ?, ?, ?)",
            [$conversationId, $userId, $text, $replyToMessageId, $attachmentUploadId]
        );

        return $this->db->lastInsertId();
    }

    /**
     * Shared by getAfter() and getRecentlyChanged() - same row shape either
     * way (a message plus its quoted reply and its attachment, if any).
     */
    private const string SELECT_WITH_JOINS = "SELECT
                m.id, m.user_id, m.text, m.created_at, m.updated_at, m.deleted_at, m.reply_to_message_id,
                r.user_id AS reply_user_id, r.text AS reply_text,
                a.id AS attachment_id, a.path AS attachment_path, a.mime AS attachment_mime,
                a.size AS attachment_size, a.original_name AS attachment_original_name
             FROM messages m
             LEFT JOIN messages r
               ON r.id = m.reply_to_message_id
             LEFT JOIN uploads a
               ON a.id = m.attachment_upload_id";

    /**
     * Uses index: idx_conv_id (conversation_id, id)
     * Uses index: PRIMARY(id) on the self-join for the quoted message.
     * Uses index: PRIMARY(id) on the join to uploads for the attachment.
     */
    public function getAfter(int $conversationId, int $afterId, int $limit): array
    {
        return $this->db->fetchAll(
            self::SELECT_WITH_JOINS."
             WHERE m.conversation_id = ?
               AND m.id > ?
               AND m.deleted_at IS NULL
             ORDER BY m.id
             LIMIT ?",
            [$conversationId, $afterId, $limit]
        );
    }

    /**
     * Messages in this conversation edited or (soft-)deleted within the
     * last $lookbackSeconds - the poll's mechanism for propagating an edit
     * or delete to participants who already have the message rendered
     * (getAfter() only ever returns messages by id > afterId, so it never
     * resurfaces one they've already seen). Same lookback-window shape as
     * the typing indicator (last_typing_at > NOW() - 5) and online presence
     * (last_used_at > NOW() - 90) - stateless, no "since last poll"
     * bookkeeping needed.
     *
     * Uses index: idx_messages_conversation_deleted_updated (conversation_id, deleted_at, updated_at)
     */
    public function getRecentlyChanged(int $conversationId, int $lookbackSeconds = 20): array
    {
        return $this->db->fetchAll(
            self::SELECT_WITH_JOINS."
             WHERE m.conversation_id = ?
               AND (
                 (m.deleted_at IS NOT NULL AND m.deleted_at > (UNIX_TIMESTAMP() - ?))
                 OR (m.updated_at IS NOT NULL AND m.updated_at > (UNIX_TIMESTAMP() - ?))
               )
             ORDER BY m.id",
            [$conversationId, $lookbackSeconds, $lookbackSeconds]
        );
    }

    /**
     * Uses index: PRIMARY(id)
     */
    public function findById(int $messageId): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, conversation_id, user_id, created_at, deleted_at
             FROM messages
             WHERE id = ?",
            [$messageId]
        );
    }

    /**
     * Soft delete: keeps the row (so getRecentlyChanged() can tell other
     * participants who already rendered this message that it's gone) but
     * marks it, so getAfter() never surfaces it as "new" to anyone who
     * hasn't seen it yet.
     *
     * Uses index: PRIMARY(id)
     */
    public function delete(int $messageId): void
    {
        $this->db->execute(
            "UPDATE messages SET deleted_at = UNIX_TIMESTAMP() WHERE id = ?",
            [$messageId]
        );
    }

    /**
     * Uses index: PRIMARY(id)
     */
    public function updateText(int $messageId, string $text): void
    {
        $this->db->execute(
            "UPDATE messages
         SET text = ?, updated_at = UNIX_TIMESTAMP()
         WHERE id = ?",
            [$text, $messageId]
        );
    }
}
