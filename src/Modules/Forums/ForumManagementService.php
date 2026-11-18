<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Forums;

use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\FeedService;
use Throwable;

/** Forum settings and membership workflows; all reading still uses the shared feed ACL. */
final readonly class ForumManagementService
{
    private FeedRepository $feeds;
    private MembershipRepository $memberships;
    private UserRepository $users;
    private ForumRepository $forums;

    public function __construct(private PdoDatabase $db, private FeedService $feedService, private TranslationManager $tm)
    {
        $this->feeds = new FeedRepository($db);
        $this->memberships = new MembershipRepository($db);
        $this->users = new UserRepository($db);
        $this->forums = new ForumRepository($db);
    }

    public function accessContainer(Feed $forum): int
    {
        return $forum->parentId !== null && $forum->containerType === 'forum' && $forum->containerId === $forum->parentId
            ? $forum->parentId : $forum->id;
    }

    public function ownVisibility(Feed $forum): string
    {
        return $this->accessContainer($forum) !== $forum->id
            ? ($this->forums->ownVisibility($forum->id) ?? 'members') : ($forum->visibility ?? 'public');
    }

    public function canManage(Feed $forum, User $actor): bool
    {
        return $forum->type === 'forum' && !$actor->isGuest() && ($actor->canModerateContent()
            || ($this->memberships->find($this->accessContainer($forum), $actor->id)?->roleLevel ?? 0) >= 2);
    }

    public function requireForum(int $id, User $actor): Feed
    {
        $forum = $this->feedService->getFeedById($id, $actor);
        if ($forum->type !== 'forum') {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }
        if (!$this->canManage($forum, $actor)) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }
        return $forum;
    }

    public function save(int $id, array $input, User $actor): Feed
    {
        $this->requireForum($id, $actor);
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->begin();
        }
        try {
            // Lock in one order, including destination categories, to serialize hierarchy changes.
            $this->forums->lockStructure();
            $forum = $this->requireForum($id, $actor);
            $title = $input['title'] ?? $forum->title;
            $description = $input['description'] ?? $forum->description ?? '';
            $parentId = array_key_exists('parentId', $input) ? $input['parentId'] : $forum->parentId;
            $visibility = $input['visibility'] ?? $forum->visibility ?? 'public';
            if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 255
                || !is_string($description) || mb_strlen($description) > 2000
                || ($parentId !== null && (!is_int($parentId) || $parentId <= 0 || $parentId === $id))) {
                throw new ValidationException($this->tm->trans('js.forums.manage_invalid'));
            }
            $visibility = $this->feedService->normalizeVisibility($visibility);
            if ($parentId !== null) {
                $parent = $this->feeds->findById($parentId, $actor);
                if ($parent === null || $parent->type !== 'forum' || $parent->parentId !== null || $this->forums->hasSubforums($id)) {
                    throw new ValidationException($this->tm->trans('js.forums.manage_invalid'));
                }
            } elseif ($forum->parentId !== null && $this->forums->findTopicsPage($id, 1, 0)['total'] > 0) {
                throw new ValidationException($this->tm->trans('js.forums.manage_invalid'));
            }
            $oldInherited = $this->accessContainer($forum) !== $id;
            $ownVisibility = $this->ownVisibility($forum);
            $containerId = $id;
            if ($parentId !== null && $parent->visibility !== 'public') {
                // One ordinary ACL container: a closed category owns its entire subtree's audience.
                if (!$this->canManage($parent, $actor)) {
                    throw new ForbiddenException($this->tm->trans('feed.forbidden'));
                }
                $this->forums->rememberVisibility($id, $ownVisibility);
                $visibility = $parent->visibility;
                $containerId = $parent->id;
            } elseif ($oldInherited && !array_key_exists('visibility', $input)) {
                $visibility = $ownVisibility;
            }
            if (!$oldInherited) {
                // Initialize legacy public sections before the shared local-moderator edit check.
                $this->forums->initializeContainer($id);
            }
            $updated = $this->feedService->updateFeed($id, [
                'title' => trim($title), 'description' => $description, 'parentId' => $parentId,
                'visibility' => $visibility, 'containerId' => $containerId, 'containerType' => 'forum',
            ], $actor);
            $this->feeds->updateChildAccess($id, 'forum-post', $visibility, $containerId, 'forum');
            if ($parentId === null) {
                foreach ($this->forums->childAccessSettings($id) as $child) {
                    $childId = (int) $child['id'];
                    $inherited = (int) ($child['container_id'] ?? 0) === $id;
                    if ($visibility === 'public' && !$inherited) {
                        continue;
                    }
                    if (!$inherited) {
                        $this->forums->rememberVisibility($childId, $child['visibility'] ?? 'public');
                    }
                    $childVisibility = $visibility === 'public' ? ($child['own_visibility'] ?? 'members') : $visibility;
                    $childContainer = $visibility === 'public' ? $childId : $id;
                    $this->forums->setAccess($childId, $childVisibility, $childContainer);
                    $this->feeds->updateChildAccess($childId, 'forum-post', $childVisibility, $childContainer, 'forum');
                }
            }
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $updated;
        } catch (Throwable $error) {
            if ($ownsTransaction) {
                $this->db->rollback();
            }
            throw $error;
        }
    }

    public function members(int $id, User $actor, int $offset): array
    {
        $forum = $this->requireForum($id, $actor);
        $containerId = $this->accessContainer($forum);
        return ['data' => $this->memberships->findMembers($containerId, 50, max(0, $offset)),
            'total' => $this->memberships->countMembers($containerId), 'readOnly' => $containerId !== $id, 'currentUserId' => $actor->id,
            'globalModerator' => $actor->canModerateContent()];
    }

    public function searchUsers(int $id, User $actor, string $query): array
    {
        $this->requireOwnMembership($id, $actor);
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $results = $this->users->findPublicUsers(20, 0, $query, 'name', 'asc');
        foreach ($this->users->findActiveByUsernamePrefix($query, 20) as $user) {
            $results[] = $user;
        }
        $unique = [];
        foreach ($results as $user) {
            if ($user['id'] > User::SYSTEM_USER_ID) {
                $unique[$user['id']] = ['id' => $user['id'], 'name' => trim((string) ($user['nick'] ?? $user['displayName'] ?? $user['username'] ?? '')) ?: '#'.$user['id']];
            }
        }
        $result = array_slice(array_values($unique), 0, 20);
        foreach ($result as &$user) {
            $user['isMember'] = ($this->memberships->find($id, $user['id'])?->roleLevel ?? 0) >= 1;
        }
        return $result;
    }

    private function requireOwnMembership(int $id, User $actor): void
    {
        if ($this->accessContainer($this->requireForum($id, $actor)) !== $id) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }
    }

    public function setMember(int $id, int $userId, ?string $role, User $actor): void
    {
        $this->requireOwnMembership($id, $actor);
        if ($userId <= User::SYSTEM_USER_ID || !in_array($role, ['member', 'moderator', null], true)) {
            throw new ValidationException($this->tm->trans('js.forums.manage_invalid'));
        }
        $this->feeds->withLockedFeed($id, function () use ($id, $userId, $role, $actor): void {
            $this->requireOwnMembership($id, $actor);
            $current = $this->memberships->find($id, $userId);
            if (($current?->roleLevel ?? 0) >= 3 || (!$actor->canModerateContent() && $userId === $actor->id)) {
                throw new ForbiddenException($this->tm->trans('feed.forbidden'));
            }
            if ($role === null) {
                $this->memberships->delete($id, $userId);
                return;
            }
            if ($this->users->findActiveById($userId) === null) {
                throw new NotFoundException($this->tm->trans('feed.not_found'));
            }
            $this->forums->initializeContainer($id);
            $roleId = $role === 'moderator' ? MembershipRepository::ROLE_MODERATOR_ID : MembershipRepository::ROLE_MEMBER_ID;
            $this->memberships->create($id, $userId, $roleId);
            $this->memberships->changeRole($id, $userId, $roleId);
        });
    }
}
