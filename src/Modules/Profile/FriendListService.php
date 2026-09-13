<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Profile;

use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Service\UserService;

/**
 * Backs the profile page's "Friends" tab: the current user's own friends and
 * pending requests in one list, plus the one mutation that list owns
 * (rejecting an incoming request).
 *
 * Module-private to Profile, built by ProfileController's own constructor -
 * see docs/MODULE_CONTRACT.md's "useful to exactly one module" case, same
 * shape as Modules\Users\FriendService/BlogPostService.
 *
 * It's a fair question why this isn't just a call into that FriendService,
 * given both are about friendship. The answer is the contract: Users owns
 * that class privately, and Profile may not import it. What Profile *can* do
 * is read the same shared platform data - `memberships` rows against personal
 * blog containers in `feeds`, through Core's own repositories - which is all
 * this needs, because the two halves of the feature split cleanly along that
 * line:
 *
 * - Listing who you're connected to, and dropping somebody else's
 *   subscription to *your own* blog, only ever touch containers you own or
 *   rows you can already see. That's here.
 * - Adding or removing your *own* half of a relationship needs the
 *   lazily-created blog feed of the other person
 *   (BlogPostService::getOrCreateUserBlogFeed()) and sends the system
 *   notification that goes with it. That stays behind the Users module's
 *   POST/DELETE /api/v1/users/{username}/friend, which the tab links to
 *   rather than reimplements - the same endpoint the public profile sidebar
 *   already uses, so there's one place where "add a friend" happens.
 *
 * The status vocabulary ('friends'/'subscribed'/'incoming') is shared with
 * FriendService::getRelationshipStatus() by convention, not by code - the tab
 * re-renders a row from whichever of the two endpoints it just called, so both
 * have to answer in the same strings. Each side pins its own literals in its
 * own test (FriendListServiceTest here, FriendServiceTest there); a change to
 * one without the other shows up as a row that renders with the wrong badge.
 */
final class FriendListService
{
    private readonly TranslationManager $tm;

    public function __construct(
        private readonly FeedRepository $feeds,
        private readonly MembershipRepository $memberships,
        private readonly PageTree $pageTree,
        private readonly UrlGenerator $urlGenerator,
        ?TranslationManager $translationManager = null,
    ) {
        $this->tm = $translationManager ?? new TranslationManager('ru', 'ru');
    }

    /**
     * Everyone $user has a relationship with in either direction, already
     * decorated for display: avatar (with UserService's fallback, never a
     * hardcoded path), profile URL, and the two API URLs the row's buttons
     * act on.
     *
     * Unpaginated behind a single generous $limit rather than offset pages,
     * because the tab filters by name client-side and that only works
     * honestly if it holds the whole list. 'truncated' says whether $limit
     * actually cut anything off - answered by asking for one row more than
     * $limit and throwing it away, rather than by a second COUNT(*), and
     * rather than by `count === $limit`, which would cry truncation at
     * someone who has exactly $limit connections and is seeing all of them.
     *
     * `username` is carried separately from `url` even though both come from
     * the same field: the card shows "@name" as a second line under the
     * display name (mirroring the public users list), which is worth having
     * even on a row whose profile page can't be linked to.
     *
     * @return array{
     *     items: list<array{
     *         id:int, displayName:string, username:?string, avatarUrl:string,
     *         url:?string, status:string, actionUrl:?string, rejectUrl:string
     *     }>,
     *     truncated: bool
     * }
     */
    public function getConnections(User $user, int $limit): array
    {
        if ($user->isGuest()) {
            return ['items' => [], 'truncated' => false];
        }

        $limit = max(1, $limit);
        $rows = $this->memberships->findConnections($user->id, $limit + 1);
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        // Resolved once for the whole list, not per row. Null if the Users
        // module isn't installed, in which case names render as plain text
        // instead of links - this module must not depend on another one's
        // pages existing (docs/MODULE_CONTRACT.md), so the absence is a
        // supported state rather than an error. Same "no username, no link"
        // rule applies per row: the public username is assigned by a
        // backfill after registration, not at registration.
        $showPage = $this->pageTree->findByAction('user.show');

        return [
            'items' => array_map(function (array $row) use ($showPage): array {
                $username = (string) ($row['username'] ?? '');

                return [
                    'id' => $row['id'],
                    'displayName' => $row['nick'] !== '' ? $row['nick'] : '#'.$row['id'],
                    'username' => $username !== '' ? $username : null,
                    'avatarUrl' => UserService::resolveAvatarUrl($row['avatarUrl']),
                    'url' => $showPage !== null && $username !== ''
                        ? $this->urlGenerator->page($showPage, ['username' => $username])
                        : null,
                    'status' => match (true) {
                        $row['incoming'] && $row['outgoing'] => 'friends',
                        $row['outgoing'] => 'subscribed',
                        default => 'incoming',
                    },
                    // The Users module's own add/remove endpoint (see this
                    // class's docblock for why the tab links to it). Null -
                    // and the tab then renders no accept/remove button - when
                    // there's no username to address it by.
                    'actionUrl' => $username !== ''
                        ? '/api/v1/users/'.rawurlencode($username).'/friend'
                        : null,
                    // This module's own, addressed by user id, so it works
                    // even for someone still without a public username.
                    'rejectUrl' => '/api/v1/my/friends/'.$row['id'],
                ];
            }, $rows),
            'truncated' => $truncated,
        ];
    }

    /**
     * $actor turns down $subscriberId's pending request: deletes that user's
     * membership row from $actor's *own* blog. Returns the relationship
     * status that's left, in the same vocabulary the tab already gets back
     * from the Users module's friend endpoint, so the row can be dropped or
     * re-rendered without a second round trip.
     *
     * Note this is also what finally clears a former friend off the list.
     * Removing your own half of a mutual friendship (the Users endpoint)
     * deliberately only downgrades it - it isn't your place to cancel the
     * other person's subscription to you silently - so the relationship
     * reappears as an incoming request until it's either reciprocated again
     * or rejected here.
     *
     * Nobody gets notified, deliberately: telling someone their request was
     * turned down is worse for both sides than letting it lapse quietly.
     *
     * A no-op (not an error) if there's nothing to remove, if $actor has no
     * blog feed yet (nobody can have subscribed to a feed that doesn't
     * exist), or if $actor is somehow rejecting themselves.
     *
     * @throws ForbiddenException
     */
    public function rejectRequest(User $actor, int $subscriberId): string
    {
        if ($actor->isGuest()) {
            throw new ForbiddenException($this->tm->trans('profile.unauthorized'));
        }

        if ($subscriberId <= 0 || $subscriberId === $actor->id) {
            return 'none';
        }

        $actorBlog = $this->feeds->findByOwnerAndType($actor->id, 'blog', $actor);
        if ($actorBlog !== null) {
            $this->memberships->delete($actorBlog->id, $subscriberId);
        }

        return $this->remainingStatus($actor, $subscriberId);
    }

    /**
     * What's left of the relationship once the incoming half is gone: only
     * $actor's own subscription to $subscriberId can still exist, so this is
     * 'subscribed' or 'none' and never 'friends'/'incoming'.
     *
     * The other user's blog is looked up *as $actor* rather than as its owner
     * (which is what Users\FriendService does internally) because that's the
     * only viewer this module has to hand - fine in practice, since personal
     * blog containers are created 'public' and there's no UI to change that,
     * so FeedRepository's ACL lets any viewer resolve one. A blog that
     * somehow weren't visible would read as 'none', i.e. the row disappears
     * from the tab - the safe direction to fail in.
     */
    private function remainingStatus(User $actor, int $otherUserId): string
    {
        $otherBlog = $this->feeds->findByOwnerAndType($otherUserId, 'blog', $actor);

        return $otherBlog !== null && $this->memberships->exists($otherBlog->id, $actor->id)
            ? 'subscribed'
            : 'none';
    }
}
