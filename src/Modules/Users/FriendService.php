<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Users;

use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Notification;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Service\NotificationService;

/**
 * Friend requests and blog subscriptions, built entirely on top of the
 * existing `memberships` table (see docs/TODO.md's own "Blog: friends-only
 * posts via container_id/container_type" entry, which anticipated exactly
 * this) rather than a new table of its own:
 *
 * "A subscribed to / requested friendship with B" is nothing more than a
 * `memberships` row whose container is B's personal blog feed
 * (BlogPostService::getOrCreateUserBlogFeed()) and whose user is A. There
 * is no separate "pending"/"accepted" state column - mutuality (real,
 * reciprocal "friendship") is derived on read by checking whether the
 * reverse row (B's own membership in A's blog) also exists. Until it does,
 * A is simply a one-directional subscriber of B, exactly matching the
 * product framing (until friendship is mutual, the requester is considered
 * a subscriber).
 *
 * CommunityService now uses the same storage shape for joining a community:
 * subscribing is a pending request for approval-based communities, and a
 * member role is an accepted/open membership. This class remains specific to
 * personal blog containers; the pending-community approval UI is tracked in
 * docs/TODO.md.
 */
final class FriendService
{
    public function __construct(
        private readonly BlogPostService $blogPostService,
        private readonly FeedRepository $feeds,
        private readonly MembershipRepository $memberships,
        private readonly NotificationService $notifications,
        private readonly TranslationManager $tm,
        private readonly PageTree $pageTree,
        private readonly UrlGenerator $urlGenerator,
    ) {
    }

    /**
     * Which of the 4 states $viewer is in relative to $profileUser:
     * 'friends' (mutual), 'subscribed' (viewer -> profileUser only),
     * 'incoming' (profileUser -> viewer only, viewer hasn't reciprocated
     * yet), or 'none'. Callers should special-case "own profile" before
     * calling this - it has no opinion on that.
     *
     * Read-only: unlike sendRequest(), this never creates either user's
     * blog feed - a user who's never posted simply can't have any
     * membership rows pointing at a container that doesn't exist, so a
     * missing blog is treated the same as "no relationship" without
     * needing to query memberships at all.
     */
    public function getRelationshipStatus(User $viewer, User $profileUser): string
    {
        if ($viewer->isGuest() || $viewer->id === $profileUser->id) {
            return 'none';
        }

        $outgoing = $this->hasMembership($profileUser, $viewer);
        $incoming = $this->hasMembership($viewer, $profileUser);

        return match (true) {
            $outgoing && $incoming => 'friends',
            $outgoing => 'subscribed',
            $incoming => 'incoming',
            default => 'none',
        };
    }

    /**
     * True if $subscriber has a membership row in $target's personal blog -
     * i.e. $subscriber subscribed to / requested friendship with $target,
     * one direction only. Doesn't create $target's blog feed if it doesn't
     * exist yet - see getRelationshipStatus()'s own note.
     *
     * Looks the blog up as $target (its owner), not $subscriber - this is
     * an internal "resolve the container id" lookup, not a "can $subscriber
     * see this feed" check, and FeedRepository::findByOwnerAndType() is
     * ACL-scoped to whichever user is passed in. Passing the owner means
     * the owner-override in applyAcl() always finds it regardless of the
     * blog container's own visibility - which is always 'public' today
     * anyway (there's no UI to change it), but this is the correct lookup
     * either way.
     */
    private function hasMembership(User $target, User $subscriber): bool
    {
        $blog = $this->feeds->findByOwnerAndType($target->id, 'blog', $target);

        return $blog !== null && $this->memberships->exists($blog->id, $subscriber->id);
    }

    /**
     * $actor requests friendship with / subscribes to $target. Idempotent -
     * calling it again while already subscribed does nothing. Ensures
     * $target's personal blog feed exists (getOrCreateUserBlogFeed(),
     * same lazy-creation the first post would trigger anyway) since this
     * is the one place in this class that's allowed to create it, unlike
     * the read-only status/listing methods.
     *
     * Sends exactly one system-messenger notification: to $target, always -
     * worded differently depending on whether this action just completed
     * a mutual friendship (because $target had already subscribed to
     * $actor before this call) or is a fresh one-directional request.
     *
     * @throws ForbiddenException
     * @throws ValidationException
     */
    public function sendRequest(User $actor, User $target): void
    {
        if ($actor->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        if ($actor->id === $target->id) {
            throw new ValidationException($this->tm->trans('friend.cannot_add_self'));
        }

        $targetBlog = $this->blogPostService->getOrCreateUserBlogFeed($target);

        if ($this->memberships->exists($targetBlog->id, $actor->id)) {
            return;
        }

        $this->memberships->create($targetBlog->id, $actor->id, MembershipRepository::ROLE_MEMBER_ID);

        $becameMutual = $this->hasMembership($actor, $target);

        $message = $becameMutual
            ? $this->tm->trans('friend.became_mutual', ['name' => $actor->getDisplayName()])
            : $this->tm->trans('friend.new_request', ['name' => $actor->getDisplayName()]);

        // The notification names $actor but otherwise leaves $target no way
        // to get to their profile from the messenger - append a real link
        // when one is resolvable, rather than a raw URL as plain text
        // (unclickable, and just noise to read). Messages are allowed
        // exactly one piece of markup - a plain <a href> - and nothing
        // else; MessageService::send() sanitizes it (strips anything that
        // isn't that) before it's stored, so it's safe to build the tag
        // by hand here. Still escaping the interpolated pieces ourselves
        // rather than leaning entirely on that downstream sanitizing, since
        // $actor->getDisplayName() is user-controlled (their own nick).
        $profileUrl = $this->buildProfileUrl($actor);
        if ($profileUrl !== null) {
            $message .= ' <a href="'.htmlspecialchars($profileUrl, ENT_QUOTES).'">'
                .htmlspecialchars($actor->getDisplayName(), ENT_QUOTES).'</a>';
        }

        $this->notifications->notify(new Notification(
            recipientUserId: $target->id,
            type: $becameMutual ? 'friend.mutual' : 'friend.request',
            messengerText: $message,
            payload: [
                'actorUserId' => $actor->id,
                'actorName' => $actor->getDisplayName(),
                'actorUrl' => $profileUrl,
            ],
        ));
    }

    /**
     * Absolute URL to $user's public profile page, or null if it can't be
     * resolved - either 'user.show' isn't a registered page (shouldn't
     * happen in production, but keeps this safe if it ever isn't), or
     * $user has no username yet (the public username is assigned by a
     * backfill after registration, not at registration itself - see
     * UserRepository::setUsername()'s own note), same "no username, no
     * link" rule UsersController::buildFriendCards() already follows for
     * the friends-list UI.
     *
     * Absolute (not the site-relative path UrlGenerator::page() returns)
     * because this URL is read outside any page context - in the
     * messenger, possibly by someone who isn't even looking at this site
     * right now - same reasoning as the absolute links UserService's own
     * registration/password-reset mails already send.
     */
    private function buildProfileUrl(User $user): ?string
    {
        if ($user->username === '') {
            return null;
        }

        $showPage = $this->pageTree->findByAction('user.show');
        if ($showPage === null) {
            return null;
        }

        $path = $this->urlGenerator->page($showPage, ['username' => $user->username]);

        return 'https://'.$_SERVER['SERVER_NAME'].$path;
    }

    /**
     * $actor removes their own subscription to / friendship with $target -
     * i.e. deletes $actor's membership row from $target's blog. If the
     * relationship was mutual, this only ever downgrades it to
     * one-directional (like unfollowing on any mutual-follow platform);
     * $target's own row into $actor's blog, if any, is untouched and is
     * $target's to remove. A no-op (not an error) if there was nothing to
     * remove, or if $target has never posted (so has no blog feed at all
     * yet, so there's nothing $actor could have subscribed to in the first
     * place).
     */
    public function removeFriend(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            return;
        }

        // Looked up as $target (owner), not $actor - same reasoning as
        // hasMembership()'s own note.
        $targetBlog = $this->feeds->findByOwnerAndType($target->id, 'blog', $target);
        if ($targetBlog === null) {
            return;
        }

        $this->memberships->delete($targetBlog->id, $actor->id);
    }

    /**
     * Mutual friends of $profileUser, paginated the same offset/nextOffset
     * shape as UsersController::handleUserPostsRequest()'s blog feed.
     *
     * @return array{items: list<array{id:int, nick:string, username:?string, avatarUrl:string}>, total: int}
     */
    public function getFriends(User $profileUser, int $limit, int $offset = 0): array
    {
        return [
            'items' => $this->memberships->findMutualFriends($profileUser->id, $limit, $offset),
            'total' => $this->memberships->countMutualFriends($profileUser->id),
        ];
    }
}
