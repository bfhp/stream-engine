<?php

declare(strict_types=1);

namespace Tests\Modules\Users;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Config;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Users\CommunityService;
use StreamEngine\Repository\FeedMetadataRepository;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

/**
 * Mirrors BlogPostServiceTest's own approach: a real FeedRepository/
 * MembershipRepository/FeedMetadataRepository wired to one mocked
 * PdoDatabase (rather than a full MySQL connection), matching production's
 * own wiring (UsersController builds CommunityService off the exact same
 * FeedService/MembershipRepository instances). fetchOne()/execute() are
 * stubbed by matching a distinctive substring of each query, not strict
 * call-count ordering - createCommunity() touches feeds (insert, then
 * findById) and feed_metadata (replace), so content-based matching is less
 * brittle than BlogPostServiceTest's consecutive-call style here. There's no
 * membership_roles lookup to stub anymore - CommunityService grants
 * MembershipRepository::ROLE_OWNER_ID directly (a hardcoded id, not a
 * runtime `SELECT id FROM membership_roles WHERE name = ?`).
 *
 * The mocked db's execute() callback records what got written into
 * $insertedMetadata/$createdMemberships as plain instance properties
 * (reset in setUp()) rather than local variables threaded back out of
 * makeService() by reference - a closure capturing a local by reference
 * stays live fine on its own, but chaining that reference back through a
 * function's *return value* via `[, &$x] = method()` does not reliably
 * survive the call boundary, so the closures and the assertions need to
 * share state some other way.
 */
final class CommunityServiceTest extends TestCase
{
    private const int COMMUNITY_ID = 500;

    /** @var list<array> */
    private array $notificationDeliveries = [];

    private function notifications(): \StreamEngine\Service\NotificationService
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturnCallback(function (string $sql, array $params): int {
            $this->notificationDeliveries[] = $params;

            return 1;
        });

        return new \StreamEngine\Service\NotificationService(
            (new \ReflectionClass(\StreamEngine\Service\MessageService::class))->newInstanceWithoutConstructor(),
            new \StreamEngine\Repository\NotificationDeliveryRepository($db),
            new \StreamEngine\Repository\NotificationPreferenceRepository($this->createStub(PdoDatabase::class)),
            new \StreamEngine\Repository\UserRepository($this->createStub(PdoDatabase::class)),
            (new \ReflectionClass(\StreamEngine\Service\MailService::class))->newInstanceWithoutConstructor(),
        );
    }

    /** @var array<string, string> */
    private array $insertedMetadata = [];

    /** @var list<array{int, int, int}> */
    private array $createdMemberships = [];

    /** @var list<mixed> params of the last `INSERT INTO feeds` call, positional (see FeedRepository::insert()) */
    private array $insertedFeedParams = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->insertedMetadata = [];
        $this->createdMemberships = [];
        $this->insertedFeedParams = [];
    }

    private function trans(string $key, array $params = []): string
    {
        return (new TranslationManager('ru', 'en'))->trans($key, $params);
    }

    private function makeCommunityRow(int $id, int $ownerId, string $title, ?string $description, ?string $imageUrl = null): array
    {
        return [
            'id' => $id,
            'parent_id' => null,
            'owner_id' => $ownerId,
            'type' => 'community',
            'slug' => null,
            'title' => $title,
            'content' => '',
            'description' => $description,
            'image_url' => $imageUrl,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => time(),
            'nick' => '',
            'avatar_url' => '',
        ];
    }

    /**
     * @return array{0: CommunityService, 1: \PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeService(): array
    {
        $db = $this->createMock(PdoDatabase::class);

        $db->method('fetchOne')->willReturnCallback(
            function (string $sql): ?array {
                if (str_contains($sql, 'FROM feeds f') && str_contains($sql, 'f.id = ?')) {
                    return $this->makeCommunityRow(self::COMMUNITY_ID, 7, 'My Community', 'A description');
                }

                return null;
            }
        );

        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params): int {
                if (str_contains($sql, 'INSERT INTO feeds')) {
                    $this->insertedFeedParams = $params;
                }

                if (str_contains($sql, 'INSERT INTO feed_metadata')) {
                    $this->insertedMetadata[$params[1]] = $params[2];
                }

                if (str_contains($sql, 'INSERT IGNORE INTO memberships')) {
                    $this->createdMemberships[] = [$params[0], $params[1], $params[2]];
                }

                return 1;
            }
        );

        $db->method('lastInsertId')->willReturn(self::COMMUNITY_ID);

        // decorateFeedsWithMetadata() reads back what replaceForFeed() just
        // wrote via FeedMetadataRepository::findByFeedIds() - reflecting
        // $this->insertedMetadata here (rather than always returning [])
        // lets tests assert on $community->metadata too, not just the raw
        // INSERT INTO feed_metadata calls.
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql): array {
                if (! str_contains($sql, 'FROM feed_metadata')) {
                    return [];
                }

                $rows = [];
                foreach ($this->insertedMetadata as $name => $content) {
                    $rows[] = ['feed_id' => self::COMMUNITY_ID, 'name' => $name, 'content' => $content];
                }

                return $rows;
            }
        );

        $feedRepository = new FeedRepository($db);
        $urlGenerator = new UrlGenerator(new PageTree([]), new FakeFeedRepository([]), new ArrayCache());

        $feedService = new FeedService(
            $feedRepository,
            $urlGenerator,
            $this->createStub(AccessService::class),
            new Formatter(new TranslationManager('ru', 'en'), 'ru'),
            new TranslationManager('ru', 'en'),
            new FeedMetadataRepository($db),
        );

        $service = new CommunityService(
            $feedService,
            $feedRepository,
            new MembershipRepository($db),
            new TranslationManager('ru', 'en'),
            new PageTree([]),
            $urlGenerator,
            $this->notifications(),
            new Config(['SITE_URL' => 'https://example.test']),
        );

        return [$service, $db];
    }

    /**
     * The mocked db here is only ever ->method()-stubbed, never
     * ->expects()-asserted (the assertions are on $this->insertedMetadata/
     * $this->createdMemberships instead, populated by those same stubbed
     * callbacks) - PHPUnit 12 otherwise flags a mock with no configured
     * expectations as "consider a stub instead", which doesn't apply here
     * since createMock() (not createStub()) is still needed by the sibling
     * tests that share makeService() and do assert call counts.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testCreateCommunityOpenMembershipGrantsCreatorOwnerRole(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        [$service] = $this->makeService();

        $community = $service->createCommunity(
            user: $user,
            name: 'My Community',
            description: 'A description',
            imageUrl: null,
            membershipType: CommunityService::MEMBERSHIP_TYPE_OPEN,
        );

        $this->assertSame(self::COMMUNITY_ID, $community->id);
        $this->assertSame('community', $community->type);
        $this->assertSame(['membership_type' => 'open'], $this->insertedMetadata);
        $this->assertSame(['membership_type' => 'open'], $community->metadata);
        $this->assertSame(
            [[self::COMMUNITY_ID, 7, MembershipRepository::ROLE_OWNER_ID]],
            $this->createdMemberships
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCreateCommunityApprovalMembershipStoresMetadata(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        [$service] = $this->makeService();

        $service->createCommunity(
            user: $user,
            name: 'My Community',
            description: null,
            imageUrl: null,
            membershipType: CommunityService::MEMBERSHIP_TYPE_APPROVAL,
        );

        $this->assertSame(['membership_type' => 'approval'], $this->insertedMetadata);
    }

    public function testCreateCommunityThrowsForGuestUser(): void
    {
        $user = new User(id: 0, email: '', role: AccessService::ROLE_USER);

        [$service, $db] = $this->makeService();

        $db->expects($this->never())->method('execute');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage($this->trans('feed.forbidden'));

        $service->createCommunity(
            user: $user,
            name: 'My Community',
            description: null,
            imageUrl: null,
            membershipType: CommunityService::MEMBERSHIP_TYPE_OPEN,
        );
    }

    public function testCreateCommunityThrowsWhenNameIsEmpty(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        [$service, $db] = $this->makeService();
        $db->expects($this->never())->method('execute');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('community.name_required'));

        $service->createCommunity(
            user: $user,
            name: '   ',
            description: null,
            imageUrl: null,
            membershipType: CommunityService::MEMBERSHIP_TYPE_OPEN,
        );
    }

    public function testCreateCommunityThrowsForInvalidMembershipType(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        [$service, $db] = $this->makeService();
        $db->expects($this->never())->method('execute');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('community.membership_type_invalid'));

        $service->createCommunity(
            user: $user,
            name: 'My Community',
            description: null,
            imageUrl: null,
            membershipType: 'not-a-real-type',
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCreateCommunitySlugifiesName(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        [$service] = $this->makeService();

        $service->createCommunity(
            user: $user,
            name: 'My Community',
            description: null,
            imageUrl: null,
            membershipType: CommunityService::MEMBERSHIP_TYPE_OPEN,
        );

        $this->assertSame('my-community', $this->insertedFeedParams[4]);
    }

    /**
     * 'create' collides with community.create's own pattern (a sibling
     * page under the same community.main parent) - RESERVED_COMMUNITY_SLUGS
     * makes uniqueCommunitySlug() treat it exactly like an already-taken
     * slug, same as BlogPostService::RESERVED_BLOG_POST_SLUGS does for
     * blog posts.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testCreateCommunityAvoidsReservedSlug(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        [$service] = $this->makeService();

        $service->createCommunity(
            user: $user,
            name: 'Create',
            description: null,
            imageUrl: null,
            membershipType: CommunityService::MEMBERSHIP_TYPE_OPEN,
        );

        $this->assertSame('create-2', $this->insertedFeedParams[4]);
    }

    /**
     * Builds a CommunityService around a caller-supplied (mocked) db, for
     * the getRelationshipStatus()/join()/leave()/getMembers() tests below -
     * none of them touch FeedService/FeedRepository/PageTree/UrlGenerator
     * (join()/leave() read membership_type off the Feed argument directly,
     * not by re-fetching it), so those collaborators are stubbed/empty
     * rather than wired to the mocked db the way makeService() wires
     * FeedService for createCommunity()'s own tests.
     */
    private function makeMinimalService(PdoDatabase $db, ?PageTree $pages = null, ?Config $config = null): CommunityService
    {
        $pages ??= new PageTree([]);

        return new CommunityService(
            $this->createStub(FeedService::class),
            new FeedRepository($db),
            new MembershipRepository($db),
            new TranslationManager('ru', 'en'),
            $pages,
            new UrlGenerator($pages, new FakeFeedRepository([]), new ArrayCache()),
            $this->notifications(),
            $config ?? new Config(['SITE_URL' => 'https://example.test']),
        );
    }

    private function makeCommunityFeed(?string $membershipType = null): Feed
    {
        $community = Feed::fromRow($this->makeCommunityRow(self::COMMUNITY_ID, 7, 'My Community', null));

        if ($membershipType !== null) {
            $community->metadata = ['membership_type' => $membershipType];
        }

        return $community;
    }

    public function testResolveImageUrlReturnsOwnImageWhenSet(): void
    {
        $service = $this->makeMinimalService($this->createStub(PdoDatabase::class));
        $community = Feed::fromRow($this->makeCommunityRow(self::COMMUNITY_ID, 7, 'My Community', null, '/uploads/community-1.webp'));

        $this->assertSame('/uploads/community-1.webp', $service->resolveImageUrl($community));
    }

    public function testResolveImageUrlFallsBackToDefaultWhenNullOrEmpty(): void
    {
        $service = $this->makeMinimalService($this->createStub(PdoDatabase::class));

        $withNullImage = Feed::fromRow($this->makeCommunityRow(self::COMMUNITY_ID, 7, 'My Community', null, null));
        $withEmptyImage = Feed::fromRow($this->makeCommunityRow(self::COMMUNITY_ID, 7, 'My Community', null, ''));

        $this->assertSame(CommunityService::DEFAULT_IMAGE_URL, $service->resolveImageUrl($withNullImage));
        $this->assertSame(CommunityService::DEFAULT_IMAGE_URL, $service->resolveImageUrl($withEmptyImage));
        $this->assertSame('/assets/img/default-community.svg', $service->resolveImageUrl($withNullImage));
    }

    public function testGetRelationshipStatusReturnsNoneForGuest(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $service = $this->makeMinimalService($db);

        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);

        $this->assertSame('none', $service->getRelationshipStatus($guest, $this->makeCommunityFeed()));
    }

    /**
     * @return array<string, array{0: ?int, 1: string}>
     */
    public static function roleLevelStatusProvider(): array
    {
        return [
            'no membership row' => [null, 'none'],
            'subscriber row (pending approval)' => [0, 'pending'],
            'member' => [1, 'member'],
            'moderator' => [2, 'moderator'],
            'owner' => [3, 'owner'],
        ];
    }

    #[DataProvider('roleLevelStatusProvider')]
    public function testGetRelationshipStatusMapsRoleLevelToStatus(?int $roleLevel, string $expectedStatus): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturnCallback(
            function (string $sql) use ($roleLevel): ?array {
                if (str_contains($sql, 'FROM memberships m') && str_contains($sql, 'JOIN membership_roles')) {
                    return $roleLevel === null ? null : ['role_level' => $roleLevel];
                }

                return null;
            }
        );

        $service = $this->makeMinimalService($db);
        $viewer = new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER);

        $this->assertSame($expectedStatus, $service->getRelationshipStatus($viewer, $this->makeCommunityFeed()));
    }

    public function testJoinCreatesMemberRoleForOpenCommunity(): void
    {
        $createdRoleId = null;

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturnCallback(
            function (string $sql) use (&$createdRoleId): ?array {
                if (! str_contains($sql, 'FROM memberships m') || ! str_contains($sql, 'JOIN membership_roles')) {
                    return null;
                }

                if ($createdRoleId === null) {
                    return null;
                }

                return ['role_level' => match ($createdRoleId) {
                    MembershipRepository::ROLE_SUBSCRIBER_ID => 0,
                    MembershipRepository::ROLE_MEMBER_ID => 1,
                    MembershipRepository::ROLE_MODERATOR_ID => 2,
                    MembershipRepository::ROLE_OWNER_ID => 3,
                }];
            }
        );
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params) use (&$createdRoleId): int {
                if (str_contains($sql, 'INSERT IGNORE INTO memberships')) {
                    $createdRoleId = $params[2];
                }

                return 1;
            }
        );

        $service = $this->makeMinimalService($db);
        $user = new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER);
        $community = $this->makeCommunityFeed(CommunityService::MEMBERSHIP_TYPE_OPEN);

        $status = $service->join($user, $community);

        $this->assertSame('member', $status);
        $this->assertSame(MembershipRepository::ROLE_MEMBER_ID, $createdRoleId);
        self::assertSame([], $this->notificationDeliveries);
    }

    public function testJoinCreatesSubscriberRoleForApprovalCommunity(): void
    {
        $createdRoleId = null;

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturnCallback(
            function (string $sql) use (&$createdRoleId): ?array {
                if (! str_contains($sql, 'FROM memberships m') || ! str_contains($sql, 'JOIN membership_roles')) {
                    return null;
                }

                if ($createdRoleId === null) {
                    return null;
                }

                return ['role_level' => 0];
            }
        );
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params) use (&$createdRoleId): int {
                if (str_contains($sql, 'INSERT IGNORE INTO memberships')) {
                    $createdRoleId = $params[2];
                }

                return 1;
            }
        );

        $service = $this->makeMinimalService($db);
        $user = new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER);
        $community = $this->makeCommunityFeed(CommunityService::MEMBERSHIP_TYPE_APPROVAL);

        $status = $service->join($user, $community);

        $this->assertSame('pending', $status);
        $this->assertSame(MembershipRepository::ROLE_SUBSCRIBER_ID, $createdRoleId);
        $service->join($user, $community);
        self::assertCount(1, $this->notificationDeliveries);
        self::assertSame([7, 'community.join_request'], array_slice($this->notificationDeliveries[0], 0, 2));
        self::assertSame(42, json_decode($this->notificationDeliveries[0][4], true)['actorUserId']);
    }

    public static function communityNotificationActions(): array
    {
        return ['request' => [false], 'approval' => [true], 'removal' => [true, true]];
    }

    #[DataProvider('communityNotificationActions')]
    public function testCommunityNotificationLinksAndEscapesUserText(bool $approval, bool $removal = false): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturn(1);
        $db->method('fetchOne')->willReturn($approval ? ['role_level' => $removal ? 1 : 0] : null);
        $page = new \StreamEngine\Domain\Page(
            100,
            null,
            $approval ? 'community/{slug}' : 'community/{slug}/manage',
            null,
            null,
            null,
            null,
            null,
            false,
            ['GET'],
            'html',
            AccessService::ACCESS_AUTHENTICATED,
            $approval ? 'community.show' : 'community.manage',
        );
        $community = Feed::fromRow([
            ...$this->makeCommunityRow(self::COMMUNITY_ID, 7, '<Community>', null),
            'slug' => 'test',
        ]);
        $community->metadata = ['membership_type' => CommunityService::MEMBERSHIP_TYPE_APPROVAL];
        $service = $this->makeMinimalService($db, new PageTree([$page]));
        if ($approval) {
            $method = $removal ? 'removeMember' : 'approveSubscriber';
            $service->$method(
                new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER),
                $community,
                42,
            );
        } else {
            $service->join(
                new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER, nick: '<Actor>'),
                $community,
            );
        }

        self::assertCount(1, $this->notificationDeliveries);
        $delivery = $this->notificationDeliveries[0];
        $url = 'https://example.test/community/test/'.($approval ? '' : 'manage/');
        self::assertSame($url, json_decode($delivery[4], true)['contentUrl']);
        if (! $approval) {
            self::assertStringContainsString('&lt;Actor&gt;', $delivery[5]);
        }
        self::assertStringContainsString('&lt;Community&gt;', $delivery[5]);
        self::assertStringContainsString('href="'.$url.'"', $delivery[5]);
    }

    public function testCommunityNotificationOmitsLinkWhenCanonicalUrlIsMissing(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturn(1);
        $page = new \StreamEngine\Domain\Page(
            100,
            null,
            'community/{slug}/manage',
            null,
            null,
            null,
            null,
            null,
            false,
            ['GET'],
            'html',
            AccessService::ACCESS_AUTHENTICATED,
            'community.manage',
        );
        $community = Feed::fromRow([
            ...$this->makeCommunityRow(self::COMMUNITY_ID, 7, 'Community', null),
            'slug' => 'test',
        ]);
        $community->metadata = ['membership_type' => CommunityService::MEMBERSHIP_TYPE_APPROVAL];

        $this->makeMinimalService($db, new PageTree([$page]), new Config([]))->join(
            new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER, nick: 'Actor'),
            $community,
        );

        $delivery = $this->notificationDeliveries[0];
        self::assertNull(json_decode($delivery[4], true)['contentUrl']);
        self::assertStringNotContainsString('href=', $delivery[5]);
    }

    public function testJoinDoesNotNotifyWhenConcurrentRequestAlreadyInsertedMembership(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturnOnConsecutiveCalls(null, ['role_level' => 0]);
        $db->method('execute')->willReturn(0);

        $status = $this->makeMinimalService($db)->join(
            new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER),
            $this->makeCommunityFeed(CommunityService::MEMBERSHIP_TYPE_APPROVAL),
        );

        self::assertSame('pending', $status);
        self::assertSame([], $this->notificationDeliveries);
    }

    public function testJoinDoesNotCreateDuplicateRowWhenAlreadyAMember(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['role_level' => 1]);
        $db->expects($this->never())->method('execute');

        $service = $this->makeMinimalService($db);
        $user = new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER);

        $status = $service->join($user, $this->makeCommunityFeed(CommunityService::MEMBERSHIP_TYPE_OPEN));

        $this->assertSame('member', $status);
    }

    public function testJoinThrowsForGuestUser(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('execute');

        $service = $this->makeMinimalService($db);
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage($this->trans('feed.forbidden'));

        $service->join($guest, $this->makeCommunityFeed(CommunityService::MEMBERSHIP_TYPE_OPEN));
    }

    public function testLeaveDeletesMembershipForRegularMember(): void
    {
        $deleted = [];

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['role_level' => 1]);
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params) use (&$deleted): int {
                if (str_contains($sql, 'DELETE FROM memberships')) {
                    $deleted[] = $params;
                }

                return 1;
            }
        );

        $service = $this->makeMinimalService($db);
        $user = new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER);

        $service->leave($user, $this->makeCommunityFeed());

        $this->assertSame([[self::COMMUNITY_ID, 42]], $deleted);
    }

    public function testLeaveIsNoopWhenNotAMember(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(null);
        $db->expects($this->never())->method('execute');

        $service = $this->makeMinimalService($db);
        $user = new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER);

        $service->leave($user, $this->makeCommunityFeed());
    }

    public function testLeaveThrowsWhenOwnerTriesToLeave(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['role_level' => 3]);
        $db->expects($this->never())->method('execute');

        $service = $this->makeMinimalService($db);
        $owner = new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('community.owner_cannot_leave'));

        $service->leave($owner, $this->makeCommunityFeed());
    }

    public function testGetMembersReturnsRepositoryItemsAndTotal(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturn([
            ['id' => 7, 'nick' => 'Owner', 'username' => 'owner', 'avatar_url' => '', 'role_level' => 3],
            ['id' => 42, 'nick' => 'Member', 'username' => 'member', 'avatar_url' => '', 'role_level' => 1],
        ]);
        $db->method('fetchOne')->willReturn(['total' => 2]);

        $service = $this->makeMinimalService($db);

        $result = $service->getMembers($this->makeCommunityFeed(), 20);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['items']);
        $this->assertSame('Owner', $result['items'][0]['nick']);
        $this->assertSame(3, $result['items'][0]['roleLevel']);
    }

    public function testGetSubscribersReturnsRepositoryItemsAndTotal(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturn([
            ['id' => 99, 'nick' => 'Pending', 'username' => 'pending', 'avatar_url' => '', 'role_level' => 0],
        ]);
        $db->method('fetchOne')->willReturn(['total' => 1]);

        $service = $this->makeMinimalService($db);

        $result = $service->getSubscribers($this->makeCommunityFeed(), 20);

        $this->assertSame(1, $result['total']);
        $this->assertSame('Pending', $result['items'][0]['nick']);
        $this->assertSame(0, $result['items'][0]['roleLevel']);
    }

    /**
     * The community's own owner (id 7, see makeCommunityFeed()) accepting a
     * pending subscriber (role_level 0) - MembershipRepository::
     * approveSubscriber() promotes it straight to ROLE_MEMBER_ID.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testApproveSubscriberPromotesPendingSubscriberToMember(): void
    {
        $changedRoles = [];

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['role_level' => 0]);
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params) use (&$changedRoles): int {
                if (str_contains($sql, 'UPDATE memberships') && str_contains($sql, 'user_id = ?')) {
                    $changedRoles[] = $params;
                }

                return 1;
            }
        );

        $service = $this->makeMinimalService($db);
        $owner = new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER);

        $service->approveSubscriber($owner, $this->makeCommunityFeed(), 99);

        $this->assertSame([[MembershipRepository::ROLE_MEMBER_ID, self::COMMUNITY_ID, 99, MembershipRepository::ROLE_SUBSCRIBER_ID]], $changedRoles);
        self::assertCount(1, $this->notificationDeliveries);
        self::assertSame([99, 'community.join_approved'], array_slice($this->notificationDeliveries[0], 0, 2));
        self::assertSame(7, json_decode($this->notificationDeliveries[0][4], true)['actorUserId']);
    }

    public function testConcurrentApprovalsNotifyOnlyOnce(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['role_level' => 0]);
        $db->method('execute')->willReturnOnConsecutiveCalls(1, 0);
        $service = $this->makeMinimalService($db);
        $owner = new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER);

        $service->approveSubscriber($owner, $this->makeCommunityFeed(), 99);
        $service->approveSubscriber($owner, $this->makeCommunityFeed(), 99);

        self::assertCount(1, $this->notificationDeliveries);
    }

    public function testApprovalDoesNotNotifyWhenMembershipWasRemovedConcurrently(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['role_level' => 0]);
        $db->method('execute')->willReturn(0);
        $this->makeMinimalService($db)->approveSubscriber(
            new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER),
            $this->makeCommunityFeed(),
            99,
        );

        self::assertSame([], $this->notificationDeliveries);
    }

    public function testApproveSubscriberIsNoopWhenTargetIsAlreadyAMember(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['role_level' => 1]);
        $db->expects($this->never())->method('execute');

        $service = $this->makeMinimalService($db);
        $owner = new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER);

        $service->approveSubscriber($owner, $this->makeCommunityFeed(), 42);
        self::assertSame([], $this->notificationDeliveries);
    }

    public function testApproveSubscriberThrowsForbiddenForNonOwnerViewer(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('execute');

        $service = $this->makeMinimalService($db);
        $notOwner = new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage($this->trans('community.manage_forbidden'));

        $service->approveSubscriber($notOwner, $this->makeCommunityFeed(), 99);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRemoveMemberDemotesMemberToSubscriber(): void
    {
        $changedRoles = [];

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['role_level' => 1]);
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params) use (&$changedRoles): int {
                if (str_contains($sql, 'UPDATE memberships') && str_contains($sql, 'user_id = ?')) {
                    $changedRoles[] = $params;
                }

                return 1;
            }
        );

        $service = $this->makeMinimalService($db);
        $owner = new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER);

        $service->removeMember($owner, $this->makeCommunityFeed(), 42);

        $this->assertSame([[MembershipRepository::ROLE_SUBSCRIBER_ID, self::COMMUNITY_ID, 42, MembershipRepository::ROLE_MEMBER_ID, MembershipRepository::ROLE_MODERATOR_ID]], $changedRoles);
        self::assertCount(1, $this->notificationDeliveries);
        self::assertSame([42, 'community.membership_changed'], array_slice($this->notificationDeliveries[0], 0, 2));
        $payload = json_decode($this->notificationDeliveries[0][4], true);
        self::assertSame(1, $payload['previousRoleLevel']);
        self::assertSame(0, $payload['newRoleLevel']);
        self::assertStringContainsString('теперь вы подписчик', $this->notificationDeliveries[0][5]);
    }

    public function testConcurrentModeratorRemovalsNotifyOnlyOnce(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['role_level' => 2]);
        $db->method('execute')->willReturnOnConsecutiveCalls(1, 0);
        $service = $this->makeMinimalService($db);
        $owner = new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER);

        $service->removeMember($owner, $this->makeCommunityFeed(), 42);
        $service->removeMember($owner, $this->makeCommunityFeed(), 42);

        self::assertCount(1, $this->notificationDeliveries);
        self::assertSame(2, json_decode($this->notificationDeliveries[0][4], true)['previousRoleLevel']);
    }

    public function testRemovalDoesNotNotifyWhenMembershipWasDeletedConcurrently(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['role_level' => 1]);
        $db->method('execute')->willReturn(0);
        $this->makeMinimalService($db)->removeMember(
            new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER),
            $this->makeCommunityFeed(),
            42,
        );

        self::assertSame([], $this->notificationDeliveries);
    }

    public function testRemoveMemberIsNoopWhenTargetHasNoRealMembership(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(null);
        $db->expects($this->never())->method('execute');

        $service = $this->makeMinimalService($db);
        $owner = new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER);

        $service->removeMember($owner, $this->makeCommunityFeed(), 999);
    }

    public function testRemoveMemberThrowsValidationWhenTargetIsTheOwner(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('execute');

        $service = $this->makeMinimalService($db);
        $owner = new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('community.owner_cannot_leave'));

        $service->removeMember($owner, $this->makeCommunityFeed(), 7);
    }

    public function testRemoveMemberThrowsForbiddenForNonOwnerViewer(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('execute');

        $service = $this->makeMinimalService($db);
        $notOwner = new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage($this->trans('community.manage_forbidden'));

        $service->removeMember($notOwner, $this->makeCommunityFeed(), 42);
    }

    /**
     * Builds a CommunityService around a caller-supplied FeedService (for
     * updateSettings()'s own tests, which - unlike join()/leave()/
     * getMembers() above - actually call through to FeedService::
     * updateFeed()) plus a caller-supplied mocked db for the
     * MembershipRepository side of things (countSubscribers()/
     * promoteSubscribersToMembers()).
     */
    private function makeServiceWithFeedService(PdoDatabase $db, FeedService $feedService): CommunityService
    {
        return new CommunityService(
            $feedService,
            new FeedRepository($db),
            new MembershipRepository($db),
            new TranslationManager('ru', 'en'),
            new PageTree([]),
            new UrlGenerator(new PageTree([]), new FakeFeedRepository([]), new ArrayCache()),
            $this->notifications(),
            new Config(['SITE_URL' => 'https://example.test']),
        );
    }

    public function testUpdateSettingsThrowsForbiddenForNonOwnerViewer(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->never())->method('updateFeed');

        $service = $this->makeServiceWithFeedService($db, $feedService);
        $notOwner = new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage($this->trans('community.manage_forbidden'));

        $service->updateSettings(
            viewer: $notOwner,
            community: $this->makeCommunityFeed(CommunityService::MEMBERSHIP_TYPE_OPEN),
            name: 'New name',
            description: null,
            imageUrl: null,
            membershipType: CommunityService::MEMBERSHIP_TYPE_OPEN,
        );
    }

    /**
     * Staying on (or moving to) 'approval' never needs a promotion, so it's
     * always applied immediately regardless of any pending subscribers.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testUpdateSettingsSavesImmediatelyWhenNotSwitchingToOpen(): void
    {
        $db = $this->createStub(PdoDatabase::class);

        $updatedFeed = $this->makeCommunityFeed(CommunityService::MEMBERSHIP_TYPE_APPROVAL);

        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->once())
            ->method('updateFeed')
            ->with(
                self::COMMUNITY_ID,
                $this->callback(static fn (array $data): bool => $data['title'] === 'New name'
                    && $data['metadata'] === ['membership_type' => CommunityService::MEMBERSHIP_TYPE_APPROVAL]),
                $this->callback(static fn (User $u): bool => $u->id === 7),
            )
            ->willReturn($updatedFeed);

        $service = $this->makeServiceWithFeedService($db, $feedService);
        $owner = new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER);

        $result = $service->updateSettings(
            viewer: $owner,
            community: $this->makeCommunityFeed(CommunityService::MEMBERSHIP_TYPE_OPEN),
            name: 'New name',
            description: null,
            imageUrl: null,
            membershipType: CommunityService::MEMBERSHIP_TYPE_APPROVAL,
        );

        $this->assertFalse($result['needsConfirmation']);
        $this->assertNull($result['promotedCount']);
        $this->assertSame($updatedFeed, $result['feed']);
    }

    /**
     * Switching from 'approval' to 'open' while at least one subscriber is
     * still pending must not save anything - not even the rest of the
     * settings - until the owner confirms via confirmPromoteSubscribers.
     */
    public function testUpdateSettingsNeedsConfirmationWhenSwitchingToOpenWithPendingSubscribers(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['total' => 3]);

        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->never())->method('updateFeed');

        $service = $this->makeServiceWithFeedService($db, $feedService);
        $owner = new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER);

        $result = $service->updateSettings(
            viewer: $owner,
            community: $this->makeCommunityFeed(CommunityService::MEMBERSHIP_TYPE_APPROVAL),
            name: 'New name',
            description: null,
            imageUrl: null,
            membershipType: CommunityService::MEMBERSHIP_TYPE_OPEN,
        );

        $this->assertTrue($result['needsConfirmation']);
        $this->assertSame(3, $result['pendingCount']);
        $this->assertNull($result['feed']);
    }

    /**
     * Once the owner confirms (confirmPromoteSubscribers=true), the
     * settings are saved AND every pending subscriber is promoted in the
     * same call.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testUpdateSettingsPromotesSubscribersWhenConfirmed(): void
    {
        $promoted = [];

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['total' => 3]);
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params) use (&$promoted): int {
                if (str_contains($sql, 'UPDATE memberships') && str_contains($sql, 'membership_role_id = ?')
                    && substr_count($sql, '?') === 3 && ! str_contains($sql, 'user_id')) {
                    $promoted[] = $params;

                    return 3;
                }

                return 0;
            }
        );

        $updatedFeed = $this->makeCommunityFeed(CommunityService::MEMBERSHIP_TYPE_OPEN);

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('updateFeed')->willReturn($updatedFeed);

        $service = $this->makeServiceWithFeedService($db, $feedService);
        $owner = new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER);

        $result = $service->updateSettings(
            viewer: $owner,
            community: $this->makeCommunityFeed(CommunityService::MEMBERSHIP_TYPE_APPROVAL),
            name: 'New name',
            description: null,
            imageUrl: null,
            membershipType: CommunityService::MEMBERSHIP_TYPE_OPEN,
            confirmPromoteSubscribers: true,
        );

        $this->assertFalse($result['needsConfirmation']);
        $this->assertSame(3, $result['promotedCount']);
        $this->assertSame($updatedFeed, $result['feed']);
        $this->assertSame(
            [[MembershipRepository::ROLE_MEMBER_ID, self::COMMUNITY_ID, MembershipRepository::ROLE_SUBSCRIBER_ID]],
            $promoted
        );
    }
}
