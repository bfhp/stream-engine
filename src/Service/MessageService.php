<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use HTMLPurifier;
use HTMLPurifier_Config;
use RuntimeException;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\User;
use StreamEngine\Repository\ConversationRepository;
use StreamEngine\Repository\MessageRepository;
use StreamEngine\Repository\ParticipantRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use Throwable;

/**
 * The messenger's one service: conversations (direct/group, membership,
 * listing), messages (send/edit/delete/read/typing), and the reserved
 * "system" account's own notifications (see User::SYSTEM_USER_ID) all live
 * here rather than split across three collaborators - a system message is
 * just a regular send() into a regular direct conversation with no
 * friend-gate server-side (see createOrGetDirect()), so there was nothing
 * left for a separate wrapper to own once conversations and messages
 * shared a class.
 */
final readonly class MessageService
{
    private HTMLPurifier $purifier;

    private TranslationManager $tm;

    public function __construct(
        private PdoDatabase $db,
        private MessageRepository $messages,
        private ParticipantRepository $participants,
        private ConversationRepository $conversations,
        private UserRepository $users,
        // Presence for the messenger's own online dots, read straight off
        // the repository rather than through UserService (which owns the
        // rest of the site's presence - see the "Presence" section at the
        // bottom of that class). UserService already depends on this class
        // for register()'s welcome notification, so depending back on it
        // would be a constructor cycle. The window is still shared, though:
        // the three calls below pass UserService::ONLINE_WINDOW_SECONDS
        // explicitly - a class constant, not a dependency - so changing it
        // there can't silently leave the messenger on a different one.
        private UserSessionRepository $sessions,
        private UploadRepository $uploads,
        ?TranslationManager $translationManager = null,
    ) {
        $this->tm = $translationManager ?? new TranslationManager('ru', 'ru');

        // Deliberately far more restrictive than FeedService's own
        // purifier (blog/comment content): a message is a single line of
        // plain text the client renders with white-space:pre-wrap (no
        // <br> needed, unlike nl2br'd comments), plus - per product intent
        // (see FriendService's own system notifications) - the ability to
        // include a real, safe link. So the only element/attribute pair
        // ever allowed to survive is a[href], and only http(s) URIs -
        // everything else (onclick, style, javascript:/data: schemes,
        // any other tag) is stripped, not merely escaped. target/rel on
        // the resulting <a> are enforced client-side instead of trusted
        // from here - see messages.js's own post-render step (function
        // `hl`) that sets them on every .msgr-bubble-text a[href].
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'a[href]');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        $config->set('AutoFormat.AutoParagraph', false);
        $config->set('AutoFormat.RemoveEmpty', false);
        $config->set('HTML.DefinitionID', 'stream-engine-messages-html');
        $config->set('HTML.DefinitionRev', 1);
        $config->set('Cache.DefinitionImpl', null);

        $this->purifier = new HTMLPurifier($config);
    }

    private function getDirectKey(int $a, int $b): string
    {
        return ($a < $b) ? "$a:$b" : "$b:$a";
    }

    /**
     * A direct conversation's direct_key is "min(a,b):max(a,b)" - given the
     * viewer's own id, pick out whichever half of the pair belongs to the
     * other participant.
     */
    private function otherUserIdFromDirectKey(string $directKey, int $userId): int
    {
        [$a, $b] = array_map('intval', explode(':', $directKey));

        return $a === $userId ? $b : $a;
    }

    /**
     * @throws Throwable
     */
    public function createOrGetDirect(int $userA, int $userB): int
    {
        if ($userA === $userB) {
            throw new RuntimeException('Cannot create conversation with yourself');
        }

        $key = $this->getDirectKey($userA, $userB);

        $existing = $this->conversations->findByDirectKey($key);
        if ($existing) {
            return $existing;
        }

        try {
            $this->db->begin();

            $conversationId = $this->conversations->createDirect($key);

            $this->participants->addUsersIgnore($conversationId, [$userA, $userB]);

            $this->db->commit();

            return $conversationId;

        } catch (Throwable $e) {
            $this->db->rollback();

            $existing = $this->conversations->findByDirectKey($key);
            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * @throws Throwable
     */
    public function createGroup(int $ownerId, array $userIds, ?string $title): int
    {
        $userIds = array_unique(array_map('intval', $userIds));
        $userIds[] = $ownerId;
        $userIds = array_unique($userIds);

        $this->db->begin();
        try {
            $conversationId = $this->conversations->createGroup($title);
            $this->participants->addUsersIgnore($conversationId, $userIds);
            $this->db->commit();
            return $conversationId;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function getUserConversationsWithMeta(int $userId): array
    {
        $rows = $this->conversations->getListWithMeta($userId, 50);

        $relatedUserIds = [];
        $directOtherUserIds = [];
        foreach ($rows as $row) {
            if ($row['direct_key'] !== null) {
                $otherId = $this->otherUserIdFromDirectKey($row['direct_key'], $userId);
                $relatedUserIds[] = $otherId;
                $directOtherUserIds[] = $otherId;
            }
            if ($row['last_message_user_id'] !== null) {
                $relatedUserIds[] = (int)$row['last_message_user_id'];
            }
        }

        $users = $this->users->findByIds($relatedUserIds);
        // Presence is only surfaced for direct chats (a "N online" summary
        // for groups is a fine future addition, but not needed yet).
        $online = $this->visibleOnlineUserIds($directOtherUserIds, $users);

        return array_map(function ($row) use ($userId, $users, $online) {
            $isGroup = $row['direct_key'] === null;
            $otherUserId = $isGroup ? null : $this->otherUserIdFromDirectKey($row['direct_key'], $userId);

            $title = $isGroup
                ? (trim((string)($row['title'] ?? '')) !== '' ? $row['title'] : $this->tm->trans('message.group_chat'))
                : ($users[$otherUserId]['displayName'] ?? ('#'.$otherUserId));

            $lastMessage = null;
            if ($row['last_message_id']) {
                $senderId = (int)$row['last_message_user_id'];
                $lastMessage = [
                    'id' => (int)$row['last_message_id'],
                    'text' => $row['last_message_text'],
                    'user_id' => $senderId,
                    // Whether the caller wrote it themselves. Resolved here
                    // rather than left to the client to work out, so the
                    // page never needs the viewer's own id put in the
                    // markup just to answer "is this mine?" - the global
                    // messenger poller (messenger-global.ts) uses this to
                    // not toast your own message back at you.
                    'is_own' => $senderId === $userId,
                    'user_name' => $senderId === $userId ? $this->tm->trans('message.you') : ($users[$senderId]['displayName'] ?? ('#'.$senderId)),
                    'created_at' => (int)$row['last_message_created_at'],
                ];
            }

            return [
                'id' => (int)$row['id'],
                'is_group' => $isGroup,
                'title' => $title,
                'other_user_id' => $otherUserId,
                'other_user_avatar' => $isGroup ? null : ($users[$otherUserId]['avatarUrl'] ?? ''),
                'other_user_online' => $isGroup ? null : isset($online[$otherUserId]),
                'unread_count' => (int)$row['unread_count'],
                'last_message' => $lastMessage,
            ];
        }, $rows);
    }

    /**
     * @throws ForbiddenException
     */
    public function getConversation(int $conversationId, int $userId): array
    {
        $row = $this->conversations->getByIdForUser($conversationId, $userId);

        if (!$row) {
            throw new ForbiddenException('Conversation not found or access denied');
        }

        $participantIds = $this->participants->getUserIds($conversationId);
        $users = $this->users->findByIds($participantIds);
        $online = $this->visibleOnlineUserIds($participantIds, $users);

        $isGroup = $row['direct_key'] === null;
        $otherUserId = $isGroup ? null : $this->otherUserIdFromDirectKey($row['direct_key'], $userId);

        $title = $isGroup
            ? (trim((string)($row['title'] ?? '')) !== '' ? $row['title'] : $this->tm->trans('message.group_chat'))
            : ($users[$otherUserId]['displayName'] ?? ('#'.$otherUserId));

        $participants = array_map(
            fn (int $id) => array_merge(
                $users[$id] ?? ['id' => $id, 'displayName' => '#'.$id, 'avatarUrl' => ''],
                ['online' => isset($online[$id])]
            ),
            $participantIds
        );

        $lastMessageId = (int)($row['last_message_id'] ?? 0);
        $lastReadId = (int)($row['last_read_message_id'] ?? 0);

        $unreadCount = max($lastMessageId - $lastReadId, 0);

        return [
            'id' => (int)$row['id'],
            'is_group' => $isGroup,
            'title' => $title,
            'other_user_id' => $otherUserId,
            'other_user_online' => $isGroup ? null : isset($online[$otherUserId]),
            'participants' => $participants,
            'last_message_id' => $lastMessageId,
            'last_read_message_id' => $lastReadId,
            'unread_count' => $unreadCount,
        ];
    }

    /**
     * @param list<int> $ids
     * @param array<int, array{hidePresence?: bool}> $users
     * @return array<int, true>
     */
    private function visibleOnlineUserIds(array $ids, array $users): array
    {
        $online = $this->sessions->getOnlineUserIds($ids, UserService::ONLINE_WINDOW_SECONDS);

        foreach (array_keys($online) as $id) {
            if (($users[$id]['hidePresence'] ?? false) === true) {
                unset($online[$id]);
            }
        }

        return $online;
    }

    /**
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    public function addParticipants(int $conversationId, int $userId, array $userIds): void
    {
        // 🔒 access
        if (!$this->participants->isParticipant($conversationId, $userId)) {
            throw new ForbiddenException('Access denied');
        }

        $meta = $this->conversations->getMeta($conversationId);

        if (!$meta) {
            throw new NotFoundException('Conversation not found');
        }

        // Cannot add participants to direct conversation
        if ($meta['direct_key'] !== null) {
            throw new ForbiddenException('Cannot add participants to direct conversation');
        }

        $userIds = array_unique(array_map('intval', $userIds));

        // removing current user from list (not needed)
        $userIds = array_filter($userIds, fn ($id) => $id !== $userId);

        if (!$userIds) {
            return;
        }

        $this->participants->addUsersIgnore($conversationId, $userIds);
    }

    /**
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    public function removeParticipant(int $conversationId, int $actorId, int $targetUserId): void
    {
        // current user must be a member
        if (!$this->participants->isParticipant($conversationId, $actorId)) {
            throw new ForbiddenException('Access denied');
        }

        $meta = $this->conversations->getMeta($conversationId);

        if (!$meta) {
            throw new NotFoundException('Conversation not found');
        }

        // cannot delete from direct
        if ($meta['direct_key'] !== null) {
            throw new ForbiddenException('Cannot modify direct conversation');
        }

        // checking that the user is participant
        if (!$this->participants->isParticipant($conversationId, $targetUserId)) {
            return; // already removed — okay
        }

        $this->participants->removeUser($conversationId, $targetUserId);
    }

    /**
     * Only place message text is allowed to reach storage from - see this
     * class's own constructor for exactly what survives.
     */
    private function sanitizeText(string $text): string
    {
        return $this->purifier->purify($text);
    }

    /**
     * @throws ValidationException
     * @throws ForbiddenException
     */
    public function send(
        int $conversationId,
        int $userId,
        string $text,
        ?int $replyToMessageId = null,
        ?int $attachmentUploadId = null
    ): int {
        $text = $this->sanitizeText(trim($text));

        // A message needs either text or an attachment - not both empty,
        // but an attachment alone (a photo with no caption) is fine.
        if ($text === '' && $attachmentUploadId === null) {
            throw new ValidationException('Empty message');
        }

        // Access check
        if (!$this->participants->isParticipant($conversationId, $userId)) {
            throw new ForbiddenException('Access denied');
        }

        // A reply target must actually exist in this same conversation, and
        // not (yet) be deleted - otherwise drop the link silently rather
        // than failing the whole send (it may simply have raced with a
        // deletion, or be spoofed).
        if ($replyToMessageId !== null) {
            $target = $this->messages->findById($replyToMessageId);
            if (!$target || (int)$target['conversation_id'] !== $conversationId || $target['deleted_at'] !== null) {
                $replyToMessageId = null;
            }
        }

        // Same treatment for the attachment: it must be an upload owned by
        // this user (never someone else's file, never a typo'd id) or the
        // link is dropped rather than failing the whole send.
        if ($attachmentUploadId !== null) {
            $upload = $this->uploads->findById($attachmentUploadId);
            if (!$upload || $upload->userId !== $userId) {
                $attachmentUploadId = null;
            }
        }

        if ($text === '' && $attachmentUploadId === null) {
            throw new ValidationException('Empty message');
        }

        $this->db->begin();

        try {
            // 1. insert message
            $messageId = $this->messages->insert($conversationId, $userId, $text, $replyToMessageId, $attachmentUploadId);

            // 2. update conversation cache
            $this->conversations->updateCache($conversationId, $messageId);

            $this->db->commit();

            return $messageId;

        } catch (Throwable $e) {
            $this->db->rollback();
            error_log($e->getMessage());
            throw new RuntimeException('Failed to send message');
        }
    }

    /**
     * Shape shared by getMessagesWithMeta()'s `messages` (new) and `edited`
     * (already-seen messages whose text changed) lists.
     */
    private function mapMessage(array $m): array
    {
        $replyToId = $m['reply_to_message_id'] !== null ? (int)$m['reply_to_message_id'] : null;
        $attachmentId = $m['attachment_id'] !== null ? (int)$m['attachment_id'] : null;

        return [
            'id' => (int)$m['id'],
            'user_id' => (int)$m['user_id'],
            'text' => $m['text'],
            'created_at' => (int)$m['created_at'],
            'edited' => $m['updated_at'] !== null,
            'reply' => $replyToId !== null ? [
                'id' => $replyToId,
                'user_id' => (int)$m['reply_user_id'],
                'text' => $m['reply_text'],
            ] : null,
            'attachment' => $attachmentId !== null ? [
                'id' => $attachmentId,
                'url' => '/uploads/'.$m['attachment_path'],
                'mime' => $m['attachment_mime'],
                'size' => (int)$m['attachment_size'],
                'original_name' => $m['attachment_original_name'],
            ] : null,
        ];
    }

    /**
     * @throws ForbiddenException
     */
    public function getMessagesWithMeta(int $conversationId, int $userId, int $afterId): array
    {
        if (!$this->participants->isParticipant($conversationId, $userId)) {
            throw new ForbiddenException('Access denied');
        }

        $messages = array_map(
            fn (array $m) => $this->mapMessage($m),
            $this->messages->getAfter($conversationId, $afterId, 50)
        );

        // Messages the client may already have rendered (so getAfter() above
        // won't return them again) but that were edited or deleted since -
        // this is how an edit/delete made by one participant reaches
        // everyone else's already-open view of the conversation.
        $edited = [];
        $deletedIds = [];
        foreach ($this->messages->getRecentlyChanged($conversationId) as $m) {
            if ($m['deleted_at'] !== null) {
                $deletedIds[] = (int)$m['id'];
            } else {
                $edited[] = $this->mapMessage($m);
            }
        }

        $typing = $this->participants->getTypingUsers($conversationId, $userId);
        $readStates = $this->participants->getReadStates($conversationId);

        // Riding the same poll: presence for this conversation's own
        // participants, so the chat header's online dot (and the group
        // drawer's, if open) refreshes without a dedicated presence poll.
        $participantIds = $this->participants->getUserIds($conversationId);
        $users = $this->users->findByIds($participantIds);
        $onlineUserIds = array_keys($this->visibleOnlineUserIds($participantIds, $users));

        return [
            'messages' => $messages,
            'edited' => $edited,
            'deleted_ids' => $deletedIds,
            'typing' => array_map(fn ($row) => (int)$row['user_id'], $typing),
            // Per-participant last_read_message_id, not a per-message read
            // log - the client derives each message's read/unread status by
            // comparing message id to these values. Sent on every poll (even
            // ones with no new messages) so ticks update once the other side
            // catches up.
            'read_states' => $readStates,
            'online_user_ids' => $onlineUserIds,
            'server_time_ms' => (int)(microtime(true) * 1000),
        ];
    }

    /**
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    public function delete(int $messageId, int $userId): void
    {
        $message = $this->messages->findById($messageId);

        if (!$message || $message['deleted_at'] !== null) {
            throw new NotFoundException('Message not found');
        }

        // Only user's message
        if ((int)$message['user_id'] !== $userId) {
            throw new ForbiddenException('Cannot delete other user\'s message');
        }

        $conversationId = (int)$message['conversation_id'];

        // Just in case checking participants
        if (!$this->participants->isParticipant($conversationId, $userId)) {
            throw new ForbiddenException('Access denied');
        }

        $this->db->begin();

        try {
            $this->messages->delete($messageId);

            // The deleted message might have been the conversation's cached
            // "last message" - recompute it so the conversation list doesn't
            // keep showing a vanished message as the preview.
            $this->conversations->refreshLastMessage($conversationId);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log($e->getMessage());
            throw new RuntimeException('Failed to delete message');
        }
    }

    /**
     * @throws ValidationException
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    public function edit(int $messageId, int $userId, string $text): void
    {
        $text = $this->sanitizeText(trim($text));

        if ($text === '') {
            throw new ValidationException('Empty message');
        }

        $message = $this->messages->findById($messageId);

        if (!$message || $message['deleted_at'] !== null) {
            throw new NotFoundException('Message not found');
        }

        $conversationId = (int)$message['conversation_id'];

        // Only user's message
        if ((int)$message['user_id'] !== $userId) {
            throw new ForbiddenException('Cannot edit other user\'s message');
        }

        // created_at is stored as a unix timestamp (int unsigned), not a
        // date-time string, so it must be compared numerically rather than
        // passed through strtotime() (which can't parse a bare epoch value
        // and would fall back to false/0, making every edit look expired).
        if ((int)$message['created_at'] < time() - 900) {
            throw new ForbiddenException('Edit window expired');
        }

        // Is it participant?
        if (!$this->participants->isParticipant($conversationId, $userId)) {
            throw new ForbiddenException('Access denied');
        }

        $this->messages->updateText($messageId, $text);
    }

    /**
     * @throws ForbiddenException
     */
    public function markAsRead(int $conversationId, int $userId, int $messageId): void
    {
        // Checking access
        if (!$this->participants->isParticipant($conversationId, $userId)) {
            throw new ForbiddenException('Access denied');
        }

        $this->participants->markAsRead($conversationId, $userId, $messageId);
    }

    /**
     * @throws ForbiddenException
     */
    public function typing(int $conversationId, int $userId): void
    {
        // Checking access
        if (!$this->participants->isParticipant($conversationId, $userId)) {
            throw new ForbiddenException('Access denied');
        }

        $this->participants->updateTyping($conversationId, $userId);
    }

    /**
     * Sends $text to $recipientUserId from the system account, creating (or
     * reusing) their direct conversation.
     *
     * Any failure from the underlying send is wrapped and rethrown as a
     * RuntimeException rather than swallowed - so callers (e.g.
     * UserService::register(), which has no try/catch of its own around
     * this call) do propagate a broken notification as a failure of their
     * own operation, the same way the registration email already does.
     */
    public function notify(int $recipientUserId, string $text): void
    {
        if ($recipientUserId === User::SYSTEM_USER_ID) {
            return;
        }

        try {
            $conversationId = $this->createOrGetDirect(User::SYSTEM_USER_ID, $recipientUserId);
            $this->send($conversationId, User::SYSTEM_USER_ID, $text);
        } catch (Throwable $e) {
            throw new RuntimeException('MessageService::notify failed for user '.$recipientUserId.': '.$e->getMessage());
        }
    }
}
