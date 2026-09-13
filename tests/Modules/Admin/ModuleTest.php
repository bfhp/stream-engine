<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Config;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Admin\AdminController;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\SettingsService;

final class ModuleTest extends TestCase
{
    public function testResolvesThroughControllerFactory(): void
    {
        $db = $this->createStub(PdoDatabase::class);

        $services = [
            $db,
            $this->createStub(AccessService::class),
            new SettingsService(new SettingsRepository($db)),
            new Config([]),
        // Injected only so Security::verifyCsrf() can translate its error
        // messages - see AdminController::callApi().
            new TranslationManager('ru', 'en'),
        ];

        $modules = new ModuleRegistry();
        $factory = new ControllerFactory($modules, ...$services);
        $context = new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'));

        $controller = $factory->createForPage($this->pageWithAction('admin.index'), $context);

        $this->assertInstanceOf(AdminController::class, $controller);
    }

    private function pageWithAction(string $action): Page
    {
        return new Page(
            id: 100,
            parentId: 1,
            pattern: 'admin',
            pageName: 'Admin',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_ADMIN,
            action: $action,
        );
    }
}
