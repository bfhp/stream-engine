<?php

declare(strict_types=1);

namespace Tests\Modules\Profile;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Profile\CommunityListService;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Service\AccessService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;
use Tests\Support\FakePdoDatabase;

/**
 * MembershipRepository is `final`, so it can't be stubbed - a real one is
 * built around a FakePdoDatabase instead, the same technique
 * FriendListServiceTest uses for the other half of this page.
 *
 * Memberships are seeded by inserting rows directly rather than through
 * Users\CommunityService::join(): this module can't reference that class
 * (docs/MODULE_CONTRACT.md), and the point is precisely that the same shared
 * `memberships`/`feeds` rows read back correctly from this side.
 */
final class CommunityListServiceTest extends TestCase
{
    private const int LIMIT = 50;

    private const int ME = 10;

    private function makeUser(int $id, string $role = AccessService::ROLE_USER): User
    {
        return new User(
            id: $id,
            email: "user{$id}@example.com",
            role: $role,
            nick: "User {$id}",
        );
    }

    /**
     * Minimal 'community.show-slug' and 'community.manage' pages so the service has
     * something to resolve card URLs against. Nested the way the real page tree
     * is (manage is a child of the community page), so the generated paths
     * differ from each other and a mixed-up lookup would show up.
     *
     * @return array{0: PageTree, 1: UrlGenerator}
     */
    private function makePageTreeAndUrlGenerator(): array
    {
        $pageTree = new PageTree([
            new Page(
                id: 1,
                parentId: null,
                pattern: 'comm',
                pageName: null,
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'html',
                accessRule: AccessService::ACCESS_PUBLIC,
                action: 'community.main',
            ),
            new Page(
                id: 2,
                parentId: 1,
                pattern: '{slug}',
                pageName: null,
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'html',
                accessRule: AccessService::ACCESS_PUBLIC,
                action: 'community.show-slug',
            ),
            new Page(
                id: 3,
                parentId: 2,
                pattern: 'manage',
                pageName: null,
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'html',
                accessRule: AccessService::ACCESS_AUTHENTICATED,
                action: 'community.manage',
            ),
        ]);

        return [$pageTree, new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache())];
    }

    /**
     * @return array{0: CommunityListService, 1: FakePdoDatabase}
     */
    private function makeService(): array
    {
        $db = new FakePdoDatabase();
        [$pageTree, $urlGenerator] = $this->makePageTreeAndUrlGenerator();

        return [
            new CommunityListService(new MembershipRepository($db), $pageTree, $urlGenerator),
            $db,
        ];
    }

    private function join(FakePdoDatabase $db, int $communityId, int $userId, int $roleId): void
    {
        (new MembershipRepository($db))->create($communityId, $userId, $roleId);
    }

    /**
     * The four states the tab renders, in the vocabulary
     * CommunityService::getRelationshipStatus() also answers in - a
     * subscriber row on a community means "asked to join, not let in yet",
     * which is 'pending' here rather than 'subscriber'.
     */
    public function testGetSubscriptionsMapsEveryRoleLevelToItsStatus(): void
    {
        [$service, $db] = $this->makeService();

        $db->addCommunityFeed(501, self::ME, 'mine', 'Моё');
        $db->addCommunityFeed(502, 99, 'mod', 'Модерирую');
        $db->addCommunityFeed(503, 99, 'in', 'Участвую');
        $db->addCommunityFeed(504, 99, 'wait', 'Жду');

        $this->join($db, 501, self::ME, MembershipRepository::ROLE_OWNER_ID);
        $this->join($db, 502, self::ME, MembershipRepository::ROLE_MODERATOR_ID);
        $this->join($db, 503, self::ME, MembershipRepository::ROLE_MEMBER_ID);
        $this->join($db, 504, self::ME, MembershipRepository::ROLE_SUBSCRIBER_ID);

        $items = $service->getSubscriptions($this->makeUser(self::ME), self::LIMIT)['items'];

        $this->assertSame(
            [501 => 'owner', 502 => 'moderator', 503 => 'member', 504 => 'pending'],
            array_column($items, 'status', 'id')
        );
    }

    /**
     * Ordered by standing - owner, moderator, member, pending - so the list
     * reads top-down as "closest to me first" no matter what order the rows
     * were created in.
     */
    public function testGetSubscriptionsOrdersByStanding(): void
    {
        [$service, $db] = $this->makeService();

        // Seeded in reverse of the expected order.
        $db->addCommunityFeed(504, 99, 'wait', 'Жду');
        $db->addCommunityFeed(503, 99, 'in', 'Участвую');
        $db->addCommunityFeed(501, self::ME, 'mine', 'Моё');

        $this->join($db, 504, self::ME, MembershipRepository::ROLE_SUBSCRIBER_ID);
        $this->join($db, 503, self::ME, MembershipRepository::ROLE_MEMBER_ID);
        $this->join($db, 501, self::ME, MembershipRepository::ROLE_OWNER_ID);

        $items = $service->getSubscriptions($this->makeUser(self::ME), self::LIMIT)['items'];

        $this->assertSame([501, 503, 504], array_column($items, 'id'));
    }

    public function testGetSubscriptionsDecoratesRowsForDisplay(): void
    {
        [$service, $db] = $this->makeService();

        $db->addCommunityFeed(503, 99, 'photos', 'Фотографы', '/uploads/photos.png');
        $this->join($db, 503, self::ME, MembershipRepository::ROLE_MEMBER_ID);

        $item = $service->getSubscriptions($this->makeUser(self::ME), self::LIMIT)['items'][0];

        $this->assertSame('Фотографы', $item['title']);
        $this->assertSame('/uploads/photos.png', $item['imageUrl']);
        $this->assertSame('/comm/photos/', $item['url']);
        $this->assertSame('/api/v1/communities/503/membership', $item['leaveUrl']);
        $this->assertNull($item['manageUrl']);
    }

    /**
     * An owner can't leave (CommunityService::leave() refuses), so the card
     * gets no leave URL at all - and a manage URL instead, which nobody else
     * gets.
     */
    public function testGetSubscriptionsGivesOwnersManageUrlAndNoLeaveUrl(): void
    {
        [$service, $db] = $this->makeService();

        $db->addCommunityFeed(501, self::ME, 'mine', 'Моё');
        $db->addCommunityFeed(503, 99, 'in', 'Участвую');
        $this->join($db, 501, self::ME, MembershipRepository::ROLE_OWNER_ID);
        $this->join($db, 503, self::ME, MembershipRepository::ROLE_MEMBER_ID);

        $byId = array_column($service->getSubscriptions($this->makeUser(self::ME), self::LIMIT)['items'], null, 'id');

        $this->assertNull($byId[501]['leaveUrl']);
        $this->assertSame('/comm/mine/manage/', $byId[501]['manageUrl']);

        $this->assertSame('/api/v1/communities/503/membership', $byId[503]['leaveUrl']);
        $this->assertNull($byId[503]['manageUrl']);
    }

    /**
     * The count is real members only - a community's pending join requests
     * aren't members yet, the same rule countMembers() applies to a
     * community's public roster.
     */
    public function testGetSubscriptionsCountsMembersExcludingPendingRequests(): void
    {
        [$service, $db] = $this->makeService();

        $db->addCommunityFeed(503, 99, 'in', 'Участвую');
        $this->join($db, 503, 99, MembershipRepository::ROLE_OWNER_ID);
        $this->join($db, 503, self::ME, MembershipRepository::ROLE_MEMBER_ID);
        $this->join($db, 503, 77, MembershipRepository::ROLE_SUBSCRIBER_ID);

        $items = $service->getSubscriptions($this->makeUser(self::ME), self::LIMIT)['items'];

        $this->assertSame(2, $items[0]['memberCount']);
    }

    /**
     * Personal blogs live in the same `memberships` table - subscribing to
     * someone's blog is a row against a `type = 'blog'` container - so the
     * community query has to be constrained by feed type or the friends list
     * leaks in here.
     */
    public function testGetSubscriptionsIgnoresBlogSubscriptionsAndOtherPeoplesRows(): void
    {
        [$service, $db] = $this->makeService();

        $db->addCommunityFeed(503, 99, 'in', 'Участвую');
        $db->addBlogFeed(101, 20);
        $db->addCommunityFeed(505, 99, 'other', 'Чужое');

        $this->join($db, 503, self::ME, MembershipRepository::ROLE_MEMBER_ID);
        $this->join($db, 101, self::ME, MembershipRepository::ROLE_MEMBER_ID);
        $this->join($db, 505, 77, MembershipRepository::ROLE_MEMBER_ID);

        $items = $service->getSubscriptions($this->makeUser(self::ME), self::LIMIT)['items'];

        $this->assertSame([503], array_column($items, 'id'));
    }

    public function testGetSubscriptionsFallsBackForMissingImageAndTitle(): void
    {
        [$service, $db] = $this->makeService();

        $db->addCommunityFeed(506, 99, null, null, null);
        $this->join($db, 506, self::ME, MembershipRepository::ROLE_MEMBER_ID);

        $item = $service->getSubscriptions($this->makeUser(self::ME), self::LIMIT)['items'][0];

        $this->assertSame(CommunityListService::DEFAULT_IMAGE_URL, $item['imageUrl']);
        $this->assertSame('#506', $item['title']);
        // No slug means no page to link to, but leaving still works: that one
        // is addressed by community id.
        $this->assertNull($item['url']);
        $this->assertSame('/api/v1/communities/506/membership', $item['leaveUrl']);
    }

    /**
     * Exactly $limit rows is not truncation - see getSubscriptions()' own
     * docblock on why this is asked with limit + 1.
     */
    public function testGetSubscriptionsReportsTruncationOnlyWhenItActuallyCuts(): void
    {
        [$service, $db] = $this->makeService();
        $me = $this->makeUser(self::ME);

        $db->addCommunityFeed(503, 99, 'in', 'Участвую');
        $this->join($db, 503, self::ME, MembershipRepository::ROLE_MEMBER_ID);

        $atLimit = $service->getSubscriptions($me, 1);
        $this->assertCount(1, $atLimit['items']);
        $this->assertFalse($atLimit['truncated']);

        $db->addCommunityFeed(504, 99, 'wait', 'Жду');
        $this->join($db, 504, self::ME, MembershipRepository::ROLE_MEMBER_ID);

        $cut = $service->getSubscriptions($me, 1);
        $this->assertCount(1, $cut['items']);
        $this->assertTrue($cut['truncated']);
    }

    public function testGetSubscriptionsIsEmptyForGuests(): void
    {
        [$service] = $this->makeService();

        $this->assertSame(
            ['items' => [], 'truncated' => false],
            $service->getSubscriptions($this->makeUser(0, role: AccessService::ROLE_USER), self::LIMIT)
        );
    }
}
