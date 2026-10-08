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
        $this->assertTrue($runtime->commentsEnabled);

        $newPage = $pageTree->findByAction('user.post-new');
        $editPage = $pageTree->findByAction('user.post-edit');
        $this->assertSame(AccessService::ACCESS_AUTHENTICATED, $newPage?->accessRule);
        $this->assertSame('noindex', $newPage?->changefreq);
        $this->assertSame('Новый пост', $newPage?->pageName);
        $this->assertSame(AccessService::ACCESS_AUTHENTICATED, $editPage?->accessRule);
        $this->assertSame('noindex', $editPage?->changefreq);
        $this->assertSame('Редактирование записи', $editPage?->pageName);

        $router = new Router($pageTree);
        $this->assertSame('user.post-new', $router->resolve('/users/alice/post/')['page']->action);
        $this->assertSame('user.post-edit', $router->resolve('/users/alice/hello/edit/')['page']->action);
        $this->assertSame('community.create', $router->resolve('/communities/create/')['page']->action);
        $this->assertSame('community.post-new', $router->resolve('/communities/devs/post/')['page']->action);
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
        $this->assertTrue($post?->commentsEnabled);
        $this->assertSame('noindex', $tree->findByAction('community.create')?->changefreq);
        $this->assertSame('Создание сообщества', $tree->findByAction('community.create')?->pageName);
        $this->assertSame('noindex', $tree->findByAction('community.post-new')?->changefreq);
        $this->assertSame('Новый пост в сообществе', $tree->findByAction('community.post-new')?->pageName);
        $this->assertSame('noindex', $tree->findByAction('community.post-edit')?->changefreq);
        $this->assertSame('Редактирование записи', $tree->findByAction('community.post-edit')?->pageName);
        $this->assertSame('noindex', $tree->findByAction('community.manage')?->changefreq);
        $this->assertSame('Управление', $tree->findByAction('community.manage')?->pageName);
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
            commentsEnabled: false,
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
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action,
        );
    }
}
