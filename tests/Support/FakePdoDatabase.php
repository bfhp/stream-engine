<?php

declare(strict_types=1);

namespace Tests\Support;

use StreamEngine\Core\PdoDatabase;

/**
 * A tiny in-memory stand-in for PdoDatabase, purpose-built for tests that
 * need to exercise real repository/service objects together (e.g.
 * FriendServiceTest wiring a real FeedRepository + MembershipRepository +
 * MessageService, whose own notify() sends through the same conversations/
 * messages tables) without a real MySQL connection. PdoDatabase isn't
 * `final`, and its constructor
 * only exists to open a real PDO connection - this subclass never calls it
 * (no `parent::__construct()`), so there's nothing to fake beyond the
 * public query methods below.
 *
 * Only supports the handful of tables/queries the tests that use it
 * actually touch (feeds, memberships, membership_roles, conversations,
 * conversation_participants, messages) - routed by matching a distinctive
 * substring of each repository's known SQL text, not a real SQL parser.
 * Add cases as needed rather than trying to make this fully general.
 */
final class FakePdoDatabase extends PdoDatabase
{
    /** @var array<int, array<string, mixed>> */
    private array $feeds = [];

    private int $nextFeedId = 1;

    /** @var array<string, int> keyed by "containerId:userId", value is insertion order (for joined_at ordering) */
    private array $memberships = [];

    /**
     * The current membership_role_id of each of those rows, same keys. Kept
     * beside $memberships rather than folded into it so the existing "value is
     * the insertion order" contract above stays true for every caller that
     * reads it; the two are written and cleared together, so a key present in
     * one is always present in the other.
     *
     * @var array<string, int>
     */
    private array $membershipRoleIds = [];

    private int $nextMembershipOrder = 1;

    /** @var array<string, int> role name => id */
    private array $membershipRoles = ['member' => 1];

    /**
     * membership_roles.id -> role_level, mirroring the seed order in
     * migrations/20260912000000_initial.sql that
     * MembershipRepository's own ROLE_*_ID constants are pinned to.
     */
    private const array ROLE_LEVELS = [1 => 0, 2 => 1, 3 => 2, 4 => 3];

    /** @var array<int, array{id:int, nick:string, username:?string, avatar_url:string}> */
    private array $users = [];

    /** @var array<string, int> direct_key => conversation id */
    private array $conversationsByDirectKey = [];

    /** @var array<int, list<int>> conversation id => participant user ids */
    private array $participants = [];

    /** @var list<array{conversation_id:int, user_id:int, text:string}> */
    public array $messages = [];

    /** @var list<array{recipient_user_id:int, notification_type:string, channel:string, delivery:string, payload:string, message_text:string, deduplication_key:?string, scheduled_at:int}> */
    public array $notificationDeliveries = [];

    private int $nextConversationId = 1;

    private int $nextMessageId = 1;

    private int $nextNotificationDeliveryId = 1;

    private int $lastInsertId = 0;

    public function __construct()
    {
    }

    /**
     * Test setup helper - seeds a personal blog feed row so
     * FeedRepository::findByOwnerAndType() can find it.
     */
    public function addBlogFeed(int $id, int $ownerId): void
    {
        $this->feeds[$id] = [
            'id' => $id,
            'parent_id' => null,
            'owner_id' => $ownerId,
            'type' => 'blog',
            'slug' => null,
            'title' => 'Blog',
            'description' => null,
            'image_url' => null,
            'content' => null,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => 1700000000,
        ];

        $this->nextFeedId = max($this->nextFeedId, $id + 1);
    }

    /**
     * Test setup helper - seeds a community feed row, the container
     * MembershipRepository::findUserCommunities() joins against. Same shape as
     * addBlogFeed(), just `type = 'community'` plus the columns that query
     * actually selects (slug/title/image_url).
     */
    public function addCommunityFeed(
        int $id,
        int $ownerId,
        ?string $slug = null,
        ?string $title = null,
        ?string $imageUrl = null
    ): void {
        $this->feeds[$id] = [
            'id' => $id,
            'parent_id' => null,
            'owner_id' => $ownerId,
            'type' => 'community',
            'slug' => $slug,
            'title' => $title,
            'description' => null,
            'image_url' => $imageUrl,
            'content' => null,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => 1700000000,
        ];

        $this->nextFeedId = max($this->nextFeedId, $id + 1);
    }

    /**
     * Test setup helper - seeds a `users` row so
     * MembershipRepository::findMutualFriends() has something to join
     * against and return.
     */
    public function addUser(int $id, string $nick, ?string $username = null, string $avatarUrl = ''): void
    {
        $this->users[$id] = ['id' => $id, 'nick' => $nick, 'username' => $username, 'avatar_url' => $avatarUrl];
    }

    /**
     * The blog feed id owned by $ownerId, or null if none was seeded via
     * addBlogFeed() - shared by the mutual-friends fetchOne()/fetchAll()
     * branches below, which both need to walk from a user id to their
     * blog's container id the same way the real JOIN ... ON f.owner_id = ?
     * AND f.type = 'blog' does.
     */
    private function findBlogIdByOwner(int $ownerId): ?int
    {
        foreach ($this->feeds as $feed) {
            if ($feed['owner_id'] === $ownerId && $feed['type'] === 'blog') {
                return $feed['id'];
            }
        }

        return null;
    }

    /**
     * Mirrors MembershipRepository::findMutualFriends()/
     * countMutualFriends()'s query: every user with a membership row in
     * $userId's blog who also has $userId sitting in *their* blog in
     * return. Returns [memberUserId => membershipOrder] so callers can
     * sort by "joined_at" (insertion order here) themselves.
     *
     * @return array<int, int>
     */
    private function findMutualFriendUserIds(int $userId): array
    {
        $targetBlogId = $this->findBlogIdByOwner($userId);
        if ($targetBlogId === null) {
            return [];
        }

        $result = [];
        foreach ($this->memberships as $key => $order) {
            [$containerId, $memberUserId] = array_map('intval', explode(':', $key));

            if ($containerId !== $targetBlogId || $memberUserId === $userId) {
                continue;
            }

            $memberBlogId = $this->findBlogIdByOwner($memberUserId);
            if ($memberBlogId === null || !isset($this->memberships["$memberBlogId:$userId"])) {
                continue; // not mutual - $memberUserId hasn't (also) subscribed back
            }

            $result[$memberUserId] = $order;
        }

        return $result;
    }

    /**
     * Mirrors MembershipRepository::findConnections()' UNION: every user
     * $userId has a personal-blog relationship with in *either* direction,
     * tagged with which direction(s) exist, ordered the same
     * actionable-first way (incoming-only, then mutual, then outgoing-only;
     * most recent - highest insertion order - first within each group).
     *
     * @return list<array{id:int, nick:string, username:?string, avatar_url:string, incoming:int, outgoing:int}>
     */
    private function findConnectionRows(int $userId): array
    {
        $myBlogId = $this->findBlogIdByOwner($userId);

        /** @var array<int, array{incoming:int, outgoing:int, order:int}> $related */
        $related = [];

        $touch = function (int $otherUserId, string $direction, int $order) use (&$related): void {
            $related[$otherUserId] ??= ['incoming' => 0, 'outgoing' => 0, 'order' => 0];
            $related[$otherUserId][$direction] = 1;
            $related[$otherUserId]['order'] = max($related[$otherUserId]['order'], $order);
        };

        foreach ($this->memberships as $key => $order) {
            [$containerId, $memberUserId] = array_map('intval', explode(':', $key));

            // Incoming: someone else sits in *my* blog.
            if ($myBlogId !== null && $containerId === $myBlogId && $memberUserId !== $userId) {
                $touch($memberUserId, 'incoming', $order);
            }

            // Outgoing: I sit in someone else's blog.
            if ($memberUserId === $userId) {
                $ownerId = $this->feeds[$containerId]['owner_id'] ?? null;

                if ($ownerId !== null && $ownerId !== $userId && ($this->feeds[$containerId]['type'] ?? '') === 'blog') {
                    $touch($ownerId, 'outgoing', $order);
                }
            }
        }

        $rows = [];
        foreach ($related as $otherUserId => $relation) {
            $rank = $relation['outgoing'] === 0 ? 0 : ($relation['incoming'] === 1 ? 1 : 2);

            $rows[] = [
                'rank' => $rank,
                'order' => $relation['order'],
                'row' => ($this->users[$otherUserId] ?? [
                    'id' => $otherUserId,
                    'nick' => '',
                    'username' => null,
                    'avatar_url' => '',
                ]) + ['incoming' => $relation['incoming'], 'outgoing' => $relation['outgoing']],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['rank'], -$a['order']] <=> [$b['rank'], -$b['order']]);

        return array_map(static fn (array $entry): array => $entry['row'], $rows);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        if (str_contains($sql, 'FROM feeds f') && str_contains($sql, 'f.owner_id = ?')) {
            [$ownerId, $type] = $params;
            foreach ($this->feeds as $feed) {
                if ($feed['owner_id'] === $ownerId && $feed['type'] === $type) {
                    return $feed;
                }
            }

            return null;
        }

        if (str_contains($sql, 'FROM memberships m') && str_contains($sql, 'JOIN membership_roles')) {
            [$containerId, $userId] = $params;
            $key = "$containerId:$userId";

            if (!isset($this->memberships[$key])) {
                return null;
            }

            return ['role_level' => self::ROLE_LEVELS[$this->membershipRoleIds[$key]] ?? 1];
        }

        if (str_contains($sql, 'FROM membership_roles WHERE name')) {
            $name = $params[0];

            return isset($this->membershipRoles[$name])
                ? ['id' => $this->membershipRoles[$name]]
                : null;
        }

        if (str_contains($sql, 'FROM conversations WHERE direct_key')) {
            $key = $params[0];

            return isset($this->conversationsByDirectKey[$key])
                ? ['id' => $this->conversationsByDirectKey[$key]]
                : null;
        }

        if (str_contains($sql, 'FROM conversation_participants')) {
            [$conversationId, $userId] = $params;

            return in_array($userId, $this->participants[$conversationId] ?? [], true)
                ? [1]
                : null;
        }

        if (str_contains($sql, 'FROM memberships m1') && str_contains($sql, 'COUNT(*)')) {
            $userId = $params[0];

            return ['total' => count($this->findMutualFriendUserIds($userId))];
        }

        return null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        if (str_contains($sql, 'SELECT user_id')
            && str_contains($sql, 'FROM memberships')
            && str_contains($sql, 'membership_role_id != ?')) {
            [$containerId, $excludedRoleId] = array_map('intval', $params);
            $rows = [];

            foreach (array_keys($this->memberships) as $key) {
                [$memberContainerId, $userId] = array_map('intval', explode(':', $key));

                if ($memberContainerId === $containerId
                    && $this->membershipRoleIds[$key] !== $excludedRoleId) {
                    $rows[] = ['user_id' => $userId];
                }
            }

            return $rows;
        }

        if (str_contains($sql, 'SELECT m1.user_id') && str_contains($sql, 'JOIN memberships m2')) {
            return array_map(
                static fn (int $userId): array => ['user_id' => $userId],
                array_keys($this->findMutualFriendUserIds((int) $params[0])),
            );
        }

        // MembershipRepository::findUserCommunities() - every community
        // container this user has a row in, with its role level and a member
        // count that excludes pending subscribers, ordered by standing then
        // most-recently-joined.
        if (str_contains($sql, 'AS member_count')) {
            [$subscriberRoleId, $userId] = $params;
            $limit = (int) (preg_match('/LIMIT (\d+)/', $sql, $m) ? $m[1] : 1000);

            $rows = [];
            foreach ($this->memberships as $key => $order) {
                [$containerId, $memberUserId] = array_map('intval', explode(':', $key));

                if ($memberUserId !== $userId || ($this->feeds[$containerId]['type'] ?? '') !== 'community') {
                    continue;
                }

                $memberCount = 0;
                foreach (array_keys($this->memberships) as $otherKey) {
                    [$otherContainerId] = array_map('intval', explode(':', $otherKey));

                    if ($otherContainerId === $containerId
                        && $this->membershipRoleIds[$otherKey] !== $subscriberRoleId) {
                        $memberCount++;
                    }
                }

                $feed = $this->feeds[$containerId];
                $roleLevel = self::ROLE_LEVELS[$this->membershipRoleIds[$key]] ?? 1;

                $rows[] = [
                    'sort' => [-$roleLevel, -$order],
                    'row' => [
                        'id' => $feed['id'],
                        'slug' => $feed['slug'],
                        'title' => $feed['title'],
                        'image_url' => $feed['image_url'],
                        'role_level' => $roleLevel,
                        'joined_at' => $order,
                        'member_count' => $memberCount,
                    ],
                ];
            }

            usort($rows, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

            return array_slice(array_map(static fn (array $e): array => $e['row'], $rows), 0, $limit);
        }

        if (str_contains($sql, 'MAX(rel.incoming)')) {
            $userId = $params[0];
            $limit = (int) (preg_match('/LIMIT (\d+)/', $sql, $m) ? $m[1] : 1000);

            return array_slice($this->findConnectionRows($userId), 0, $limit);
        }

        if (str_contains($sql, 'FROM memberships m1') && str_contains($sql, 'JOIN users u')) {
            $userId = $params[0];
            $limit = (int) (preg_match('/LIMIT (\d+)/', $sql, $m) ? $m[1] : 1000);
            $offset = (int) (preg_match('/OFFSET (\d+)/', $sql, $m) ? $m[1] : 0);

            $friendUserIds = $this->findMutualFriendUserIds($userId);
            // Most-recently-joined first, same as the real query's
            // ORDER BY m1.joined_at DESC.
            arsort($friendUserIds);

            $rows = [];
            foreach (array_keys($friendUserIds) as $friendUserId) {
                $rows[] = $this->users[$friendUserId] ?? [
                    'id' => $friendUserId,
                    'nick' => '',
                    'username' => null,
                    'avatar_url' => '',
                ];
            }

            return array_slice($rows, $offset, $limit);
        }

        return [];
    }

    public function execute(string $sql, array $params = []): int
    {
        if (str_contains($sql, 'INSERT INTO notification_deliveries')) {
            [$recipientUserId, $notificationType, $channel, $delivery, $payload, $messageText, $deduplicationKey, $scheduledAt] = $params;

            foreach ($this->notificationDeliveries as $existing) {
                if ($deduplicationKey !== null
                    && $existing['recipient_user_id'] === (int) $recipientUserId
                    && $existing['notification_type'] === (string) $notificationType
                    && $existing['channel'] === (string) $channel
                    && $existing['deduplication_key'] === $deduplicationKey) {
                    return 0;
                }
            }

            $this->notificationDeliveries[] = [
                'recipient_user_id' => (int) $recipientUserId,
                'notification_type' => (string) $notificationType,
                'channel' => (string) $channel,
                'delivery' => (string) $delivery,
                'payload' => (string) $payload,
                'message_text' => (string) $messageText,
                'deduplication_key' => $deduplicationKey !== null ? (string) $deduplicationKey : null,
                'scheduled_at' => (int) $scheduledAt,
            ];
            $this->lastInsertId = $this->nextNotificationDeliveryId++;

            return 1;
        }

        if (str_contains($sql, 'INSERT IGNORE INTO memberships')) {
            [$containerId, $userId, $roleId] = $params;
            $key = "$containerId:$userId";
            if (!isset($this->memberships[$key])) {
                $this->memberships[$key] = $this->nextMembershipOrder++;
                $this->membershipRoleIds[$key] = (int) $roleId;
            }

            return 1;
        }

        if (str_contains($sql, 'DELETE FROM memberships')) {
            [$containerId, $userId] = $params;
            unset($this->memberships["$containerId:$userId"], $this->membershipRoleIds["$containerId:$userId"]);

            return 1;
        }

        // MembershipRepository::changeRole() - move one row to another role,
        // preserving its joined_at (so the insertion order stays put).
        if (str_contains($sql, 'UPDATE memberships SET membership_role_id') && str_contains($sql, 'user_id = ?')) {
            [$roleId, $containerId, $userId] = $params;
            $key = "$containerId:$userId";

            if (!isset($this->memberships[$key])) {
                return 0;
            }

            $this->membershipRoleIds[$key] = (int) $roleId;

            return 1;
        }

        // MembershipRepository::promoteSubscribersToMembers() - bulk-move
        // every row of one role in a container to another, returning how many
        // were touched (its caller reports that number back to the user).
        if (str_contains($sql, 'UPDATE memberships SET membership_role_id')) {
            [$newRoleId, $containerId, $oldRoleId] = $params;
            $affected = 0;

            foreach (array_keys($this->memberships) as $key) {
                [$rowContainerId] = array_map('intval', explode(':', $key));

                if ($rowContainerId === $containerId && ($this->membershipRoleIds[$key] ?? 0) === $oldRoleId) {
                    $this->membershipRoleIds[$key] = (int) $newRoleId;
                    $affected++;
                }
            }

            return $affected;
        }

        if (str_contains($sql, 'INSERT INTO conversations')) {
            $key = $params[0];
            $id = $this->nextConversationId++;
            $this->conversationsByDirectKey[$key] = $id;
            $this->lastInsertId = $id;

            return 1;
        }

        if (str_contains($sql, 'INSERT IGNORE INTO conversation_participants')) {
            // (conversation_id, user_id) pairs, flattened two-at-a-time.
            for ($i = 0; $i < count($params); $i += 2) {
                $conversationId = $params[$i];
                $userId = $params[$i + 1];
                $this->participants[$conversationId][] = $userId;
            }

            return 1;
        }

        if (str_contains($sql, 'INSERT INTO messages')) {
            [$conversationId, $userId, $text] = $params;
            $this->messages[] = ['conversation_id' => $conversationId, 'user_id' => $userId, 'text' => $text];
            $this->lastInsertId = $this->nextMessageId++;

            return 1;
        }

        // UPDATE conversations SET last_message_id... and anything else
        // this feature's write paths touch but these tests don't assert on.
        return 1;
    }

    public function lastInsertId(): int
    {
        return $this->lastInsertId;
    }

    /**
     * @return list<int>
     */
    public function conversationParticipants(int $conversationId): array
    {
        return $this->participants[$conversationId] ?? [];
    }

    public function begin(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollback(): void
    {
    }
}
