<?php

declare(strict_types=1);

namespace Tests\Modules\API;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Config;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\API\APIController;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\AuthService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\PollService;
use StreamEngine\Service\UploadService;

final class ModuleTest extends TestCase
{
    public function testResolvesThroughControllerFactory(): void
    {
        // AuthService is final - can't be doubled with createStub(), so build
        // a real instance the way ControllerFactoryTest already does.
        $db = $this->createStub(PdoDatabase::class);
        $authService = new AuthService(
            new UserRepository($db),
            new UserSessionRepository($db)
        );

        $services = [
            $db,
            $authService,
            $this->createStub(AccessService::class),
            $this->createStub(UploadService::class),
            $this->createStub(FeedService::class),
            $this->createStub(PollService::class),
            new PageTree([]),
            new Config([]),
            new TranslationManager('ru', 'en'),
            (new \ReflectionClass(NotificationService::class))->newInstanceWithoutConstructor(),
        ];

        $modules = new ModuleRegistry();

        // Like Sitemap, API declares no pageActions() - its routes are bound
        // to its module id by StreamEngine::initCronAndApi() at boot (some,
        // like api.index/api.v1.index, are even registered there directly,
        // outside of registerApi()). Mirror one such binding here.
        $modules->registerAction('api.index', 'API');

        $factory = new ControllerFactory($modules, ...$services);
        $context = new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'));

        $controller = $factory->createForPage($this->pageWithAction('api.index'), $context);

        $this->assertInstanceOf(APIController::class, $controller);
    }

    private function pageWithAction(string $action): Page
    {
        return new Page(
            id: 100,
            parentId: 1,
            pattern: 'api',
            pageName: 'API',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action,
        );
    }
}
