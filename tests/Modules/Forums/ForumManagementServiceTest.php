<?php

declare(strict_types=1);

namespace Tests\Modules\Forums;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Forums\ForumManagementService;
use StreamEngine\Service\FeedService;

final class ForumManagementServiceTest extends TestCase
{
    private function forum(): Feed
    {
        return Feed::fromRow(['id' => 101, 'parent_id' => 100, 'type' => 'forum', 'owner_id' => 10,
            'container_id' => 101, 'visibility' => 'public', 'title' => 'Forum']);
    }

    #[DataProvider('actors')]
    public function testManagementPermissionIsLocalAndSeparateFromOrdinaryMembership(string $globalRole, ?int $level, bool $allowed): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($level === null ? null : ['role_level' => $level]);
        $service = new ForumManagementService($db, $this->createStub(FeedService::class), new TranslationManager('en', 'en'));
        self::assertSame($allowed, $service->canManage($this->forum(), new User(20, '', $globalRole)));
    }

    public static function actors(): array
    {
        return [['user', null, false], ['user', 0, false], ['user', 1, false], ['user', 2, true], ['user', 3, true], ['moderator', null, true], ['admin', null, true]];
    }

    public function testOrdinaryMemberCannotSaveOrMutateMembership(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['role_level' => 1]);
        $db->expects(self::never())->method('execute');
        $feeds = $this->createStub(FeedService::class);
        $feeds->method('getFeedById')->willReturn($this->forum());
        $service = new ForumManagementService($db, $feeds, new TranslationManager('en', 'en'));
        $this->expectException(ForbiddenException::class);
        $service->setMember(101, 30, 'moderator', new User(20, '', 'user'));
    }

    #[DataProvider('invalidMembers')]
    public function testInvalidRolesAndSystemAccountsCannotBeAdded(int $id, ?string $role): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects(self::never())->method('execute');
        $feeds = $this->createStub(FeedService::class);
        $feeds->method('getFeedById')->willReturn($this->forum());
        $this->expectException(ValidationException::class);
        (new ForumManagementService($db, $feeds, new TranslationManager('en', 'en')))
            ->setMember(101, $id, $role, new User(60, '', 'admin'));
    }

    public static function invalidMembers(): array
    {
        return [[0, 'member'], [1, 'member'], [20, 'owner'], [20, 'admin'], [20, 'subscriber']];
    }
}
