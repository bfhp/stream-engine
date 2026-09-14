<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Users;

use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Notification;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\NotificationService;
use Throwable;

/**
 * Business logic for the Users module's "create a community" feature
 * (community.create). Same division of responsibility as
 * BlogPostService: FeedService::createFeed()/normalizeVisibility() stay
 * generic (a community is just another Feed type - see AccessService's own
 * "a container is also a Feed" docblock), everything community-specific
 * lives here.
 *
 * A community's membership policy - "open" (anyone who joins becomes a
 * member immediately) vs "approval" (a join request sits as a pending
 * subscriber until a moderator approves it) - has no dedicated column: it's
 * stored as the community feed's own 'membership_type' metadata entry, the
 * same feed_metadata mechanism BlogPostService already uses for
 * 'track_upload_id'. Nothing in this class implements the *join* flow
 * itself: join()/leave() below store the relationship in memberships, using
 * a pending subscriber for approval-based communities. Moderator approval is
 * still a separate feature; see docs/TODO.md's "Community membership (join
 * request/approval)" section.
 */
class CommunityService
{
    public const string MEMBERSHIP_TYPE_OPEN = 'open';

    public const string MEMBERSHIP_TYPE_APPROVAL = 'approval';

    /**
     * The generic default image shown for a community with no imageUrl of
     * its own (see resolveImageUrl()) - two overlapping silhouettes, not
     * the site favicon/brand mark (which makes an ugly, unreadable avatar
     * at these sizes). Lives here rather than on the domain Feed class -
     * Feed has no idea what a "community" even is (any feed type can have
     * an imageUrl; only this module's own community.* pages treat it as an
     * avatar-style identity image), same module/domain split as this
     * class's own docblock already describes for createCommunity().
     */
    public const string DEFAULT_IMAGE_URL = '/assets/img/default-community.svg';

    private const array ALLOWED_MEMBERSHIP_TYPES = [
        self::MEMBERSHIP_TYPE_OPEN,
        self::MEMBERSHIP_TYPE_APPROVAL,
    ];

    private const int MAX_NAME_LENGTH = 200;

    private const int MAX_DESCRIPTION_LENGTH = 2000;

    /**
     * The minimum role_level allowed to publish a post into a community -
     * 'member' (1). A mere subscriber (role_level 0 - a pending join
     * request on an approval-type community, see this class's own docblock
     * on membership_type) can't post, only an actual member/moderator/owner
     * can. Same role_level scale as getRelationshipStatus()'s own match().
     */
    private const int MIN_POSTING_ROLE_LEVEL = 1;

    /**
     * Slugs a community is never allowed to land on - each collides with a
     * static sibling page mounted under the same community.main parent:
     * 'create' is community.create's own pattern, 'tags' is reserved for a
     * planned tag-listing route (same "/community/{slug}/" URL shape a real
     * community would otherwise use). Same Router::resolve()-matches-
     * static-before-dynamic reasoning as BlogPostService::
     * RESERVED_BLOG_POST_SLUGS, just scoped to communities (a top-level feed
     * type, so uniqueCommunitySlug() checks this list against every
     * community rather than one blog's own posts).
     *
     * @var string[]
     */
    private const array RESERVED_COMMUNITY_SLUGS = ['create', 'tags'];

    public function __construct(
        private readonly FeedService $feedService,
        private readonly FeedRepository $feedRepository,
        private readonly MembershipRepository $memberships,
        private readonly TranslationManager $tm,
        private readonly PageTree $pageTree,
        private readonly UrlGenerator $urlGenerator,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Creates a new community feed (type 'community', no parent - same
     * top-level nesting as a personal blog) and immediately grants $user
     * the 'owner' membership role in it. $membershipType is validated and
     * stored as-is in feed_metadata['membership_type'] for the (not yet
     * built) join flow to read later.
     *
     * @throws ForbiddenException
     * @throws ValidationException
     */
    public function createCommunity(
        User $user,
        string $name,
        ?string $description,
        ?string $imageUrl,
        string $membershipType,
    ): Feed {
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $name = $this->normalizeName($name);
        $description = $this->normalizeDescription($description);
        $membershipType = $this->normalizeMembershipType($membershipType);
        $slug = $this->uniqueCommunitySlug($name, $user);

        $community = $this->feedService->createFeed(
            title: $name,
            slug: $slug,
            type: 'community',
            parentId: null,
            description: $description,
            imageUrl: $imageUrl,
            content: '',
            user: $user,
            metadata: ['membership_type' => $membershipType],
            visibility: 'public',
        );

        // The creator's role - not "member" (the role a later join grants)
        // or "moderator", but "owner": the one who created the community.
        // Hardcoded rather than looked up (see MembershipRepository::
        // ROLE_OWNER_ID's own docblock for why that's safe here).
        $this->memberships->create($community->id, $user->id, MembershipRepository::ROLE_OWNER_ID);

        $community->canonicalUrl = $this->buildCommunityCanonicalUrl($community);

        return $community;
    }

    /**
     * Which of the 5 states $viewer is in relative to $community:
     * 'owner' (created it), 'moderator', 'member' (a real, approved
     * member), 'pending' (a subscriber row waiting on an approval-type
     * community's moderator - see this class's own docblock on
     * membership_type), or 'none'. role_level (not the membership_role_id)
     * is what distinguishes these - see migrations/
     * 20260912000000_initial.sql's own mapping
     * (subscriber=0, member=1, moderator=2, owner=3).
     */
    public function getRelationshipStatus(User $viewer, Feed $community): string
    {
        if ($viewer->isGuest()) {
            return 'none';
        }

        $membership = $this->memberships->find($community->id, $viewer->id);

        if ($membership === null) {
            return 'none';
        }

        return match ($membership->roleLevel) {
            3 => 'owner',
            2 => 'moderator',
            1 => 'member',
            default => 'pending',
        };
    }

    /**
     * $community's own imageUrl, or DEFAULT_IMAGE_URL when it has none -
     * the single place that fallback is decided (same "one constant, no
     * hardcoded fallback elsewhere" contract as UserService::
     * DEFAULT_AVATAR_URL/resolveAvatarUrl()). Every view/card showing a
     * community's identity
     * image - community.show's own sidebar header, community.manage's
     * header, community.main's "Popular/New communities" widgets -
     * should read an already-resolved value a controller built via this
     * method, rather than hardcode a fallback path itself.
     */
    public function resolveImageUrl(Feed $community): string
    {
        return $community->imageUrl !== null && $community->imageUrl !== '' ? $community->imageUrl : self::DEFAULT_IMAGE_URL;
    }

    /**
     * Whether $user may publish a blog post into $community - a real
     * member, moderator, or owner (role_level >= MIN_POSTING_ROLE_LEVEL); a
     * mere subscriber (role_level 0, see this class's own docblock on
     * membership_type) may not, same as a guest or a stranger with no
     * membership row at all. Used both by UsersController::
     * showCommunityPostFormPage()/showCommunityShowPage() (to decide
     * whether to show the "Post to community" entry point) and by
     * handleCommunityPostCreateRequest() (to actually gate the write) -
     * same defense-in-depth split as every other write path in this module.
     */
    public function canPost(User $user, Feed $community): bool
    {
        if ($user->isGuest()) {
            return false;
        }

        $membership = $this->memberships->find($community->id, $user->id);

        return $membership !== null && $membership->roleLevel >= self::MIN_POSTING_ROLE_LEVEL;
    }

    /**
     * $user joins (or, for an approval-type community, requests to join)
     * $community - idempotent, same as FriendService::sendRequest(): a
     * repeat call while already related (member or pending) just returns
     * the existing status rather than touching the row again.
     * MembershipRepository::create()'s own INSERT IGNORE means this alone
     * would already be idempotent at the DB level, but the existence check
     * up front avoids a wasted write on the (by far) more common repeat
     * click.
     *
     * There's no moderator-approval flow yet for approval-type communities
     * (see docs/TODO.md's "Community membership (join request/approval)"
     * entry) - a subscriber row just sits at role_level 0 until one exists.
     *
     * @throws ForbiddenException
     */
    public function join(User $user, Feed $community): string
    {
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        if ($this->memberships->find($community->id, $user->id) === null) {
            $membershipType = (string) ($community->metadata['membership_type'] ?? self::MEMBERSHIP_TYPE_OPEN);

            $roleId = $membershipType === self::MEMBERSHIP_TYPE_APPROVAL
                ? MembershipRepository::ROLE_SUBSCRIBER_ID
                : MembershipRepository::ROLE_MEMBER_ID;

            $created = $this->memberships->create($community->id, $user->id, $roleId);

            if ($created && $roleId === MembershipRepository::ROLE_SUBSCRIBER_ID && $community->ownerId !== $user->id) {
                $this->notifyJoinRequest($user, $community);
            }
        }

        return $this->getRelationshipStatus($user, $community);
    }

    private function notifyJoinRequest(User $user, Feed $community): void
    {
        try {
            $page = $this->pageTree->findByAction('community.manage');
            $url = $page !== null && $community->slug !== null
                ? $this->urlGenerator->page($page, ['slug' => $community->slug])
                : null;
            if ($url !== null && str_starts_with($url, '/') && isset($_SERVER['SERVER_NAME'])) {
                $url = 'https://'.$_SERVER['SERVER_NAME'].$url;
            }
            $message = $this->tm->trans('community.join_request_message', [
                'name' => htmlspecialchars($user->getDisplayName(), ENT_QUOTES),
                'community' => htmlspecialchars((string) $community->title, ENT_QUOTES),
            ]);
            if ($url !== null) {
                $message .= ' <a href="'.htmlspecialchars($url, ENT_QUOTES).'">'
                    .$this->tm->trans('community.review_request').'</a>';
            }

            $this->notifications->notify(new Notification(
                recipientUserId: $community->ownerId,
                type: 'community.join_request',
                messengerText: $message,
                payload: [
                    'communityId' => $community->id,
                    'communityTitle' => $community->title,
                    'contentUrl' => $url,
                    'actorUserId' => $user->id,
                    'actorName' => $user->getDisplayName(),
                ],
            ));
        } catch (Throwable $e) {
            error_log('Unable to notify community owner about join request: '.$e->getMessage());
        }
    }

    /**
     * $user leaves $community - "Leave community"/cancels a pending
     * join request, whichever applies (same delete either way; the only
     * difference is which role_level it was removed at). A no-op (not an
     * error) if there was nothing to remove, same idempotent convention as
     * FriendService::removeFriend().
     *
     * The owner can't leave their own community this way - there's no
     * ownership-transfer flow yet, so removing that row would leave the
     * community ownerless. UI-side this is enforced by simply not
     * rendering the button for an owner (see community-show.twig), but the
     * check is repeated here too, same defense-in-depth as every other
     * write path in this module.
     *
     * @throws ValidationException
     */
    public function leave(User $user, Feed $community): void
    {
        $membership = $this->memberships->find($community->id, $user->id);

        if ($membership === null) {
            return;
        }

        if ($membership->roleLevel >= 3) {
            throw new ValidationException($this->tm->trans('community.owner_cannot_leave'));
        }

        $this->memberships->delete($community->id, $user->id);
    }

    /**
     * A community's real members (owner/moderators/members - not pending
     * subscribers, see MembershipRepository::findMembers()'s own docblock),
     * paginated the same offset/nextOffset shape as
     * UsersController::handleUserFriendsRequest()'s friends list.
     *
     * @return array{items: list<array{id:int, nick:string, username:?string, avatarUrl:string, roleLevel:int}>, total: int}
     */
    public function getMembers(Feed $community, int $limit, int $offset = 0): array
    {
        return [
            'items' => $this->memberships->findMembers($community->id, $limit, $offset),
            'total' => $this->memberships->countMembers($community->id),
        ];
    }

    /**
     * A community's pending join requests (subscriber rows) - the
     * community.manage "Members" tab's own moderation queue, mirroring
     * getMembers()'s shape/pagination but over MembershipRepository::
     * findSubscribers()/countSubscribers() instead.
     *
     * @return array{items: list<array{id:int, nick:string, username:?string, avatarUrl:string, roleLevel:int}>, total: int}
     */
    public function getSubscribers(Feed $community, int $limit, int $offset = 0): array
    {
        return [
            'items' => $this->memberships->findSubscribers($community->id, $limit, $offset),
            'total' => $this->memberships->countSubscribers($community->id),
        ];
    }

    /**
     * "Accept into community" - the community.manage "Members" tab's
     * approve button: promotes a pending subscriber straight to a full
     * member. Owner-only - same explicit id check as updateSettings() (see
     * its own docblock for why the page's access_rule alone can't
     * express "this particular community's owner"). A no-op if $userId
     * isn't actually a pending subscriber of $community (already a member,
     * never joined, or already removed) - same idempotent convention as
     * join()/leave().
     *
     * @throws ForbiddenException
     */
    public function approveSubscriber(User $viewer, Feed $community, int $userId): void
    {
        if ($viewer->isGuest() || $viewer->id !== $community->ownerId) {
            throw new ForbiddenException($this->tm->trans('community.manage_forbidden'));
        }

        $membership = $this->memberships->find($community->id, $userId);

        if ($membership === null || $membership->roleLevel !== 0) {
            return;
        }

        if (! $this->memberships->approveSubscriber($community->id, $userId)) {
            return;
        }

        try {
            $url = $this->buildCommunityCanonicalUrl($community);
            if ($url !== null && str_starts_with($url, '/') && isset($_SERVER['SERVER_NAME'])) {
                $url = 'https://'.$_SERVER['SERVER_NAME'].$url;
            }
            $message = $this->tm->trans('community.join_approved_message', [
                'community' => htmlspecialchars((string) $community->title, ENT_QUOTES),
            ]);
            if ($url !== null) {
                $message .= ' <a href="'.htmlspecialchars($url, ENT_QUOTES).'">'
                    .$this->tm->trans('community.open').'</a>';
            }

            $this->notifications->notify(new Notification(
                recipientUserId: $userId,
                type: 'community.join_approved',
                messengerText: $message,
                payload: [
                    'communityId' => $community->id,
                    'communityTitle' => $community->title,
                    'contentUrl' => $url,
                    'actorUserId' => $viewer->id,
                    'actorName' => $viewer->getDisplayName(),
                ],
            ));
        } catch (Throwable $e) {
            error_log('Unable to notify user about community join approval: '.$e->getMessage());
        }
    }

    /**
     * "Remove from community" - the community.manage "Members" tab's
     * remove button: demotes a real member (or moderator) back to a
     * pending subscriber. Deliberately not a hard delete (unlike leave()'s
     * own row removal) - per this feature's own spec, "removing" a member
     * here means "make them a subscriber again", the same state a fresh
     * join request on an approval-type community starts at. A no-op if
     * $userId has no real membership to demote (guest row, already a
     * subscriber, or no row at all). The owner can never be removed this
     * way - same guard leave() applies to itself, repeated here since this
     * is a distinct write path (defense-in-depth, same convention as every
     * other mutation in this module).
     *
     * @throws ForbiddenException
     * @throws ValidationException
     */
    public function removeMember(User $viewer, Feed $community, int $userId): void
    {
        if ($viewer->isGuest() || $viewer->id !== $community->ownerId) {
            throw new ForbiddenException($this->tm->trans('community.manage_forbidden'));
        }

        if ($userId === $community->ownerId) {
            throw new ValidationException($this->tm->trans('community.owner_cannot_leave'));
        }

        $membership = $this->memberships->find($community->id, $userId);

        if ($membership === null || $membership->roleLevel === 0) {
            return;
        }

        if (! $this->memberships->demoteMemberToSubscriber($community->id, $userId)) {
            return;
        }

        try {
            $url = $this->buildCommunityCanonicalUrl($community);
            if ($url !== null && str_starts_with($url, '/') && isset($_SERVER['SERVER_NAME'])) {
                $url = 'https://'.$_SERVER['SERVER_NAME'].$url;
            }
            $message = $this->tm->trans('community.membership_changed_message', [
                'community' => htmlspecialchars((string) $community->title, ENT_QUOTES),
            ]);
            if ($url !== null) {
                $message .= ' <a href="'.htmlspecialchars($url, ENT_QUOTES).'">'
                    .$this->tm->trans('community.open').'</a>';
            }

            $this->notifications->notify(new Notification(
                recipientUserId: $userId,
                type: 'community.membership_changed',
                messengerText: $message,
                payload: [
                    'communityId' => $community->id,
                    'communityTitle' => $community->title,
                    'contentUrl' => $url,
                    'actorUserId' => $viewer->id,
                    'actorName' => $viewer->getDisplayName(),
                    'previousRoleLevel' => $membership->roleLevel,
                    'newRoleLevel' => 0,
                ],
            ));
        } catch (Throwable $e) {
            error_log('Unable to notify user about community membership change: '.$e->getMessage());
        }
    }

    /**
     * Updates a community's own basic settings (name/description/picture/
     * membership type) from the community.manage "Settings" tab.
     * Owner-only, same explicit id check as approveSubscriber()/
     * removeMember() above - FeedService::updateFeed()'s own
     * canEditFeed() check backs this up too (a community feed has no
     * container of its own to grant a moderator bypass through, so that
     * check alone already reduces to "owner or admin" here), but the
     * explicit check keeps this method's contract self-evident rather than
     * relying on that incidental fact.
     *
     * Switching membership_type from "approval" to "open" means every
     * subscriber who was stuck waiting on an approval no longer needs
     * one - they should become real members immediately rather than stay
     * pending forever. Because that's a one-way action affecting other
     * people's membership, it's never applied silently: called with
     * $confirmPromoteSubscribers = false (the default), this makes *no*
     * changes at all - not even the rest of the settings - whenever there
     * is at least one pending subscriber, and instead reports back how
     * many would be promoted so the caller (the settings form's own JS)
     * can show the owner a confirmation dialog and resubmit with
     * $confirmPromoteSubscribers = true once they agree. Every other
     * settings change (name/description/picture, or a membership_type
     * change that isn't this specific approval-to-open transition) is
     * always applied immediately regardless of that flag.
     *
     * @return array{feed: ?Feed, needsConfirmation: bool, pendingCount: int, promotedCount: ?int}
     * @throws ForbiddenException
     * @throws ValidationException
     */
    public function updateSettings(
        User $viewer,
        Feed $community,
        string $name,
        ?string $description,
        ?string $imageUrl,
        string $membershipType,
        bool $confirmPromoteSubscribers = false,
    ): array {
        if ($viewer->isGuest() || $viewer->id !== $community->ownerId) {
            throw new ForbiddenException($this->tm->trans('community.manage_forbidden'));
        }

        $name = $this->normalizeName($name);
        $description = $this->normalizeDescription($description);
        $membershipType = $this->normalizeMembershipType($membershipType);

        $currentMembershipType = (string) ($community->metadata['membership_type'] ?? self::MEMBERSHIP_TYPE_OPEN);
        $switchingToOpen = $currentMembershipType === self::MEMBERSHIP_TYPE_APPROVAL
            && $membershipType === self::MEMBERSHIP_TYPE_OPEN;

        $pendingCount = $switchingToOpen ? $this->memberships->countSubscribers($community->id) : 0;

        if ($switchingToOpen && $pendingCount > 0 && ! $confirmPromoteSubscribers) {
            return [
                'feed' => null,
                'needsConfirmation' => true,
                'pendingCount' => $pendingCount,
                'promotedCount' => null,
            ];
        }

        $updated = $this->feedService->updateFeed($community->id, [
            'title' => $name,
            'slug' => $community->slug,
            'parentId' => $community->parentId,
            'type' => 'community',
            'description' => $description,
            'imageUrl' => $imageUrl,
            'content' => $community->content,
            'metadata' => ['membership_type' => $membershipType],
        ], $viewer);

        $promotedCount = null;
        if ($switchingToOpen && $pendingCount > 0) {
            $promotedCount = $this->memberships->promoteSubscribersToMembers($community->id);
        }

        return [
            'feed' => $updated,
            'needsConfirmation' => false,
            'pendingCount' => $pendingCount,
            'promotedCount' => $promotedCount,
        ];
    }

    /**
     * Builds a freshly created community's canonical URL directly against
     * 'community.show' - same explicit UrlGenerator::page() approach
     * BlogPostService::buildPostCanonicalUrl() uses instead of the generic
     * per-feed-type walker, except here it's not strictly required (a
     * community has no per-owner URL segment the walker can't fill in) -
     * kept explicit anyway for the same reason showCommunityCreatePage()'s
     * redirect needs a real URL immediately, before the walker's cache
     * would otherwise resolve it on first request.
     */
    private function buildCommunityCanonicalUrl(Feed $community): ?string
    {
        $page = $this->pageTree->findByAction('community.show');

        if ($page === null || $community->slug === null) {
            return null;
        }

        return $this->urlGenerator->page($page, ['slug' => $community->slug]);
    }

    /**
     * Slugifies $name and appends a numeric suffix until the result is free
     * among every top-level community feed. The application-level check
     * chooses a friendly suffix; the database constraint closes the
     * concurrent-create race. A
     * community has no such parent to scope under (parent_id is always
     * null, same top-level nesting as a personal blog), so this checks
     * global uniqueness among parent_id IS NULL AND type = 'community'
     * rows instead.
     */
    private function uniqueCommunitySlug(string $name, User $user): string
    {
        $maxLength = FeedService::MAX_SLUG_LENGTH;
        $base = Formatter::slugify($name, '-');

        if ($base === '') {
            $base = 'community';
        }

        $base = mb_substr($base, 0, $maxLength);

        $candidate = $base;
        $suffix = 1;

        while (
            in_array($candidate, self::RESERVED_COMMUNITY_SLUGS, true)
            || $this->feedRepository->findByParentAndSlug(null, $candidate, $user, 'community') !== null
        ) {
            $suffix++;
            $suffixPart = '-'.$suffix;
            $candidate = mb_substr($base, 0, $maxLength - mb_strlen($suffixPart)).$suffixPart;
        }

        return $candidate;
    }

    /**
     * @throws ValidationException
     */
    private function normalizeName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new ValidationException($this->tm->trans('community.name_required'));
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ValidationException($this->tm->trans('community.name_too_long'));
        }

        return $name;
    }

    /**
     * @throws ValidationException
     */
    private function normalizeDescription(?string $description): ?string
    {
        $description = trim((string) $description);

        if ($description === '') {
            return null;
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new ValidationException($this->tm->trans('community.description_too_long'));
        }

        return $description;
    }

    /**
     * @throws ValidationException
     */
    private function normalizeMembershipType(string $membershipType): string
    {
        $membershipType = trim($membershipType);

        if (! in_array($membershipType, self::ALLOWED_MEMBERSHIP_TYPES, true)) {
            throw new ValidationException($this->tm->trans('community.membership_type_invalid'));
        }

        return $membershipType;
    }
}
