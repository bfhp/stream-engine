<?php

declare(strict_types=1);

namespace Tests\Modules\Users;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Router;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Users\CommunityService;
use StreamEngine\Modules\Users\UsersController;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\UserService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

final class UsersControllerPhotosTest extends TestCase
{
    private function controller(FeedService $feeds, User $viewer, User $owner, array $query = []): array
    {
        $tree = new PageTree([
            new Page(1, null, '', null, null, null, null, null, ['GET'], 'html', 'public', 'home.index'),
            new Page(2, 1, 'users', null, null, null, null, null, ['GET'], 'html', 'public', 'users.list'),
            Page::runtime(3, 2, '{username}', 'user.show', 'public', null, null, null),
            new Page(4, 1, 'communities', null, null, null, null, null, ['GET'], 'html', 'public', 'community.main'),
        ]);
        $tm = new TranslationManager('ru', 'en');
        UsersController::registerRuntimePages($tree, $tm);
        $reflection = new ReflectionClass(UsersController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $db = $this->createStub(PdoDatabase::class);
        $reflection->getParentClass()->getProperty('db')->setValue($controller, $db);
        $reflection->getParentClass()->getProperty('context')->setValue($controller, new RequestContext($viewer, new \DateTimeZone('UTC'), new QueryParams($query)));
        $db->method('fetchOne')->willReturn(['id' => $owner->id, 'email' => $owner->email, 'role' => $owner->role, 'username' => $owner->username]);
        $userReflection = new ReflectionClass(UserService::class);
        $users = $userReflection->newInstanceWithoutConstructor();
        $userReflection->getProperty('userRepository')->setValue($users, new \StreamEngine\Repository\UserRepository($db));
        $userReflection->getProperty('sessionRepository')->setValue($users, new \StreamEngine\Repository\UserSessionRepository($db));
        $friendReflection = new ReflectionClass(\StreamEngine\Modules\Users\FriendService::class);
        $friends = $friendReflection->newInstanceWithoutConstructor();
        $friendReflection->getProperty('feeds')->setValue($friends, new \StreamEngine\Repository\FeedRepository($this->createStub(PdoDatabase::class)));
        $reflection->getProperty('friendService')->setValue($controller, $friends);
        $communities = $this->createStub(CommunityService::class);
        $communities->method('canPost')->willReturn(false);
        $communities->method('getMembers')->willReturn(['items' => [], 'total' => 0]);
        foreach (['pageTree' => $tree, 'tm' => $tm, 'feedService' => $feeds, 'userService' => $users, 'communityService' => $communities, 'uploadService' => $this->createStub(\StreamEngine\Service\UploadService::class), 'memberships' => new MembershipRepository($db), 'urlGenerator' => new UrlGenerator($tree, new FakeFeedRepository([]), new ArrayCache())] as $key => $value) {
            $reflection->getProperty($key)->setValue($controller, $value);
        }

        return [$controller, new Router($tree)];
    }

    public function testEmptyPersonalGalleryDoesNotCreateBlogOnRead(): void
    {
        $user = new User(1, '', username: 'alice');
        $feeds = $this->createMock(FeedService::class);
        $feeds->method('getFeedByOwnerAndType')->willReturn(null);
        $feeds->expects(self::never())->method('createFeed');
        [$controller, $router] = $this->controller($feeds, $user, $user);
        $route = $router->resolve('/users/alice/photos/');
        $view = $controller->show($route['page'], ['username' => 'alice']);
        self::assertSame([], $view->data['albums']);
        self::assertTrue($view->data['canCreate']);
        self::assertSame('#1', $view->data['photoOwner']['name']);
        self::assertSame('/users/alice/', $view->data['photoOwner']['url']);
        self::assertArrayNotHasKey('photoSidebar', $view->data);
        self::assertSame('/api/v1/users/photo-albums', $view->data['apiUrl']);
    }

    public function testVisitorCannotCreateAlbumInAnotherBlog(): void
    {
        $feeds = $this->createStub(FeedService::class);
        $feeds->method('getFeedByOwnerAndType')->willReturn(null);
        [$controller, $router] = $this->controller($feeds, new User(2, ''), new User(1, '', username: 'alice'));
        $route = $router->resolve('/users/alice/photos/');
        self::assertFalse($controller->show($route['page'], ['username' => 'alice'])->data['canCreate']);
    }

    public function testCommunityPhotosUseCompactOwnerLink(): void
    {
        $community = new Feed(10, null, 1, 'community', 'devs', 'Devs', null, null, '', null, 'public', 0, null, null, null);
        $feeds = $this->createStub(FeedService::class);
        $feeds->method('getFeedByParentAndSlug')->willReturn($community);
        $feeds->method('getFeedById')->willReturn($community);
        $feeds->method('getFeedsByParentAndTypePage')->willReturn(['items' => [], 'total' => 0]);
        $feeds->method('getRatingTotalsByParentAndType')->willReturn(['count' => 0, 'sum' => 0]);
        [$controller, $router] = $this->controller($feeds, new User(2, ''), new User(1, '', username: 'alice'));
        $route = $router->resolve('/communities/devs/photos/');
        $view = $controller->show($route['page'], ['slug' => 'devs']);
        self::assertTrue($view->data['isCommunityGallery']);
        self::assertSame('Devs', $view->data['photoOwner']['name']);
        self::assertSame('/communities/devs/', $view->data['photoOwner']['url']);
        self::assertArrayNotHasKey('photoSidebar', $view->data);
    }

    public function testNewAlbumFormIsSeparateFromListing(): void
    {
        $owner = new User(1, '', username: 'alice');
        $feeds = $this->createMock(FeedService::class);
        $feeds->method('getFeedByOwnerAndType')->willReturn(null);
        $feeds->expects(self::never())->method('getFeedsByParentAndTypePage');
        [$controller, $router] = $this->controller($feeds, $owner, $owner);
        $route = $router->resolve('/users/alice/photos/new-album/');
        $view = $controller->show($route['page'], ['username' => 'alice']);
        self::assertTrue($view->data['isAlbumForm']);
        self::assertSame('/users/alice/photos/', $view->data['listUrl']);
    }

    public function testNewAlbumFormRejectsAnotherUser(): void
    {
        $feeds = $this->createStub(FeedService::class);
        $feeds->method('getFeedByOwnerAndType')->willReturn(null);
        [$controller, $router] = $this->controller($feeds, new User(2, ''), new User(1, '', username: 'alice'));
        $route = $router->resolve('/users/alice/photos/new-album/');
        $this->expectException(\StreamEngine\Core\Exceptions\ForbiddenException::class);
        $controller->show($route['page'], ['username' => 'alice']);
    }

    public function testPersonalAlbumUsesCompactBlogOwnerLink(): void
    {
        $owner = new User(1, '', username: 'alice');
        $blog = new Feed(10, null, 1, 'blog', 'alice', 'Blog', null, null, '', null, 'public', 0, null, null, null);
        $album = new Feed(42, 10, 1, 'gallery-album', 'album', 'Album', null, null, '', null, 'public', 0, null, null, null);
        $feeds = $this->createStub(FeedService::class);
        $feeds->method('getFeedByOwnerAndType')->willReturn($blog);
        $feeds->method('getFeedByParentAndSlug')->willReturn($album);
        $feeds->method('getFeedById')->willReturnCallback(fn (int $id) => $id === 10 ? $blog : $album);
        [$controller, $router] = $this->controller($feeds, new User(2, ''), $owner);
        $route = $router->resolve('/users/alice/photos/album/');
        $view = $controller->show($route['page'], ['username' => 'alice', 'albumSlug' => 'album']);
        self::assertSame($album, $view->data['album']);
        self::assertSame('#1', $view->data['photoOwner']['name']);
        self::assertSame('/users/alice/', $view->data['photoOwner']['url']);
        self::assertFalse($view->data['isManage']);
        self::assertArrayNotHasKey('photoSidebar', $view->data);
    }

    public function testAlbumEditPageRejectsUnauthorizedViewer(): void
    {
        $owner = new User(1, '', username: 'alice');
        $blog = new Feed(10, null, 1, 'blog', 'alice', 'Blog', null, null, '', null, 'public', 0, null, null, null);
        $album = new Feed(42, 10, 1, 'gallery-album', 'album', 'Album', null, null, '', null, 'public', 0, null, null, null);
        $feeds = $this->createStub(FeedService::class);
        $feeds->method('getFeedByOwnerAndType')->willReturn($blog);
        $feeds->method('getFeedByParentAndSlug')->willReturn($album);
        $feeds->method('getFeedById')->willReturnCallback(fn (int $id) => $id === 10 ? $blog : $album);
        [$controller, $router] = $this->controller($feeds, new User(2, ''), $owner);
        $route = $router->resolve('/users/alice/photos/album/edit/');
        $this->expectException(\StreamEngine\Core\Exceptions\ForbiddenException::class);
        $controller->show($route['page'], ['username' => 'alice', 'albumSlug' => 'album']);
    }

    public function testAlbumBreadcrumbLinksResolveForBothContextsAndEditPages(): void
    {
        foreach (['user', 'community'] as $scope) {
            $owner = new User(1, '', username: 'alice');
            $parent = new Feed(10, null, 1, $scope === 'user' ? 'blog' : 'community', $scope === 'user' ? 'alice' : 'devs', 'Context', null, null, '', null, 'public', 0, null, null, null);
            $album = new Feed(42, 10, 1, 'gallery-album', 'summer', 'Summer album', null, null, '', null, 'public', 0, null, null, null);
            $feeds = $this->createStub(FeedService::class);
            $feeds->method('getFeedByOwnerAndType')->willReturn($parent);
            $feeds->method('getFeedByParentAndSlug')->willReturnCallback(fn ($id) => $id === null ? $parent : $album);
            $feeds->method('getFeedById')->willReturn($parent);
            [$controller, $router] = $this->controller($feeds, $owner, $owner);
            $base = $scope === 'user' ? '/users/alice/photos/summer/' : '/communities/devs/photos/summer/';
            foreach (['', 'edit/'] as $suffix) {
                $resolved = $router->resolve($base.$suffix);
                $breadcrumbs = new \StreamEngine\Service\BreadcrumbsService();
                foreach ($resolved['breadcrumbs'] as $page) {
                    $page->pageName ??= $page->pattern;
                    $breadcrumbs->addFor($page, $controller->getBreadcrumb(...));
                }
                $trail = $breadcrumbs->finalize();
                $albumCrumb = $trail[count($trail) - ($suffix === '' ? 1 : 2)];
                self::assertSame('Summer album', $albumCrumb->title);
                self::assertSame($base, $albumCrumb->url);
                self::assertSame($scope.'.photo-album', $router->resolve($albumCrumb->url)['page']->action);
                self::assertSame($base.$suffix, $trail[array_key_last($trail)]->url);
            }
        }
    }

    public function testCommunityAlbumCannotBeOpenedUnderAnotherCommunity(): void
    {
        $community = new Feed(10, null, 1, 'community', 'devs', 'Devs', null, null, '', null, 'public', 0, null, null, null);
        $album = new Feed(42, 99, 1, 'gallery-album', 'album', 'Album', null, null, '', null, 'public', 0, null, null, null);
        $feeds = $this->createStub(FeedService::class);
        $feeds->method('getFeedByParentAndSlug')->willReturnCallback(fn ($parent) => $parent === null ? $community : $album);
        $feeds->method('getFeedById')->willReturnCallback(fn (int $id) => $id === 10 ? $community : $album);
        [$controller, $router] = $this->controller($feeds, new User(2, ''), new User(1, ''));
        $route = $router->resolve('/communities/devs/photos/album/');
        $this->expectException(NotFoundException::class);
        $controller->show($route['page'], ['slug' => 'devs', 'albumSlug' => 'album']);
    }

    public function testAlbumEditorLoadsCompleteOrderedGridAndUsesUploadAnchor(): void
    {
        $owner = new User(1, '', username: 'alice');
        $blog = new Feed(10, null, 1, 'blog', 'alice', 'Blog', null, null, '', null, 'public', 0, null, null, null);
        $album = new Feed(42, 10, 1, 'gallery-album', 'album', 'Album', null, null, '', null, 'public', 0, null, null, null);
        $feeds = $this->createMock(FeedService::class);
        $feeds->method('getFeedByOwnerAndType')->willReturn($blog);
        $feeds->method('getFeedByParentAndSlug')->willReturn($album);
        $feeds->method('getFeedById')->willReturnCallback(fn (int $id) => $id === 10 ? $blog : $album);
        $feeds->expects(self::once())->method('countFeedsByParentAndType')->with(42, 'gallery-photo', $owner)->willReturn(200);
        $feeds->expects(self::once())->method('getFeedsByParentAndTypePage')->with(42, 'gallery-photo', $owner, 200, 0, true)->willReturn(['items' => [], 'total' => 200]);
        [$controller, $router] = $this->controller($feeds, $owner, $owner);
        $route = $router->resolve('/users/alice/photos/album/edit/');
        $view = $controller->show($route['page'], ['username' => 'alice', 'albumSlug' => 'album']);
        self::assertTrue($view->data['isManage']);
        self::assertFalse($view->data['isAlbumForm']);
        self::assertSame('/users/alice/photos/album/edit/#album-upload', $view->data['newPhotoUrl']);
        self::assertArrayNotHasKey('photoSidebar', $view->data);
    }

    public function testPhotoViewerUsesSavedOrderAndReturnsToTheCorrectAlbumPage(): void
    {
        $owner = new User(1, '', username: 'alice');
        $viewer = new User(2, '');
        $blog = new Feed(10, null, 1, 'blog', 'alice', 'Blog', null, null, '', null, 'public', 0, null, null, null);
        $album = new Feed(42, 10, 1, 'gallery-album', 'album', 'Album', null, null, '', null, 'public', 0, null, null, null);
        $photos = [];
        for ($i = 0; $i < 23; $i++) {
            $photos[] = new Feed(1000 + $i, 42, 1, 'gallery-photo', (string) (31 + $i), 'Photo', null, null, '', null, 'public', $i, null, null, null, metadata: ['gallery.state' => 'ready', 'gallery.width' => '10', 'gallery.height' => '10', 'gallery.original_upload_id' => '1', 'gallery.preview_upload_id' => '2']);
        }
        $current = $photos[20];
        $feeds = $this->createMock(FeedService::class);
        $feeds->expects(self::never())->method('getComments');
        $feeds->method('getFeedByOwnerAndType')->willReturn($blog);
        $feeds->method('getFeedByParentAndSlug')->willReturnCallback(fn ($parent) => $parent === 10 ? $album : $current);
        $feeds->method('getFeedById')->willReturnCallback(fn ($id) => $id === 10 ? $blog : ($id === 42 ? $album : $current));
        $feeds->method('countFeedsByParentAndType')->willReturn(23);
        $feeds->expects(self::once())->method('getFeedsByParentAndTypePage')->with(42, 'gallery-photo', $viewer, 23, 0, true)->willReturn(['items' => $photos, 'total' => 23]);
        [$controller, $router] = $this->controller($feeds, $viewer, $owner);
        $route = $router->resolve('/users/alice/photos/album/51/');
        $view = $controller->show($route['page'], ['username' => 'alice', 'albumSlug' => 'album', 'photoSlug' => '51']);
        self::assertSame(21, $view->data['photoPosition']);
        self::assertSame(23, $view->data['photoCount']);
        self::assertSame('/users/alice/photos/album/50/', $view->data['previousPhotoUrl']);
        self::assertSame('/users/alice/photos/album/52/', $view->data['nextPhotoUrl']);
        self::assertSame('/users/alice/photos/album/?offset=20', $view->data['photoReturnUrl']);
    }
    public function testPhotoDiscussionUsesExistingCommentPermissionsAndScopedReplies(): void
    {
        $owner = new User(1, '', username: 'alice');
        $viewer = new User(2, '');
        $blog = new Feed(10, null, 1, 'blog', 'alice', 'Blog', null, null, '', null, 'public', 0, null, null, null);
        $album = new Feed(42, 10, 1, 'gallery-album', 'album', 'Album', null, null, '', null, 'public', 0, null, null, null);
        $photos = [];
        for ($i = 0; $i < 23; $i++) {
            $photos[] = new Feed(1000 + $i, 42, 1, 'gallery-photo', (string) (31 + $i), 'Photo', null, null, '', null, 'public', $i, null, null, null, metadata: ['gallery.state' => 'ready', 'gallery.width' => '10', 'gallery.height' => '10', 'gallery.original_upload_id' => '1', 'gallery.preview_upload_id' => '2']);
        }
        $current = $photos[20];
        $comment = new Feed(500, $current->id, 2, 'comment', null, '', null, null, 'Comment', null, 'public', 0, null, null, null);
        $reply = new Feed(501, 500, 3, 'comment', null, '', null, null, 'Reply', null, 'public', 0, null, null, null);
        $comment->children = ['items' => [$reply], 'meta' => ['next_cursor' => 'replies']];
        $feeds = $this->createMock(FeedService::class);
        $feeds->method('getFeedByOwnerAndType')->willReturn($blog);
        $feeds->method('getFeedByParentAndSlug')->willReturnCallback(fn ($parent) => $parent === 10 ? $album : $current);
        $feeds->method('getFeedById')->willReturnCallback(fn ($id) => $id === 10 ? $blog : ($id === 42 ? $album : $current));
        $feeds->method('countFeedsByParentAndType')->willReturn(23);
        $feeds->expects(self::once())->method('getFeedsByParentAndTypePage')->with(42, 'gallery-photo', $viewer, 23, 0, true)->willReturn(['items' => $photos, 'total' => 23]);
        $feeds->expects(self::once())->method('getComments')->with($current->id, null, $viewer)->willReturn(['items' => [$comment], 'meta' => ['next_cursor' => 'more']]);
        $feeds->method('canEditFeed')->willReturnCallback(fn ($feed) => $feed->id === 500);
        $feeds->method('isWithinEditWindow')->willReturn(false);
        [$controller, $router] = $this->controller($feeds, $viewer, $owner, ['discussion' => '1']);
        $route = $router->resolve('/users/alice/photos/album/51/');
        $view = $controller->show($route['page'], ['username' => 'alice', 'albumSlug' => 'album', 'photoSlug' => '51']);
        $discussion = $view->data['photoComments'];
        self::assertSame($current->id, $discussion['rootId']);
        self::assertSame('more', $discussion['cursor']);
        self::assertTrue($discussion['items'][0]['canDelete']);
        self::assertFalse($discussion['items'][0]['canEdit']);
        self::assertSame('replies', $discussion['items'][0]['nextReplies']);
        self::assertFalse($discussion['items'][0]['replies'][0]['canDelete']);
    }

    public function testPhotoDiscussionRejectsACommentFromAnotherPhoto(): void
    {
        $owner = new User(1, '', username: 'alice');
        $viewer = new User(2, '');
        $blog = new Feed(10, null, 1, 'blog', 'alice', 'Blog', null, null, '', null, 'public', 0, null, null, null);
        $album = new Feed(42, 10, 1, 'gallery-album', 'album', 'Album', null, null, '', null, 'public', 0, null, null, null);
        $photos = [];
        for ($i = 0; $i < 23; $i++) {
            $photos[] = new Feed(1000 + $i, 42, 1, 'gallery-photo', (string) (31 + $i), 'Photo', null, null, '', null, 'public', $i, null, null, null, metadata: ['gallery.state' => 'ready', 'gallery.width' => '10', 'gallery.height' => '10', 'gallery.original_upload_id' => '1', 'gallery.preview_upload_id' => '2']);
        }
        $current = $photos[20];
        $comment = new Feed(500, $current->id + 1, 2, 'comment', null, '', null, null, 'Comment', null, 'public', 0, null, null, null);
        $feeds = $this->createMock(FeedService::class);
        $feeds->method('getFeedByOwnerAndType')->willReturn($blog);
        $feeds->method('getFeedByParentAndSlug')->willReturnCallback(fn ($parent) => $parent === 10 ? $album : $current);
        $feeds->method('getFeedById')->willReturnCallback(fn ($id) => $id === 10 ? $blog : ($id === 42 ? $album : ($id === 500 ? $comment : $current)));
        $feeds->method('countFeedsByParentAndType')->willReturn(23);
        $feeds->expects(self::once())->method('getFeedsByParentAndTypePage')->with(42, 'gallery-photo', $viewer, 23, 0, true)->willReturn(['items' => $photos, 'total' => 23]);
        $feeds->expects(self::never())->method('getComments');
        [$controller, $router] = $this->controller($feeds, $viewer, $owner, ['discussion' => '1', 'comment_parent' => '500']);
        $route = $router->resolve('/users/alice/photos/album/51/');
        $this->expectException(NotFoundException::class);
        $controller->show($route['page'], ['username' => 'alice', 'albumSlug' => 'album', 'photoSlug' => '51']);
    }
    public function testAlbumCardsUseScopedCoversAndOnlyFetchOneVisiblePhotoPerAlbum(): void
    {
        $owner = new User(1, '', username: 'alice');
        $viewer = new User(2, '');
        $blog = new Feed(10, null, 1, 'blog', 'alice', 'Blog', null, null, '', null, 'public', 0, null, null, null);
        $album = new Feed(42, 10, 1, 'gallery-album', 'summer', 'Summer', '<p>Summer &amp; sunshine</p>', null, '', null, 'public', 0, null, null, null, metadata: ['gallery.cover_photo_id' => '900']);
        $foreignCoverAlbum = new Feed(43, 10, 1, 'gallery-album', 'walks', 'Walks', null, null, '', null, 'public', 0, null, null, null, metadata: ['gallery.cover_photo_id' => '900']);
        $emptyAlbum = new Feed(44, 10, 1, 'gallery-album', 'empty', 'Empty', null, null, '', null, 'public', 0, null, null, null);
        $metadata = ['gallery.state' => 'ready', 'gallery.width' => '100', 'gallery.height' => '100', 'gallery.original_upload_id' => '1', 'gallery.preview_upload_id' => '2'];
        $cover = new Feed(900, 42, 1, 'gallery-photo', '9', 'Cover', null, null, '', null, 'public', 9, null, null, null, metadata: $metadata);
        $firstSummer = new Feed(902, 42, 1, 'gallery-photo', '1', 'First summer photo', null, null, '', null, 'public', 0, null, null, null, metadata: $metadata);
        $first = new Feed(901, 43, 1, 'gallery-photo', '1', 'First', null, null, '', null, 'public', 0, null, null, null, metadata: $metadata);
        $feeds = $this->createMock(FeedService::class);
        $feeds->method('getFeedByOwnerAndType')->willReturn($blog);
        $feeds->method('getFeedById')->willReturnCallback(fn ($id) => match ($id) {
            10 => $blog, 42 => $album, 43 => $foreignCoverAlbum, 44 => $emptyAlbum, 900 => $cover, default => null,
        });
        $feeds->expects(self::exactly(4))->method('getFeedsByParentAndTypePage')->willReturnCallback(function ($parent, $type, $user, $limit, $offset, $ordered = false) use ($viewer, $album, $foreignCoverAlbum, $emptyAlbum, $firstSummer, $first) {
            self::assertSame($viewer, $user);
            if ($parent === 10) {
                self::assertSame('gallery-album', $type);
                self::assertSame(20, $limit);
                self::assertSame(20, $offset);
                return ['items' => [$album, $foreignCoverAlbum, $emptyAlbum], 'total' => 23];
            }
            self::assertSame('gallery-photo', $type);
            self::assertSame(1, $limit);
            self::assertSame(0, $offset);
            self::assertTrue($ordered);
            return match ($parent) {
                42 => ['items' => [$firstSummer], 'total' => 200],
                43 => ['items' => [$first], 'total' => 1],
                44 => ['items' => [], 'total' => 0],
            };
        });
        [$controller, $router] = $this->controller($feeds, $viewer, $owner, ['offset' => '20']);
        $route = $router->resolve('/users/alice/photos/');
        $view = $controller->show($route['page'], ['username' => 'alice']);
        self::assertSame('/users/alice/photos/summer/9/preview/', $view->data['albums'][0]['previewUrl']);
        self::assertSame('Summer & sunshine', $view->data['albums'][0]['description']);
        self::assertSame(200, $view->data['albums'][0]['photoCount']);
        self::assertSame('/users/alice/photos/walks/1/preview/', $view->data['albums'][1]['previewUrl']);
        self::assertNull($view->data['albums'][2]['previewUrl']);
        self::assertSame(0, $view->data['albums'][2]['photoCount']);
    }

    public function testShowcaseUsesConfiguredMountAndExistingViewerUrls(): void
    {
        $viewer = new User(2, '');
        $photo = new Feed(100, 42, 1, 'gallery-photo', '7', 'Summer', null, null, '', null, 'public', 0, null, null, '/users/alice/photos/summer/7/', authorDisplayName: 'Alice', ratingSum: 9, ratingCount: 2, authorUrl: '/users/alice/');
        $feeds = $this->createMock(FeedService::class);
        $feeds->expects(self::once())->method('getFeedsByTypePage')->with('gallery-photo', $viewer, 12, 12)->willReturn(['items' => [$photo], 'total' => 30]);
        [$controller] = $this->controller($feeds, $viewer, new User(1, '', username: 'alice'), ['offset' => '12']);
        $page = new Page(900, 1, 'photography', 'Photography', (object) ['photosPerPage' => '12'], null, null, null, ['GET'], 'html', 'public', 'photos.showcase');
        $view = $controller->show($page);
        self::assertSame('modules/users/photo-showcase.twig', $view->template);
        self::assertSame('Photography', $view->data['title']);
        self::assertSame('/photography/', $view->data['previousUrl']);
        self::assertSame('/photography/?offset=24', $view->data['nextUrl']);
        self::assertSame('/users/alice/photos/summer/7/', $view->data['photos'][0]['url']);
        self::assertSame('/users/alice/photos/summer/7/preview/', $view->data['photos'][0]['previewUrl']);
        self::assertSame(4.5, $view->data['photos'][0]['ratingAverage']);
        self::assertSame(2, $view->data['photos'][0]['ratingCount']);
        self::assertSame('Alice', $view->data['photos'][0]['authorName']);
    }

    public function testShowcaseBoundsSettingsAndHasAGracefulEmptyState(): void
    {
        $viewer = new User(0, '');
        $feeds = $this->createMock(FeedService::class);
        $feeds->expects(self::once())->method('getFeedsByTypePage')->with('gallery-photo', $viewer, 24, 0)->willReturn(['items' => [], 'total' => 0]);
        [$controller] = $this->controller($feeds, $viewer, new User(1, ''), ['offset' => '-100']);
        $page = new Page(900, 1, 'pictures', null, (object) ['photosPerPage' => 99999], null, null, null, ['GET'], 'html', 'public', 'photos.showcase');
        $view = $controller->show($page);
        self::assertSame([], $view->data['photos']);
        self::assertNull($view->data['previousUrl']);
        self::assertNull($view->data['nextUrl']);
    }

}
