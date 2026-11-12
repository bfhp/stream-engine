<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Users;

use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\User;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;

/** Photo workflows owned by Users; no public endpoints until the UI is ready. */
class PhotoService
{
    public function __construct(
        private readonly FeedService $feeds,
        private readonly AccessService $access,
        private readonly CommunityService $communities,
    ) {
    }

    public function createAlbum(int $contextId, User $user, string $title, ?string $description = null, string $visibility = 'public'): Feed
    {
        $context = $this->context($contextId, $user);
        if ($user->isGuest()
            || ($context->type === 'blog' && $context->ownerId !== $user->id)
            || ($context->type === 'community' && ! $this->communities->canPost($user, $context))) {
            throw new ForbiddenException('Cannot publish in this photo context');
        }

        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) {
            throw new ValidationException('Album title must contain 1–255 characters');
        }
        $visibility = $this->feeds->normalizeVisibility($visibility);

        // Match BlogPostService: communities always carry an access container;
        // personal blogs use it for members-only publications.
        $container = $context->type === 'community' || $visibility === 'members';

        return $this->feeds->createFeed(
            title: $title,
            slug: null,
            type: 'gallery-album',
            parentId: $context->id,
            description: $description,
            imageUrl: null,
            content: '',
            user: $user,
            metadata: null,
            visibility: $visibility,
            containerId: $container ? $context->id : null,
            containerType: $container ? $context->type : null,
        );
    }

    public function getAlbum(int $contextId, int $albumId, User $user): Feed
    {
        $this->context($contextId, $user);
        $album = $this->feeds->getFeedById($albumId, $user);
        if ($album === null || $album->type !== 'gallery-album' || $album->parentId !== $contextId) {
            throw new NotFoundException('Album not found');
        }

        return $album;
    }

    /** @return array{items: Feed[], total: int} */
    public function listAlbums(int $contextId, User $user, int $limit = 20, int $offset = 0): array
    {
        $this->context($contextId, $user);

        // Shared repository SQL applies the same access rules as blog posts
        // before counting and pagination, rather than filtering results in PHP.
        return $this->feeds->getFeedsByParentAndTypePage(
            $contextId,
            'gallery-album',
            $user,
            max(1, min(100, $limit)),
            max(0, $offset),
        );
    }

    public function canEditAlbum(int $contextId, int $albumId, User $user): bool
    {
        return $this->access->canEditFeed($user, $this->getAlbum($contextId, $albumId, $user));
    }

    private function context(int $id, User $user): Feed
    {
        $context = $this->feeds->getFeedById($id, $user);
        if ($context === null || ! in_array($context->type, ['blog', 'community'], true)) {
            throw new NotFoundException('Photo context not found');
        }

        return $context;
    }
}
