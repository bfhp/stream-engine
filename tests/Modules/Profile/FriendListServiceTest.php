<?php

declare(strict_types=1);

namespace Tests\Modules\Profile;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Profile\FriendListService;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\UserService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;
use Tests\Support\FakePdoDatabase;

/**
 * FeedRepository/MembershipRepository are `final`, so they can't be stubbed -
 * real instances are built around a shared FakePdoDatabase instead, the same
 * technique FriendServiceTest uses for the Users side of this feature.
 *
 * The relationships under test are seeded by inserting membership rows
 * directly rather than by going through Users\FriendService::sendRequest():
 * this module can't reference that class (docs/MODULE_CONTRACT.md), and the
 * point here is precisely that the same shared `memberships`/`feeds` rows read
 * back correctly from this side.
 */
final class FriendListServiceTest extends TestCase
{
    private const int LIMIT = 50;

    private const int ME = 10;

    private const int MY_BLOG = 101;

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
     * @return array{0: FriendListService, 1: FakePdoDatabase}
     */
    private function makeService(): array
    {
        $db = new FakePdoDatabase();
        $db->addBlogFeed(self::MY_BLOG, self::ME);

        // A minimal 'user.show' page, so getConnections() has something to
        // resolve each row's profile URL against - same {username} pattern
        // FriendServiceTest's own page tree uses.
        $pageTree = new PageTree([
            new Page(
                id: 1,
                parentId: null,
                pattern: '{username}',
                pageName: null,
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'html',
                accessRule: AccessService::ACCESS_PUBLIC,
                action: 'user.show',
            ),
        ]);

        $service = new FriendListService(
            new FeedRepository($db),
            new MembershipRepository($db),
            $pageTree,
            new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache()),
        );

        return [$service, $db];
    }

    /**
     * "$subscriberId asked $ownerId" - a membership row in $ownerId's personal
     * blog. Seeds the blog feed on first use, since a user who's never posted
     * doesn't have one.
     */
    private function subscribe(FakePdoDatabase $db, int $blogId, int $ownerId, int $subscriberId): void
    {
        $db->addBlogFeed($blogId, $ownerId);
        (new MembershipRepository($db))->create($blogId, $subscriberId, MembershipRepository::ROLE_MEMBER_ID);
    }

    /**
     * The three states the tab renders, in the vocabulary the Users module's
     * friend endpoint also answers in (see FriendListService's own docblock on
     * why that agreement is a convention pinned by tests on both sides).
     */
    public function testGetConnectionsReportsAllThreeStates(): void
    {
        [$service, $db] = $this->makeService();
        $me = $this->makeUser(self::ME);

        $db->addUser(20, 'Bob', 'bob');
        $db->addUser(30, 'Carol', 'carol');
        $db->addUser(40, 'Dave', 'dave');

        // Mutual with 20, one-directional out to 30, one-directional in from 40.
        $this->subscribe($db, 102, 20, self::ME);
        $this->subscribe($db, self::MY_BLOG, self::ME, 20);
        $this->subscribe($db, 103, 30, self::ME);
        $this->subscribe($db, self::MY_BLOG, self::ME, 40);

        $items = $service->getConnections($me, self::LIMIT)['items'];
        $byId = array_column($items, 'status', 'id');

        $this->assertSame(
            [20 => 'friends', 30 => 'subscribed', 40 => 'incoming'],
            [20 => $byId[20] ?? null, 30 => $byId[30] ?? null, 40 => $byId[40] ?? null]
        );
    }

    /**
     * Actionable-first, so an incoming request the owner can accept with one
     * click never hides below their own established friendships or their
     * still-unanswered outgoing ones.
     */
    public function testGetConnectionsPutsIncomingFirstAndOutgoingLast(): void
    {
        [$service, $db] = $this->makeService();

        // Seeded in the opposite order to the one expected back, so passing
        // can't be an accident of insertion order.
        $this->subscribe($db, 103, 30, self::ME);                // outgoing only
        $this->subscribe($db, 102, 20, self::ME);                // \ mutual
        $this->subscribe($db, self::MY_BLOG, self::ME, 20);      // /
        $this->subscribe($db, self::MY_BLOG, self::ME, 40);      // incoming only

        $items = $service->getConnections($this->makeUser(self::ME), self::LIMIT)['items'];

        $this->assertSame([40, 20, 30], array_column($items, 'id'));
    }

    public function testGetConnectionsDecoratesRowsForDisplay(): void
    {
        [$service, $db] = $this->makeService();

        $db->addUser(20, 'Bob', 'bob', '/uploads/bob.png');
        $db->addUser(30, '', null);
        $this->subscribe($db, self::MY_BLOG, self::ME, 20);
        $this->subscribe($db, self::MY_BLOG, self::ME, 30);

        $items = $service->getConnections($this->makeUser(self::ME), self::LIMIT)['items'];
        $byId = array_column($items, null, 'id');

        $this->assertSame('Bob', $byId[20]['displayName']);
        $this->assertSame('bob', $byId[20]['username']);
        $this->assertSame('/uploads/bob.png', $byId[20]['avatarUrl']);
        $this->assertSame('/bob/', $byId[20]['url']);
        $this->assertSame('/api/v1/users/bob/friend', $byId[20]['actionUrl']);
        $this->assertSame('/api/v1/my/friends/20', $byId[20]['rejectUrl']);

        // No nick and no username: a placeholder name, no "@handle" line on
        // the card, the shared avatar fallback (never a hardcoded path), no
        // profile link and no username-addressed action - but "Отклонить"
        // still works, since that one is addressed by id.
        $this->assertSame('#30', $byId[30]['displayName']);
        $this->assertNull($byId[30]['username']);
        $this->assertSame(UserService::DEFAULT_AVATAR_URL, $byId[30]['avatarUrl']);
        $this->assertNull($byId[30]['url']);
        $this->assertNull($byId[30]['actionUrl']);
        $this->assertSame('/api/v1/my/friends/30', $byId[30]['rejectUrl']);
    }

    public function testGetConnectionsExcludesUnrelatedUsersAndSelf(): void
    {
        [$service, $db] = $this->makeService();

        $this->subscribe($db, self::MY_BLOG, self::ME, 20);
        // 30 and 40 have a relationship with each other, not with me.
        $this->subscribe($db, 104, 40, 30);
        // A stray self-membership must not list me as my own friend.
        $this->subscribe($db, self::MY_BLOG, self::ME, self::ME);

        $items = $service->getConnections($this->makeUser(self::ME), self::LIMIT)['items'];

        $this->assertSame([20], array_column($items, 'id'));
    }

    /**
     * Exactly $limit connections is *not* truncation - the caller is seeing
     * all of them. See getConnections()' own docblock on why this is asked
     * with limit + 1 rather than count === limit.
     */
    public function testGetConnectionsReportsTruncationOnlyWhenItActuallyCuts(): void
    {
        [$service, $db] = $this->makeService();
        $me = $this->makeUser(self::ME);

        $this->subscribe($db, self::MY_BLOG, self::ME, 20);

        $atLimit = $service->getConnections($me, 1);
        $this->assertCount(1, $atLimit['items']);
        $this->assertFalse($atLimit['truncated']);

        $this->subscribe($db, self::MY_BLOG, self::ME, 30);

        $cut = $service->getConnections($me, 1);
        $this->assertCount(1, $cut['items']);
        $this->assertTrue($cut['truncated']);
    }

    public function testGetConnectionsIsEmptyForGuests(): void
    {
        [$service] = $this->makeService();

        $this->assertSame(
            ['items' => [], 'truncated' => false],
            $service->getConnections($this->makeUser(0, role: AccessService::ROLE_USER), self::LIMIT)
        );
    }

    /**
     * Rejecting removes the *other* side's row out of the actor's own blog -
     * the one thing removing your own half (the Users module's endpoint)
     * can't do.
     */
    public function testRejectRequestRemovesTheIncomingHalfAndReportsNone(): void
    {
        [$service, $db] = $this->makeService();
        $me = $this->makeUser(self::ME);

        $this->subscribe($db, self::MY_BLOG, self::ME, 20);
        $this->assertSame('incoming', $service->getConnections($me, self::LIMIT)['items'][0]['status']);

        $this->assertSame('none', $service->rejectRequest($me, 20));
        $this->assertSame([], $service->getConnections($me, self::LIMIT)['items']);
    }

    /**
     * Rejecting while you're also subscribed to them leaves your own
     * subscription alone - the row stays, now as your unanswered outgoing
     * request.
     */
    public function testRejectRequestLeavesTheActorsOwnSubscriptionIntact(): void
    {
        [$service, $db] = $this->makeService();
        $me = $this->makeUser(self::ME);

        $this->subscribe($db, 102, 20, self::ME);
        $this->subscribe($db, self::MY_BLOG, self::ME, 20);
        $this->assertSame('friends', $service->getConnections($me, self::LIMIT)['items'][0]['status']);

        $this->assertSame('subscribed', $service->rejectRequest($me, 20));

        $items = $service->getConnections($me, self::LIMIT)['items'];
        $this->assertCount(1, $items);
        $this->assertSame('subscribed', $items[0]['status']);
    }

    public function testRejectRequestIsANoOpForSelfAndMissingIds(): void
    {
        [$service, $db] = $this->makeService();
        $me = $this->makeUser(self::ME);

        $this->subscribe($db, self::MY_BLOG, self::ME, 20);

        $this->assertSame('none', $service->rejectRequest($me, self::ME));
        $this->assertSame('none', $service->rejectRequest($me, 0));

        // Neither call touched the real relationship.
        $this->assertSame([20], array_column($service->getConnections($me, self::LIMIT)['items'], 'id'));
    }

    /**
     * A user who's never posted has no blog feed, so nobody can have
     * subscribed to them - nothing to reject, and no feed created on the way
     * past (unlike the add-a-friend path, which does have to create one).
     */
    public function testRejectRequestIsANoOpWhenActorHasNoBlog(): void
    {
        [$service] = $this->makeService();

        $this->assertSame('none', $service->rejectRequest($this->makeUser(999), 20));
    }

    public function testRejectRequestRejectsGuests(): void
    {
        [$service] = $this->makeService();

        $this->expectException(ForbiddenException::class);
        $service->rejectRequest($this->makeUser(0, role: AccessService::ROLE_USER), 20);
    }
}
