<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Users;

use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\User;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;

/** Photo workflows owned by Users; authorization follows blog post rules. */
class PhotoService
{
    public function __construct(
        private readonly FeedService $feeds,
        private readonly AccessService $access,
        private readonly CommunityService $communities,
        private readonly FeedRepository $repository,
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
        if ($description !== null && mb_strlen($description) > 2000) {
            throw new ValidationException('Album description must contain at most 2000 characters');
        }
        $visibility = $this->feeds->normalizeVisibility($visibility);

        // Match BlogPostService: communities always carry an access container;
        // personal blogs use it for members-only publications.
        $container = $context->type === 'community' || $visibility === 'members';

        return $this->feeds->createFeed(
            title: $title,
            slug: $this->albumSlug($contextId, $title),
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

    public function getAlbumBySlug(int $contextId, string $slug, User $user): Feed
    {
        $this->context($contextId, $user);
        $album = $this->feeds->getFeedByParentAndSlug($contextId, $slug, $user, 'gallery-album');
        if ($album === null && ctype_digit($slug)) {
            $legacy = $this->getAlbum($contextId, (int) $slug, $user);
            if ($legacy->slug === null || $legacy->slug === '') {
                return $legacy;
            }
        }
        if ($album === null || $album->type !== 'gallery-album' || $album->parentId !== $contextId) {
            throw new NotFoundException('Album not found');
        }

        return $album;
    }

    private function albumSlug(int $contextId, string $title): string
    {
        $maxLength = FeedService::MAX_SLUG_LENGTH;
        $base = Formatter::slugify($title, '-', $maxLength, 'album');
        $candidate = $base;
        $suffix = 1;
        while ($candidate === 'new-album' || $this->repository->hasSlugInParent($contextId, 'gallery-album', $candidate)) {
            $suffix++;
            $tail = '-'.$suffix;
            $candidate = rtrim(substr($base, 0, $maxLength - strlen($tail)), '-').$tail;
        }

        return $candidate;
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
