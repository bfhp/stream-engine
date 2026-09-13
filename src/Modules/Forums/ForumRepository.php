<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Forums;

use StreamEngine\Core\PdoDatabase;

/**
 * Forums-only read queries, built directly by ForumsController's own
 * constructor and never wired through ControllerFactory - same shape as
 * Modules\Users\BlogPostService/FriendService (see
 * docs/MODULE_CONTRACT.md's "where a new piece of logic belongs"). This
 * logic is useful to exactly one module, so it lives here instead of
 * bloating the shared FeedRepository/FeedService with forum vocabulary
 * ("topics", "posts") that every other module would otherwise have to
 * wade through.
 */
final class ForumRepository
{
    private const int IN_CHUNK_SIZE = 1000;

    public function __construct(
        private readonly PdoDatabase $db,
    ) {
    }

    /**
     * Uses index: feeds_parent_id_type_position_index (parent_id, type, position) on feeds t (WHERE t.parent_id IN (...) AND t.type = 'forum-post').
     * Uses index: feeds_parent_id_type_position_index (parent_id, type, position) on feeds c (LEFT JOIN ... ON c.parent_id = t.id AND c.type = 'comment').
     *
     * Topic/post counts for a batch of forums (subforums) - forums.list's
     * "Topics" / "Posts" columns. A "topic" is a type='forum-post' feed
     * parented directly under the forum; its "posts" count is that same
     * topic plus every 'comment' reply on it (the classic phpBB-style
     * convention - a topic's own opening message counts as a post too),
     * summed across every topic in the forum.
     *
     * No ACL/visibility filtering here: forum topics and their replies are
     * always public today - there's no membership-gated sub-forum feature,
     * unlike blog/community posts which use container_id for that. Same
     * reasoning FeedRepository::countCommentsForParents() documents for
     * skipping ACL on comments (always inserted 'public'), extended here to
     * topics too since Forums has no private-post path at all yet. If that
     * changes, this needs revisiting alongside whatever grants the access.
     *
     * @param int[] $forumIds
     * @return array<int, array{topics: int, posts: int}> keyed by forum id;
     *     forums with no topics are absent (caller should default to 0)
     */
    public function countTopicsAndPosts(array $forumIds): array
    {
        $forumIds = array_values(array_unique(array_map('intval', $forumIds)));

        if ($forumIds === []) {
            return [];
        }

        $result = [];

        foreach (array_chunk($forumIds, self::IN_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $rows = $this->db->fetchAll(
                "
                SELECT
                    t.parent_id AS forum_id,
                    COUNT(DISTINCT t.id) AS topics_total,
                    COUNT(c.id) AS replies_total
                FROM feeds t
                LEFT JOIN feeds c ON c.parent_id = t.id AND c.type = 'comment'
                WHERE t.type = 'forum-post' AND t.parent_id IN ($placeholders)
                GROUP BY t.parent_id
                ",
                $chunk
            );

            foreach ($rows as $row) {
                $topics = (int) $row['topics_total'];
                $replies = (int) $row['replies_total'];

                $result[(int) $row['forum_id']] = [
                    'topics' => $topics,
                    'posts' => $topics + $replies,
                ];
            }
        }

        return $result;
    }

    /**
     * Uses index: feeds_parent_id_type_position_index (parent_id, type, position) on feeds - both "topics of these forums" lookups (topic_activity's own WHERE and last_comment's inner subquery filter on parent_id IN (...) AND type = 'forum-post').
     * Uses index: feeds_parent_id_type_created_at_id_index (parent_id, type, created_at, id) on feeds c for last_comment's ROW_NUMBER() OVER (PARTITION BY c.parent_id ORDER BY c.created_at DESC, c.id DESC) - the index's own column and sort order match the partition/order exactly, so MariaDB can walk it without a filesort per topic.
     * Warning: no index for the final `ranked` CTE's ROW_NUMBER() OVER (PARTITION BY forum_id ORDER BY GREATEST(...) DESC, topic_id DESC) - it sorts an already-small, in-memory row set (at most one row per topic already fetched above for the requested forums), not a base-table scan, so this is an acceptable computed sort rather than a missing index.
     *
     * The single most recent activity per forum (subforum) - forums.list's
     * "last post" preview. "Activity" is whichever is newer: a topic being
     * created, or a reply landing on any topic in the forum (the usual
     * forum "bump" behaviour - a quiet topic that gets a fresh reply jumps
     * back to the top, same as everywhere else this kind of listing shows
     * up).
     *
     * No ACL/visibility filtering, same reasoning as countTopicsAndPosts()
     * above - forums have no private-post path today.
     *
     * @param int[] $forumIds
     * @return array<int, array{topicId: int, ownerId: int, createdAt: int}>
     *     keyed by forum id; forums with no topics are absent
     */
    public function findLastActivity(array $forumIds): array
    {
        $forumIds = array_values(array_unique(array_map('intval', $forumIds)));

        if ($forumIds === []) {
            return [];
        }

        $result = [];

        foreach (array_chunk($forumIds, self::IN_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            // 1. last_comment: the newest reply per topic (rn = 1), scoped
            //    to topics living in one of the requested forums.
            // 2. topic_activity: each of those topics paired with its own
            //    creation and its newest reply, if any.
            // 3. ranked: per topic, "last activity" is whichever is newer -
            //    its own creation or its newest reply - then ranked within
            //    its forum so rank 1 is that forum's single most-recently-
            //    active topic.
            $sql = "
                WITH last_comment AS (
                    SELECT
                        c.parent_id AS topic_id,
                        c.owner_id AS owner_id,
                        c.created_at AS created_at,
                        ROW_NUMBER() OVER (
                            PARTITION BY c.parent_id
                            ORDER BY c.created_at DESC, c.id DESC
                        ) AS rn
                    FROM feeds c
                    WHERE c.type = 'comment'
                      AND c.parent_id IN (
                          SELECT id FROM feeds WHERE type = 'forum-post' AND parent_id IN ($placeholders)
                      )
                ),
                topic_activity AS (
                    SELECT
                        t.id AS topic_id,
                        t.parent_id AS forum_id,
                        t.owner_id AS topic_owner_id,
                        t.created_at AS topic_created_at,
                        lc.owner_id AS last_comment_owner_id,
                        lc.created_at AS last_comment_created_at
                    FROM feeds t
                    LEFT JOIN last_comment lc ON lc.topic_id = t.id AND lc.rn = 1
                    WHERE t.type = 'forum-post' AND t.parent_id IN ($placeholders)
                ),
                ranked AS (
                    SELECT
                        topic_id,
                        forum_id,
                        CASE
                            WHEN last_comment_created_at IS NOT NULL
                                 AND last_comment_created_at >= topic_created_at
                                THEN last_comment_owner_id
                            ELSE topic_owner_id
                        END AS last_owner_id,
                        GREATEST(
                            topic_created_at,
                            COALESCE(last_comment_created_at, topic_created_at)
                        ) AS last_activity_at,
                        ROW_NUMBER() OVER (
                            PARTITION BY forum_id
                            ORDER BY GREATEST(
                                topic_created_at,
                                COALESCE(last_comment_created_at, topic_created_at)
                            ) DESC, topic_id DESC
                        ) AS forum_rank
                    FROM topic_activity
                )
                SELECT topic_id, forum_id, last_owner_id, last_activity_at
                FROM ranked
                WHERE forum_rank = 1
            ";

            // $chunk appears twice: once for last_comment's inner subquery,
            // once for topic_activity's own WHERE.
            $rows = $this->db->fetchAll($sql, array_merge($chunk, $chunk));

            foreach ($rows as $row) {
                $result[(int) $row['forum_id']] = [
                    'topicId' => (int) $row['topic_id'],
                    'ownerId' => (int) $row['last_owner_id'],
                    'createdAt' => (int) $row['last_activity_at'],
                ];
            }
        }

        return $result;
    }

    /**
     * Uses index: feeds_parent_id_type_position_index (parent_id, type, position) on feeds - the initial COUNT(*) and every "topics of this forum" filter (t's own WHERE, and both last_comment's/reply_counts' inner subqueries all filter on parent_id = ? AND type = 'forum-post').
     * Uses index: feeds_parent_id_type_created_at_id_index (parent_id, type, created_at, id) on feeds c for last_comment's ROW_NUMBER() OVER (PARTITION BY c.parent_id ORDER BY c.created_at DESC, c.id DESC) - column and sort order match exactly, no filesort per topic.
     * Warning: no index for the outer `ORDER BY last_activity_at DESC, t.id DESC` - last_activity_at is GREATEST(t.created_at, lc.created_at), a computed value no index can back. MariaDB filesorts the matching rows before applying LIMIT/OFFSET, but that set is already filtered down to "topics in this one forum" (the same count countTopicsAndPosts() reports - typically low hundreds at most), not the whole feeds table, so this is acceptable for now. Offset pagination itself can also degrade on very deep pages, same as every other offset-paginated list in this codebase. If a single forum's topic count grows large enough for either to matter, the fix is a materialized "topic last-activity" column with its own index, not something patchable here.
     *
     * One forum's topic list (forums.topic-list page), paged and ordered by
     * "last activity" - the same bump rule findLastActivity() uses above
     * (own creation, or newest reply, whichever is newer), just scoped to a
     * single forum and windowed with LIMIT/OFFSET instead of ranked top-1
     * per forum. Each topic comes back with its reply count (comment
     * children - matches countTopicsAndPosts()'s "topics/posts" split: this
     * is the "replies" half, not topics+replies) and who/when it was last
     * bumped by, so the caller can build the same "last post" preview
     * forums.list shows per-forum, but per-topic here.
     *
     * No ACL/visibility filtering, same reasoning as the rest of this class
     * - forum topics/replies are always public today.
     *
     * @return array{
     *     topicIds: int[],
     *     stats: array<int, array{replyCount: int, lastOwnerId: int, lastActivityAt: int}>,
     *     total: int
     * }
     */
    public function findTopicsPage(int $forumId, int $limit, int $offset): array
    {
        $total = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS total FROM feeds WHERE type = 'forum-post' AND parent_id = ?",
            [$forumId]
        )['total'] ?? 0);

        if ($total === 0) {
            return ['topicIds' => [], 'stats' => [], 'total' => 0];
        }

        $sql = "
            WITH last_comment AS (
                SELECT
                    c.parent_id AS topic_id,
                    c.owner_id AS owner_id,
                    c.created_at AS created_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY c.parent_id
                        ORDER BY c.created_at DESC, c.id DESC
                    ) AS rn
                FROM feeds c
                WHERE c.type = 'comment'
                  AND c.parent_id IN (SELECT id FROM feeds WHERE type = 'forum-post' AND parent_id = ?)
            ),
            reply_counts AS (
                SELECT c.parent_id AS topic_id, COUNT(*) AS reply_count
                FROM feeds c
                WHERE c.type = 'comment'
                  AND c.parent_id IN (SELECT id FROM feeds WHERE type = 'forum-post' AND parent_id = ?)
                GROUP BY c.parent_id
            )
            SELECT
                t.id AS topic_id,
                COALESCE(rc.reply_count, 0) AS reply_count,
                CASE
                    WHEN lc.created_at IS NOT NULL AND lc.created_at >= t.created_at
                        THEN lc.owner_id
                    ELSE t.owner_id
                END AS last_owner_id,
                GREATEST(t.created_at, COALESCE(lc.created_at, t.created_at)) AS last_activity_at
            FROM feeds t
            LEFT JOIN last_comment lc ON lc.topic_id = t.id AND lc.rn = 1
            LEFT JOIN reply_counts rc ON rc.topic_id = t.id
            WHERE t.type = 'forum-post' AND t.parent_id = ?
            ORDER BY last_activity_at DESC, t.id DESC
            LIMIT $limit OFFSET $offset
        ";

        $rows = $this->db->fetchAll($sql, [$forumId, $forumId, $forumId]);

        $topicIds = [];
        $stats = [];

        foreach ($rows as $row) {
            $topicId = (int) $row['topic_id'];
            $topicIds[] = $topicId;
            $stats[$topicId] = [
                'replyCount' => (int) $row['reply_count'],
                'lastOwnerId' => (int) $row['last_owner_id'],
                'lastActivityAt' => (int) $row['last_activity_at'],
            ];
        }

        return ['topicIds' => $topicIds, 'stats' => $stats, 'total' => $total];
    }

    /**
     * Uses index: feeds_type_created_at_id_index (type, created_at, id) on feeds - the 'forum-post' count (type equality + created_at range, id covered - index-only scan) when $forumId is null; scoped to one or more forums, this becomes a (parent_id, created_at) filter with no composite index covering both, so MariaDB will use whichever of feeds_parent_id_type_position_index / feeds_type_created_at_id_index it estimates cheaper and re-check the other predicate - fine at "one section's (or one category's handful of subforums') topics created today" volumes, revisit if this ever needs to scale past that.
     * Uses index: feeds_type_created_at_id_index (type, created_at, id) on feeds - the outer 'comment' count, same as above, scoped or not (the EXISTS subquery is what scopes it, not this index).
     * Uses index: PRIMARY(id) on feeds for the EXISTS subquery's `t.id = c.parent_id` lookup - a single-row primary-key fetch per candidate comment; the subquery's own `t.type = 'forum-post'` (and `t.parent_id IN (...)` when scoped) checks run against that one already-fetched row, not a separate indexed filter.
     *
     * Count of forum activity (topics created + replies posted) since a
     * given timestamp - forums.list's site-wide "Forum statistics" card's
     * "posts in the last day" row (caller passes `time() - 86400`, $forumId
     * null); forums.topic-list's own per-section version of the same stat
     * (a single forum id, scoped to just that forum's topics/replies); and
     * a "base" section's (one with subforums, no topics of its own)
     * aggregate version (an array of every subforum id, summed across all
     * of them in one query rather than one call per subforum).
     * Same topics+replies "posts" definition as countTopicsAndPosts(),
     * just time-windowed instead of all-time.
     *
     * Two queries rather than one: 'comment' isn't forum-only (other feed
     * content uses the same type), so a plain `type IN ('forum-post',
     * 'comment')` count would double-count other modules' comments too -
     * the second query's EXISTS keeps replies scoped to comments whose
     * parent is actually a topic (and, when scoped, a topic of one of the
     * given forums).
     *
     * No chunking of a multi-forum $forumId - unlike countTopicsAndPosts()/
     * findLastActivity(), this collapses straight to one summed total, so
     * there's no per-id breakdown to chunk-and-merge safely. Callers here
     * only ever pass one category's own subforum ids (a handful, not
     * thousands), so a single IN(...) list is fine; revisit if that stops
     * being true.
     *
     * No ACL filtering, same reasoning as the rest of this class.
     *
     * @param int|int[]|null $forumId
     */
    public function countPostsSince(int $sinceTimestamp, int|array|null $forumId = null): int
    {
        $forumIds = match (true) {
            $forumId === null => null,
            is_array($forumId) => array_values(array_unique(array_map('intval', $forumId))),
            default => [$forumId],
        };

        if ($forumIds !== null && $forumIds === []) {
            return 0;
        }

        $topicsSql = "
            SELECT COUNT(*) AS total
            FROM feeds
            WHERE type = 'forum-post' AND created_at >= ?
        ";
        $topicsParams = [$sinceTimestamp];

        if ($forumIds !== null) {
            $placeholders = implode(', ', array_fill(0, count($forumIds), '?'));
            $topicsSql .= " AND parent_id IN ($placeholders)";
            $topicsParams = array_merge($topicsParams, $forumIds);
        }

        $topics = $this->db->fetchOne($topicsSql, $topicsParams);

        $repliesSql = "
            SELECT COUNT(*) AS total
            FROM feeds c
            WHERE c.type = 'comment'
              AND c.created_at >= ?
              AND EXISTS (
                  SELECT 1 FROM feeds t WHERE t.id = c.parent_id AND t.type = 'forum-post'
        ";
        $repliesParams = [$sinceTimestamp];

        if ($forumIds !== null) {
            $placeholders = implode(', ', array_fill(0, count($forumIds), '?'));
            $repliesSql .= " AND t.parent_id IN ($placeholders)";
            $repliesParams = array_merge($repliesParams, $forumIds);
        }

        $repliesSql .= '
              )
        ';

        $replies = $this->db->fetchOne($repliesSql, $repliesParams);

        return (int) ($topics['total'] ?? 0) + (int) ($replies['total'] ?? 0);
    }

    /**
     * Uses index: feeds_parent_id_type_position_index (parent_id, type, position) on feeds - both the outer topics filter and the inner "topics of these forums" subquery (parent_id IN (...) AND type = 'forum-post').
     * Warning: no index for the outer `COUNT(DISTINCT owner_id)` itself - it dedupes over a derived table (topic owners UNION ALL reply owners), which MariaDB can't serve from an index; the two halves feeding it are each indexed and bounded to a handful of forums' topics/replies, so this is a small in-memory dedup, not a table scan.
     *
     * Distinct participant count for one or more forums - forums.topic-
     * list's "Section statistics" card's "participants posted here" row. A
     * "participant" is anyone who either started a topic or replied to one
     * in the given forum(s); UNION ALL rather than a join keeps the two
     * owner sources (topic authors, reply authors) independent instead of
     * requiring a topic to have both to count either.
     *
     * A single forum id is the leaf-section case (this forum's own
     * participants); an array is a "base" section's (one with subforums,
     * no topics of its own) aggregate case - every subforum's participants
     * deduped together in one query, not summed per-subforum (which would
     * double-count anyone active in more than one). No chunking, same
     * reasoning as countPostsSince() above.
     *
     * No ACL filtering, same reasoning as the rest of this class.
     *
     * @param int|int[] $forumId
     */
    public function countParticipants(int|array $forumId): int
    {
        $forumIds = array_values(array_unique(array_map('intval', (array) $forumId)));

        if ($forumIds === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($forumIds), '?'));

        $row = $this->db->fetchOne(
            "
            SELECT COUNT(DISTINCT owner_id) AS total
            FROM (
                SELECT owner_id FROM feeds WHERE type = 'forum-post' AND parent_id IN ($placeholders)
                UNION ALL
                SELECT c.owner_id
                FROM feeds c
                WHERE c.type = 'comment'
                  AND c.parent_id IN (SELECT id FROM feeds WHERE type = 'forum-post' AND parent_id IN ($placeholders))
            ) participants
            ",
            array_merge($forumIds, $forumIds)
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Uses index: feeds_parent_id_type_position_index (parent_id, type, position) on feeds - COUNT(*) filtered on parent_id = ? AND type = 'comment' is served index-only.
     *
     * Reply count for a single topic - forums.topic-view's "N posts"
     * header stat (that page's own total post count is this plus the
     * topic's own opening post, i.e. 1 + this) and its pagination math
     * (see ForumsController::showTopicViewPage()'s own docblock for how the
     * opening post is folded into the same page-size window as replies).
     *
     * No ACL filtering, same reasoning as the rest of this class.
     */
    public function countReplies(int $topicId): int
    {
        return (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS total FROM feeds WHERE type = 'comment' AND parent_id = ?",
            [$topicId]
        )['total'] ?? 0);
    }

    /**
     * Uses index: feeds_parent_id_type_created_at_id_index (parent_id, type, created_at, id) on feeds - parent_id/type equality plus the exact (created_at, id) sort order this query asks for (ASC here instead of the DESC most other callers in this class use, but a B-tree index serves either direction without a filesort).
     *
     * One page of a topic's replies (forums.topic-view), oldest first -
     * classic forum post ordering (#2, #3, ... following the topic's own
     * opening post as #1), unlike forums.topic-list's topics which sort by
     * last activity. $limit/$offset are in "replies" units; the caller
     * (ForumsController::showTopicViewPage()) is responsible for folding the
     * topic's own opening post into page 1 of the combined post numbering.
     *
     * No ACL filtering, same reasoning as the rest of this class.
     *
     * @return int[] comment feed ids, oldest first
     */
    public function findTopicPostsPage(int $topicId, int $limit, int $offset): array
    {
        if ($limit <= 0) {
            return [];
        }

        $rows = $this->db->fetchAll(
            "
            SELECT id
            FROM feeds
            WHERE type = 'comment' AND parent_id = ?
            ORDER BY created_at ASC, id ASC
            LIMIT $limit OFFSET $offset
            ",
            [$topicId]
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Uses index: feeds_parent_id_type_position_index (parent_id, type, position) on feeds - both the topic-owner literal row (PRIMARY(id) actually, see below) and the reply half's parent_id = ? AND type = 'comment' filter.
     * Uses index: PRIMARY(id) on feeds for the topic-owner half's WHERE id = ?.
     * Warning: no index for the outer GROUP BY owner_id / ORDER BY first_at / COUNT(*) OVER() - it aggregates an already-small derived set (one row per topic + one per reply, at most the topic's own reply count), not a base-table scan.
     *
     * Distinct participants of a single topic (forums.topic-view's "Members
     * topic" avatar row), ordered by when each first posted (topic owner
     * first, by construction - their post always has the earliest
     * created_at), capped at $limit with the true total returned alongside
     * so the caller can render a "+N" overflow badge - same shape as
     * findLastActivity()'s per-forum single row, just per-topic and
     * returning several ids instead of one.
     *
     * No ACL filtering, same reasoning as the rest of this class.
     *
     * @return array{ids: int[], total: int}
     */
    public function findTopicParticipants(int $topicId, int $limit): array
    {
        if ($limit <= 0) {
            return ['ids' => [], 'total' => 0];
        }

        $rows = $this->db->fetchAll(
            "
            SELECT owner_id, COUNT(*) OVER () AS total_count
            FROM (
                SELECT owner_id, MIN(created_at) AS first_at
                FROM (
                    SELECT owner_id, created_at FROM feeds WHERE id = ?
                    UNION ALL
                    SELECT owner_id, created_at FROM feeds WHERE type = 'comment' AND parent_id = ?
                ) posts
                GROUP BY owner_id
            ) participants
            ORDER BY first_at ASC
            LIMIT $limit
            ",
            [$topicId, $topicId]
        );

        if ($rows === []) {
            return ['ids' => [], 'total' => 0];
        }

        return [
            'ids' => array_map(static fn (array $row): int => (int) $row['owner_id'], $rows),
            'total' => (int) $rows[0]['total_count'],
        ];
    }

    /**
     * Uses index: feeds_type_created_at_id_index (type, created_at, id) on feeds - the topic-authored half (type = 'forum-post' AND owner_id IN (...)) is a type-equality scan re-checking owner_id; there's no (type, owner_id) composite today, same gap countPostsSince()'s own docblock already notes.
     * Uses index: PRIMARY(id) on feeds for the reply half's EXISTS subquery (`t.id = c.parent_id`), same single-row lookup countPostsSince() uses to keep 'comment' scoped to actual forum topics.
     *
     * Total forum posts (topics started + replies) per user, batched - the
     * "Posts: N" stat next to each post's author in forums.topic-view.
     * Same topics+replies definition and the same EXISTS-scoped 'comment'
     * filter countPostsSince()/countTopicsAndPosts() already use (a plain
     * `type IN ('forum-post', 'comment')` count would double-count a user's
     * other feed comments as if they were forum posts).
     *
     * No ACL filtering, same reasoning as the rest of this class.
     *
     * @param int[] $userIds
     * @return array<int, int> keyed by user id; users with no forum posts are absent
     */
    public function countUserForumPosts(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds === []) {
            return [];
        }

        $result = [];

        foreach (array_chunk($userIds, self::IN_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $rows = $this->db->fetchAll(
                "
                SELECT owner_id, COUNT(*) AS total
                FROM (
                    SELECT owner_id FROM feeds WHERE type = 'forum-post' AND owner_id IN ($placeholders)
                    UNION ALL
                    SELECT c.owner_id
                    FROM feeds c
                    WHERE c.type = 'comment'
                      AND c.owner_id IN ($placeholders)
                      AND EXISTS (SELECT 1 FROM feeds t WHERE t.id = c.parent_id AND t.type = 'forum-post')
                ) posts
                GROUP BY owner_id
                ",
                array_merge($chunk, $chunk)
            );

            foreach ($rows as $row) {
                $result[(int) $row['owner_id']] = (int) $row['total'];
            }
        }

        return $result;
    }

    /**
     * Uses index: feeds_parent_id_type_position_index (parent_id, type, position) on feeds - both the outer topics filter and the inner "topics of these forums" subquery (parent_id IN (...) AND type = 'forum-post').
     * Uses index: feeds_parent_id_type_created_at_id_index (parent_id, type, created_at, id) on feeds c for last_comment's ROW_NUMBER() OVER (PARTITION BY c.parent_id ORDER BY c.created_at DESC, c.id DESC) - column and sort order match exactly, no filesort per topic.
     *
     * Every topic's own last-activity timestamp (same "own creation, or
     * newest reply, whichever is newer" rule findLastActivity()/
     * findTopicsPage() already use), batched across a set of forums -
     * unlike findLastActivity() above, which ranks down to just the single
     * most-recent topic per forum (enough for a "last post" preview), this
     * returns every topic so a caller can count how many are actually
     * unread, not just check the newest one.
     *
     * No ACL filtering, same reasoning as the rest of this class.
     *
     * @param int[] $forumIds
     * @return array<int, array{topicId: int, forumId: int, lastActivityAt: int}>
     */
    public function findTopicActivity(array $forumIds): array
    {
        $forumIds = array_values(array_unique(array_map('intval', $forumIds)));

        if ($forumIds === []) {
            return [];
        }

        $result = [];

        foreach (array_chunk($forumIds, self::IN_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $sql = "
                WITH last_comment AS (
                    SELECT
                        c.parent_id AS topic_id,
                        c.created_at AS created_at,
                        ROW_NUMBER() OVER (
                            PARTITION BY c.parent_id
                            ORDER BY c.created_at DESC, c.id DESC
                        ) AS rn
                    FROM feeds c
                    WHERE c.type = 'comment'
                      AND c.parent_id IN (
                          SELECT id FROM feeds WHERE type = 'forum-post' AND parent_id IN ($placeholders)
                      )
                )
                SELECT
                    t.id AS topic_id,
                    t.parent_id AS forum_id,
                    GREATEST(t.created_at, COALESCE(lc.created_at, t.created_at)) AS last_activity_at
                FROM feeds t
                LEFT JOIN last_comment lc ON lc.topic_id = t.id AND lc.rn = 1
                WHERE t.type = 'forum-post' AND t.parent_id IN ($placeholders)
            ";

            // $chunk appears twice: once for last_comment's inner subquery,
            // once for the outer topics filter.
            $rows = $this->db->fetchAll($sql, array_merge($chunk, $chunk));

            foreach ($rows as $row) {
                $result[] = [
                    'topicId' => (int) $row['topic_id'],
                    'forumId' => (int) $row['forum_id'],
                    'lastActivityAt' => (int) $row['last_activity_at'],
                ];
            }
        }

        return $result;
    }

    /**
     * Uses index: feeds_parent_id_type_position_index (parent_id, type, position) on feeds - parent_id IN (...) AND type = 'forum-post' is served index-only.
     *
     * Every topic id directly under a given set of forums - the topic-id
     * source for "mark as read" scoped to one forum/subforum
     * (ForumsController's own mark-all-read endpoint passes that forum's own
     * id plus its subforums' ids, since a top-level forum's subforums should
     * count as part of "this section" for that action - see the endpoint's
     * own docblock).
     *
     * No ACL filtering, same reasoning as the rest of this class - forum
     * topics are always public today.
     *
     * @param int[] $forumIds
     * @return int[] forum-post ids, unordered
     */
    public function findTopicIdsForForums(array $forumIds): array
    {
        $forumIds = array_values(array_unique(array_map('intval', $forumIds)));

        if ($forumIds === []) {
            return [];
        }

        $topicIds = [];

        foreach (array_chunk($forumIds, self::IN_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $rows = $this->db->fetchAll(
                "SELECT id FROM feeds WHERE type = 'forum-post' AND parent_id IN ($placeholders)",
                $chunk
            );

            foreach ($rows as $row) {
                $topicIds[] = (int) $row['id'];
            }
        }

        return $topicIds;
    }

    /**
     * Uses index: feeds_type_created_at_id_index (type, created_at, id) on feeds - type = 'forum-post' is an index-only scan, id already covered.
     *
     * Every topic id across every forum and subforum - the topic-id source
     * for "mark the whole forum read" (no specific forum to scope to).
     *
     * No ACL filtering, same reasoning as the rest of this class.
     *
     * @return int[] forum-post ids, unordered
     */
    public function findAllTopicIds(): array
    {
        $rows = $this->db->fetchAll("SELECT id FROM feeds WHERE type = 'forum-post'");

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
