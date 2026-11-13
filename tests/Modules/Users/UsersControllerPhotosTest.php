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
    private function controller(FeedService $feeds, User $viewer, User $owner): array
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
        $reflection->getParentClass()->getProperty('context')->setValue($controller, new RequestContext($viewer, new \DateTimeZone('UTC'), new QueryParams([])));
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
        foreach (['pageTree' => $tree, 'tm' => $tm, 'feedService' => $feeds, 'userService' => $users, 'communityService' => $communities, 'memberships' => new MembershipRepository($db), 'urlGenerator' => new UrlGenerator($tree, new FakeFeedRepository([]), new ArrayCache())] as $key => $value) {
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
}
