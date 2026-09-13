<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\MembershipRepository;

/**
 * AccessService
 *
 * Centralized authorization logic for pages and feeds.
 *
 * Responsibilities:
 *  - Global role-based access control (RBAC)
 *  - Container-based membership access
 *  - Owner override
 *  - Admin override
 *
 * Priority order of access rules:
 *
 * 1. Admin override — admin can access everything.
 * 2. Owner override — feed owner or container owner can access everything inside container.
 * 3. Visibility rules:
 *      - public  → accessible to everyone
 *      - members → accessible to container members
 *      - private → accessible to container moderators+
 *
 * Notes:
 *  - A "container" is also a Feed (type: blog, community, etc.).
 *  - Container owner is stored in feeds.owner_id.
 *  - Membership roles are resolved via MembershipRepository.
 *
 * This service contains only policy logic.
 * It should not perform heavy IO operations (ideally).
 */
class AccessService
{
    public const string ROLE_USER = 'user';
    public const string ROLE_MODERATOR = 'moderator';
    public const string ROLE_ADMIN = 'admin';
    public const array ROLES = [self::ROLE_USER, self::ROLE_MODERATOR, self::ROLE_ADMIN];

    public const string ACCESS_PUBLIC = 'public';
    public const string ACCESS_AUTHENTICATED = 'authenticated';
    public const string ACCESS_MODERATOR = 'moderator';
    public const string ACCESS_ADMIN = 'admin';
    public const array ACCESS_RULES = [
        self::ACCESS_PUBLIC, self::ACCESS_AUTHENTICATED, self::ACCESS_MODERATOR, self::ACCESS_ADMIN,
    ];

    /** Shared audience check for routes and navigation. Unknown rules deny access. */
    public static function allows(User $user, string $rule): bool
    {
        return match ($rule) {
            self::ACCESS_PUBLIC => true,
            self::ACCESS_AUTHENTICATED => ! $user->isGuest(),
            self::ACCESS_MODERATOR => ! $user->isGuest() && in_array($user->role, [self::ROLE_MODERATOR, self::ROLE_ADMIN], true),
            self::ACCESS_ADMIN => $user->isAdmin(),
            default => false,
        };
    }

    public function __construct(
        private readonly MembershipRepository $membershipRepository,
        private readonly FeedRepository $feedRepository
    ) {

    }

    /**
     * Determine whether a user can access a page.
     *
     * Rules:
     *  - Admin can access any page.
     *  - Otherwise the page audience rule decides access.
     */
    public function canAccessPage(User $user, Page $page): bool
    {
        return self::allows($user, $page->accessRule);
    }

    /**
     * Determine whether a user can access a feed.
     *
     * Access resolution order:
     *  1. Admin override
     *  2. Owner override (feed owner or container owner)
     *  3. Global (non-container) feeds are public
     *  4. Visibility-based rules
     *
     * NEVER use this in a loop.
     */
    public function canAccessFeed(User $user, Feed $feed): bool
    {
        // 🔥 1. Admin override
        if ($this->isAdmin($user)) {
            return true;
        }

        // 🔥 2. Owner override
        if ($this->isOwner($user, $feed)) {
            return true;
        }

        // 🔹 3. Public
        if ($feed->visibility === 'public') {
            return true;
        }

        // 🔹 4. Private global content is visible only to owner/admin.
        if ($feed->containerId === null) {
            return false;
        }

        // 🔹 5. Membership is required
        $membership = $this->membershipRepository
            ->find($feed->containerId, $user->id);

        if (!$membership) {
            return false;
        }

        // members
        if ($feed->visibility === 'members') {
            return true;
        }

        // private → moderator+
        return (int) ($membership->roleLevel ?? 0) >= 2;
    }

    public function canEditFeed(User $user, Feed $feed): bool
    {
        // 🔥 1. Admin override
        if ($this->isAdmin($user)) {
            return true;
        }

        // 🔥 2. Owner override (feed owner or container owner - isOwner()
        // already covers a community's owner_id, see its own docblock).
        if ($this->isOwner($user, $feed)) {
            return true;
        }

        // 🔹 3. Container moderator - same role_level >= 2 threshold
        // canAccessFeed() already uses for 'private' visibility. A personal
        // blog's container never has a moderator-level membership row
        // (FriendService only ever grants 'member', role_level 1 - see
        // migrations/20260912000000_initial.sql), so this is
        // a no-op there and only actually grants anything for a community
        // post's moderators.
        if ($feed->containerId !== null && $this->isContainerModerator($user, $feed->containerId)) {
            return true;
        }

        return false;
    }

    /**
     * Check whether user is:
     *  - Owner of the feed itself
     *  - Owner of the container feed
     *
     * Container is also stored in feeds table.
     *
     * NEVER user it ina loop.
     */
    private function isOwner(User $user, Feed $feed): bool
    {
        if (!isset($user->id)) {
            return false;
        }

        // Owner of the feed itself
        if ($user->id === $feed->ownerId) {
            return true;
        }

        // Owner of the container
        if ($feed->containerId !== null) {

            $container = $this->feedRepository
                ->findById($feed->containerId, $user);

            if ($container && $container->ownerId === $user->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if $user holds a moderator-or-above membership row (role_level
     * >= 2: moderator or owner) in $containerId - same threshold
     * canAccessFeed() uses for 'private' visibility, reused here so
     * canEditFeed() grants community moderators/owners the same edit/
     * delete rights as the container's owner_id (already covered
     * separately by isOwner()).
     */
    private function isContainerModerator(User $user, int $containerId): bool
    {
        if (!isset($user->id)) {
            return false;
        }

        $membership = $this->membershipRepository->find($containerId, $user->id);

        return (int) ($membership->roleLevel ?? 0) >= 2;
    }

    /**
     * Determine whether user has admin-level privileges.
     *
     * Uses the same identity check as SQL feed filtering.
     */
    public function isAdmin(User $user): bool
    {
        return $user->isAdmin();
    }
}
