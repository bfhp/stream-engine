<?php

declare(strict_types=1);

namespace Tests\Modules\Messages;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Messages\MessagesController;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MessageService;

final class ModuleTest extends TestCase
{
    public function testResolvesThroughControllerFactory(): void
    {
        $services = [
            $this->createStub(PdoDatabase::class),
            $this->createStub(FeedService::class),
        // MessageService is `final`, so it can't be createStub()'d - built
        // via reflection without its own constructor instead, same trick
        // used elsewhere in this suite (e.g. Modules\Users\ModuleTest).
        // Safe here since this test only checks that ControllerFactory can
        // resolve MessagesController's constructor, not messenger behavior.
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor()
        ];
        // Injected only so Security::verifyCsrf() can translate its error
        // messages - see MessagesController::callApi().
        $services[] = new TranslationManager('ru', 'en');

        $modules = new ModuleRegistry();
        $factory = new ControllerFactory($modules, ...$services);
        $context = new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'));

        $controller = $factory->createForPage($this->pageWithAction('messages.inbox'), $context);

        $this->assertInstanceOf(MessagesController::class, $controller);
    }

    private function pageWithAction(string $action): Page
    {
        return new Page(
            id: 100,
            parentId: 1,
            pattern: 'messages',
            pageName: 'Messages',
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
