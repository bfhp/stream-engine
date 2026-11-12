<?php

declare(strict_types=1);

namespace Tests\Modules\Users;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Users\CommunityService;
use StreamEngine\Modules\Users\PhotoService;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;

final class PhotoServiceTest extends TestCase
{
    private function feed(int $id, string $type, int $owner = 1, ?int $parent = null): Feed
    {
        return new Feed($id, $parent, $owner, $type, null, 'Title', null, null, '', null, 'public', 0, null, null, null);
    }

    public static function contexts(): array
    {
        return [['blog', 'public', null, null], ['blog', 'private', null, null], ['blog', 'members', 10, 'blog'], ['community', 'public', 10, 'community'], ['community', 'private', 10, 'community'], ['community', 'members', 10, 'community']];
    }

    #[DataProvider('contexts')]
    public function testCreationUsesBlogPostContainerRules(string $type, string $visibility, ?int $containerId, ?string $containerType): void
    {
        $user = new User(1, 'test@example.org');
        $feeds = $this->createMock(FeedService::class);
        $community = $this->createStub(CommunityService::class);
        $community->method('canPost')->willReturn(true);
        $feeds->method('getFeedById')->with(10, $user)->willReturn($this->feed(10, $type));
        $feeds->method('normalizeVisibility')->with($visibility)->willReturn($visibility);
        $album = $this->feed(20, 'gallery-album', parent: 10);
        $feeds->expects(self::once())->method('createFeed')->with(
            'Summer',
            null,
            'gallery-album',
            10,
            'Description',
            null,
            '',
            $user,
            null,
            $visibility,
            $containerId,
            $containerType,
        )->willReturn($album);
        $service = new PhotoService($feeds, $this->createStub(AccessService::class), $community);
        self::assertSame($album, $service->createAlbum(10, $user, ' Summer ', 'Description', $visibility));
    }

    public static function forbiddenContexts(): array
    {
        return [['blog', 2, 1], ['community', 2, 1], ['blog', 1, 0]];
    }

    #[DataProvider('forbiddenContexts')]
    public function testCreationRequiresContextPublicationRights(string $type, int $owner, int $viewer): void
    {
        $feeds = $this->createMock(FeedService::class);
        $feeds->method('getFeedById')->willReturn($this->feed(10, $type, $owner));
        $feeds->expects(self::never())->method('createFeed');
        $communities = $this->createStub(CommunityService::class);
        $communities->method('canPost')->willReturn(false);
        $service = new PhotoService($feeds, $this->createStub(AccessService::class), $communities);
        $this->expectException(ForbiddenException::class);
        $service->createAlbum(10, new User($viewer, ''), 'Album');
    }

    public function testDirectAlbumLinkCannotCrossContexts(): void
    {
        $feeds = $this->createStub(FeedService::class);
        $feeds->method('getFeedById')->willReturnMap([
            [10, $user = new User(1, ''), $this->feed(10, 'blog')],
            [20, $user, $this->feed(20, 'gallery-album', parent: 99)],
        ]);
        $service = new PhotoService($feeds, $this->createStub(AccessService::class), $this->createStub(CommunityService::class));
        $this->expectException(NotFoundException::class);
        $service->getAlbum(10, 20, $user);
    }

    public function testInvalidContextCannotBeUsedAsAlbumParent(): void
    {
        $feeds = $this->createMock(FeedService::class);
        $feeds->method('getFeedById')->willReturn($this->feed(10, 'blog-post'));
        $feeds->expects(self::never())->method('createFeed');
        $service = new PhotoService($feeds, $this->createStub(AccessService::class), $this->createStub(CommunityService::class));
        $this->expectException(NotFoundException::class);
        $service->createAlbum(10, new User(1, ''), 'Album');
    }

    public function testEmptyTitleIsRejected(): void
    {
        $feeds = $this->createMock(FeedService::class);
        $feeds->method('getFeedById')->willReturn($this->feed(10, 'blog'));
        $feeds->expects(self::never())->method('createFeed');
        $service = new PhotoService($feeds, $this->createStub(AccessService::class), $this->createStub(CommunityService::class));
        $this->expectException(ValidationException::class);
        $service->createAlbum(10, new User(1, ''), '  ');
    }

    public function testInaccessibleContextStopsListingBeforeQuery(): void
    {
        $feeds = $this->createMock(FeedService::class);
        $feeds->method('getFeedById')->willThrowException(new ForbiddenException('Hidden'));
        $feeds->expects(self::never())->method('getFeedsByParentAndTypePage');
        $service = new PhotoService($feeds, $this->createStub(AccessService::class), $this->createStub(CommunityService::class));
        $this->expectException(ForbiddenException::class);
        $service->listAlbums(10, new User(2, ''));
    }

    public function testListingDelegatesAccessFilteringAndBoundsPagination(): void
    {
        $user = new User(1, '');
        $feeds = $this->createMock(FeedService::class);
        $feeds->method('getFeedById')->with(10, $user)->willReturn($this->feed(10, 'blog'));
        $result = ['items' => [], 'total' => 0];
        $feeds->expects(self::once())->method('getFeedsByParentAndTypePage')
            ->with(10, 'gallery-album', $user, 100, 0)->willReturn($result);
        $service = new PhotoService($feeds, $this->createStub(AccessService::class), $this->createStub(CommunityService::class));
        self::assertSame($result, $service->listAlbums(10, $user, 10000, -1));
    }

    public function testEditingUsesSharedPostPermissions(): void
    {
        $user = new User(2, '');
        $album = $this->feed(20, 'gallery-album', parent: 10);
        $feeds = $this->createStub(FeedService::class);
        $feeds->method('getFeedById')->willReturnMap([[10, $user, $this->feed(10, 'community')], [20, $user, $album]]);
        $access = $this->createMock(AccessService::class);
        $access->expects(self::once())->method('canEditFeed')->with($user, $album)->willReturn(true);
        $service = new PhotoService($feeds, $access, $this->createStub(CommunityService::class));
        self::assertTrue($service->canEditAlbum(10, 20, $user));
    }
}
