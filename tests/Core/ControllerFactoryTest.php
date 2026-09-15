<?php

declare(strict_types=1);

namespace Tests\Core;

use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use StreamEngine\Controllers\ActionProbeController;
use StreamEngine\Controllers\ControllerWithUnknownDependency;
use StreamEngine\Controllers\FactoryProbeController;
use StreamEngine\Controllers\PlainClass;
use StreamEngine\Core\Cache;
use StreamEngine\Core\Config;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\ControllerInterface;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\API\APIController;
use StreamEngine\Modules\Feedback\FeedbackController;
use StreamEngine\Repository\FeedTermRepository;
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
use StreamEngine\Service\PollService;
use StreamEngine\Service\SettingsService;
use StreamEngine\Service\TermService;
use StreamEngine\Service\UploadService;
use StreamEngine\Service\UserService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

require_once __DIR__.'/../Support/ControllerFactoryFixtures.php';

final class ControllerFactoryTest extends TestCase
{
    private function makeFactory(): array
    {
        $db = $this->createStub(PdoDatabase::class);
        $settingsService = new SettingsService(new SettingsRepository($db));
        $feedService = $this->createStub(FeedService::class);
        $accessService = $this->createStub(AccessService::class);
        $uploadService = $this->createStub(UploadService::class);
        $pageTree = new PageTree([
            new Page(
                id: 1,
                parentId: null,
                pattern: '',
                pageName: 'Root',
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'raw',
                accessRule: AccessService::ACCESS_PUBLIC
            ),
        ]);
        $urlGenerator = new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache());
        $termService = new TermService(new FeedTermRepository($db), $urlGenerator);
        $config = new Config([]);
        $cache = new Cache();
        $formatter = new Formatter(new TranslationManager('ru', 'en'), 'ru');
        $tm = new TranslationManager('ru', 'en');

        $authService = new AuthService(
            new UserRepository($db),
            new UserSessionRepository($db)
        );

        $mailService = (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor();
        // MessageService is `final`, so it's built the same
        // reflection-without-constructor way as MailService above. Safe
        // only because nothing here calls notify() - UserServiceTest's own
        // makeMessageServiceStub() no longer uses this trick at all,
        // since notify() now rethrows rather than swallows a failure (see
        // its own source).
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
            new UserSessionRepository($db),
            new Config([])
        );

        $services = [
            $db,
            $settingsService,
            $feedService,
            $this->createStub(PollService::class),
            $authService,
            $userService,
            new UserRepository($db),
            new NotificationService(
                $messageService,
                new NotificationDeliveryRepository($db),
                new NotificationPreferenceRepository($db),
                new UserRepository($db),
                $mailService,
                translationManager: new \StreamEngine\Core\TranslationManager('ru', 'en'),
                config: new \StreamEngine\Core\Config(['SITE_URL' => 'https://example.test']),
            ),
            $accessService,
            $termService,
            $uploadService,
            $pageTree,
            $urlGenerator,
            $config,
            $cache,
            $formatter,
            $tm,
        ];

        // Registrations known to this test's factory: fixtures used to probe DI
        // behaviour and automatic pageActions() resolution, plus the real
        // APIController - now migrated, so registered the same way
        // module discovery would - used to probe manual registerAction()
        // (API declares no pageActions() of its own; see ModuleTest.php
        // under tests/Modules/API for why).
        $modules = \StreamEngine\Controllers\registryWithFixtures([
            FeedbackController::class,
            FactoryProbeController::class,
            ControllerWithUnknownDependency::class,
            ActionProbeController::class,
            APIController::class,
        ]);

        $factory = new ControllerFactory($modules, ...$services);

        return [
            'factory' => $factory,
            'modules' => $modules,
            'db' => $db,
            'settingsService' => $settingsService,
            'feedService' => $feedService,
            'authService' => $authService,
            'userService' => $userService,
            'accessService' => $accessService,
            'termService' => $termService,
            'uploadService' => $uploadService,
            'pageTree' => $pageTree,
            'urlGenerator' => $urlGenerator,
            'config' => $config,
            'cache' => $cache,
            'formatter' => $formatter,
            'tm' => $tm,
        ];
    }

    public function testCreateFeedbackControllerWithRegisteredDependencies(): void
    {
        $deps = $this->makeFactory();
        $context = new RequestContext(
            new User(id: 0, email: '', role: AccessService::ROLE_USER),
            new \DateTimeZone('UTC'),
        );

        self::assertInstanceOf(
            FeedbackController::class,
            $deps['factory']->create('Feedback', $context),
        );
    }

    public function testCreateInjectsKnownDependenciesIntoController(): void
    {
        $deps = $this->makeFactory();
        $context = new RequestContext(
            new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER),
            new \DateTimeZone('UTC')
        );

        /** @var FactoryProbeController $controller */
        $controller = $deps['factory']->create('FactoryProbeController', $context);

        $this->assertInstanceOf(FactoryProbeController::class, $controller);
        $this->assertSame($deps['db'], $controller->exposedDb());
        $this->assertSame($context, $controller->exposedContext());
        $this->assertSame($deps['settingsService'], $controller->settingsService);
        $this->assertSame($deps['feedService'], $controller->feedService);
        $this->assertSame($deps['authService'], $controller->authService);
        $this->assertSame($deps['userService'], $controller->userService);
        $this->assertSame($deps['accessService'], $controller->accessService);
        $this->assertSame($deps['uploadService'], $controller->uploadService);
        $this->assertSame($deps['pageTree'], $controller->pageTree);
        $this->assertSame($deps['urlGenerator'], $controller->urlGenerator);
        $this->assertSame($deps['config'], $controller->config);
        $this->assertSame($deps['cache'], $controller->cache);
        $this->assertSame($deps['formatter'], $controller->formatter);
        $this->assertSame($deps['tm'], $controller->translationManager);
        $this->assertSame($deps['termService'], $controller->termService);
    }

    public function testCreateThrowsForUnknownControllerClass(): void
    {
        $deps = $this->makeFactory();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Controller DoesNotExist not found');

        $deps['factory']->create(
            'DoesNotExist',
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'))
        );
    }

    public function testModuleRegistryIgnoresClassThatIsNotAController(): void
    {
        $registry = \StreamEngine\Controllers\registryWithFixtures([PlainClass::class]);
        self::assertNull($registry->controllerClassFor('PlainClass'));
    }

    public function testCreateThrowsForUnknownConstructorDependency(): void
    {
        $deps = $this->makeFactory();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown dependency: stdClass');

        $deps['factory']->create(
            'ControllerWithUnknownDependency',
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'))
        );
    }

    public function testCreateForPageResolvesControllerFromAction(): void
    {
        $deps = $this->makeFactory();
        $context = new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'));

        $controller = $deps['factory']->createForPage($this->pageWithAction('probe.show'), $context);

        $this->assertInstanceOf(ActionProbeController::class, $controller);
    }

    public function testCreateForPageThrowsForNullAction(): void
    {
        $deps = $this->makeFactory();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("No controller resolves page action 'null'");

        $deps['factory']->createForPage(
            $this->pageWithAction(null),
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'))
        );
    }

    public function testCreateForPageThrowsForUnknownAction(): void
    {
        $deps = $this->makeFactory();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("No controller resolves page action 'nope.nope'");

        $deps['factory']->createForPage(
            $this->pageWithAction('nope.nope'),
            new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'))
        );
    }

    public function testRegisterActionEnablesResolution(): void
    {
        $deps = $this->makeFactory();
        $context = new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'));

        $deps['factory']->registerAction('feeds.list', 'API');

        $controller = $deps['factory']->createForPage($this->pageWithAction('feeds.list'), $context);

        $this->assertInstanceOf(APIController::class, $controller);
    }

    public function testRegisterActionThrowsOnCollision(): void
    {
        $deps = $this->makeFactory();

        // 'probe.show' is already bound via ActionProbeController::pageActions().
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Duplicate page action 'probe.show'");

        $deps['factory']->registerAction('probe.show', 'Search');
    }

    /**
     * Integration check on the real production wiring: everything
     * StreamEngine discovers in the project must declare globally unique
     * page actions. This is the guard that used to live against
     * ControllerFactory::CONTROLLERS; it now covers the same set
     * of modules that StreamEngine discovers at startup.
     */
    public function testProductionModulesDeclareGloballyUniqueActions(): void
    {
        $registrations = (new ModuleRegistry())->controllerClasses();

        $seen = [];
        foreach ($registrations as $entry) {
            $controllerClass = $entry;

            foreach (array_keys($controllerClass::pageActions()) as $action) {
                $this->assertArrayNotHasKey(
                    $action,
                    $seen,
                    "Action '$action' is declared by both " . ($seen[$action] ?? '?') . " and $controllerClass"
                );
                $seen[$action] = $controllerClass;
            }
        }

        $this->assertNotEmpty($seen);

        // Also confirm ModuleRegistry itself accepts the production list without
        // throwing (duplicate ids, invalid entries, etc.)
        $this->assertNotEmpty((new ModuleRegistry())->controllerClasses());
    }

    /**
     * registerApi() actions are declared dynamically into a shared PageTree
     * at boot (see StreamEngine::initCronAndApi()) rather than statically
     * via pageActions() - the check above never calls registerApi() at all,
     * so a collision entirely inside one module's own registerApi() (e.g.
     * two placeholder path segments reusing the same throwaway action name
     * - exactly what happened with Forums' 'forums.api-parent', declared by
     * both its 'forums' and '{topicId}' nodes) only ever surfaced as a
     * fatal RuntimeException at real app boot, never in any test.
     *
     * Mirrors StreamEngine::initCronAndApi()'s own loop closely enough to
     * catch the same bug class it would hit in production: call every real
     * module's registerApi() against one shared PageTree in turn, and after
     * each call, check every newly-added page's action against the same
     * kind of "seen" map testProductionModulesDeclareGloballyUniqueActions()
     * uses (a fresh one here, since pageActions() and registerApi() are
     * different namespaces of actions in this test - in production both
     * ultimately share ModuleRegistry's one $actionToId map, but pageActions()
     * duplicates are already covered above). No DB or controller factory
     * needed: every real registerApi() implementation is pure
     * PageTree::add()/Page::api() calls, nothing else.
     */
    public function testProductionModulesRegisterApiWithGloballyUniqueActions(): void
    {
        $registrations = (new ModuleRegistry())->controllerClasses();

        $apiPageId = 1;
        $pageTree = new PageTree([
            new Page(
                id: $apiPageId,
                parentId: null,
                pattern: 'v1',
                pageName: 'API v1',
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'json',
                accessRule: AccessService::ACCESS_PUBLIC,
            ),
        ]);

        // Pre-seed with every id already in the tree (just the root above)
        // so its own action, whatever it is, is never inspected below - only
        // pages registerApi() itself adds are in scope, same as
        // StreamEngine::initCronAndApi()'s own $seenIds diff.
        $seenPageIds = array_fill_keys(
            array_map(static fn (Page $p): int => $p->id, $pageTree->all()),
            true
        );

        $seenActions = [];

        foreach ($registrations as $entry) {
            $controllerClass = $entry;

            if (! is_subclass_of($controllerClass, ControllerInterface::class)) {
                continue;
            }

            $controllerClass::registerApi($apiPageId, $pageTree);

            foreach ($pageTree->all() as $page) {
                if (isset($seenPageIds[$page->id])) {
                    continue;
                }
                $seenPageIds[$page->id] = true;

                if ($page->action === null) {
                    continue;
                }

                $this->assertArrayNotHasKey(
                    $page->action,
                    $seenActions,
                    "registerApi() action '{$page->action}' (pattern '{$page->pattern}') is declared by both "
                        . ($seenActions[$page->action] ?? '?') . " and $controllerClass"
                );
                $seenActions[$page->action] = $controllerClass;
            }
        }

        $this->assertNotEmpty($seenActions);
    }

    private function pageWithAction(?string $action): Page
    {
        return new Page(
            id: 100,
            parentId: 1,
            pattern: 'x',
            pageName: 'X',
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
