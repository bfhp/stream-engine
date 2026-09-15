<?php

declare(strict_types=1);

namespace Tests\Modules\Feedback;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\Config;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Feedback\FeedbackController;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

final class ModuleTest extends TestCase
{
    public function testResolvesThroughControllerFactory(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $users = new UserRepository($db);
        $services = [
            $db,
            $users,
            new NotificationService(
                (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
                new NotificationDeliveryRepository($db),
                new NotificationPreferenceRepository($db),
                $users,
                $this->createStub(MailService::class),
                translationManager: new \StreamEngine\Core\TranslationManager('ru', 'en'),
                config: new \StreamEngine\Core\Config(['SITE_URL' => 'https://example.test']),
            ),
            $this->createStub(FeedService::class),
            new TranslationManager('ru', 'en'),
            new Config(['APP_SECRET' => 'test-secret']),
            new UrlGenerator(
                new PageTree([]),
                new FakeFeedRepository([]),
                new ArrayCache(),
            ),
        ];

        $modules = new ModuleRegistry();
        $factory = new ControllerFactory($modules, ...$services);
        $context = new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'));

        $controller = $factory->createForPage($this->pageWithAction('feedback.show'), $context);

        $this->assertInstanceOf(FeedbackController::class, $controller);
    }

    private function pageWithAction(string $action): Page
    {
        return new Page(
            id: 100,
            parentId: 1,
            pattern: 'feedback',
            pageName: 'Feedback',
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
