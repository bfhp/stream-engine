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
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Users\UsersController;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\AuthService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
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
        $pageTree = new PageTree([]);
        $urlGenerator = new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache());
        $tm = new TranslationManager('ru', 'en');

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
            ),
            // Presence (the profile page's online dot) lives on UserService
            // now - a real repository over the same stub $db.
            new UserSessionRepository($db),
            new Config([])
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
            ),
            new Formatter($tm, 'ru'),
        ];

        $modules = new ModuleRegistry();
        $factory = new ControllerFactory($modules, ...$services);
        $context = new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'));

        $controller = $factory->createForPage($this->pageWithAction('users.list'), $context);

        $this->assertInstanceOf(UsersController::class, $controller);
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
}
