<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Service\AccessService;

final class AccessServiceTest extends TestCase
{
    #[DataProvider('audiences')]
    public function testAudienceMatrix(int $id, string $role, array $expected): void
    {
        $user = new User($id, '', $role);
        $actual = array_map(static fn (string $rule): bool => AccessService::allows($user, $rule), AccessService::ACCESS_RULES);
        $this->assertSame($expected, $actual);
    }

    public static function audiences(): array
    {
        return [
            'guest' => [0, AccessService::ROLE_USER, [true, false, false, false]],
            'guest with moderator role' => [0, AccessService::ROLE_MODERATOR, [true, false, false, false]],
            'guest with admin role' => [0, AccessService::ROLE_ADMIN, [true, false, false, false]],
            'user' => [42, AccessService::ROLE_USER, [true, true, false, false]],
            'moderator' => [42, AccessService::ROLE_MODERATOR, [true, true, true, false]],
            'admin' => [42, AccessService::ROLE_ADMIN, [true, true, true, true]],
            'system' => [User::SYSTEM_USER_ID, AccessService::ROLE_USER, [true, true, false, false]],
        ];
    }

    public function testUnknownAudienceRulesDenyAccessEvenToAdmins(): void
    {
        $admin = new User(42, '', AccessService::ROLE_ADMIN);
        $this->assertFalse(AccessService::allows($admin, 'unknown'));
        $this->assertFalse(AccessService::allows($admin, ''));
    }

    private function makeService(
        ?PdoDatabase $membershipDb = null,
        ?PdoDatabase $feedDb = null,
    ): AccessService {
        return new AccessService(
            new MembershipRepository($membershipDb ?? $this->createStub(PdoDatabase::class)),
            new FeedRepository($feedDb ?? $this->createStub(PdoDatabase::class)),
        );
    }

    private function makePage(string $accessRule): Page
    {
        return new Page(
            id: 1,
            parentId: null,
            pattern: 'secret',
            pageName: 'Secret',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'raw',
            accessRule: $accessRule,
        );
    }

    private function makeFeed(
        int $ownerId = 1,
        ?int $containerId = null,
        string $visibility = 'public',
    ): Feed {
        return new Feed(
            id: 10,
            parentId: null,
            ownerId: $ownerId,
            type: 'article',
            slug: 'feed-10',
            title: 'Feed 10',
            description: null,
            imageUrl: null,
            content: '<p>Body</p>',
            containerId: $containerId,
            visibility: $visibility,
            position: 1,
            createdAt: time(),
            relevance: null,
            canonicalUrl: null,
        );
    }

    private function makeUser(int $id, string $role = AccessService::ROLE_USER): User
    {
        return new User(
            id: $id,
            email: sprintf('user%d@example.com', $id),
            role: $role,
        );
    }

    public function testCanAccessPageDependsOnAudienceRule(): void
    {
        $service = $this->makeService();

        $this->assertFalse($service->canAccessPage($this->makeUser(1, AccessService::ROLE_USER), $this->makePage(AccessService::ACCESS_MODERATOR)));
        $this->assertTrue($service->canAccessPage($this->makeUser(1, AccessService::ROLE_MODERATOR), $this->makePage(AccessService::ACCESS_MODERATOR)));
    }

    public function testAdminCanAccessAnyPage(): void
    {
        $service = $this->makeService();

        $this->assertTrue($service->canAccessPage($this->makeUser(1, AccessService::ROLE_ADMIN), $this->makePage(AccessService::ACCESS_ADMIN)));
    }

    public function testGlobalModeratorHasNoNewContentPrivileges(): void
    {
        $service = $this->makeService();
        $user = $this->makeUser(7, AccessService::ROLE_MODERATOR);
        $feed = $this->makeFeed(ownerId: 9, visibility: 'private');

        $this->assertFalse($service->isAdmin($user));
        $this->assertFalse($service->canAccessFeed($user, $feed));
        $this->assertFalse($service->canEditFeed($user, $feed));
        $this->assertFalse($service->canAccessPage($user, $this->makePage(AccessService::ACCESS_ADMIN)));
    }

    public function testOwnerCanAccessAndEditOwnFeed(): void
    {
        $service = $this->makeService();
        $user = $this->makeUser(5);
        $feed = $this->makeFeed(ownerId: 5, containerId: 42, visibility: 'private');

        $this->assertTrue($service->canAccessFeed($user, $feed));
        $this->assertTrue($service->canEditFeed($user, $feed));
    }

    public function testGlobalFeedIsAccessibleWithoutMembership(): void
    {
        $service = $this->makeService();

        $this->assertTrue($service->canAccessFeed($this->makeUser(7), $this->makeFeed(containerId: null)));
    }

    public function testPrivateGlobalFeedIsNotAccessibleWithoutOwnership(): void
    {
        $service = $this->makeService();

        $this->assertFalse($service->canAccessFeed(
            $this->makeUser(7),
            $this->makeFeed(ownerId: 1, containerId: null, visibility: 'private')
        ));
    }

    public function testMembersVisibilityRequiresMembership(): void
    {
        $membershipDb = $this->createMock(PdoDatabase::class);
        $service = $this->makeService($membershipDb);
        $user = $this->makeUser(7);
        $feed = $this->makeFeed(ownerId: 1, containerId: 42, visibility: 'members');

        $membershipDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('FROM memberships'), [42, 7])
            ->willReturn(['role_level' => 1]);

        $this->assertTrue($service->canAccessFeed($user, $feed));
    }

    public function testPrivateVisibilityRequiresModeratorLevel(): void
    {
        $membershipDb = $this->createMock(PdoDatabase::class);
        $service = $this->makeService($membershipDb);
        $user = $this->makeUser(7);
        $feed = $this->makeFeed(ownerId: 1, containerId: 42, visibility: 'private');

        $membershipDb
            ->expects($this->exactly(2))
            ->method('fetchOne')
            ->with($this->stringContains('FROM memberships'), [42, 7])
            ->willReturnOnConsecutiveCalls(
                ['role_level' => 1],
                ['role_level' => 2],
            );

        $this->assertFalse($service->canAccessFeed($user, $feed));
        $this->assertTrue($service->canAccessFeed($user, $feed));
    }

    public function testNonOwnerNonAdminCannotEditFeed(): void
    {
        $service = $this->makeService();
        $user = $this->makeUser(7);
        $feed = $this->makeFeed(ownerId: 1, containerId: null, visibility: 'public');

        $this->assertFalse($service->canEditFeed($user, $feed));
    }

    public function testMembersVisibilityDeniesAccessWithoutMembership(): void
    {
        $membershipDb = $this->createMock(PdoDatabase::class);
        $service = $this->makeService($membershipDb);
        $user = $this->makeUser(7);
        $feed = $this->makeFeed(ownerId: 1, containerId: 42, visibility: 'members');

        $membershipDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('FROM memberships'), [42, 7])
            ->willReturn(null);

        $this->assertFalse($service->canAccessFeed($user, $feed));
    }

    public function testPublicFeedInContainerDoesNotQueryMembership(): void
    {
        $membershipDb = $this->createMock(PdoDatabase::class);
        $feedDb = $this->createMock(PdoDatabase::class);
        $service = $this->makeService($membershipDb, $feedDb);
        $user = $this->makeUser(7);
        $feed = $this->makeFeed(ownerId: 1, containerId: 42, visibility: 'public');

        $feedDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('f.id = ?'), [42, 7, 7])
            ->willReturn([
                'id' => 42,
                'parent_id' => null,
                'owner_id' => 999,
                'type' => 'blog',
                'slug' => 'container',
                'title' => 'Container',
                'content' => null,
                'description' => null,
                'image_url' => null,
                'container_id' => null,
                'visibility' => 'private',
                'position' => 1,
                'created_at' => time(),
                'nick' => 'Author 999',
                'avatar_url' => '/uploads/avatar-999.webp',
            ]);

        $membershipDb->expects($this->never())->method('fetchOne');

        $this->assertTrue($service->canAccessFeed($user, $feed));
    }

    /**
     * A community moderator (role_level 2, not the post's own author or
     * the community's owner_id) can still edit/delete a post inside it -
     * the case AccessService::canEditFeed()'s isContainerModerator() was
     * added for (used by the blog-post/community-post show pages' delete
     * button, see UsersController::showCommunityPostPage()).
     */
    public function testContainerModeratorCanEditFeedTheyDidNotAuthor(): void
    {
        $feedDb = $this->createMock(PdoDatabase::class);
        $membershipDb = $this->createMock(PdoDatabase::class);
        $service = $this->makeService($membershipDb, $feedDb);
        $user = $this->makeUser(7);
        $feed = $this->makeFeed(ownerId: 1, containerId: 42, visibility: 'public');

        $feedDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('f.id = ?'), [42, 7, 7])
            ->willReturn([
                'id' => 42,
                'parent_id' => null,
                'owner_id' => 1,
                'type' => 'community',
                'slug' => 'container',
                'title' => 'Container',
                'content' => null,
                'description' => null,
                'image_url' => null,
                'container_id' => null,
                'visibility' => 'public',
                'position' => 1,
                'created_at' => time(),
                'nick' => 'Author 1',
                'avatar_url' => '/uploads/avatar-1.webp',
            ]);

        $membershipDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('FROM memberships'), [42, 7])
            ->willReturn(['role_level' => 2]);

        $this->assertTrue($service->canEditFeed($user, $feed));
    }

    /**
     * A regular member (role_level 1 - the same role FriendService grants
     * a personal blog's subscriber/friend) does NOT get edit/delete
     * rights just from having a membership row - only moderator+ does.
     */
    public function testContainerMemberCannotEditFeedTheyDidNotAuthor(): void
    {
        $feedDb = $this->createMock(PdoDatabase::class);
        $membershipDb = $this->createMock(PdoDatabase::class);
        $service = $this->makeService($membershipDb, $feedDb);
        $user = $this->makeUser(7);
        $feed = $this->makeFeed(ownerId: 1, containerId: 42, visibility: 'public');

        $feedDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('f.id = ?'), [42, 7, 7])
            ->willReturn([
                'id' => 42,
                'parent_id' => null,
                'owner_id' => 1,
                'type' => 'community',
                'slug' => 'container',
                'title' => 'Container',
                'content' => null,
                'description' => null,
                'image_url' => null,
                'container_id' => null,
                'visibility' => 'public',
                'position' => 1,
                'created_at' => time(),
                'nick' => 'Author 1',
                'avatar_url' => '/uploads/avatar-1.webp',
            ]);

        $membershipDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('FROM memberships'), [42, 7])
            ->willReturn(['role_level' => 1]);

        $this->assertFalse($service->canEditFeed($user, $feed));
    }

    public function testContainerOwnerCanAccessAndEditNestedFeed(): void
    {
        $feedDb = $this->createMock(PdoDatabase::class);
        $service = $this->makeService(feedDb: $feedDb);
        $user = $this->makeUser(9);
        $feed = $this->makeFeed(ownerId: 1, containerId: 42, visibility: 'private');

        $feedDb
            ->expects($this->exactly(2))
            ->method('fetchOne')
            ->with($this->stringContains('f.id = ?'), [42, 9, 9])
            ->willReturn([
                'id' => 42,
                'parent_id' => null,
                'owner_id' => 9,
                'type' => 'blog',
                'slug' => 'container',
                'title' => 'Container',
                'content' => null,
                'description' => null,
                'image_url' => null,
                'container_id' => null,
                'visibility' => 'private',
                'position' => 1,
                'created_at' => time(),
                'nick' => 'Author 9',
                'avatar_url' => '/uploads/avatar-9.webp',
            ]);

        $this->assertTrue($service->canAccessFeed($user, $feed));
        $this->assertTrue($service->canEditFeed($user, $feed));
    }
}
