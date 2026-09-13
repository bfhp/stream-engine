<?php

declare(strict_types=1);

namespace Tests\Modules\Profile;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Profile\ProfileController;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\NotificationService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

final class ModuleTest extends TestCase
{
    public function testResolvesThroughControllerFactory(): void
    {
        $pageTree = new PageTree([]);

        $services = [
            $this->createStub(PdoDatabase::class),
            new TranslationManager('ru', 'en'),
            // Needed by the "Друзья" tab's FriendListService, which the
            // controller builds itself - it resolves each row's profile URL
            // through these (see ProfileController::__construct()).
            $pageTree,
            new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache()),
            (new \ReflectionClass(NotificationService::class))->newInstanceWithoutConstructor(),
        ];

        $modules = new ModuleRegistry();
        $factory = new ControllerFactory($modules, ...$services);
        $context = new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'));

        $controller = $factory->createForPage($this->pageWithAction('profile.show'), $context);

        $this->assertInstanceOf(ProfileController::class, $controller);
    }

    private function pageWithAction(string $action): Page
    {
        return new Page(
            id: 100,
            parentId: 1,
            pattern: 'profile',
            pageName: 'Profile',
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
