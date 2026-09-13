<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;

final readonly class MembershipRepository
{
    /**
     * membership_roles.id values, hardcoded rather than resolved at runtime
     * via `SELECT id FROM membership_roles WHERE name = ?` - migrations/
     * 20260912000000_initial.sql is the migration that
     * ever writes to membership_roles, it runs exactly once against an
     * empty table, and its four INSERTs are in this exact order - so the
     * auto_increment ids they get are deterministic. Keep that file's row
     * order in sync with these constants if it's ever touched again.
     */
    public const int ROLE_SUBSCRIBER_ID = 1;

    public const int ROLE_MEMBER_ID = 2;

    public const int ROLE_MODERATOR_ID = 3;

    public const int ROLE_OWNER_ID = 4;

    public function __construct(
        private PdoDatabase $db
    ) {
    }

    /**
     * Uses index: PRIMARY(container_id, user_id)
     * Uses index: PRIMARY(id) on membership_roles join.
     */
    public function find(int $containerId, int $userId): ?object
    {
        $row = $this->db->fetchOne(
            '
            SELECT mr.role_level
            FROM memberships m
            JOIN membership_roles mr ON mr.id = m.membership_role_id
            WHERE m.container_id = ? AND m.user_id = ?
            ',
            [$containerId, $userId]
        );

        if (! $row) {
            return null;
        }

        return (object) [
            'roleLevel' => (int) $row['role_level'],
        ];
    }

    /**
     * Thin readability wrapper over find() for callers (FriendService) that
     * only care about existence, not role level.
     *
     * Uses index: PRIMARY(container_id, user_id)
     */
    public function exists(int $containerId, int $userId): bool
    {
        return $this->find($containerId, $userId) !== null;
    }

    /**
     * Creates (or, if the pair already exists, silently no-ops on) a
     * membership row - `INSERT IGNORE` rather than an upsert because the
     * primary key already fully identifies the relationship; a repeat
     * request shouldn't bump `joined_at` or change the role of an existing
     * row. Returns whether a new row was inserted, including under concurrent requests.
     *
     * Uses index: PRIMARY(container_id, user_id)
     */
    public function create(int $containerId, int $userId, int $membershipRoleId): bool
    {
        return $this->db->execute(
            '
            INSERT IGNORE INTO memberships (container_id, user_id, membership_role_id, joined_at)
            VALUES (?, ?, ?, UNIX_TIMESTAMP())
            ',
            [$containerId, $userId, $membershipRoleId]
        ) > 0;
    }

    /**
     * Uses index: PRIMARY(container_id, user_id)
     */
    public function delete(int $containerId, int $userId): void
    {
        $this->db->execute(
            'DELETE FROM memberships WHERE container_id = ? AND user_id = ?',
            [$containerId, $userId]
        );
    }

    /**
     * A community's real, approved members - subscriber rows (pending
     * approval, ROLE_SUBSCRIBER_ID) are deliberately excluded, since a
     * subscriber isn't a member yet (see CommunityService's own docblock on
     * the "approval" membership type) - only someone who's actually joined
     * (member/moderator/owner) belongs on a community's public "members"
     * list. Ranked owner/moderators first (role_level DESC), then by join
     * order within the same role.
     *
     * Uses index: PRIMARY(container_id, user_id) for the WHERE, membership_role_id join.
     *
     * @return list<array{id:int, nick:string, username:?string, avatarUrl:string, roleLevel:int}>
     */
    public function findMembers(int $containerId, int $limit, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $rows = $this->db->fetchAll(
            "
            SELECT u.id, u.nick, u.username, u.avatar_url, mr.role_level
            FROM memberships m
            JOIN membership_roles mr ON mr.id = m.membership_role_id
            JOIN users u ON u.id = m.user_id
            WHERE m.container_id = ? AND m.membership_role_id != ?
            ORDER BY mr.role_level DESC, m.joined_at ASC
            LIMIT $limit OFFSET $offset
            ",
            [$containerId, self::ROLE_SUBSCRIBER_ID]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'nick' => (string) $row['nick'],
            'username' => $row['username'],
            'avatarUrl' => (string) $row['avatar_url'],
            'roleLevel' => (int) $row['role_level'],
        ], $rows);
    }

    /**
     * Companion count for findMembers() - same "real members only" exclusion
     * of pending subscribers.
     *
     * Uses index: PRIMARY(container_id, user_id)
     */
    public function countMembers(int $containerId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total FROM memberships WHERE container_id = ? AND membership_role_id != ?',
            [$containerId, self::ROLE_SUBSCRIBER_ID]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * A community's pending join requests (subscriber rows, ROLE_SUBSCRIBER_ID)
     * - the mirror image of findMembers(): this is exactly the set that one
     * excludes. Used by the community.manage "Members" tab's moderation
     * queue (see CommunityService::getSubscribers()) - a plain member never
     * sees this list, only the community's owner. Ordered oldest-request-
     * first (joined_at ASC), same "earliest first" convention as
     * findMembers()'s own within-role ordering.
     *
     * Uses index: PRIMARY(container_id, user_id) for the WHERE, membership_role_id join.
     *
     * @return list<array{id:int, nick:string, username:?string, avatarUrl:string, roleLevel:int}>
     */
    public function findSubscribers(int $containerId, int $limit, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $rows = $this->db->fetchAll(
            "
            SELECT u.id, u.nick, u.username, u.avatar_url, mr.role_level
            FROM memberships m
            JOIN membership_roles mr ON mr.id = m.membership_role_id
            JOIN users u ON u.id = m.user_id
            WHERE m.container_id = ? AND m.membership_role_id = ?
            ORDER BY m.joined_at ASC
            LIMIT $limit OFFSET $offset
            ",
            [$containerId, self::ROLE_SUBSCRIBER_ID]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'nick' => (string) $row['nick'],
            'username' => $row['username'],
            'avatarUrl' => (string) $row['avatar_url'],
            'roleLevel' => (int) $row['role_level'],
        ], $rows);
    }

    /**
     * Companion count for findSubscribers().
     *
     * Uses index: PRIMARY(container_id, user_id)
     */
    public function countSubscribers(int $containerId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total FROM memberships WHERE container_id = ? AND membership_role_id = ?',
            [$containerId, self::ROLE_SUBSCRIBER_ID]
        );

        return (int) ($row['total'] ?? 0);
    }

    /** Atomically approves a pending membership; only the winning request returns true. */
    public function approveSubscriber(int $containerId, int $userId): bool
    {
        return $this->db->execute(
            'UPDATE memberships SET membership_role_id = ?
             WHERE container_id = ? AND user_id = ? AND membership_role_id = ?',
            [self::ROLE_MEMBER_ID, $containerId, $userId, self::ROLE_SUBSCRIBER_ID],
        ) > 0;
    }

    /** Atomically demotes a member or moderator; repeated requests return false. */
    public function demoteMemberToSubscriber(int $containerId, int $userId): bool
    {
        return $this->db->execute(
            'UPDATE memberships SET membership_role_id = ?
             WHERE container_id = ? AND user_id = ? AND membership_role_id IN (?, ?)',
            [self::ROLE_SUBSCRIBER_ID, $containerId, $userId, self::ROLE_MEMBER_ID, self::ROLE_MODERATOR_ID],
        ) > 0;
    }

    /**
     * Changes an existing role while preserving the original joined_at date.
     * Community approval and removal use the conditional methods above.
     *
     * Uses index: PRIMARY(container_id, user_id)
     */
    public function changeRole(int $containerId, int $userId, int $membershipRoleId): void
    {
        $this->db->execute(
            'UPDATE memberships SET membership_role_id = ? WHERE container_id = ? AND user_id = ?',
            [$membershipRoleId, $containerId, $userId]
        );
    }

    /**
     * Bulk-promotes every pending subscriber of $containerId straight to
     * ROLE_MEMBER_ID in one statement - CommunityService::updateSettings()'s
     * own "switch from approval to open membership" transition (see its
     * docblock): once a community stops requiring approval, everyone who
     * was already waiting on one should be let in immediately rather than
     * staying stuck as a subscriber forever. Returns how many rows were
     * actually touched, so the caller can report it back to the confirming
     * owner.
     *
     * Uses index: PRIMARY(container_id, user_id)
     */
    public function promoteSubscribersToMembers(int $containerId): int
    {
        return $this->db->execute(
            'UPDATE memberships SET membership_role_id = ? WHERE container_id = ? AND membership_role_id = ?',
            [self::ROLE_MEMBER_ID, $containerId, self::ROLE_SUBSCRIBER_ID]
        );
    }

    /**
     * Every user with their own membership row in $userId's personal blog
     * container (m1) who *also* has $userId sitting in their own personal
     * blog container in return (m2) - i.e. the relationship is mutual in
     * both directions, which is this whole feature's definition of
     * "friends" (see FriendService's own docblock: there's no separate
     * "friend" role, mutuality is derived by checking for the reverse row).
     *
     * Uses index: owner_id (feeds.owner_id) for f1/f2, PRIMARY(container_id, user_id) for m1/m2.
     *
     * @return list<array{id:int, nick:string, username:?string, avatarUrl:string}>
     */
    public function findMutualFriends(int $userId, int $limit, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $rows = $this->db->fetchAll(
            "
            SELECT u.id, u.nick, u.username, u.avatar_url
            FROM memberships m1
            JOIN feeds f1 ON f1.id = m1.container_id AND f1.owner_id = ? AND f1.type = 'blog'
            JOIN users u ON u.id = m1.user_id
            JOIN feeds f2 ON f2.owner_id = m1.user_id AND f2.type = 'blog'
            JOIN memberships m2 ON m2.container_id = f2.id AND m2.user_id = ?
            WHERE m1.user_id != ?
            ORDER BY m1.joined_at DESC
            LIMIT $limit OFFSET $offset
            ",
            [$userId, $userId, $userId]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'nick' => (string) $row['nick'],
            'username' => $row['username'],
            'avatarUrl' => (string) $row['avatar_url'],
        ], $rows);
    }

    /**
     * User ids of all mutual friends, used to fan out a newly published
     * personal-blog post. This is the id-only counterpart to
     * findMutualFriends(): no user join or pagination is needed because the
     * caller needs the complete recipient set rather than profile cards.
     *
     * @return list<int>
     */
    public function findMutualFriendIds(int $userId): array
    {
        $rows = $this->db->fetchAll(
            "
            SELECT m1.user_id
            FROM memberships m1
            JOIN feeds f1 ON f1.id = m1.container_id AND f1.owner_id = ? AND f1.type = 'blog'
            JOIN feeds f2 ON f2.owner_id = m1.user_id AND f2.type = 'blog'
            JOIN memberships m2 ON m2.container_id = f2.id AND m2.user_id = ?
            WHERE m1.user_id != ?
            ",
            [$userId, $userId, $userId]
        );

        return array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $rows,
        )));
    }

    /**
     * User ids of all approved community members. Pending subscribers are
     * excluded for the same reason as in findMembers().
     *
     * @return list<int>
     */
    public function findMemberIds(int $containerId): array
    {
        $rows = $this->db->fetchAll(
            '
            SELECT user_id
            FROM memberships
            WHERE container_id = ? AND membership_role_id != ?
            ',
            [$containerId, self::ROLE_SUBSCRIBER_ID]
        );

        return array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $rows,
        )));
    }

    /**
     * The communities $userId belongs to, in any capacity - the mirror image
     * of findMembers()/findSubscribers(), which answer "who is in this
     * container" for one container; this answers "which containers is this
     * person in" for one person. Backs the profile page's "Subscriptions" tab (see
     * Modules\Profile\CommunityListService::getSubscriptions()).
     *
     * `roleLevel` carries the whole distinction the tab renders: 0 is a
     * pending join request on an approval community (the caller hasn't been
     * let in yet), 1 a plain member, 2 a moderator, 3 the owner. Deliberately
     * *not* filtered to any subset - unlike findMembers(), which excludes
     * pending subscribers because they don't belong on a community's public
     * roster, your own unanswered request is exactly the kind of thing you
     * want to see on your own subscriptions page.
     *
     * `memberCount` counts real members only (pending requests excluded, same
     * rule countMembers() applies), as a correlated subquery rather than a
     * second round trip per row - it's one number per card and the list is
     * capped well below the point where that matters.
     *
     * Ordered the same actionable-first spirit as findConnections(), except
     * here it's rank-by-standing: owner, moderator, member, then pending;
     * most recently joined first within each.
     *
     * Uses index: memberships_user_container (user_id, container_id) for the WHERE.
     * Uses index: PRIMARY(id) on feeds for the join, PRIMARY(id) on membership_roles.
     * Warning: no index for the ORDER BY - it spans two tables (feeds' joined
     * role_level, then memberships' own joined_at), so MariaDB filesorts every
     * one of this user's membership rows before $limit applies. Small N by
     * nature: it's one person's own memberships, not a public listing.
     * Warning: the memberCount subquery re-reads memberships per row through
     * PRIMARY(container_id, user_id); bounded by $limit, which is a small cap
     * (Modules\Profile\ProfileController::SUBSCRIPTIONS_LIST_LIMIT), not a page size.
     *
     * @return list<array{
     *     id:int, slug:?string, title:?string, imageUrl:?string,
     *     roleLevel:int, memberCount:int, joinedAt:int
     * }>
     */
    public function findUserCommunities(int $userId, int $limit): array
    {
        $limit = max(1, $limit);

        $rows = $this->db->fetchAll(
            "
            SELECT f.id, f.slug, f.title, f.image_url, mr.role_level, m.joined_at,
                   (
                       SELECT COUNT(*)
                       FROM memberships mc
                       WHERE mc.container_id = f.id AND mc.membership_role_id != ?
                   ) AS member_count
            FROM memberships m
            JOIN feeds f ON f.id = m.container_id AND f.type = 'community'
            JOIN membership_roles mr ON mr.id = m.membership_role_id
            WHERE m.user_id = ?
            ORDER BY mr.role_level DESC, m.joined_at DESC
            LIMIT $limit
            ",
            [self::ROLE_SUBSCRIBER_ID, $userId]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'imageUrl' => $row['image_url'],
            'roleLevel' => (int) $row['role_level'],
            'memberCount' => (int) $row['member_count'],
            'joinedAt' => (int) $row['joined_at'],
        ], $rows);
    }

    /**
     * Every user $userId has *any* personal-blog relationship with, in
     * either direction, with the direction(s) reported per row so the caller
     * can derive the same three states FriendService::getRelationshipStatus()
     * does - without running two existence queries per person:
     * `incoming` = that user has a row in $userId's blog (they asked first,
     * $userId hasn't reciprocated yet), `outgoing` = $userId has a row in
     * theirs. Both together = mutual, i.e. "friends" - see FriendService's
     * own docblock for why mutuality is derived rather than stored.
     *
     * findMutualFriends() is the both-directions-only subset of this and
     * stays a separate method rather than a filter over it, because the two
     * have different audiences: that one backs the *public* profile page and
     * must never leak either side's pending, one-directional requests, while
     * this one is only ever read for $userId by $userId themselves (see
     * Modules\Profile\ProfileController::handleFriendsRequest(), its only
     * caller, which takes no user in the path at all).
     *
     * Ordered actionable-first: incoming-only requests (the ones the owner
     * can accept with one click) before established friendships before
     * their own still-unanswered outgoing ones; most recent first within
     * each group. Community memberships never show up here - both halves
     * constrain the container to `type = 'blog'`, and a user's own blog is
     * excluded either way so a stray self-membership row can't list you as
     * your own friend.
     *
     * Uses index: owner_id (feeds.owner_id) for the incoming half's f, PRIMARY(container_id, user_id) for its m.
     * Uses index: memberships_user_container (user_id, container_id) for the outgoing half's m, PRIMARY(id) on feeds for its f.
     * Warning: no index for the outer GROUP BY/ORDER BY - the UNION derived table
     * is materialized and sorted in full before $limit applies, so cost scales
     * with how many people this user is connected to rather than with $limit.
     * Acceptable because it's a single self-scoped read behind a generous cap
     * (Modules\Profile\ProfileController::FRIENDS_LIST_LIMIT), not a public
     * listing.
     *
     * @return list<array{id:int, nick:string, username:?string, avatarUrl:string, incoming:bool, outgoing:bool}>
     */
    public function findConnections(int $userId, int $limit): array
    {
        $limit = max(1, $limit);

        $rows = $this->db->fetchAll(
            "
            SELECT u.id, u.nick, u.username, u.avatar_url,
                   MAX(rel.incoming) AS incoming,
                   MAX(rel.outgoing) AS outgoing
            FROM (
                SELECT m.user_id AS user_id, 1 AS incoming, 0 AS outgoing, m.joined_at AS joined_at
                FROM memberships m
                JOIN feeds f ON f.id = m.container_id AND f.owner_id = ? AND f.type = 'blog'
                WHERE m.user_id <> ?
                UNION ALL
                SELECT f.owner_id AS user_id, 0 AS incoming, 1 AS outgoing, m.joined_at AS joined_at
                FROM memberships m
                JOIN feeds f ON f.id = m.container_id AND f.type = 'blog' AND f.owner_id <> ?
                WHERE m.user_id = ?
            ) rel
            JOIN users u ON u.id = rel.user_id
            GROUP BY u.id, u.nick, u.username, u.avatar_url
            ORDER BY CASE
                         WHEN MAX(rel.outgoing) = 0 THEN 0
                         WHEN MAX(rel.incoming) = 1 THEN 1
                         ELSE 2
                     END ASC,
                     MAX(rel.joined_at) DESC
            LIMIT $limit
            ",
            [$userId, $userId, $userId, $userId]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'nick' => (string) $row['nick'],
            'username' => $row['username'],
            'avatarUrl' => (string) $row['avatar_url'],
            'incoming' => (bool) $row['incoming'],
            'outgoing' => (bool) $row['outgoing'],
        ], $rows);
    }

    /**
     * Uses index: owner_id (feeds.owner_id) for f1/f2, PRIMARY(container_id, user_id) for m1/m2.
     */
    public function countMutualFriends(int $userId): int
    {
        $row = $this->db->fetchOne(
            "
            SELECT COUNT(*) AS total
            FROM memberships m1
            JOIN feeds f1 ON f1.id = m1.container_id AND f1.owner_id = ? AND f1.type = 'blog'
            JOIN feeds f2 ON f2.owner_id = m1.user_id AND f2.type = 'blog'
            JOIN memberships m2 ON m2.container_id = f2.id AND m2.user_id = ?
            WHERE m1.user_id != ?
            ",
            [$userId, $userId, $userId]
        );

        return (int) ($row['total'] ?? 0);
    }
}
