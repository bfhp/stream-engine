<?php

declare(strict_types=1);

namespace Tests\Modules\Forums;

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
use StreamEngine\Modules\Forums\ForumsController;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\PollService;
use StreamEngine\Service\UploadService;
use StreamEngine\Service\UserService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

/**
 * Forums was the pilot for the feature-module architecture (see
 * docs/MODULE_CONTRACT.md). This test exists to prove the module resolves
 * correctly end to end: id/controller resolution through ModuleRegistry +
 * ControllerFactory using the module controller class. Discovery itself is
 * covered by ModuleAutoloadTest.
 */
final class ModuleTest extends TestCase
{
    public function testResolvesThroughControllerFactory(): void
    {
        $db = $this->createStub(PdoDatabase::class);
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
            new Page(
                id: 2,
                parentId: 1,
                pattern: 'forums',
                pageName: 'Forums',
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'html',
                accessRule: AccessService::ACCESS_PUBLIC,
                action: 'forums.list',
            ),
        ]);
        $urlGenerator = new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache());
        $tm = new TranslationManager('ru', 'en');

        // UserService is `final`, so it can't be doubled with createStub()
        // - build a real instance the same way Modules\Users\ModuleTest and
        // ControllerFactoryTest do for the same reason. MailService/
        // MessageService are also `final`; nothing in this test
        // reaches a code path that calls either, so building them via
        // reflection without their own constructor is enough.
        $mailService = (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor();
        $messageService = (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor();
        $userService = new UserService(
            new UserRepository($db),
            $db,
            $mailService,
            new TranslationManager('ru', 'en'),
            new NotificationService(
                $messageService,
                new NotificationDeliveryRepository($db),
                new NotificationPreferenceRepository($db),
                new UserRepository($db),
                $mailService,
                translationManager: new \StreamEngine\Core\TranslationManager('ru', 'en'),
                config: new \StreamEngine\Core\Config(['SITE_URL' => 'https://example.test']),
            ),
            // UserService owns presence now (the stats card's "Сейчас на
            // сайте" line goes through its onlineMembers()) - a real
            // repository over the same stub $db, unused on the paths this
            // test walks.
            new UserSessionRepository($db),
            new Config([])
        );

        $services = [
            $db,
            $this->createStub(FeedService::class),
            $userService,
            // ForumsController injects NotificationService for its own reply
            // notification endpoint (see notifyTopicFollowers()).
            new NotificationService(
                $messageService,
                new NotificationDeliveryRepository($db),
                new NotificationPreferenceRepository($db),
                new UserRepository($db),
                $mailService,
                translationManager: new \StreamEngine\Core\TranslationManager('ru', 'en'),
                config: new \StreamEngine\Core\Config(['SITE_URL' => 'https://example.test']),
            ),
            // ForumsController now injects PollService directly too (its own
            // topic-create endpoint attaching a poll - see attachTopicPoll()) -
            // a plain stub, same as FeedService above: nothing in this test
            // reaches a code path that calls it.
            $this->createStub(PollService::class),
            // ForumsController now injects UploadService directly too (its own
            // "Вложения" card - see resolveTopicAttachments()/
            // buildAttachmentRows()) - a plain stub, same reasoning as
            // PollService above.
            $this->createStub(UploadService::class),
            $pageTree,
            $urlGenerator,
            new Config([]),
            new Formatter($tm, 'ru'),
            $tm,
        ];

        $modules = new ModuleRegistry();
        $factory = new ControllerFactory($modules, ...$services);
        $factory->registerRuntimePages($pageTree, $tm);
        $pageTree->validateDefinitions();

        $context = new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'));

        $controller = $factory->create('Forums', $context);
        $this->assertInstanceOf(ForumsController::class, $controller);

        $viaAction = $factory->createForPage($this->pageWithAction('forums.list'), $context);
        $this->assertInstanceOf(ForumsController::class, $viaAction);

        $runtimePage = $pageTree->findByAction('forums.topic-edit');
        $this->assertNotNull($runtimePage);
        $this->assertInstanceOf(ForumsController::class, $factory->createForPage($runtimePage, $context));

        $router = new Router($pageTree);
        $this->assertSame(
            'forums.topic-new',
            $router->resolve('/forums/magiya/new/')['page']->action,
        );
        $this->assertSame(
            'forums.topic-edit',
            $router->resolve('/forums/magiya/svecha/edit/')['page']->action,
        );
        $this->assertSame(AccessService::ACCESS_AUTHENTICATED, $runtimePage->accessRule);
        $this->assertSame('noindex', $runtimePage->changefreq);
        $this->assertSame('Редактирование темы', $runtimePage->pageName);

        $topicList = $pageTree->findByAction('forums.topic-list');
        $topicView = $pageTree->findByAction('forums.topic-view');
        $topicNew = $pageTree->findByAction('forums.topic-new');
        $this->assertSame('forum', $topicList?->feedType);
        $this->assertSame(AccessService::ACCESS_PUBLIC, $topicList?->accessRule);
        $this->assertSame('forum-post', $topicView?->feedType);
        $this->assertSame(AccessService::ACCESS_PUBLIC, $topicView?->accessRule);
        $this->assertSame(AccessService::ACCESS_AUTHENTICATED, $topicNew?->accessRule);
        $this->assertSame('noindex', $topicNew?->changefreq);
        $this->assertSame('Новая тема', $topicNew?->pageName);
    }

    public function testRuntimePagesAreNotRegisteredWithoutForumsMount(): void
    {
        $tree = new PageTree([]);

        ForumsController::registerRuntimePages($tree, new TranslationManager('ru', 'en'));

        $this->assertSame([], $tree->all());
    }

    public function testRuntimePageNamesUseActiveLocale(): void
    {
        $tree = new PageTree([
            new Page(
                id: 1,
                parentId: null,
                pattern: 'forums',
                pageName: 'Forums',
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'html',
                accessRule: AccessService::ACCESS_PUBLIC,
                action: 'forums.list',
            ),
        ]);

        ForumsController::registerRuntimePages($tree, new TranslationManager('en'));

        $this->assertSame('New theme', $tree->findByAction('forums.topic-new')?->pageName);
        $this->assertSame('Editing the topic', $tree->findByAction('forums.topic-edit')?->pageName);
    }

    public function testOnlyForumsMountRemainsPubliclyConfigurable(): void
    {
        $this->assertSame(['forums.list'], array_keys(ForumsController::pageActions()));
    }

    private function pageWithAction(string $action): Page
    {
        return new Page(
            id: 100,
            parentId: 1,
            pattern: 'forums',
            pageName: 'Forums',
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
