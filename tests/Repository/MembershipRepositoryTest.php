<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\MembershipRepository;

final class MembershipRepositoryTest extends TestCase
{
    public function testPromoteBlogFriendshipRequiresAndUpdatesBothRows(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'UPDATE memberships first_membership')
                        && str_contains($sql, 'JOIN memberships second_membership')
                        && str_contains($sql, 'first_membership.membership_role_id = ?')
                ),
                [
                    600,
                    7,
                    MembershipRepository::ROLE_MEMBER_ID,
                    MembershipRepository::ROLE_MEMBER_ID,
                    500,
                    8,
                    MembershipRepository::ROLE_SUBSCRIBER_ID,
                    MembershipRepository::ROLE_MEMBER_ID,
                    MembershipRepository::ROLE_SUBSCRIBER_ID,
                    MembershipRepository::ROLE_MEMBER_ID,
                ]
            )
            ->willReturn(2);

        self::assertTrue((new MembershipRepository($db))->promoteBlogFriendship(500, 8, 600, 7));
    }

    public function testPromoteBlogFriendshipReturnsFalseWithoutBothRows(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturn(0);

        self::assertFalse((new MembershipRepository($db))->promoteBlogFriendship(500, 8, 600, 7));
    }

    public function testFindMemberIdsExcludesPendingSubscribersAndReturnsUniqueIntegers(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'SELECT user_id')
                        && str_contains($sql, 'FROM memberships')
                        && str_contains($sql, 'membership_role_id != ?')
                ),
                [500, MembershipRepository::ROLE_SUBSCRIBER_ID]
            )
            ->willReturn([
                ['user_id' => '7'],
                ['user_id' => '42'],
                ['user_id' => '7'],
            ]);

        self::assertSame([7, 42], (new MembershipRepository($db))->findMemberIds(500));
    }

    public function testFindMutualFriendIdsReturnsUniqueIntegerIds(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'SELECT m1.user_id')
                        && str_contains($sql, "f1.type = 'blog'")
                        && str_contains($sql, 'm1.membership_role_id = ?')
                ),
                [7, MembershipRepository::ROLE_MEMBER_ID, 7]
            )
            ->willReturn([
                ['user_id' => '8'],
                ['user_id' => '9'],
                ['user_id' => '8'],
            ]);

        self::assertSame([8, 9], (new MembershipRepository($db))->findMutualFriendIds(7));
    }

    public function testFindMembersExcludesPendingSubscribersAndMapsRoleLevel(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'FROM memberships m')
                        && str_contains($sql, 'membership_role_id != ?')
                        && str_contains($sql, 'ORDER BY mr.role_level DESC, m.joined_at ASC')
                        && str_contains($sql, 'LIMIT 20 OFFSET 0')
                ),
                [500, MembershipRepository::ROLE_SUBSCRIBER_ID]
            )
            ->willReturn([
                ['id' => '7', 'nick' => 'Owner', 'username' => 'owner', 'avatar_url' => '', 'role_level' => '3'],
                ['id' => '42', 'nick' => 'Member', 'username' => null, 'avatar_url' => '', 'role_level' => '1'],
            ]);

        $repository = new MembershipRepository($db);

        $this->assertSame(
            [
                ['id' => 7, 'nick' => 'Owner', 'username' => 'owner', 'avatarUrl' => '', 'roleLevel' => 3],
                ['id' => 42, 'nick' => 'Member', 'username' => null, 'avatarUrl' => '', 'roleLevel' => 1],
            ],
            $repository->findMembers(500, 20)
        );
    }

    public function testCountMembersExcludesPendingSubscribers(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'FROM memberships')
                        && str_contains($sql, 'membership_role_id != ?')
                ),
                [500, MembershipRepository::ROLE_SUBSCRIBER_ID]
            )
            ->willReturn(['total' => '3']);

        $repository = new MembershipRepository($db);

        $this->assertSame(3, $repository->countMembers(500));
    }

    public function testCountMembersReturnsZeroWhenNoRowFound(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(null);

        $repository = new MembershipRepository($db);

        $this->assertSame(0, $repository->countMembers(500));
    }
}
