<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use Random\RandomException;
use RuntimeException;
use StreamEngine\Core\FeedRepositoryInterface;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\User;

final class FeedRepository implements FeedRepositoryInterface
{
    private const int IN_CHUNK_SIZE = 1000;

    private const int MAX_DEPTH = 10;

    public function __construct(
        private readonly PdoDatabase $db
    ) {

    }

    /**
     * Gets a single Feed by its ID.
     * Checks ACL.
     * Uses index: PRIMARY(id)
     *
     * @param int $id
     * @param User $user
     * @return Feed|null
     */
    public function findById(int $id, User $user): ?Feed
    {
        $sql = $this->baseSelect();

        $condition = "f.id = ?\n";
        $params = [$id];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nLIMIT 1";

        return $this->fetchOne($sql, $params);
    }

    /**
     * A single feed owned by a specific user, of a specific type - e.g. a
     * user's personal blog container (owner_id + type='blog', at most one
     * per user by convention, not enforced in the DB). Checks ACL, though
     * the owner override in applyAcl() means the owner can always find
     * their own feed here regardless of its visibility.
     *
     * Uses index: owner_id (owner_id)
     */
    public function findByOwnerAndType(int $ownerId, string $type, User $user): ?Feed
    {
        $sql = $this->baseSelect();

        $condition = "f.owner_id = ?\nAND f.type = ?\n";
        $params = [$ownerId, $type];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nLIMIT 1";

        return $this->fetchOne($sql, $params);
    }

    /**
     * Gets a single Feed by its slug.
     * Checks ACL.
     * Uses index: feeds_slug_index(slug)
     *
     * @param string $slug
     * @param User $user
     * @return Feed[]
     */
    public function findBySlug(string $slug, User $user): array
    {
        $sql = $this->baseSelect();

        $condition = "f.slug = ?\n";
        $params = [$slug];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nLIMIT 1";

        return $this->fetch($sql, $params);
    }

    /**
     * Uses index: feeds_parent_id_slug_type_index (parent_id, slug, type)
     * Fallback: feeds_slug_index (slug) may be used when parent selectivity is poor.
     */
    public function findByParentAndSlug(?int $parentId, string $slug, User $user, ?string $type = null): ?Feed
    {
        $sql = $this->baseSelect();

        if ($parentId === null) {
            $condition = "f.parent_id IS NULL\nAND f.slug = ?\n";
            $params = [$slug];
        } else {
            $condition = "f.parent_id = ?\nAND f.slug = ?\n";
            $params = [$parentId, $slug];
        }

        if ($type !== null) {
            $condition .= "AND f.type = ?\n";
            $params[] = $type;
        }

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nLIMIT 1";

        return $this->fetchOne($sql, $params);
    }

    /**
     * Gets Feeds with of the certain parent by its id.
     * Checks ACL.
     * Uses index: feeds_parent_id_position_index (parent_id, position);
     *
     * @param int $parentId
     * @param User $user
     * @param int $limit
     * @return Feed[]
     */
    public function findByParent(int $parentId, User $user, int $limit = 20): array
    {
        $sql = $this->baseSelect();

        $condition = "f.parent_id = ?\n";
        $params = [$parentId];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nORDER BY position\nLIMIT $limit";

        return $this->fetch($sql, $params);
    }

    /**
     * Gets Feeds with of the certain parent and type.
     * Checks ACL.
     * Uses index: feeds_parent_id_type_position_index (parent_id, type, position);
     *
     * @param int|null $parentId
     * @param User $user
     * @param string $type
     * @param int $limit
     * @return Feed[]
     */
    public function findByParentAndType(?int $parentId, User $user, string $type, int $limit = 20): array
    {
        $sql = $this->baseSelect();

        if ($parentId === null) {
            $condition = "f.parent_id IS NULL\nAND f.type = ?\n";
            $params = [$type];
        } else {
            $condition = "f.parent_id = ?\nAND f.type = ?\n";
            $params = [$parentId, $type];
        }

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nORDER BY position\nLIMIT $limit";

        return $this->fetch($sql, $params);
    }

    /**
     * A specific container's own feeds of a type, paginated - e.g. a single
     * community page's own blog-post feed. Same ACL rule as findByParentAndType():
     * posts the viewer cannot read never appear in the page and never count
     * toward the total.
     * Uses index: feeds_parent_id_type_position_index (parent_id, type, position);
     *
     * @return array{items:list<Feed>, total:int}
     */
    public function findByParentAndTypePage(?int $parentId, string $type, User $user, int $limit, int $offset = 0): array
    {
        $sql = $this->baseSelect(", COUNT(*) OVER () AS total_count");

        if ($parentId === null) {
            $condition = "f.parent_id IS NULL\nAND f.type = ?\n";
            $params = [$type];
        } else {
            $condition = "f.parent_id = ?\nAND f.type = ?\n";
            $params = [$parentId, $type];
        }

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nORDER BY f.created_at DESC, f.id DESC\nLIMIT $limit OFFSET $offset";

        return $this->fetchWithTotal($sql, $params);
    }

    /**
     * Gets Feeds list for the public API's endpoint /api/v1/feeds.
     * Checks ACL.
     * Keep every branch tied to an index named inline below.
     *
     * @return Feed[]
     */
    public function list(
        User $user,
        int $limit = 20,
        int $offset = 0,
        ?int $id = null,
        ?string $slug = null,
        ?string $type = null,
        ?string $title = null,
        ?int $parentId = null,
        ?int $ownerId = null,
        ?string $sort = null
    ): array {
        $sql = $this->baseSelect();
        $condition = "";
        $params = [];
        $orderBy = "ORDER BY f.id DESC";

        if ($id !== null) {
            // Uses index: PRIMARY(id)
            $condition = "f.id = ?\n";
            $params = [$id];
        } elseif ($slug !== null) {
            // Uses index: feeds_slug_index(slug)
            $condition = "f.slug = ?\n";
            $params = [$slug];
        } elseif ($parentId !== null && $type !== null) {
            $condition = "f.parent_id = ?\nAND f.type = ?\n";
            $params = [$parentId, $type];

            if ($sort === 'created_desc') {
                // Uses index: feeds_parent_id_type_created_at_id_index(parent_id, type, created_at, id)
                $orderBy = "ORDER BY f.created_at DESC, f.id DESC";
            } else {
                // Uses index: feeds_parent_id_type_position_index(parent_id, type, position)
                $orderBy = "ORDER BY f.position";
            }
        } elseif ($parentId !== null) {
            // Uses index: feeds_parent_id_position_index(parent_id, position)
            $condition = "f.parent_id = ?\n";
            $params = [$parentId];
            $orderBy = "ORDER BY f.position";
        } elseif ($ownerId !== null) {
            // Uses index: owner_id(owner_id)
            $condition = "f.owner_id = ?\n";
            $params = [$ownerId];
        } elseif ($title !== null) {
            // Legacy title filter: no useful B-tree index for LIKE '%...%'.
            // Kept here so /api/v1/feeds has one list query builder; admin UI
            // leaves this disabled until it gets a supporting index/search path.
            $condition = "f.title LIKE ?\n";
            $params = ['%'.$this->escapeLike($title).'%'];

            if ($type !== null) {
                $condition .= "AND f.type = ?\n";
                $params[] = $type;
            }

            $orderBy = "ORDER BY f.created_at DESC, f.id DESC";
        } elseif ($type !== null) {
            $condition = "f.type = ?\n";
            $params = [$type];

            if ($sort === 'rating_desc') {
                // Uses index: feeds_type_rating_avg_id_index(type, rating_avg, id)
                $orderBy = "ORDER BY f.rating_avg DESC, f.id DESC";
            } else {
                // Uses index: feeds_type_created_at_id_index(type, created_at, id)
                $orderBy = "ORDER BY f.created_at DESC, f.id DESC";
            }
        } else {
            // Uses index: PRIMARY(id)
            $orderBy = "ORDER BY f.id DESC";
        }

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);

        $sql .= "\n$orderBy\nLIMIT $limit OFFSET $offset";

        return $this->fetch($sql, $params);
    }

    /**
     * Uses index: feeds_type_created_at_id_index (type asc, created_at desc, id desc)
     *
     * @return Feed[]
     */
    public function findByType(
        string $type,
        User $user,
        int $limit = 20,
        int $offset = 0
    ): array {
        $sql = $this->baseSelect();

        $condition = "f.type = ?\n";
        $params = [$type];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nORDER BY f.created_at DESC, f.id DESC\nLIMIT $limit OFFSET $offset";

        return $this->fetch($sql, $params);
    }

    /**
     * Top-rated feeds of a type, ranked by average rating.
     * Unrated feeds (rating_avg = 0) naturally sort last.
     * Checks ACL.
     * Uses index: feeds_type_rating_avg_id_index (type asc, rating_avg desc, id desc)
     *
     * @return Feed[]
     */
    public function findTopRatedByType(
        string $type,
        User $user,
        int $limit = 50
    ): array {
        $sql = $this->baseSelect();

        $condition = "f.type = ?\n";
        $params = [$type];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nORDER BY f.rating_avg DESC, f.id DESC\nLIMIT $limit";

        return $this->fetch($sql, $params);
    }

    /**
     * Gets several Feeds by their IDs, e.g. for a user's favorites list.
     * Checks ACL - unlike fetchByIds()/hydrateTree(), which are used for
     * internal URL/tree hydration and intentionally skip it.
     * Preserves the order of $ids (e.g. most-recently-favorited first);
     * a plain "IN (...)" query wouldn't otherwise guarantee that. IDs the
     * user can't access (or that no longer exist) are silently dropped.
     * Uses index: PRIMARY(id)
     *
     * @param int[] $ids
     * @return Feed[]
     */
    public function findByIds(array $ids, User $user, ?string $type = null): array
    {
        if ($ids === []) {
            return [];
        }

        $feedsById = [];

        foreach (array_chunk(array_unique($ids), self::IN_CHUNK_SIZE) as $chunk) {
            $sql = $this->baseSelect();

            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $condition = "f.id IN ($placeholders)\n";
            $params = $chunk;

            if ($type !== null) {
                $condition .= "AND f.type = ?\n";
                $params[] = $type;
            }

            $condition = $this->applyAcl($condition, $params, $user);

            $sql .= $this->where($condition);

            foreach ($this->fetch($sql, $params) as $feed) {
                $feedsById[$feed->id] = $feed;
            }
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($feedsById[$id])) {
                $ordered[] = $feedsById[$id];
            }
        }

        return $ordered;
    }

    /**
     * Resolves each (parent id, position) pair to the matching child's feed
     * id. Missing children are omitted; visibility is checked later when the
     * caller loads the returned ids through findByIds().
     * Uses index: feeds_parent_id_type_position_index (parent_id, type, position)
     *
     * @param array<int, int> $positionByParentId position keyed by parent id
     * @return array<int, int> child feed id keyed by parent id
     */
    public function findChildIdsAtPositions(array $positionByParentId, string $childType): array
    {
        if ($positionByParentId === []) {
            return [];
        }

        $result = [];

        foreach (array_chunk($positionByParentId, self::IN_CHUNK_SIZE, true) as $chunk) {
            $conditions = [];
            $params = [$childType];

            foreach ($chunk as $parentId => $position) {
                $conditions[] = '(parent_id = ? AND position = ?)';
                $params[] = $parentId;
                $params[] = $position;
            }

            $rows = $this->db->fetchAll(
                'SELECT id, parent_id FROM feeds WHERE type = ? AND ('.implode(' OR ', $conditions).')',
                $params
            );

            foreach ($rows as $row) {
                $result[(int) $row['parent_id']] = (int) $row['id'];
            }
        }

        return $result;
    }

    /**
     * Returns the highest child position for every requested parent. Parents
     * without children of the requested type are omitted.
     * Uses index: feeds_parent_id_type_position_index (parent_id, type, position)
     *
     * @param int[] $parentIds
     * @return array<int, int> last position keyed by parent id
     */
    public function findLastChildPositions(array $parentIds, string $childType): array
    {
        if ($parentIds === []) {
            return [];
        }

        $result = [];

        foreach (array_chunk(array_unique($parentIds), self::IN_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $rows = $this->db->fetchAll(
                "
                SELECT parent_id, MAX(position) AS last_position
                FROM feeds
                WHERE type = ? AND parent_id IN ($placeholders)
                GROUP BY parent_id
                ",
                [$childType, ...$chunk]
            );

            foreach ($rows as $row) {
                $result[(int) $row['parent_id']] = (int) $row['last_position'];
            }
        }

        return $result;
    }

    /**
     * Uses index: feed_terms_vocabulary_slug_index (vocabulary, slug)
     * Uses index: feed_term_links_term_position_index (term_id, position) for term-to-feed join.
     * Uses index: PRIMARY(id) on feeds after feed_term_links.feed_id lookup.
     * Warning: final ORDER BY f.created_at DESC, f.id DESC may require filesort for broad terms.
     *
     * @return Feed[]
     */
    public function findByTerm(
        string $vocabulary,
        string $slug,
        User $user,
        ?string $type = null,
        int $limit = 20,
        int $offset = 0
    ): array {
        $sql = $this->baseSelect()
            ."\nJOIN feed_term_links ftl ON ftl.feed_id = f.id"
            ."\nJOIN feed_terms ft ON ft.id = ftl.term_id";

        $condition = "ft.vocabulary = ?\nAND ft.slug = ?\n";
        $params = [$vocabulary, $slug];

        if ($type !== null) {
            $condition .= "AND f.type = ?\n";
            $params[] = $type;
        }

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nORDER BY f.created_at DESC, f.id DESC\nLIMIT $limit OFFSET $offset";

        return $this->fetch($sql, $params);
    }

    /**
     * Picks a single random feed of the given type.
     * Checks ACL.
     * Avoids "ORDER BY RAND()", which forces a full scan + filesort + temp table of every matching
     * row (measured ~1s on roughly 10k rows).
     * Also avoids running the full SELECT (with its LEFT JOIN to `users`) directly with
     * "LIMIT 1 OFFSET $n": MySQL applies OFFSET only after building each joined row, so that would
     * still execute the `users` lookup for every one of the $n skipped rows (measured ~600ms at
     * offset ~7125 on ~10k rows).
     * Instead: count matching rows once, then resolve just the id via a deferred lookup - for an
     * admin user (ACL condition "1=1") this is a pure covering-index walk over
     * feeds_type_created_at_id_index with no row or `users` lookups for the skipped rows - and only
     * then fetch the single full row (+ author fields) for that id.
     */
    public function findRandomByType(string $type, User $user): ?Feed
    {
        $condition = "f.type = ?\n";
        $params = [$type];

        $condition = $this->applyAcl($condition, $params, $user);
        $whereSql = $this->where($condition);

        $countRow = $this->db->fetchOne("SELECT COUNT(*) AS total FROM feeds f".$whereSql, $params);
        $total = (int) ($countRow['total'] ?? 0);

        if ($total === 0) {
            return null;
        }

        try {
            $offset = random_int(0, $total - 1);
        } catch (RandomException $e) {
            error_log($e->getMessage());
            throw new RuntimeException('Unable to generate random feed.');
        }

        $idRow = $this->db->fetchOne(
            "SELECT f.id FROM feeds f".$whereSql."\nORDER BY f.created_at DESC, f.id DESC\nLIMIT 1 OFFSET $offset",
            $params
        );

        if ($idRow === null) {
            return null;
        }

        $sql = $this->baseSelect()."\nWHERE f.id = ?";

        return $this->fetchOne($sql, [(int) $idRow['id']]);
    }

    /**
     * Uses index: feeds_type_created_at_id_index (type asc, created_at desc, id desc)
     */
    public function countByType(string $type, User $user): int
    {
        $sql = "
            SELECT COUNT(*) AS total
            FROM feeds f
        ";

        $condition = "f.type = ?\n";
        $params = [$type];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);

        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Site-wide feeds of a type, paginated, with an optional tag filter -
     * e.g. action=community.main's global blog-post feed and its per-tag
     * view (?tag=slug). Same ACL rule as every other listing method here:
     * a viewer without access to a private/members-only post never sees it
     * counted into $total either. The tag filter joins the same
     * feed_term_links/feed_terms tables as findByTerm(), just folded into
     * one COUNT(*) OVER()-based total query instead of a separate count
     * round-trip.
     * Uses index: feeds_type_created_at_id_index (type asc, created_at desc, id desc)
     * Uses index: feed_terms_vocabulary_slug_index (vocabulary, slug) when $tagSlug is set.
     *
     * @return array{items:list<Feed>, total:int}
     */
    public function findByTypePage(string $type, User $user, int $limit, int $offset = 0, ?string $tagSlug = null): array
    {
        $joins = '';
        if ($tagSlug !== null) {
            $joins = "\nJOIN feed_term_links ftl ON ftl.feed_id = f.id"
                ."\nJOIN feed_terms ft ON ft.id = ftl.term_id";
        }

        $sql = $this->baseSelect(", COUNT(*) OVER () AS total_count").$joins;

        $condition = "f.type = ?\n";
        $params = [$type];

        if ($tagSlug !== null) {
            $condition .= "AND ft.vocabulary = 'tag'\nAND ft.slug = ?\n";
            $params[] = $tagSlug;
        }

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nORDER BY f.created_at DESC, f.id DESC\nLIMIT $limit OFFSET $offset";

        return $this->fetchWithTotal($sql, $params);
    }

    /**
     * A specific user's own feeds of a type, paginated - e.g. the profile
     * page's "own blog posts" feed. Same ACL rule as every other listing
     * method: a viewer without access to a private/members-only post
     * simply never sees it counted into $total either, except the owner
     * themselves (or an admin), who always does.
     * Uses index: owner_id (owner_id)
     *
     * @return array{items:list<Feed>, total:int}
     */
    public function findByOwnerAndTypePage(int $ownerId, string $type, User $user, int $limit, int $offset = 0): array
    {
        $sql = $this->baseSelect(", COUNT(*) OVER () AS total_count");

        $condition = "f.owner_id = ?\nAND f.type = ?\n";
        $params = [$ownerId, $type];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nORDER BY f.created_at DESC, f.id DESC\nLIMIT $limit OFFSET $offset";

        return $this->fetchWithTotal($sql, $params);
    }

    /**
     * Total feeds of a type owned by a specific user - e.g. a public
     * profile's "posts in blog" or "comments written" stat. Same ACL rule
     * as countByType(), just owner-scoped too.
     * Uses index: owner_id (owner_id)
     */
    public function countByOwnerAndType(int $ownerId, string $type, User $user): int
    {
        $sql = "
            SELECT COUNT(*) AS total
            FROM feeds f
        ";

        $condition = "f.owner_id = ?\nAND f.type = ?\n";
        $params = [$ownerId, $type];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);

        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Aggregate rating across every feed of a type owned by a user - e.g.
     * the profile page's "Rating" card, folding every blog post's own
     * rating_sum/rating_count into one total rather than pulling every
     * post into PHP just to average them. Same ACL rule as
     * countByOwnerAndType(): a rating a viewer can't otherwise see doesn't
     * get folded into the total they see either.
     *
     * @return array{sum:int, count:int}
     */
    public function sumRatingByOwnerAndType(int $ownerId, string $type, User $user): array
    {
        $sql = "
            SELECT COALESCE(SUM(f.rating_sum), 0) AS total_sum, COALESCE(SUM(f.rating_count), 0) AS total_count
            FROM feeds f
        ";

        $condition = "f.owner_id = ?\nAND f.type = ?\n";
        $params = [$ownerId, $type];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);

        $row = $this->db->fetchOne($sql, $params);

        return [
            'sum' => (int) ($row['total_sum'] ?? 0),
            'count' => (int) ($row['total_count'] ?? 0),
        ];
    }

    /**
     * Aggregate rating across every feed of a type parented under a
     * container - the community sidebar's "Rating" card, folding every
     * community post's own rating_sum/rating_count into one total, same
     * shape/reasoning as sumRatingByOwnerAndType() just parent-scoped
     * instead of owner-scoped (a community's posts come from many
     * different owners, so owner_id can't identify "this community's
     * posts" - parent_id can, same column findByParentAndType() already
     * filters on for the community's own post list).
     *
     * @return array{sum:int, count:int}
     */
    public function sumRatingByParentAndType(int $parentId, string $type, User $user): array
    {
        $sql = "
            SELECT COALESCE(SUM(f.rating_sum), 0) AS total_sum, COALESCE(SUM(f.rating_count), 0) AS total_count
            FROM feeds f
        ";

        $condition = "f.parent_id = ?\nAND f.type = ?\n";
        $params = [$parentId, $type];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);

        $row = $this->db->fetchOne($sql, $params);

        return [
            'sum' => (int) ($row['total_sum'] ?? 0),
            'count' => (int) ($row['total_count'] ?? 0),
        ];
    }

    /**
     * Total feeds of a type parented under a container - the community
     * sidebar's "Statistics" card's "Community posts" row. Same
     * shape/reasoning as countByOwnerAndType(), just parent-scoped instead
     * of owner-scoped, same as sumRatingByParentAndType() vs
     * sumRatingByOwnerAndType().
     */
    public function countByParentAndType(int $parentId, string $type, User $user): int
    {
        $sql = "
            SELECT COUNT(*) AS total
            FROM feeds f
        ";

        $condition = "f.parent_id = ?\nAND f.type = ?\n";
        $params = [$parentId, $type];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);

        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Total comments left on any post parented under a container - the
     * community sidebar's "Statistics" card's "Comments" row. Two
     * levels deep (comment -> its post -> the community), unlike
     * countByParentAndType()'s direct parent_id match, since a comment's
     * own parent_id points at the post it's on, not at the community -
     * hence the subquery instead of a plain WHERE. Same ACL rule as
     * everywhere else here, applied to the comments themselves (alias
     * `f`): a comment a viewer can't otherwise see doesn't get counted.
     */
    public function countCommentsByParentContainer(int $parentId, User $user): int
    {
        $sql = "
            SELECT COUNT(*) AS total
            FROM feeds f
        ";

        $condition = "f.type = 'comment'\nAND f.parent_id IN (SELECT id FROM feeds WHERE parent_id = ? AND type = 'blog-post')\n";
        $params = [$parentId];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);

        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Root-comment count for a batch of parent feeds, e.g. the little
     * comment-bubble badge on each card of a blog feed listing. Comments
     * are always inserted with the default 'public' visibility (see
     * FeedService::createComment()), so unlike findCommentsPage()/
     * findCommentsByParents() this skips the ACL join entirely - a caller
     * batching this for a list of posts already only asked for posts the
     * viewer can see in the first place. Matches the same "direct children
     * only, replies to a comment don't count" scope as
     * findCommentsPage()'s own total_count.
     *
     * @param int[] $parentIds
     * @return array<int, int> comment count keyed by feed id
     */
    public function countCommentsForParents(array $parentIds): array
    {
        $parentIds = array_values(array_unique(array_map('intval', $parentIds)));

        if ($parentIds === []) {
            return [];
        }

        $result = [];

        foreach (array_chunk($parentIds, self::IN_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $rows = $this->db->fetchAll(
                "
                SELECT parent_id, COUNT(*) AS total
                FROM feeds
                WHERE type = 'comment' AND parent_id IN ($placeholders)
                GROUP BY parent_id
                ",
                $chunk
            );

            foreach ($rows as $row) {
                $result[(int) $row['parent_id']] = (int) $row['total'];
            }
        }

        return $result;
    }

    /**
     * Counts feeds per term for every term in a vocabulary, in a single grouped query.
     * Checks ACL.
     * Uses index: feed_terms_vocabulary_parent_name_unique (vocabulary, parent_key, name) for the vocabulary
     * scan - covering, so no lookup against the ft clustered index is needed.
     * Uses index: feed_term_links_term_position_index (term_id, position) for the term-to-feed join.
     * Warning: GROUP BY ft.id causes a filesort, since the vocabulary index above is ordered by name, not id.
     * Fine at the current scale (dozens of terms per vocabulary); revisit if a vocabulary grows into
     * the thousands.
     *
     * @return array<int, int> feed count keyed by term id
     */
    public function countGroupedByTermsAndVocabulary(string $vocabulary, User $user, ?string $type = null): array
    {
        $sql = "
            SELECT ft.id AS term_id, COUNT(DISTINCT f.id) AS total
            FROM feed_terms ft
            JOIN feed_term_links ftl ON ftl.term_id = ft.id
            JOIN feeds f ON f.id = ftl.feed_id
        ";

        $condition = "ft.vocabulary = ?\n";
        $params = [$vocabulary];

        if ($type !== null) {
            $condition .= "AND f.type = ?\n";
            $params[] = $type;
        }

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nGROUP BY ft.id";

        $rows = $this->db->fetchAll($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['term_id']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Top N terms of a vocabulary ranked by feed count. Same shape/ACL/type
     * filtering as countGroupedByTermsAndVocabulary() above, but the
     * ranking and LIMIT happen in SQL instead of pulling every term in the
     * vocabulary into PHP just to sort them - the fix for exactly the
     * "revisit if a vocabulary grows into the thousands" case noted above,
     * since author counts can realistically get that large.
     *
     * @return list<array{id: int|string, parent_id: int|string|null, vocabulary: string, name: string, slug: string, updated_at: int|string|null, total: int|string}>
     */
    public function findTopTermsByVocabularyAndFeedCount(string $vocabulary, User $user, ?string $type, int $limit): array
    {
        $sql = "
            SELECT ft.id, ft.parent_id, ft.vocabulary, ft.name, ft.slug, ft.updated_at, COUNT(DISTINCT f.id) AS total
            FROM feed_terms ft
            JOIN feed_term_links ftl ON ftl.term_id = ft.id
            JOIN feeds f ON f.id = ftl.feed_id
        ";

        $condition = "ft.vocabulary = ?\n";
        $params = [$vocabulary];

        if ($type !== null) {
            $condition .= "AND f.type = ?\n";
            $params[] = $type;
        }

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nGROUP BY ft.id";
        $sql .= "\nORDER BY total DESC, ft.name";
        $sql .= "\nLIMIT $limit";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Gets sibling feeds for the feed.
     * Checks ACL.
     * Uses index: feeds_parent_id_position_index (parent_id, position)
     * Range select.
     *
     * `$operator` and `$order` are interpolated into the SQL rather than
     * bound - they are the `<`/`>` and `DESC`/`ASC` literals
     * FeedService::getPrevNext() passes, and neither position can be
     * parameterised. Anything reaching them from a request would be
     * injection, so they have to stay caller-controlled constants.
     *
     * `$user` was declared `?User` until now, which promised something the
     * body cannot do: buildAclCondition() takes a non-nullable User, so a
     * null was a TypeError rather than an unfiltered read. Never reached -
     * the one caller always passes one - but a nullable type on the
     * parameter carrying the access check is the wrong thing to advertise.
     */
    public function findSibling(
        int $parentId,
        int $position,
        string $operator,
        string $order,
        User $user
    ): ?Feed {

        $sql = $this->baseSelect();
        $condition = "f.parent_id = ? AND f.position $operator ?";
        $params = [$parentId, $position];

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= " ORDER BY f.position $order LIMIT 1";

        return $this->fetchOne($sql, $params);
    }

    /**
     * Site search with exact title, title phrase, then weighted fulltext ranking.
     * Uses feeds_fulltext_title and feeds_fulltext_title_content; checks ACL.
     * The cursor follows the complete (priority, relevance, id) sort tuple.
     *
     * @return Feed[]
     */
    public function search(string $search, int $limit, ?object $cursor, User $user): array
    {
        $titleQuery = trim(preg_replace('/\\s+/u', ' ', $search) ?? $search);
        // title's case-insensitive collation also applies to equality/LOCATE.
        $title = "TRIM(REGEXP_REPLACE(COALESCE(f.title, ''), '[[:space:]]+', ' '))";
        $sql = $this->baseSelect(
            ", CASE WHEN $title = ? THEN 2
                    WHEN LOCATE(?, $title) > 0 THEN 1
                    ELSE 0 END AS search_priority,
               CAST(5 * MATCH(f.title) AGAINST (? IN BOOLEAN MODE)
                 + MATCH(f.title, f.content) AGAINST (? IN BOOLEAN MODE) AS DECIMAL(16, 6)) AS relevance"
        );
        // Exact titles remain eligible even when FULLTEXT ignores every token.
        $condition = "(MATCH(f.title, f.content) AGAINST (? IN BOOLEAN MODE) OR $title = ?)";
        $params = [$titleQuery, $titleQuery, $search, $search, $search, $titleQuery];
        $condition = $this->applyAcl($condition, $params, $user);
        $sql .= $this->where($condition);

        if ($cursor) {
            $sql .= ' HAVING (search_priority, relevance, id) < (?, ?, ?)';
            // Fixed precision avoids float round-trip drift repeating the last row.
            array_push($params, $cursor->priority, sprintf('%.6F', $cursor->rank), $cursor->id);
        }

        $sql .= " ORDER BY search_priority DESC, relevance DESC, id DESC LIMIT $limit";

        return $this->fetch($sql, $params);
    }

    /**
     * Find comments for the parent which can be a comment too.
     * Uses index: feeds_parent_id_type_created_at_id_index (parent_id asc, type asc, created_at desc, id desc)
     *
     * @param int $parentId
     * @param int $limit
     * @param object|null $cursor
     * @param User $user
     * @return Feed[]
     */
    public function findComments(int $parentId, int $limit, ?object $cursor, User $user): array
    {
        return $this->findCommentsPage($parentId, $limit, $cursor, $user)['items'];
    }

    /**
     * Find one paginated comment page for a specific parent and return the
     * total number of rows visible in the current cursor window.
     *
     * @return array{items:list<Feed>, total:int}
     */
    public function findCommentsPage(int $parentId, int $limit, ?object $cursor, User $user): array
    {
        $sql = $this->baseSelect(
            ", COUNT(*) OVER () AS total_count"
        );

        $condition = "f.parent_id = ?\nAND f.type = 'comment'\n";
        $params = [$parentId];

        if ($cursor) {
            $condition .= "AND (\n";
            $condition .= "  f.created_at < ?\n";
            $condition .= "  OR (f.created_at = ? AND f.id < ?)\n";
            $condition .= ")\n";
            array_push($params, $cursor->created_at, $cursor->created_at, $cursor->id);
        }

        $condition = $this->applyAcl($condition, $params, $user);

        $sql .= $this->where($condition);
        $sql .= "\nORDER BY f.created_at DESC, f.id DESC\nLIMIT $limit";

        return $this->fetchWithTotal($sql, $params);
    }

    /**
     * Fetch latest child comments for multiple parents in one query.
     * Uses index: feeds_parent_id_type_created_at_id_index (parent_id asc, type asc, created_at desc, id desc)
     *
     * @param int[] $parentIds
     * @return array<int, array{items:list<Feed>, total:int}>
     */
    public function findCommentsByParents(array $parentIds, int $limitPerParent, User $user): array
    {
        $parentIds = array_values(array_unique(array_map('intval', $parentIds)));

        if ($parentIds === [] || $limitPerParent <= 0) {
            return [];
        }

        $result = [];

        foreach (array_chunk($parentIds, self::IN_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $innerSql = $this->baseSelect(
                ", ROW_NUMBER() OVER (
                    PARTITION BY f.parent_id
                    ORDER BY f.created_at DESC, f.id DESC
                ) AS row_num,
                COUNT(*) OVER (
                    PARTITION BY f.parent_id
                ) AS total_count"
            );

            $condition = "f.parent_id IN ($placeholders)\nAND f.type = 'comment'\n";
            $params = $chunk;

            $condition = $this->applyAcl($condition, $params, $user);
            $innerSql .= $this->where($condition);

            $sql = "
                SELECT *
                FROM (
                    $innerSql
                ) ranked
                WHERE ranked.row_num <= ?
                ORDER BY ranked.parent_id, ranked.created_at DESC, ranked.id DESC
            ";

            $rows = $this->db->fetchAll($sql, [...$params, $limitPerParent]);

            foreach ($rows as $row) {
                $parentId = (int) $row['parent_id'];

                if (! isset($result[$parentId])) {
                    $result[$parentId] = [
                        'items' => [],
                        'total' => (int) $row['total_count'],
                    ];
                }

                $result[$parentId]['items'][] = Feed::fromRow($row);
            }
        }

        return $result;
    }

    /**
     * f.container_type was added for UrlGenerator's sake: Feed::$containerType
     * lets a caller (e.g. UsersController::showCommunityPostPage()) tell
     * "this post lives in a community" apart from any other container type
     * without an extra query.
     */
    private function baseSelect(string $extra = ''): string
    {
        return "
        SELECT
            f.id,
            f.parent_id,
            f.owner_id,
            f.type,
            f.slug,
            f.title,
            f.content,
            f.description,
            f.image_url,
            f.container_id,
            f.container_type,
            f.visibility,
            f.position,
            f.created_at,
            f.updated_at,
            f.rating_sum,
            f.rating_count,
            f.views,
            u.nick,
            u.avatar_url
            $extra
        FROM feeds f
        LEFT JOIN users u ON u.id = f.owner_id
    ";
    }

    private function applyAcl(string $condition, array &$params, User $user): string
    {
        [$aclSql, $aclParams] = $this->buildAclCondition($user);

        $params = array_merge($params, $aclParams);

        $condition = trim($condition);

        if ($condition === '') {
            return "($aclSql)";
        }

        return "$condition AND ($aclSql)";
    }

    private function buildAclCondition(User $user): array
    {
        // Admin override
        if ($user->isAdmin()) {
            return ['1=1', []];
        }

        $sql = "
        (
            f.owner_id = ?
            OR f.visibility = 'public'
            OR (
                f.container_id IS NOT NULL
                AND EXISTS (
                    SELECT 1
                    FROM memberships m
                    JOIN membership_roles mr
                      ON mr.id = m.membership_role_id
                    WHERE m.user_id = ?
                      AND m.container_id = f.container_id
                      AND (
                            f.visibility = 'members'
                            OR mr.role_level >= 2
                          )
                )
            )
        )
        ";

        return [
            $sql,
            [$user->id, $user->id],
        ];
    }

    private function where(string $condition): string
    {
        return " WHERE $condition ";
    }

    private function fetch(string $sql, array $params): array
    {
        $rows = $this->db->fetchAll($sql, $params);
        return $this->hydrate($rows);
    }

    private function fetchOne(string $sql, array $params): ?Feed
    {
        $row = $this->db->fetchOne($sql, $params);
        return $row ? Feed::fromRow($row) : null;
    }

    /**
     * @return array{items:list<Feed>, total:int}
     */
    private function fetchWithTotal(string $sql, array $params): array
    {
        $rows = $this->db->fetchAll($sql, $params);

        if ($rows === []) {
            return [
                'items' => [],
                'total' => 0,
            ];
        }

        return [
            'items' => $this->hydrate($rows),
            'total' => (int) $rows[0]['total_count'],
        ];
    }

    /**
     * Hard-deletes a feed row. `feeds` has no soft-delete column, so this is
     * final - but every FK that references feeds.id (feed_metadata,
     * feed_term_links, feed_ratings, feed_favorites, feed_reads,
     * memberships.container_id, and child feeds via parent_id - e.g.
     * comments on this post) is declared `ON DELETE CASCADE`
     * (initial_schema.sql), so this alone is enough to clean up everything
     * that hangs off the row.
     *
     * Uses index: PRIMARY(id)
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM feeds WHERE id = ?', [$id]);
    }

    /**
     * Bumps a feed's raw `views` counter by one - no per-viewer dedup, no
     * read-tracking involved (see Service\FeedService::recordView()'s own
     * docblock for why). A plain `UPDATE ... SET views = views + 1` rather
     * than read-then-write, so concurrent views on the same feed can't lose
     * an increment to a race.
     */
    public function incrementViews(int $id): void
    {
        $this->db->execute('UPDATE feeds SET views = views + 1 WHERE id = ?', [$id]);
    }

    /**
     * $containerId/$containerType (if given) point this feed at the
     * membership-checked container it lives inside - e.g. a community post
     * passes its community's own feed id/type here, so AccessService::
     * canAccessFeed()'s 'members'-visibility branch (which looks up
     * MembershipRepository::find($feed->containerId, ...)) can actually
     * restrict it to that container's members instead of degrading to
     * "owner/admin only" (containerId null). Personal blog posts pass their
     * own blog feed here only for 'members' visibility, so "Friends only"
     * resolves through the same memberships table. Left null for feed types
     * that don't nest inside a membership container (comments, articles, etc.)
     * - same as before this param existed.
     */
    public function insert(
        int $ownerId,
        string $title,
        ?string $slug,
        ?int $parentId,
        string $type,
        ?string $description,
        ?string $imageUrl,
        string $content,
        string $visibility = 'public',
        ?int $containerId = null,
        ?string $containerType = null,
    ): int {

        $sql = '
        INSERT INTO feeds
        (parent_id, owner_id, title, type, slug, content, description, image_url, visibility, container_id, container_type, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
    ';

        $this->db->execute($sql, [
            $parentId,
            $ownerId,
            $title,
            $type,
            $slug,
            $content,
            $description,
            $imageUrl,
            $visibility,
            $containerId,
            $containerType,
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * $visibility is optional (unlike insert()'s always-set column) because
     * the admin feed.show PATCH never sends it and shouldn't silently reset an
     * article back to 'public'. Passing a value (as BlogPostService::
     * updateBlogPost() does, to support publish/draft from the edit form) adds
     * `visibility = ?` to the SET clause instead of leaving the column
     * untouched.
     *
     * $containerId/$containerType are guarded by $updateContainer so callers
     * can deliberately clear them to NULL without every existing update path
     * accidentally doing the same. BlogPostService uses this when a personal
     * post moves into or out of 'members' visibility.
     *
     * Uses index: PRIMARY(id)
     */
    public function update(
        int $id,
        ?string $title,
        ?string $slug,
        ?int $parentId,
        string $type,
        ?string $description,
        ?string $imageUrl,
        ?string $content,
        ?string $visibility = null,
        ?int $containerId = null,
        ?string $containerType = null,
        bool $updateContainer = false,
    ): void {

        $sql = '
        UPDATE feeds
        SET
            title = ?,
            slug = ?,
            parent_id = ?,
            type = ?,
            description = ?,
            image_url = ?,
            content = ?'
            .($visibility !== null ? ',
            visibility = ?' : '')
            .($updateContainer ? ',
            container_id = ?,
            container_type = ?' : '').',
            updated_at = UNIX_TIMESTAMP()
        WHERE id = ?
    ';

        $params = [
            $title,
            $slug,
            $parentId,
            $type,
            $description,
            $imageUrl,
            $content,
        ];

        if ($visibility !== null) {
            $params[] = $visibility;
        }

        if ($updateContainer) {
            $params[] = $containerId;
            $params[] = $containerType;
        }

        $params[] = $id;

        $this->db->execute($sql, $params);
    }

    /**
     * @param array $rows
     * @return Feed[]
     */
    private function hydrate(array $rows): array
    {
        return array_map(
            static fn (array $row) => Feed::fromRow($row),
            $rows
        );
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, "\\%_");
    }

    /**
     * @param  int[]  $ids
     * @return array<int, Feed> map[id => Feed]
     */
    public function hydrateTree(array $ids): array
    {
        $allFeeds = [];
        $toLoad = array_unique($ids);
        $depth = 0;

        while (! empty($toLoad)) {

            if ($depth++ > self::MAX_DEPTH) {
                throw new RuntimeException('Feed tree depth exceeded');
            }

            $rows = $this->fetchByIds($toLoad);
            $toLoad = [];

            foreach ($rows as $row) {

                $id = (int) $row['id'];

                if (isset($allFeeds[$id])) {
                    continue;
                }

                $feed = Feed::fromRow($row);

                $allFeeds[$id] = $feed;

                if ($feed->parentId !== null
                    && ! isset($allFeeds[$feed->parentId])) {
                    $toLoad[] = $feed->parentId;
                }
            }
        }

        return $allFeeds;
    }

    /**
     *  Gets a several Feeds by their IDs for the UrlGenerator.
     *  Doesn't check ACL as soon url generator is out of the ACL's scope.
     *  Uses: PRIMARY(id) index
     *
     * @param  int[]  $ids
     * @return array[]
     */
    private function fetchByIds(array $ids): array
    {
        $result = [];

        foreach (array_chunk($ids, self::IN_CHUNK_SIZE) as $chunk) {

            if (empty($ids)) {
                return [];
            }

            $sql = $this->baseSelect();

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $condition = "f.id IN ($placeholders)\n";
            $params = $chunk;

            $sql .= $this->where($condition);

            $rows = $this->db->fetchAll($sql, $params);
            $result = array_merge($result, $rows);
        }

        return $result;
    }
}
