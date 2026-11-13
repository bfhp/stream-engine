<?php

declare(strict_types=1);

namespace Tests\Modules\Users;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\Config;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Router;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Users\UsersController;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\AuthService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\SettingsService;
use StreamEngine\Service\UploadService;
use StreamEngine\Service\UserService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

final class ModuleTest extends TestCase
{
    public function testResolvesThroughControllerFactory(): void
    {
        // AuthService and UserService are final - can't be doubled with
        // createStub(), so build real instances the way ControllerFactoryTest
        // and CronRunnerTest already do for the same reason.
        $db = $this->createStub(PdoDatabase::class);
        $pageTree = new PageTree([
            $this->page(id: 1, parentId: null, pattern: '', action: null),
            $this->page(id: 2, parentId: 1, pattern: 'users', action: 'users.list'),
            $this->page(id: 3, parentId: 2, pattern: '{username}', action: 'user.show', feedType: 'blog'),
            $this->page(id: 4, parentId: 1, pattern: 'communities', action: 'community.main'),
        ]);
        $urlGenerator = new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache());
        $tm = new TranslationManager('ru', 'en');
        $config = new Config(['SITE_URL' => 'https://example.test']);

        $authService = new AuthService(
            new UserRepository($db),
            new UserSessionRepository($db)
        );
        $mailService = (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor();
        // MessageService is `final`, so it's built the same
        // reflection-without-constructor way as MailService above. Safe
        // here only because nothing in this test calls notify() - unlike
        // UserServiceTest, whose default now builds a real instance around
        // FakePdoDatabase instead, since notify() rethrows rather than
        // swallows a failure (see its own source). UsersController's own
        // constructor also takes this instance directly (to build its
        // private FriendService - see its own note), so it's registered
        // below like any other shared platform service.
        $messageService = (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor();
        $userService = new UserService(
            new UserRepository($db),
            $db,
            $mailService,
            $tm,
            new NotificationService(
                $messageService,
                new NotificationDeliveryRepository($db),
                new NotificationPreferenceRepository($db),
                new UserRepository($db),
                $mailService,
                translationManager: new \StreamEngine\Core\TranslationManager('ru', 'en'),
                config: new \StreamEngine\Core\Config(['SITE_URL' => 'https://example.test']),
            ),
            // Presence (the profile page's online dot) lives on UserService
            // now - a real repository over the same stub $db.
            new UserSessionRepository($db),
            $config
        );

        // UploadService/FeedService aren't final, so plain stubs are enough
        // here - this test only cares that ControllerFactory can resolve
        // UsersController's constructor, not about their behavior.
        // BlogPostService/FriendService are no longer resolved through the
        // container at all - UsersController builds both itself (see its
        // own constructor), so there's nothing to stub for them here.
        $uploadService = $this->createStub(UploadService::class);
        $feedService = $this->createStub(FeedService::class);

        $services = [
            $db,
            $authService,
            $userService,
            $tm,
            $urlGenerator,
            $pageTree,
            $uploadService,
            $feedService,
            new NotificationService(
                $messageService,
                new NotificationDeliveryRepository($db),
                new NotificationPreferenceRepository($db),
                new UserRepository($db),
                $mailService,
                translationManager: new \StreamEngine\Core\TranslationManager('ru', 'en'),
                config: new \StreamEngine\Core\Config(['SITE_URL' => 'https://example.test']),
            ),
            new Formatter($tm, 'ru'),
            $config,
            new SettingsService(new SettingsRepository($db)),
        ];

        $modules = new ModuleRegistry();
        $factory = new ControllerFactory($modules, ...$services);
        $factory->registerRuntimePages($pageTree, $tm);
        $pageTree->validateDefinitions();
        $context = new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'));

        $controller = $factory->createForPage($this->pageWithAction('users.list'), $context);

        $this->assertInstanceOf(UsersController::class, $controller);

        $runtime = $pageTree->findByAction('user.post-show-slug');
        $this->assertNotNull($runtime);
        $this->assertInstanceOf(UsersController::class, $factory->createForPage($runtime, $context));
        $this->assertSame('blog-post', $runtime->feedType);
        $this->assertTrue($runtime->commentsEnabled());

        $newPage = $pageTree->findByAction('user.post-new');
        $editPage = $pageTree->findByAction('user.post-edit');
        $this->assertSame(AccessService::ACCESS_AUTHENTICATED, $newPage?->accessRule);
        $this->assertSame('noindex', $newPage?->changefreq);
        $this->assertSame('Новый пост', $newPage?->pageName);
        $this->assertSame(AccessService::ACCESS_AUTHENTICATED, $editPage?->accessRule);
        $this->assertSame('noindex', $editPage?->changefreq);
        $this->assertSame('Редактирование записи', $editPage?->pageName);
        $this->assertSame('Новый пост', $controller->getBreadcrumb($newPage)->title);
        $this->assertSame('Редактирование записи', $controller->getBreadcrumb($editPage)->title);
        $this->assertSame(
            '/users/alice/post/',
            $urlGenerator->childAction(
                'user.post-new',
                $urlGenerator->action('user.show', ['username' => 'alice']),
            ),
        );
        $this->assertSame(
            '/users/alice/hello/edit/',
            $urlGenerator->childAction('user.post-edit', '/users/alice/hello/'),
        );
        $communityUrl = $urlGenerator->action('community.show-slug', ['slug' => 'devs']);
        $this->assertSame('/communities/create/', $urlGenerator->childAction(
            'community.create',
            $urlGenerator->action('community.main'),
        ));
        $this->assertSame('/communities/devs/post/', $urlGenerator->childAction('community.post-new', $communityUrl));
        $this->assertSame('/communities/devs/manage/', $urlGenerator->childAction('community.manage', $communityUrl));
        $this->assertSame(
            '/communities/devs/hello/edit/',
            $urlGenerator->childAction('community.post-edit', '/communities/devs/hello/'),
        );

        foreach ([
            'community.create' => 'Создание сообщества',
            'community.post-new' => 'Новый пост в сообществе',
            'community.post-edit' => 'Редактирование записи',
            'community.manage' => 'Управление',
        ] as $action => $title) {
            $runtimePage = $pageTree->findByAction($action);
            $this->assertNotNull($runtimePage);
            $this->assertSame($title, $controller->getBreadcrumb($runtimePage)->title);
        }

        $router = new Router($pageTree);
        $this->assertSame('user.post-new', $router->resolve('/users/alice/post/')['page']->action);
        $this->assertSame('user.post-show-slug', $router->resolve('/users/alice/hello/')['page']->action);
        $this->assertSame('user.post-edit', $router->resolve('/users/alice/hello/edit/')['page']->action);
        $this->assertSame('community.create', $router->resolve('/communities/create/')['page']->action);
        $this->assertSame('community.show-slug', $router->resolve('/communities/devs/')['page']->action);
        $this->assertSame('community.post-new', $router->resolve('/communities/devs/post/')['page']->action);
        $this->assertSame('community.post-show-slug', $router->resolve('/communities/devs/hello/')['page']->action);
        $this->assertSame('community.post-edit', $router->resolve('/communities/devs/hello/edit/')['page']->action);
        $this->assertSame('community.manage', $router->resolve('/communities/devs/manage/')['page']->action);
    }

    public function testPersonalBlogRuntimePagesRequireProfileMount(): void
    {
        $tree = new PageTree([]);

        UsersController::registerRuntimePages($tree, new TranslationManager('ru', 'en'));

        $this->assertSame([], $tree->all());
    }

    public function testCommunityRuntimePagesDoNotRequireProfileMount(): void
    {
        $tree = new PageTree([
            $this->page(id: 1, parentId: null, pattern: '', action: null),
            $this->page(id: 2, parentId: 1, pattern: 'communities', action: 'community.main'),
        ]);

        UsersController::registerRuntimePages($tree, new TranslationManager('ru', 'en'));
        $tree->validateDefinitions();

        $community = $tree->findByAction('community.show-slug');
        $post = $tree->findByAction('community.post-show-slug');
        $this->assertSame('community', $community?->feedType);
        $this->assertSame(AccessService::ACCESS_PUBLIC, $community?->accessRule);
        $this->assertSame('blog-post', $post?->feedType);
        $this->assertTrue($post?->commentsEnabled());
        $this->assertSame('noindex', $tree->findByAction('community.create')?->changefreq);
        $this->assertSame('Создание сообщества', $tree->findByAction('community.create')?->pageName);
        $this->assertSame(AccessService::ACCESS_AUTHENTICATED, $tree->findByAction('community.create')?->accessRule);
        $this->assertSame('noindex', $tree->findByAction('community.post-new')?->changefreq);
        $this->assertSame('Новый пост в сообществе', $tree->findByAction('community.post-new')?->pageName);
        $this->assertSame(AccessService::ACCESS_AUTHENTICATED, $tree->findByAction('community.post-new')?->accessRule);
        $this->assertSame('noindex', $tree->findByAction('community.post-edit')?->changefreq);
        $this->assertSame('Редактирование записи', $tree->findByAction('community.post-edit')?->pageName);
        $this->assertSame(AccessService::ACCESS_AUTHENTICATED, $tree->findByAction('community.post-edit')?->accessRule);
        $this->assertSame('noindex', $tree->findByAction('community.manage')?->changefreq);
        $this->assertSame('Управление', $tree->findByAction('community.manage')?->pageName);
        $this->assertSame(AccessService::ACCESS_AUTHENTICATED, $tree->findByAction('community.manage')?->accessRule);
    }

    public function testRuntimePageNamesUseActiveLocale(): void
    {
        $tree = new PageTree([
            $this->page(id: 1, parentId: null, pattern: '', action: null),
            $this->page(id: 2, parentId: 1, pattern: 'users', action: 'users.list'),
            $this->page(id: 3, parentId: 2, pattern: '{username}', action: 'user.show', feedType: 'blog'),
            $this->page(id: 4, parentId: 1, pattern: 'communities', action: 'community.main'),
        ]);

        UsersController::registerRuntimePages($tree, new TranslationManager('en'));

        $this->assertSame('New post', $tree->findByAction('user.post-new')?->pageName);
        $this->assertSame('Edit post', $tree->findByAction('user.post-edit')?->pageName);
        $this->assertSame('Create a community', $tree->findByAction('community.create')?->pageName);
        $this->assertSame('New community post', $tree->findByAction('community.post-new')?->pageName);
        $this->assertSame('Management', $tree->findByAction('community.manage')?->pageName);
    }

    public function testPersonalBlogInternalActionsAreNotPubliclyConfigurable(): void
    {
        $actions = UsersController::pageActions();

        $this->assertArrayHasKey('user.show', $actions);
        $this->assertArrayHasKey('user.post-show-id', $actions);
        $this->assertArrayNotHasKey('user.post-new', $actions);
        $this->assertArrayNotHasKey('user.post-show-slug', $actions);
        $this->assertArrayNotHasKey('user.post-edit', $actions);
    }

    public function testCommunityInternalActionsAreNotPubliclyConfigurable(): void
    {
        $actions = UsersController::pageActions();

        $this->assertArrayHasKey('community.main', $actions);
        $this->assertArrayHasKey('community.show-id', $actions);
        $this->assertArrayHasKey('community.post-show-id', $actions);
        foreach ([
            'community.create',
            'community.show-slug',
            'community.post-new',
            'community.post-show-slug',
            'community.post-edit',
            'community.manage',
        ] as $action) {
            $this->assertArrayNotHasKey($action, $actions);
        }
    }

    public function testPhotoRoutesPreserveBlogAndCommunityContexts(): void
    {
        $tree = new PageTree([
            $this->page(1, null, '', 'home.index'),
            $this->page(2, 1, 'users', 'users.list'),
            $this->page(3, 2, '{username}', 'user.show', 'blog'),
            $this->page(4, 1, 'communities', 'community.main'),
        ]);
        UsersController::registerRuntimePages($tree, new TranslationManager('ru', 'en'));
        $router = new Router($tree);
        $urls = new UrlGenerator($tree, new FakeFeedRepository([]), new ArrayCache());
        foreach ([['user', ['username' => 'alice'], '/users/alice'], ['community', ['slug' => 'devs'], '/communities/devs']] as [$scope, $params, $base]) {
            self::assertSame($scope.'.photos', $router->resolve($base.'/photos/')['page']->action);
            $resolved = $router->resolve($base.'/photos/letniy-otdyh/');
            self::assertSame($scope.'.photo-album', $resolved['page']->action);
            self::assertSame($scope.'.photo-album-edit', $router->resolve($base.'/photos/letniy-otdyh/edit/')['page']->action);
            self::assertSame($scope.'.photo-new', $router->resolve($base.'/photos/letniy-otdyh/new-photo/')['page']->action);
            self::assertSame($scope.'.photo-show', $router->resolve($base.'/photos/letniy-otdyh/7/')['page']->action);
            self::assertSame($base.'/photos/letniy-otdyh/', $urls->action($scope.'.photo-album', [...$params, 'albumSlug' => 'letniy-otdyh']));
            self::assertArrayNotHasKey($scope.'.photos', UsersController::pageActions());
        }
        UsersController::registerApi(1, $tree);
        $router = new Router($tree);
        self::assertSame('user.photo-album-create', $router->resolve('/users/photo-albums/')['page']->action);
        self::assertSame('community.photo-album-create', $router->resolve('/communities/10/photo-albums/')['page']->action);
    }

    public function testAlbumMutationApiResolvesContextAndAlbumSegments(): void
    {
        $tree = new PageTree([
            $this->page(1, null, '', 'home.index'),
            Page::api(2, 1, 'api', ['GET'], 'api.index'),
            Page::api(3, 2, 'v1', ['GET'], 'api.v1'),
        ]);
        UsersController::registerApi(3, $tree);
        $tree->validateDefinitions();
        $router = new Router($tree);
        foreach (['/api/v1/photo-albums/9/32', '/api/v1/photo-albums/9/32/'] as $url) {
            $route = $router->resolve($url);
            self::assertNotNull($route);
            self::assertSame('photos.album-item', $route['page']->action);
            self::assertSame(['PATCH', 'DELETE'], $route['page']->requestMethods);
            self::assertSame(['contextId' => '9', 'albumId' => '32'], $route['params']);
        }
        $upload = $router->resolve('/api/v1/photo-albums/9/32/photos');
        self::assertSame('photos.upload', $upload['page']->action);
        self::assertSame(['POST'], $upload['page']->requestMethods);
        $file = $router->resolve('/api/v1/photo-albums/9/32/photos/7/preview');
        self::assertSame('photos.file', $file['page']->action);
        self::assertSame(['GET'], $file['page']->requestMethods);
        self::assertSame(['contextId' => '9', 'albumId' => '32', 'photoId' => '7', 'variant' => 'preview'], $file['params']);
    }

    private function pageWithAction(string $action): Page
    {
        return new Page(
            id: 100,
            parentId: 1,
            pattern: 'users',
            pageName: 'Users',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action,
        );
    }

    private function page(
        int $id,
        ?int $parentId,
        string $pattern,
        ?string $action,
        ?string $feedType = null,
    ): Page {
        return new Page(
            id: $id,
            parentId: $parentId,
            pattern: $pattern,
            pageName: 'Page '.$id,
            settings: null,
            feedType: $feedType,
            listFeedType: null,
            feedId: null,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action,
        );
    }
}
