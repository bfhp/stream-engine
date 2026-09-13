<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Profile;

use StreamEngine\Core\PageTree;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\User;
use StreamEngine\Repository\MembershipRepository;

/**
 * Backs the profile page's "Subscriptions" tab: every community the current user
 * belongs to, in any capacity, on one page.
 *
 * Module-private to Profile and built by ProfileController's own constructor,
 * the same arrangement (and for the same reasons) as FriendListService next
 * door - see docs/MODULE_CONTRACT.md's "useful to exactly one module" case.
 * Read that class's docblock for the full rationale on why a Profile service
 * reads shared `memberships`/`feeds` data through Core's repositories instead
 * of calling into Modules\Users\CommunityService, which Profile may not
 * import. The split lands in the same place here:
 *
 * - Listing what you belong to is a read over shared data. That's this class.
 * - Joining and leaving need the community Feed resolved through FeedService's
 *   ACL, the owner-cannot-leave guard, and the open-vs-approval policy that
 *   decides which role a new row gets. That stays behind the Users module's
 *   POST/DELETE /api/v1/communities/{id}/membership, which the tab links to -
 *   the same endpoint the community page's own button uses, so there's one
 *   place where "leave a community" happens.
 *
 * The status vocabulary ('owner'/'moderator'/'member'/'pending') is shared
 * with CommunityService::getRelationshipStatus() by convention, not by code:
 * the tab re-renders a card from whatever that endpoint answers, so both sides
 * have to agree on the strings. Each pins its own literals in its own test.
 */
final class CommunityListService
{
    /**
     * The `feeds.type = 'community'` rows this service reads are created with
     * a default image only implicitly - `image_url` is simply left null - so
     * the fallback has to live somewhere. Modules\Users\CommunityService
     * declares the same path as its own DEFAULT_IMAGE_URL and resolves it
     * through an instance method; this module can't reach either, so the
     * constant is repeated rather than imported.
     *
     * Keep the two in sync if the asset is ever renamed. They're both just
     * naming the file at public/assets/img/default-community.svg, which is
     * the actual shared thing.
     */
    public const string DEFAULT_IMAGE_URL = '/assets/img/default-community.svg';

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly PageTree $pageTree,
        private readonly UrlGenerator $urlGenerator,
    ) {
    }

    /**
     * Every community $user belongs to, decorated for display: image (with
     * the shared fallback), community URL, member count, the status its badge
     * renders from, and the URLs its buttons act on.
     *
     * Unpaginated behind a single generous $limit, same reasoning as
     * FriendListService::getConnections(): the tab filters by name
     * client-side, which is only honest if it holds the whole list.
     * 'truncated' is answered by asking for one row more than $limit and
     * throwing it away.
     *
     * @return array{
     *     items: list<array{
     *         id:int, title:string, url:?string, imageUrl:string, status:string,
     *         memberCount:int, leaveUrl:?string, manageUrl:?string
     *     }>,
     *     truncated: bool
     * }
     */
    public function getSubscriptions(User $user, int $limit): array
    {
        if ($user->isGuest()) {
            return ['items' => [], 'truncated' => false];
        }

        $limit = max(1, $limit);
        $rows = $this->memberships->findUserCommunities($user->id, $limit + 1);
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        // Resolved once for the whole list. Both are null when the Users
        // module isn't installed, in which case cards render without links -
        // this module must not depend on another one's pages existing
        // (docs/MODULE_CONTRACT.md), so their absence is a supported state.
        $showPage = $this->pageTree->findByAction('community.show');
        $managePage = $this->pageTree->findByAction('community.manage');

        return [
            'items' => array_map(function (array $row) use ($showPage, $managePage): array {
                $slug = (string) ($row['slug'] ?? '');
                $imageUrl = (string) ($row['imageUrl'] ?? '');
                $status = $this->status($row['roleLevel']);

                return [
                    'id' => $row['id'],
                    // A community always has a title in practice; '#id' is the
                    // same last-resort placeholder the friends list uses for a
                    // user with no nick, so a broken row is still actionable
                    // rather than an empty card.
                    'title' => (string) ($row['title'] ?? '') !== '' ? (string) $row['title'] : '#'.$row['id'],
                    'url' => $showPage !== null && $slug !== ''
                        ? $this->urlGenerator->page($showPage, ['slug' => $slug])
                        : null,
                    'imageUrl' => $imageUrl !== '' ? $imageUrl : self::DEFAULT_IMAGE_URL,
                    'status' => $status,
                    'memberCount' => $row['memberCount'],
                    // Null for an owner: CommunityService::leave() refuses to
                    // let them out (there's no ownership transfer and no
                    // ownerless-community state), so offering the button would
                    // only produce a validation error. The tab shows
                    // "Manage" in its place.
                    'leaveUrl' => $status !== 'owner'
                        ? '/api/v1/communities/'.$row['id'].'/membership'
                        : null,
                    'manageUrl' => $status === 'owner' && $managePage !== null && $slug !== ''
                        ? $this->urlGenerator->page($managePage, ['slug' => $slug])
                        : null,
                ];
            }, $rows),
            'truncated' => $truncated,
        ];
    }

    /**
     * membership_roles.role_level -> the same strings
     * CommunityService::getRelationshipStatus() answers with. Level 0 is a
     * subscriber, which for a community means "asked to join an approval-only
     * community and hasn't been let in yet" - hence 'pending' rather than
     * 'subscriber'.
     */
    private function status(int $roleLevel): string
    {
        return match ($roleLevel) {
            3 => 'owner',
            2 => 'moderator',
            1 => 'member',
            default => 'pending',
        };
    }
}
