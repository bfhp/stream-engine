<?php

declare(strict_types=1);

namespace StreamEngine\Domain;

final class Feed
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly int $ownerId,
        public readonly string $type,
        public readonly ?string $slug,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $imageUrl,
        public readonly ?string $content,
        public readonly ?int $containerId,
        public readonly ?string $visibility,
        public readonly ?int $position,
        public readonly ?int $createdAt,
        private readonly ?float $relevance,
        public ?string $canonicalUrl,
        public readonly ?int $updatedAt = null,
        public readonly string $authorDisplayName = '',
        public readonly string $authorAvatarUrl = '',
        public array $metadata = [],
        public ?string $createdAtLabel = null,
        public ?string $createdAtTitle = null,
        public ?array $children = null,
        public readonly int $ratingSum = 0,
        public readonly int $ratingCount = 0,
        // Raw view counter (`feeds.views`) - incremented by
        // Service\FeedService::recordView(), currently only called from
        // Modules\Forums\ForumsController::showTopicViewPage(). Every view
        // counts, no per-viewer dedup (see recordView()'s own docblock).
        public readonly int $views = 0,
        // 0-100, how far the current user has progressed through this feed's
        // positioned children; null when progress was not requested.
        public ?int $readingProgress = null,
        // The container feed's own type ('community', 'blog', ...) - lets a
        // caller tell "this post lives in a community" (containerType ===
        // 'community') apart from any other container without a second
        // query, e.g. UsersController::showCommunityPostPage()'s own guard.
        public readonly ?string $containerType = null,
        private readonly int $searchPriority = 0,
    ) {

    }

    public function getRelevance(): float
    {
        return $this->relevance ?? 0.0;
    }

    public function getSearchPriority(): int
    {
        return $this->searchPriority;
    }

    public function ratingAverage(): float
    {
        return $this->ratingCount > 0
            ? $this->ratingSum / $this->ratingCount
            : 0.0;
    }

    /**
     * Resolves the display name shown for a feed's author: the user's own
     * nick, trimmed, falling back to "#<ownerId>" when they haven't set one
     * (or the feed's owner_id no longer joins to a user row).
     */
    private static function resolveAuthorDisplayName(array $row): string
    {
        $nick = trim((string) ($row['nick'] ?? ''));

        return $nick !== '' ? $nick : '#'.(int) $row['owner_id'];
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            parentId: $row['parent_id'] ? (int) $row['parent_id'] : null,
            ownerId: (int) $row['owner_id'],
            type: $row['type'],
            slug: $row['slug'] ?? null,
            title: $row['title'] ?? null,
            description: $row['description'] ?? null,
            imageUrl: $row['image_url'] ?? null,
            content: $row['content'] ?? null,
            containerId: $row['container_id'] ? (int) $row['container_id'] : null,
            visibility: $row['visibility'] ?? 'public',
            position: $row['position'] ?? 0,
            createdAt: $row['created_at'] ?? null,
            relevance: isset($row['relevance']) ? (float) $row['relevance'] : null,
            canonicalUrl: null,
            updatedAt: isset($row['updated_at']) ? (int) $row['updated_at'] : null,
            authorDisplayName: self::resolveAuthorDisplayName($row),
            // Raw author-avatar snapshot cached on the row (nick/avatar_url
            // joined in at query time - see FeedRepository::baseSelect()),
            // possibly empty. Falling back to a default image is a
            // presentation concern, not something this Domain object
            // decides - callers resolve it via Service\UserService::
            // resolveAvatarUrl() when building a view (see
            // UsersController::buildCommunityPostCards()/
            // showBlogPostPage()/showCommunityPostPage()).
            authorAvatarUrl: (string) ($row['avatar_url'] ?? ''),
            ratingSum: (int) ($row['rating_sum'] ?? 0),
            ratingCount: (int) ($row['rating_count'] ?? 0),
            views: (int) ($row['views'] ?? 0),
            containerType: $row['container_type'] ?? null,
            searchPriority: (int) ($row['search_priority'] ?? 0),
        );
    }
}
