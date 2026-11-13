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

    public function updateAlbum(int $contextId, int $albumId, User $user, array $data): Feed
    {
        return $this->repository->withLockedFeed($albumId, function () use ($contextId, $albumId, $user, $data): Feed {
            $album = $this->getAlbum($contextId, $albumId, $user);
            if ($user->isGuest() || ! $this->access->canEditFeed($user, $album)) {
                throw new ForbiddenException('Cannot edit album');
            }
            if (array_diff(array_keys($data), ['title', 'description', 'visibility']) !== []) {
                throw new ValidationException('Unsupported album fields');
            }
            if (array_key_exists('title', $data)) {
                if (! is_string($data['title']) || trim($data['title']) === '' || mb_strlen(trim($data['title'])) > 255) {
                    throw new ValidationException('Album title must contain 1–255 characters');
                }
                $data['title'] = trim($data['title']);
            }
            if (array_key_exists('description', $data) && $data['description'] !== null
                && (! is_string($data['description']) || mb_strlen($data['description']) > 2000)) {
                throw new ValidationException('Invalid album description');
            }
            if (array_key_exists('visibility', $data)) {
                if (! is_string($data['visibility'])) {
                    throw new ValidationException('Invalid album visibility');
                }
                $data['visibility'] = $this->feeds->normalizeVisibility($data['visibility']);
                if ($data['visibility'] !== $album->visibility && $this->repository->hasChildren($albumId)) {
                    throw new ValidationException('Cannot change visibility of a nonempty album yet', 409);
                }
                $context = $this->context($contextId, $user);
                $container = $context->type === 'community' || $data['visibility'] === 'members';
                $data['containerId'] = $container ? $contextId : null;
                $data['containerType'] = $container ? $context->type : null;
            }

            // Omitted slug, parent, owner and metadata retain their stored values.
            return $this->feeds->updateFeed($albumId, $data, $user);
        });
    }

    public function deleteAlbum(int $contextId, int $albumId, User $user): void
    {
        $this->repository->withLockedFeed($albumId, function () use ($contextId, $albumId, $user): void {
            $album = $this->getAlbum($contextId, $albumId, $user);
            if ($user->isGuest() || ! $this->access->canEditFeed($user, $album)) {
                throw new ForbiddenException('Cannot delete album');
            }
            // Photo deletion must also clean protected files; connect it with uploads.
            if ($this->repository->hasChildren($albumId)) {
                throw new ValidationException('Cannot delete a nonempty album yet', 409);
            }
            $this->feeds->deleteFeed($albumId, $user);
        });
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

    public function canUploadToAlbum(int $contextId, int $albumId, User $user): bool
    {
        $context = $this->context($contextId, $user);
        return ! $user->isGuest() && $this->canEditAlbum($contextId, $albumId, $user)
            && ($context->type === 'blog' ? $context->ownerId === $user->id : $this->communities->canPost($user, $context));
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
