<?php

declare(strict_types=1);

namespace Tests\Modules\Profile;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Profile\ProfileController;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

final class ProfileUnsubscribeTest extends TestCase
{
    private const string TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        $_POST = [];
        http_response_code(200);

        parent::tearDown();
    }

    public function testRegistersRootUnsubscribePage(): void
    {
        $tree = new PageTree([$this->rootPage()]);

        ProfileController::registerApi(100, $tree);

        $page = $tree->findByAction('profile.email.unsubscribe');
        self::assertNotNull($page);
        self::assertSame(1, $page->parentId);
        self::assertSame('unsubscribe', $page->pattern);
        self::assertSame(['GET', 'POST'], $page->requestMethods);
        self::assertSame('html', $page->responseType);
        self::assertSame(AccessService::ACCESS_PUBLIC, $page->accessRule);

        $mailingsPage = $tree->findByAction('profile.mailings.update');
        self::assertNotNull($mailingsPage);
        self::assertSame('mailings', $mailingsPage->pattern);
        self::assertSame(['POST'], $mailingsPage->requestMethods);
    }

    public function testGetShowsConfirmationWithoutUnsubscribing(): void
    {
        $preferenceDb = $this->createMock(PdoDatabase::class);
        $preferenceDb->expects($this->once())->method('fetchOne')->willReturn(['user_id' => '7']);
        $preferenceDb->expects($this->never())->method('execute');
        $deliveryDb = $this->createMock(PdoDatabase::class);
        $deliveryDb->expects($this->never())->method('execute');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $view = $this->controller($preferenceDb, $deliveryDb)->show($this->unsubscribePage());

        self::assertSame('modules/profile/email-unsubscribe.twig', $view->template);
        self::assertTrue($view->data['valid']);
        self::assertFalse($view->data['unsubscribed']);
    }

    public function testOneClickPostUnsubscribesWithoutAuthentication(): void
    {
        $preferenceDb = $this->createMock(PdoDatabase::class);
        $preferenceDb->expects($this->once())->method('fetchOne')->willReturn(['user_id' => '7']);
        $preferenceDb->expects($this->once())->method('execute')->with(
            $this->stringContains('notification_preferences'),
            [7, '*', 'email', 'off']
        );
        $deliveryDb = $this->createMock(PdoDatabase::class);
        $deliveryDb->expects($this->once())->method('execute')->with(
            $this->stringContains("status = 'cancelled'"),
            [7]
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['List-Unsubscribe'] = 'One-Click';
        $view = $this->controller($preferenceDb, $deliveryDb)->show($this->unsubscribePage());

        self::assertTrue($view->data['valid']);
        self::assertTrue($view->data['unsubscribed']);
    }

    private function controller(PdoDatabase $preferenceDb, PdoDatabase $deliveryDb): ProfileController
    {
        $db = $this->createStub(PdoDatabase::class);
        $tree = new PageTree([]);
        $notifications = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($preferenceDb),
            new UserRepository($db),
            (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
            translationManager: new \StreamEngine\Core\TranslationManager('ru', 'en'),
            config: new \StreamEngine\Core\Config(['SITE_URL' => 'https://example.test']),
        );

        return new ProfileController(
            $db,
            new RequestContext(
                new User(0, '', AccessService::ROLE_USER),
                new DateTimeZone('UTC'),
                new QueryParams(['token' => self::TOKEN]),
            ),
            new TranslationManager('ru', 'en'),
            $tree,
            new UrlGenerator($tree, new FakeFeedRepository([]), new ArrayCache()),
            $notifications,
        );
    }

    private function unsubscribePage(): Page
    {
        return new Page(
            id: 2,
            parentId: 1,
            pattern: 'unsubscribe',
            pageName: 'Отписка от email-уведомлений',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET', 'POST'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'profile.email.unsubscribe',
        );
    }

    private function rootPage(): Page
    {
        return new Page(
            id: 1,
            parentId: null,
            pattern: '',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
        );
    }
}
