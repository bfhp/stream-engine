<?php

declare(strict_types=1);

namespace Tests\Modules\Users;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\Config;
use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Users\BlogPostService;
use StreamEngine\Modules\Users\CommunityService;
use StreamEngine\Modules\Users\FriendService;
use StreamEngine\Modules\Users\UsersController;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\UploadService;
use StreamEngine\Service\UserService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;
use Tests\Support\PhpInputStreamMock;

final class UsersControllerTest extends TestCase
{
    private function makeUsersModule(PdoDatabase $db): UsersController
    {
        $reflection = new ReflectionClass(UsersController::class);
        $module = $reflection->newInstanceWithoutConstructor();
        $notificationService = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($db),
            new NotificationPreferenceRepository($db),
            new UserRepository($db),
            (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
        );

        $dbProperty = $reflection->getParentClass()->getProperty('db');
        $dbProperty->setValue($module, $db);

        $contextProperty = $reflection->getParentClass()->getProperty('context');
        $contextProperty->setValue(
            $module,
            new RequestContext(
                new User(0, '', AccessService::ROLE_USER),
                new \DateTimeZone('UTC'),
                QueryParams::fromGlobals()
            )
        );

        $userServiceProperty = $reflection->getProperty('userService');
        $userServiceProperty->setValue(
            $module,
            new UserService(
                new UserRepository($db),
                $db,
                (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
                new TranslationManager('ru', 'en'),
                $notificationService,
                // The profile page's online dot reads through UserService
                // now, over this same stub $db - which is what the
                // online-status tests below steer, via what its fetchAll()
                // returns for the presence query.
                new UserSessionRepository($db),
                new Config([]),
            )
        );

        $tmProperty = $reflection->getProperty('tm');
        $tmProperty->setValue($module, new TranslationManager('ru', 'en'));

        $formatterProperty = $reflection->getProperty('formatter');
        $formatterProperty->setValue($module, new Formatter(new TranslationManager('ru', 'en'), 'ru'));

        $reflection->getProperty('notificationService')->setValue($module, $notificationService);
        $reflection->getProperty('memberships')->setValue($module, new MembershipRepository($db));

        // feedService is NOT defaulted here (unlike userService/tm/formatter
        // above): it's a readonly property, and setFeedService() below is
        // already called a second time by every blog-post-page test to
        // install its own mock - a second reflection setValue() on an
        // already-initialized readonly property throws. Tests that reach
        // showUserPage()'s new stats/blog-feed code call setFeedService()
        // themselves (see makeEmptyFeedServiceStub()).
        return $module;
    }

    private function makeEmptyFeedServiceStub(): FeedService
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedsByOwnerAndTypePage')->willReturn(['items' => [], 'total' => 0]);
        $feedService->method('countFeedsByOwnerAndType')->willReturn(0);
        $feedService->method('getRatingTotalsByOwnerAndType')->willReturn(['sum' => 0, 'count' => 0]);

        return $feedService;
    }

    private function setUrlGenerator(UsersController $module, Page $page): void
    {
        $reflection = new ReflectionClass(UsersController::class);
        $property = $reflection->getProperty('urlGenerator');
        $property->setValue(
            $module,
            new UrlGenerator(
                new PageTree([$page]),
                new FakeFeedRepository([]),
                new ArrayCache()
            )
        );
    }

    private function setContext(UsersController $module, User $user): void
    {
        $reflection = new ReflectionClass(UsersController::class);
        $property = $reflection->getParentClass()->getProperty('context');
        $property->setValue(
            $module,
            new RequestContext($user, new \DateTimeZone('UTC'), QueryParams::fromGlobals())
        );
    }

    private function setBlogPostService(UsersController $module, BlogPostService $blogPostService): void
    {
        $reflection = new ReflectionClass(UsersController::class);
        $property = $reflection->getProperty('blogPostService');
        $property->setValue($module, $blogPostService);
    }

    private function setUploadService(UsersController $module, UploadService $uploadService): void
    {
        $reflection = new ReflectionClass(UsersController::class);
        $property = $reflection->getProperty('uploadService');
        $property->setValue($module, $uploadService);
    }

    private function makeUploadServiceStub(int $used = 128 * 1024 * 1024): UploadService
    {
        $limit = 500 * 1024 * 1024;
        $uploadService = $this->createStub(UploadService::class);
        $uploadService->method('getUserStorageUsage')->willReturn([
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
        ]);

        return $uploadService;
    }

    private function setFeedService(UsersController $module, FeedService $feedService): void
    {
        $reflection = new ReflectionClass(UsersController::class);
        $property = $reflection->getProperty('feedService');
        $property->setValue($module, $feedService);
    }

    private function setFriendService(UsersController $module, FriendService $friendService): void
    {
        $reflection = new ReflectionClass(UsersController::class);
        $property = $reflection->getProperty('friendService');
        $property->setValue($module, $friendService);
    }

    private function setCommunityService(UsersController $module, CommunityService $communityService): void
    {
        $reflection = new ReflectionClass(UsersController::class);
        $property = $reflection->getProperty('communityService');
        $property->setValue($module, $communityService);
    }

    /**
     * FriendService is `final` and wraps other `final` collaborators
     * (FeedRepository, MembershipRepository, MessageService) that
     * can't be createStub()'d either - built the same "real instances
     * around a stub PdoDatabase" way as ControllerFactoryTest/
     * UserServiceTest already build final services elsewhere in this
     * suite. A stub PdoDatabase's unconfigured fetchOne()/fetchAll() return
     * null/[] by default, which resolves here to "no friends, no
     * relationship" - a safe empty default for tests that don't care about
     * the friends feature specifically.
     */
    private function makeEmptyFriendServiceStub(): FriendService
    {
        $db = $this->createStub(PdoDatabase::class);
        $pageTree = new PageTree([]);

        return new FriendService(
            $this->createStub(BlogPostService::class),
            new FeedRepository($db),
            new MembershipRepository($db),
            new NotificationService(
                (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
                new NotificationDeliveryRepository($db),
                new NotificationPreferenceRepository($db),
                new UserRepository($db),
                (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
            ),
            new TranslationManager('ru', 'en'),
            $pageTree,
            new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache()),
        );
    }

    private function setPageTree(UsersController $module, PageTree $pageTree): void
    {
        $reflection = new ReflectionClass(UsersController::class);
        $property = $reflection->getProperty('pageTree');
        $property->setValue($module, $pageTree);
    }

    private function setUrlGeneratorPages(UsersController $module, array $pages): void
    {
        $reflection = new ReflectionClass(UsersController::class);
        $property = $reflection->getProperty('urlGenerator');
        $property->setValue(
            $module,
            new UrlGenerator(
                new PageTree($pages),
                new FakeFeedRepository([]),
                new ArrayCache()
            )
        );
    }

    private function makeBlogPostPage(): Page
    {
        return new Page(
            id: 27,
            parentId: 26,
            pattern: 'post',
            pageName: 'Новый пост',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_AUTHENTICATED,
            action: 'user.post-new',
        );
    }

    private function makeBlogPostShowPage(bool $commentsEnabled = false): Page
    {
        return new Page(
            id: 60,
            parentId: 58,
            pattern: '{slug}',
            pageName: 'Страница записи в блоге',
            settings: null,
            feedType: 'blog-post',
            listFeedType: null,
            feedId: null,
            commentsEnabled: $commentsEnabled,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'user.post-show',
        );
    }

    private function makeBlogPostEditPage(): Page
    {
        return new Page(
            id: 61,
            parentId: 60,
            pattern: 'edit',
            pageName: 'Редактирование записи',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_AUTHENTICATED,
            action: 'user.post-edit',
        );
    }

    private function makeUsersListPage(): Page
    {
        return new Page(
            id: 25,
            parentId: null,
            pattern: 'users',
            pageName: 'Пользователи',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'users.list',
        );
    }

    private function makeUserShowPage(): Page
    {
        return new Page(
            id: 26,
            parentId: 25,
            pattern: '{username}',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'user.show',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makeUserRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'email' => 'user@example.com',
            'role' => 'user',
            'nick' => 'Nicky',
            'username' => 'nicky42',
            'bio' => 'Hello there',
            'signature' => '',
            'homepage' => '',
            'gender' => '',
            'birth_date' => null,
            'avatar_url' => '',
        ], $overrides);
    }

    public function testShowUserPageBuildsViewModelForFoundProfile(): void
    {
        $page = $this->makeUserShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow());
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setUrlGenerator($module, $page);
        $this->setFeedService($module, $this->makeEmptyFeedServiceStub());
        $this->setFriendService($module, $this->makeEmptyFriendServiceStub());

        $view = $module->show($page, ['username' => 'nicky42']);

        $this->assertSame('modules/users/show.twig', $view->template);
        $this->assertSame('Nicky', $view->data['title']);
        $this->assertSame('Hello there', $view->data['description']);
        $this->assertFalse($view->data['isOnline']);
        $this->assertFalse($view->data['isOwnProfile']);
        $this->assertSame(7, $view->data['profileUser']->id);
        $this->assertSame('nicky42', $view->data['profileUser']->username);
        $this->assertSame($view->data['profileUserAvatarUrl'], $view->data['image']);
    }

    public function testShowUserPageFormatsBirthDateForProfileSidebar(): void
    {
        $page = $this->makeUserShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow([
            'birth_date' => '1985-11-04',
            'show_birth_date_publicly' => 1,
        ]));
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setUrlGenerator($module, $page);
        $this->setFeedService($module, $this->makeEmptyFeedServiceStub());
        $this->setFriendService($module, $this->makeEmptyFriendServiceStub());

        $view = $module->show($page, ['username' => 'nicky42']);

        $this->assertSame('4 ноября 1985 года', $view->data['profileBirthDateLabel']);
    }

    public function testShowUserPageLoadsPostsFromPersonalBlogParent(): void
    {
        $page = $this->makeUserShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow());
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setUrlGenerator($module, $page);
        $this->setFriendService($module, $this->makeEmptyFriendServiceStub());

        $blogFeed = new Feed(
            id: 900,
            parentId: null,
            ownerId: 7,
            type: 'blog',
            slug: 'nicky42',
            title: 'Nicky',
            description: null,
            imageUrl: null,
            content: '',
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: '/users/nicky42/',
        );
        $post = $this->makeBlogPostFeed();
        $calls = [];

        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedByOwnerAndType')->with(7, 'blog', $this->anything())->willReturn($blogFeed);
        $feedService
            ->method('getFeedsByParentAndTypePage')
            ->willReturnCallback(function (int $parentId, string $type, User $viewer, int $limit, int $offset = 0) use (&$calls, $post): array {
                $calls[] = [$parentId, $type, $limit, $offset];

                return $limit === 4 ? ['items' => [$post], 'total' => 1] : ['items' => [], 'total' => 1];
            });
        $feedService->method('countCommentsForFeeds')->willReturn([902 => 0]);
        $feedService->method('countFeedsByParentAndType')->with(900, 'blog-post', $this->anything())->willReturn(1);
        $feedService->method('getRatingTotalsByParentAndType')->with(900, 'blog-post', $this->anything())->willReturn(['sum' => 0, 'count' => 0]);
        $feedService->method('countFeedsByOwnerAndType')->willReturn(0);
        $this->setFeedService($module, $feedService);

        $view = $module->show($page, ['username' => 'nicky42']);

        $this->assertSame('My Post Title', $view->data['posts'][0]['title']);
        $this->assertContains([900, 'blog-post', 4, 0], $calls);
        $this->assertContains([900, 'blog-post', 100, 0], $calls);
        $this->assertSame(1, $view->data['postCount']);
    }

    public function testShowUserPageHidesPersonalFieldsByDefault(): void
    {
        $page = $this->makeUserShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow([
            'homepage' => 'https://example.com',
            'gender' => 'female',
            'birth_date' => '1990-02-03',
        ]));
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setUrlGenerator($module, $page);
        $this->setFeedService($module, $this->makeEmptyFeedServiceStub());
        $this->setFriendService($module, $this->makeEmptyFriendServiceStub());

        $view = $module->show($page, ['username' => 'nicky42']);

        $this->assertFalse($view->data['canViewProfileGender']);
        $this->assertFalse($view->data['canViewProfileBirthDate']);
        $this->assertFalse($view->data['canViewProfileHomepage']);
    }

    public function testShowUserPageShowsPersonalFieldsWhenPublicFlagsAreEnabled(): void
    {
        $page = $this->makeUserShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow([
            'homepage' => 'https://example.com',
            'gender' => 'female',
            'birth_date' => '1990-02-03',
            'show_gender_publicly' => 1,
            'show_birth_date_publicly' => 1,
            'show_homepage_publicly' => 1,
        ]));
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setUrlGenerator($module, $page);
        $this->setFeedService($module, $this->makeEmptyFeedServiceStub());
        $this->setFriendService($module, $this->makeEmptyFriendServiceStub());

        $view = $module->show($page, ['username' => 'nicky42']);

        $this->assertTrue($view->data['canViewProfileGender']);
        $this->assertTrue($view->data['canViewProfileBirthDate']);
        $this->assertTrue($view->data['canViewProfileHomepage']);
    }

    public function testShowUserPageLetsOwnerSeeHiddenPersonalFields(): void
    {
        $page = $this->makeUserShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow([
            'homepage' => 'https://example.com',
            'gender' => 'female',
            'birth_date' => '1990-02-03',
        ]));
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setUrlGenerator($module, $page);
        $this->setPageTree($module, new PageTree([$page, $this->makeBlogPostPage()]));
        $this->setFeedService($module, $this->makeEmptyFeedServiceStub());
        $this->setFriendService($module, $this->makeEmptyFriendServiceStub());
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42'));

        $view = $module->show($page, ['username' => 'nicky42']);

        $this->assertTrue($view->data['canViewProfileGender']);
        $this->assertTrue($view->data['canViewProfileBirthDate']);
        $this->assertTrue($view->data['canViewProfileHomepage']);
    }

    public function testProfilePrivacyFriendExceptionShowsHiddenPersonalFieldsToMutualFriends(): void
    {
        $module = $this->makeUsersModule($this->createStub(PdoDatabase::class));
        $method = new \ReflectionMethod(UsersController::class, 'buildProfilePrivacyViewData');

        $result = $method->invoke(
            $module,
            new User(
                id: 7,
                email: 'user@example.com',
                role: AccessService::ROLE_USER,
                showHiddenProfileToFriends: true
            ),
            false,
            'friends'
        );

        $this->assertTrue($result['canViewProfileGender']);
        $this->assertTrue($result['canViewProfileBirthDate']);
        $this->assertTrue($result['canViewProfileHomepage']);
    }

    public function testProfilePrivacyFriendExceptionDoesNotShowHiddenPersonalFieldsToNonFriends(): void
    {
        $module = $this->makeUsersModule($this->createStub(PdoDatabase::class));
        $method = new \ReflectionMethod(UsersController::class, 'buildProfilePrivacyViewData');

        $result = $method->invoke(
            $module,
            new User(
                id: 7,
                email: 'user@example.com',
                role: AccessService::ROLE_USER,
                showHiddenProfileToFriends: true
            ),
            false,
            'subscribed'
        );

        $this->assertFalse($result['canViewProfileGender']);
        $this->assertFalse($result['canViewProfileBirthDate']);
        $this->assertFalse($result['canViewProfileHomepage']);
    }

    public function testShowUserPageReportsOnlineStatus(): void
    {
        $page = $this->makeUserShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow());
        $db->method('fetchAll')->willReturn([['user_id' => 7]]);

        $module = $this->makeUsersModule($db);
        $this->setUrlGenerator($module, $page);
        $this->setFeedService($module, $this->makeEmptyFeedServiceStub());
        $this->setFriendService($module, $this->makeEmptyFriendServiceStub());

        $view = $module->show($page, ['username' => 'nicky42']);

        $this->assertTrue($view->data['isOnline']);
    }

    public function testShowUserPageThrowsNotFoundForUnknownUsername(): void
    {
        $page = $this->makeUserShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(null);
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setUrlGenerator($module, $page);

        $this->expectException(NotFoundException::class);

        $module->show($page, ['username' => 'missing']);
    }

    public function testRegisterApiUsesUsersCollectionForGetAndPost(): void
    {
        $apiPage = new Page(
            id: 1,
            parentId: null,
            pattern: 'api',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'api.index',
        );
        $pageTree = new PageTree([$apiPage]);

        UsersController::registerApi($apiPage->id, $pageTree);

        $usersPage = $pageTree->findChildren($apiPage->id)[0];

        $this->assertSame('users', $usersPage->pattern);
        $this->assertSame(['GET', 'POST'], $usersPage->requestMethods);
        $this->assertSame('users.collection', $usersPage->action);
    }

    /**
     * Checks that head_ext loads $jsFile as a module script, without
     * asserting the exact literal tag markup (attribute order/quoting) -
     * a byte-for-byte string match already broke once when type="module"
     * was added to fix a real bug (users.js/site.js's classic-script
     * global-scope collision, "Identifier ... has already been declared")
     * without every hardcoded assertion of the old tag getting updated in
     * lockstep. This checks the two things that actually matter: the
     * right file is loaded, and it's loaded as a module.
     */
    private function assertHeadExtLoadsModuleScript(array $headExt, string $jsFile): void
    {
        $matches = array_filter(
            $headExt,
            static fn (string $tag): bool => str_contains($tag, $jsFile) && str_contains($tag, 'type="module"')
        );

        $this->assertNotEmpty($matches, sprintf('Expected head_ext to load "%s" as a module script', $jsFile));
    }

    public function testShowUsersListBuildsViewModel(): void
    {
        $_GET = [
            'q' => 'Але',
            'sort' => 'name',
            'direction' => 'asc',
            'page' => '2',
        ];
        $page = $this->makeUsersListPage();
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->willReturn(['total' => 23]);
        $db->expects($this->never())->method('fetchAll');

        $module = $this->makeUsersModule($db);
        $this->setUrlGenerator($module, $page);

        $view = $module->show($page);

        $this->assertSame('modules/users/list.twig', $view->template);
        $this->assertSame('Пользователи', $view->data['title']);
        $this->assertSame('/users/?q=%D0%90%D0%BB%D0%B5&sort=name&direction=asc&page=2', $view->data['canonical']);
        $this->assertSame('/users/', $view->data['listUrl']);
        $this->assertSame('/api/v1/users', $view->data['apiUrl']);
        $this->assertHeadExtLoadsModuleScript($view->data['head_ext'], '/assets/js/users.js');
        $this->assertSame(['q' => 'Але', 'sort' => 'name', 'direction' => 'asc'], $view->data['filters']);

        $_GET = [];
    }

    public function testUsersFirstPageCanonicalOmitsDefaultsAndTrackingParameters(): void
    {
        $previousQuery = $_GET;
        $_GET = ['page' => '1', 'q' => '  ', 'sort' => 'registered', 'direction' => 'desc', 'utm_source' => 'test'];
        try {
            $db = $this->createMock(PdoDatabase::class);
            $db->expects($this->never())->method('fetchOne');
            $module = $this->makeUsersModule($db);
            $page = $this->makeUsersListPage();
            $this->setUrlGenerator($module, $page);
            $this->assertSame('/users/', $module->show($page)->data['canonical']);
        } finally {
            $_GET = $previousQuery;
        }
    }

    public function testUsersPageBeyondTheLastPageIsNotFound(): void
    {
        $previousQuery = $_GET;
        $_GET = ['page' => '3'];
        try {
            $db = $this->createMock(PdoDatabase::class);
            $db->expects($this->once())->method('fetchOne')->willReturn(['total' => 23]);
            $module = $this->makeUsersModule($db);
            $page = $this->makeUsersListPage();
            $this->setUrlGenerator($module, $page);
            $this->expectException(NotFoundException::class);
            $module->show($page);
        } finally {
            $_GET = $previousQuery;
        }
    }

    public function testCallApiReturnsUsersList(): void
    {
        $_GET = [
            'q' => 'Але',
            'sort' => 'name',
            'direction' => 'asc',
            'page' => '2',
        ];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('COUNT(*) AS total'), ['Але%'])
            ->willReturn(['total' => 23]);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->logicalAnd(
                $this->stringContains('FROM users u'),
                $this->stringContains('AND u.nick LIKE ?'),
                $this->stringContains('ORDER BY u.nick ASC, u.id ASC'),
                $this->stringContains('LIMIT 20 OFFSET 20')
            ), ['Але%'])
            ->willReturn([
                [
                    'id' => 7,
                    'nick' => 'Алексей',
                    'username' => 'alexey7',
                    'avatar_url' => '',
                    'created_at' => 1778167990,
                ],
            ]);

        $page = new Page(
            id: 100,
            parentId: 99,
            pattern: 'users',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET', 'POST'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'users.collection',
        );

        // A minimal 'user.show' fixture so buildPublicUserCards() (called
        // from getUsersListPayload()) can resolve each row's profile url.
        $userShowPage = new Page(
            id: 58,
            parentId: null,
            pattern: '{username}',
            pageName: 'Страница пользователя',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'user.show',
        );

        $module = $this->makeUsersModule($db);
        $this->setPageTree($module, new PageTree([$userShowPage, $page]));
        $this->setUrlGeneratorPages($module, [$userShowPage, $page]);

        ob_start();
        $module->callApi($page);
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertSame(
            [
                [
                    'id' => 7,
                    'displayName' => 'Алексей',
                    'username' => 'alexey7',
                    'avatarUrl' => '',
                    'createdAt' => 1778167990,
                    'url' => '/alexey7/',
                ],
            ],
            $payload['data']
        );
        $this->assertSame(['q' => 'Але', 'sort' => 'name', 'direction' => 'asc'], $payload['filters']);
        $this->assertSame(['currentPage' => 2, 'totalPages' => 2, 'total' => 23, 'limit' => 20], $payload['pagination']);

        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testShowBlogPostFormPageRendersForOwnUsername(): void
    {
        $page = $this->makeBlogPostPage();
        $userShowPage = $this->makeUserShowPage();
        $db = $this->createStub(PdoDatabase::class);

        $module = $this->makeUsersModule($db);
        $this->setUrlGeneratorPages($module, [$page, $userShowPage]);
        // showBlogPostFormPage() now looks up 'user.show' via pageTree to
        // build the "Отмена" link's cancelUrl.
        $this->setPageTree($module, new PageTree([$page, $userShowPage]));
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42'));
        $this->setUploadService($module, $this->makeUploadServiceStub());

        $view = $module->show($page, ['username' => 'nicky42']);

        $this->assertSame('modules/users/blog-post-form.twig', $view->template);
        $this->assertSame('/api/v1/users/blog-posts', $view->data['apiUrl']);
        $this->assertSame('/api/v1/uploads', $view->data['uploadsApiUrl']);
        $this->assertSame('128.0 МБ', $view->data['uploadStorageUsedLabel']);
        $this->assertSame('500.0 МБ', $view->data['uploadStorageLimitLabel']);
        $this->assertSame('372.0 МБ', $view->data['uploadStorageRemainingLabel']);
        $this->assertHeadExtLoadsModuleScript($view->data['head_ext'], '/assets/js/blog-post-form.js');
        // "Отмена" link - back to the user's own profile/blog.
        $this->assertSame('/nicky42/', $view->data['cancelUrl']);
    }

    public function testShowBlogPostFormPageIsCaseInsensitiveAboutOwnUsername(): void
    {
        $page = $this->makeBlogPostPage();
        $db = $this->createStub(PdoDatabase::class);

        $module = $this->makeUsersModule($db);
        $this->setUrlGenerator($module, $page);
        $this->setPageTree($module, new PageTree([$page]));
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'Nicky42'));
        $this->setUploadService($module, $this->makeUploadServiceStub());

        $view = $module->show($page, ['username' => 'nicky42']);

        $this->assertSame('modules/users/blog-post-form.twig', $view->template);
    }

    public function testShowBlogPostFormPageThrowsForbiddenForAnotherUsersUrl(): void
    {
        $page = $this->makeBlogPostPage();
        $db = $this->createStub(PdoDatabase::class);

        $module = $this->makeUsersModule($db);
        $this->setUrlGenerator($module, $page);
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42'));

        $this->expectException(ForbiddenException::class);

        $module->show($page, ['username' => 'someone-else']);
    }

    private function makeBlogPostFeed(
        string $slug = 'my-post-title',
        string $type = 'blog-post',
        ?string $content = '<p>Body</p>',
    ): Feed {
        return new Feed(
            id: 902,
            parentId: 900,
            ownerId: 7,
            type: $type,
            slug: $slug,
            title: 'My Post Title',
            description: null,
            imageUrl: null,
            content: $content,
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: '/blog/my-post-title/',
        );
    }

    public function testShowBlogPostPageRendersFoundPost(): void
    {
        $page = $this->makeBlogPostShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);

        $post = $this->makeBlogPostFeed();

        $feedService = $this->createMock(FeedService::class);
        $feedService
            ->expects($this->once())
            ->method('getFeedBySlug')
            ->with('my-post-title', $this->anything())
            ->willReturn([$post]);
        $feedService->expects($this->never())->method('getComments');
        $feedService
            ->expects($this->once())
            ->method('getUserRatingValue')
            ->with(902, $this->anything())
            ->willReturn(4);
        $this->setFeedService($module, $feedService);

        $view = $module->show($page, ['slug' => 'my-post-title']);

        $this->assertSame('modules/users/blog-post.show.twig', $view->template);
        $this->assertSame('My Post Title', $view->data['title']);
        $this->assertSame('/blog/my-post-title/', $view->data['canonical']);
        $this->assertSame(902, $view->data['feed']->id);
        $this->assertNull($view->data['comments']);
        $this->assertFalse($view->data['shareButtons']);
        $this->assertSame(4, $view->data['userRating']);
        // No fetchOne configured on the db stub -> author lookup finds
        // nobody. The post itself still renders; only the sidebar degrades.
        $this->assertNull($view->data['profileUser']);
        $this->assertFalse($view->data['isOwnProfile']);
        // No author -> no link target for the byline either.
        $this->assertNull($view->data['authorUrl']);
        // No author -> friend button data is never computed.
        $this->assertNull($view->data['friendActionUrl']);
        $this->assertNull($view->data['relationshipStatus']);
        // users.js now loads unconditionally (not just for isOwnProfile) -
        // initFriendButton() lives in that bundle and is what the "Добавить
        // в друзья" button needs client-side.
        $this->assertHeadExtLoadsModuleScript($view->data['head_ext'], '/assets/js/users.js');
        // Unconfigured FeedService::canEditFeed() on this mock defaults to
        // false (its return type) - matches reality anyway, since there's
        // no author here for anyone to be the owner of.
        $this->assertFalse($view->data['canDelete']);
    }

    public function testShowBlogPostPageMarksOwnProfileForAuthorViewingOwnPost(): void
    {
        $page = $this->makeBlogPostShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow());
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42'));

        $post = $this->makeBlogPostFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$post]);
        $feedService->method('getFeedsByOwnerAndTypePage')->willReturn(['items' => [], 'total' => 0]);
        // Real AccessService::canEditFeed() would grant this to the post's
        // author (see AccessServiceTest) - stubbed true here since
        // FeedService itself is stubbed away in this view-assembly test.
        // showBlogPostPage() gates editUrl on isOwnProfile (not this stub),
        // but canDelete is this value directly.
        $feedService->method('canEditFeed')->willReturn(true);
        $this->setFeedService($module, $feedService);

        $view = $module->show($page, ['slug' => 'my-post-title']);

        $this->assertSame(7, $view->data['profileUser']->id);
        $this->assertTrue($view->data['isOwnProfile']);
        // Taken as-is from $post->canonicalUrl (set on the fixture by
        // makeBlogPostFeed()) - showBlogPostPage() no longer rebuilds this.
        $this->assertSame('/blog/my-post-title/', $view->data['canonical']);
        // buildPostEditUrl(): $post->canonicalUrl with a literal '/edit/'
        // suffix - no pageTree/urlGenerator lookup needed any more.
        $this->assertSame('/blog/my-post-title/edit/', $view->data['editUrl']);
        // Own profile -> guarded, never calls friendService.
        $this->assertNull($view->data['relationshipStatus']);
        $this->assertTrue($view->data['canDelete']);
        $this->assertSame([], $view->data['audioPlaylist']);
        // The author byline (blog_post_header block) links here.
        $this->assertSame('/users/nicky42/', $view->data['authorUrl']);
    }

    public function testShowBlogPostPageDoesNotMarkOwnProfileForOtherViewer(): void
    {
        $page = $this->makeBlogPostShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow());
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 99, email: 'other@example.com', role: AccessService::ROLE_USER, username: 'someone-else'));
        // Author resolves non-null here too - same reasoning as the
        // "own post" variant above.
        $this->setUrlGenerator($module, $page);
        // Different viewer than the resolved author -> isOwnProfile is
        // false, so showBlogPostPage() now calls
        // friendService->getRelationshipStatus() to feed the shared
        // profile-sidebar partial's "Добавить в друзья" button. Needs a
        // real (empty-stub) FriendService wired up or the readonly property
        // is never initialized and this fatals.
        $this->setFriendService($module, $this->makeEmptyFriendServiceStub());

        $post = $this->makeBlogPostFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$post]);
        $feedService->method('getFeedsByOwnerAndTypePage')->willReturn(['items' => [], 'total' => 0]);
        // A plain other-viewer with no moderator/owner standing - real
        // AccessService::canEditFeed() would deny this too.
        $feedService->method('canEditFeed')->willReturn(false);
        $this->setFeedService($module, $feedService);

        $view = $module->show($page, ['slug' => 'my-post-title']);

        $this->assertSame(7, $view->data['profileUser']->id);
        $this->assertFalse($view->data['isOwnProfile']);
        // Not the owner - no editUrl, and no pageTree lookup attempted for
        // one (pageTree is never even wired up in this test).
        $this->assertNull($view->data['editUrl']);
        // Friend button data is now computed for this "another viewer sees
        // someone else's post" case - the empty-stub FriendService resolves
        // to 'none' (no membership rows configured on its stub db).
        $this->assertSame('none', $view->data['relationshipStatus']);
        $this->assertSame('/api/v1/users/nicky42/friend', $view->data['friendActionUrl']);
        $this->assertHeadExtLoadsModuleScript($view->data['head_ext'], '/assets/js/users.js');
        $this->assertFalse($view->data['canDelete']);
        $this->assertSame([], $view->data['audioPlaylist']);
        $this->assertSame('/users/nicky42/', $view->data['authorUrl']);
    }

    private function makeCommunityPostShowPage(): Page
    {
        return new Page(
            id: 65,
            parentId: 64,
            pattern: '{slug}',
            pageName: 'Пост в сообществе',
            settings: null,
            feedType: 'blog-post',
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'community.post-show',
        );
    }

    private function makeCommunityPostFeed(int $ownerId = 1): Feed
    {
        return new Feed(
            id: 902,
            parentId: 42,
            ownerId: $ownerId,
            type: 'blog-post',
            slug: 'my-post-title',
            title: 'My Post Title',
            description: null,
            imageUrl: null,
            content: '<p>Body</p>',
            containerId: 42,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: '/communities/devs/my-post-title/',
            containerType: 'community',
        );
    }

    /**
     * The whole point of this session's fix: a community moderator who
     * didn't write the post still gets a working delete button - canDelete
     * mirrors FeedService::canEditFeed() (in turn AccessService::
     * canEditFeed()'s isContainerModerator() branch), not just
     * isOwnProfile.
     */
    public function testShowCommunityPostPageAllowsModeratorToDeleteEvenIfNotAuthor(): void
    {
        $page = $this->makeCommunityPostShowPage();
        $communityShowPage = $this->makeCommunityShowPage();

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow());
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        // Moderator viewing someone else's community post - not the author
        // (post owner_id 1), so isOwnProfile is false but canDelete should
        // still be true.
        $this->setContext($module, new User(id: 42, email: 'mod@example.com', role: AccessService::ROLE_USER, username: 'moduser'));
        $this->setUrlGeneratorPages($module, [$page, $communityShowPage]);
        $this->setPageTree($module, new PageTree([$page, $communityShowPage]));
        $this->setFriendService($module, $this->makeEmptyFriendServiceStub());

        // showCommunityPostPage() now resolves the community sidebar the
        // same way showCommunityShowPage() does (see components/users/
        // community-sidebar.twig) - needs a real communityService, or the
        // readonly property is never initialized and this fatals, same
        // reasoning as friendService in the delete-permission tests above.
        $communityService = $this->createStub(CommunityService::class);
        $communityService->method('getRelationshipStatus')->willReturn('moderator');
        $communityService->method('getMembers')->willReturn(['items' => [], 'total' => 0]);
        $this->setCommunityService($module, $communityService);

        $post = $this->makeCommunityPostFeed();
        $community = new Feed(
            id: 42,
            parentId: null,
            ownerId: 1,
            type: 'community',
            slug: 'devs',
            title: 'Devs',
            description: null,
            imageUrl: null,
            content: null,
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: null,
        );

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$post]);
        $feedService->method('getFeedById')->willReturn($community);
        $feedService->method('canEditFeed')->willReturn(true);
        // The sidebar's "Рейтинг" card is an aggregate across this
        // community's own posts, not a vote on the community feed itself -
        // see UsersController::buildCommunityRatingViewData().
        $feedService->method('getRatingTotalsByParentAndType')->willReturn(['sum' => 9, 'count' => 2]);
        // Sidebar's "Статистика" card (community_stats block) - see
        // UsersController::buildCommunityStatsViewData().
        $feedService->method('countFeedsByParentAndType')->willReturn(4);
        $feedService->method('countCommentsByParentContainer')->willReturn(11);
        $this->setFeedService($module, $feedService);

        $view = $module->show($page, ['slug' => 'my-post-title']);

        $this->assertSame('modules/users/blog-post.show.twig', $view->template);
        $this->assertFalse($view->data['isOwnProfile']);
        $this->assertTrue($view->data['canDelete']);
        // Redirects to the community's own page, not the author's profile
        // - whoever deleted it (moderator here) came from the community.
        $this->assertSame('/devs/', $view->data['deleteRedirectUrl']);
        // The sidebar now shows this community (blog-post.show.twig's
        // sidebar_widgets branches on isCommunityPost to include
        // components/users/community-sidebar.twig instead of the author's
        // profile-sidebar.twig - see UsersController::showCommunityPostPage()).
        $this->assertTrue($view->data['isCommunityPost']);
        $this->assertSame(42, $view->data['community']->id);
        $this->assertSame('moderator', $view->data['communityRelationshipStatus']);
        $this->assertSame('/api/v1/communities/42/membership', $view->data['communityMembershipActionUrl']);
        $this->assertFalse($view->data['hasCommunityMembers']);
        $this->assertSame(0, $view->data['communityMembersTotal']);
        // The author byline still links to the post's author regardless of
        // which sidebar renders alongside it.
        $this->assertSame('/users/nicky42/', $view->data['authorUrl']);
        $this->assertSame(2, $view->data['communityRatingCount']);
        $this->assertSame(4.5, $view->data['communityRatingAverage']);
        $this->assertSame(4, $view->data['communityPostCount']);
        $this->assertSame(11, $view->data['communityCommentCount']);
        $this->assertNotNull($view->data['communityCreatedAtLabel']);
    }

    /**
     * A regular viewer with no moderator/owner standing (canEditFeed()
     * returns false) sees no delete button, same as before this session's
     * fix - the delete button was never meant to be universally visible,
     * just correctly gated.
     */
    public function testShowCommunityPostPageHidesDeleteForPlainViewer(): void
    {
        $page = $this->makeCommunityPostShowPage();

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow());
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 43, email: 'viewer@example.com', role: AccessService::ROLE_USER, username: 'viewer'));
        $this->setUrlGenerator($module, $page);
        $this->setFriendService($module, $this->makeEmptyFriendServiceStub());

        $post = $this->makeCommunityPostFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$post]);
        // Community lookup for the redirect URL fails/is inaccessible here
        // - showCommunityPostPage() should degrade to '/' rather than
        // fail the whole page render.
        $feedService->method('getFeedById')->willThrowException(new NotFoundException('Сообщество не найдено'));
        $feedService->method('canEditFeed')->willReturn(false);
        $this->setFeedService($module, $feedService);

        $view = $module->show($page, ['slug' => 'my-post-title']);

        $this->assertFalse($view->data['canDelete']);
        $this->assertSame('/', $view->data['deleteRedirectUrl']);
        // Community lookup failed above - the sidebar degrades gracefully
        // (community.twig's include is `only`-scoped, so a null community
        // just renders its empty-state markup) rather than fataling.
        $this->assertTrue($view->data['isCommunityPost']);
        $this->assertNull($view->data['community']);
        $this->assertNull($view->data['communityRelationshipStatus']);
        $this->assertFalse($view->data['hasCommunityMembers']);
        $this->assertSame('/users/nicky42/', $view->data['authorUrl']);
        // Same degrade-to-empty default for the rating card.
        $this->assertSame(0, $view->data['communityRatingCount']);
        $this->assertNull($view->data['communityRatingAverage']);
        // Same degrade-to-empty default for the stats card.
        $this->assertSame(0, $view->data['communityPostCount']);
        $this->assertSame(0, $view->data['communityCommentCount']);
        $this->assertNull($view->data['communityCreatedAtLabel']);
    }

    public function testGetBreadcrumbForPostShowUsesPostTitleAndSlug(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);

        $post = $this->makeBlogPostFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$post]);
        $this->setFeedService($module, $feedService);

        $page = $this->makeBlogPostShowPage();
        $page->params = ['slug' => 'my-post-title'];

        $breadcrumb = $module->getBreadcrumb($page);

        $this->assertSame('My Post Title', $breadcrumb->title);
        $this->assertSame('my-post-title', $breadcrumb->slug);
    }

    public function testShowBlogPostPageLoadsCommentsWhenEnabled(): void
    {
        $page = $this->makeBlogPostShowPage(commentsEnabled: true);
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);

        $post = $this->makeBlogPostFeed();

        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$post]);
        $feedService
            ->expects($this->once())
            ->method('getComments')
            ->with(902, null, $this->anything())
            ->willReturn(['items' => [], 'meta' => []]);
        $this->setFeedService($module, $feedService);

        $view = $module->show($page, ['slug' => 'my-post-title']);

        $this->assertSame(['items' => [], 'meta' => []], $view->data['comments']);
    }

    public function testShowBlogPostPageThrowsNotFoundWhenSlugMissing(): void
    {
        $page = $this->makeBlogPostShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);

        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->never())->method('getFeedBySlug');
        $this->setFeedService($module, $feedService);

        $this->expectException(NotFoundException::class);

        $module->show($page, []);
    }

    public function testShowBlogPostPageThrowsNotFoundWhenSlugUnmatched(): void
    {
        $page = $this->makeBlogPostShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([]);
        $this->setFeedService($module, $feedService);

        $this->expectException(NotFoundException::class);

        $module->show($page, ['slug' => 'missing']);
    }

    public function testShowBlogPostPageThrowsNotFoundForNonBlogPostFeedType(): void
    {
        $page = $this->makeBlogPostShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);

        // Slugs aren't unique across feed types - guard against matching e.g.
        // an article that happens to share this slug.
        $article = $this->makeBlogPostFeed(type: 'article');

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$article]);
        $this->setFeedService($module, $feedService);

        $this->expectException(NotFoundException::class);

        $module->show($page, ['slug' => 'my-post-title']);
    }

    public function testShowBlogPostEditPageRendersForOwner(): void
    {
        // canonical/cancelUrl now come straight off $post->canonicalUrl
        // (see UsersController::buildPostEditViewModel()'s own docblock on
        // why - UrlGenerator::page() can't be trusted here in general,
        // since a community post's edit page has two {slug} ancestors) -
        // no user.show/urlGenerator page-tree fixture needed any more,
        // just the edit/show pair resolvePostForEdit() itself walks.
        $editPage = $this->makeBlogPostEditPage();
        $showPage = $this->makeBlogPostShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42'));
        $this->setPageTree($module, new PageTree([$editPage, $showPage]));
        $this->setUploadService($module, $this->makeUploadServiceStub());

        $post = $this->makeBlogPostFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$post]);
        $this->setFeedService($module, $feedService);

        $blogPostService = $this->createStub(BlogPostService::class);
        $blogPostService->method('getBlogPostTags')->willReturn(['travel']);
        $this->setBlogPostService($module, $blogPostService);

        $view = $module->show($editPage, ['slug' => 'my-post-title']);

        $this->assertSame('modules/users/blog-post-form.twig', $view->template);
        $this->assertSame('/api/v1/users/blog-posts/902', $view->data['apiUrl']);
        $this->assertSame('/api/v1/uploads', $view->data['uploadsApiUrl']);
        $this->assertSame(902, $view->data['post']->id);
        $this->assertSame(['travel'], $view->data['initialTags']);
        // makeBlogPostFeed()'s fixture canonicalUrl, with the edit page's
        // own '/edit/' suffix appended.
        $this->assertSame('/blog/my-post-title/edit/', $view->data['canonical']);
        // "Отмена" link on the edit form - back to the post itself.
        $this->assertSame('/blog/my-post-title/', $view->data['cancelUrl']);
        $this->assertFalse($view->data['isCommunityPost']);
    }

    public function testShowBlogPostEditPageThrowsForbiddenForNonOwner(): void
    {
        $editPage = $this->makeBlogPostEditPage();
        $showPage = $this->makeBlogPostShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 99, email: 'other@example.com', role: AccessService::ROLE_USER, username: 'someone-else'));
        $this->setPageTree($module, new PageTree([$editPage, $showPage]));

        $post = $this->makeBlogPostFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$post]);
        $this->setFeedService($module, $feedService);

        $this->expectException(ForbiddenException::class);

        $module->show($editPage, ['slug' => 'my-post-title']);
    }

    private function makeCommunityPostEditPage(): Page
    {
        return new Page(
            id: 67,
            // Same id makeCommunityPostShowPage() (above, near the other
            // showCommunityPostPage() tests) already uses - resolvePostForEdit()
            // reads this page's own {slug} lookup off that parent.
            parentId: 65,
            pattern: 'edit',
            pageName: 'Редактирование записи в сообществе',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_AUTHENTICATED,
            action: 'community.post-edit',
        );
    }

    /**
     * The whole point of community.post-edit vs user.post-edit: this
     * viewer isn't the post's author, but a real AccessService::canEditFeed()
     * would still grant it via container-moderator status (stubbed true
     * here, same as showCommunityPostPage()'s own $canDelete tests) -
     * showBlogPostEditPage()'s author-only rule would reject this exact
     * viewer/post pairing.
     */
    public function testShowCommunityPostEditPageRendersForModerator(): void
    {
        $editPage = $this->makeCommunityPostEditPage();
        $showPage = $this->makeCommunityPostShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 42, email: 'mod@example.com', role: AccessService::ROLE_USER, username: 'moderator'));
        $this->setPageTree($module, new PageTree([$editPage, $showPage]));
        $this->setUploadService($module, $this->makeUploadServiceStub());

        $post = $this->makeCommunityPostFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$post]);
        $feedService->method('canEditFeed')->willReturn(true);
        $this->setFeedService($module, $feedService);

        $blogPostService = $this->createStub(BlogPostService::class);
        $blogPostService->method('getBlogPostTags')->willReturn([]);
        $this->setBlogPostService($module, $blogPostService);

        $view = $module->show($editPage, ['slug' => 'my-post-title']);

        $this->assertSame('modules/users/blog-post-form.twig', $view->template);
        $this->assertSame('/api/v1/users/blog-posts/902', $view->data['apiUrl']);
        // makeCommunityPostFeed()'s fixture canonicalUrl, with the edit
        // page's own '/edit/' suffix appended - same buildPostEditViewModel()
        // logic as the personal-blog case, not a page/params rebuild (which
        // would break here: community.show and community.post-show both
        // use {slug}).
        $this->assertSame('/communities/devs/my-post-title/edit/', $view->data['canonical']);
        $this->assertSame('/communities/devs/my-post-title/', $view->data['cancelUrl']);
        $this->assertTrue($view->data['isCommunityPost']);
    }

    public function testShowCommunityPostEditPageThrowsForbiddenWhenCanEditFeedDenies(): void
    {
        $editPage = $this->makeCommunityPostEditPage();
        $showPage = $this->makeCommunityPostShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 99, email: 'other@example.com', role: AccessService::ROLE_USER, username: 'someone-else'));
        $this->setPageTree($module, new PageTree([$editPage, $showPage]));

        $post = $this->makeCommunityPostFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$post]);
        $feedService->method('canEditFeed')->willReturn(false);
        $this->setFeedService($module, $feedService);

        $this->expectException(ForbiddenException::class);

        $module->show($editPage, ['slug' => 'my-post-title']);
    }

    /**
     * A personal post reachable at this URL by slug coincidence
     * (getFeedBySlug() doesn't filter by parent) - same containerType
     * guard as showCommunityPostPage()'s own, checked before the
     * permission gate so a stray match 404s rather than leaking a
     * forbidden/allowed signal about a post that doesn't even belong here.
     */
    public function testShowCommunityPostEditPageThrowsNotFoundForNonCommunityPost(): void
    {
        $editPage = $this->makeCommunityPostEditPage();
        $showPage = $this->makeCommunityPostShowPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42'));
        $this->setPageTree($module, new PageTree([$editPage, $showPage]));

        $post = $this->makeBlogPostFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$post]);
        $this->setFeedService($module, $feedService);

        $this->expectException(NotFoundException::class);

        $module->show($editPage, ['slug' => 'my-post-title']);
    }

    public function testCallApiUpdatesBlogPostAndReturnsCanonicalUrl(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42');
        $this->setContext($module, $user);

        $updatedPost = new Feed(
            id: 902,
            parentId: 900,
            ownerId: 7,
            type: 'blog-post',
            slug: 'my-post-title',
            title: 'Updated Title',
            description: null,
            imageUrl: null,
            content: 'Updated body',
            containerId: null,
            visibility: 'private',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: '/blog/my-post-title/',
        );

        $blogPostService = $this->createMock(BlogPostService::class);
        $blogPostService
            ->expects($this->once())
            ->method('updateBlogPost')
            ->with(
                902,
                'Updated Title',
                'Updated body',
                null,
                null,
                $this->callback(static fn (User $u): bool => $u->id === 7),
                'private',
                null,
                null,
            )
            ->willReturn($updatedPost);
        $this->setBlogPostService($module, $blogPostService);

        $page = $this->makeBlogPostItemPage();

        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        PhpInputStreamMock::register(json_encode([
            'title' => 'Updated Title',
            'content' => 'Updated body',
            'visibility' => 'private',
        ]));

        try {
            ob_start();
            $module->callApi($page, ['id' => '902']);
            $output = ob_get_clean();
        } finally {
            PhpInputStreamMock::restore();
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }

        $decoded = json_decode($output, true);
        $this->assertSame(902, $decoded['id']);
        $this->assertSame('private', $decoded['visibility']);
        $this->assertSame('/blog/my-post-title/', $decoded['canonicalUrl']);
    }

    public function testCallApiUpdateBlogPostRequiresCsrf(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42'));

        $blogPostService = $this->createMock(BlogPostService::class);
        $blogPostService->expects($this->never())->method('updateBlogPost');
        $this->setBlogPostService($module, $blogPostService);

        $page = $this->makeBlogPostItemPage();

        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        PhpInputStreamMock::register(json_encode(['title' => 'T', 'content' => 'C']));

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($page, ['id' => '902']);
        } finally {
            PhpInputStreamMock::restore();
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    /* ------------------------------------------------------------------ */
    /* password.retrieve                                                    */
    /* ------------------------------------------------------------------ */

    private function makeRetrievePage(): Page
    {
        return Page::api(
            id: 33,
            parentId: 10,
            pattern: 'retrieve',
            requestMethods: ['POST', 'PUT'],
            action: 'password.retrieve',
        );
    }

    /**
     * The PUT half of this endpoint had no CSRF check while the POST half did,
     * and `composer audit:csrf` could not see it: the audit asks whether a
     * `verifyCsrf()` is reachable from the *handler*, and this handler has one
     * - in the other branch. Same blind spot MessagesController's per-branch
     * checks were written for.
     *
     * Low severity on its own, since a forged reset still needs a token that
     * only reaches the account's own mailbox. Pinned because the asymmetry is
     * the kind that gets copied into the next two-verb handler.
     */
    public function testCallApiPasswordResetRequiresCsrf(): void
    {
        $module = $this->makeUsersModule($this->createStub(PdoDatabase::class));

        $_SERVER['REQUEST_METHOD'] = 'PUT';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        PhpInputStreamMock::register(json_encode([
            'token' => 'reset-token',
            'password' => 'very-secure-password',
        ]));

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($this->makeRetrievePage(), []);
        } finally {
            PhpInputStreamMock::restore();
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    public function testCallApiPasswordResetRequestRequiresCsrf(): void
    {
        $module = $this->makeUsersModule($this->createStub(PdoDatabase::class));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        PhpInputStreamMock::register(json_encode(['email' => 'user@example.com']));

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($this->makeRetrievePage(), []);
        } finally {
            PhpInputStreamMock::restore();
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    /**
     * Both halves are guests-only - someone already signed in has a password
     * change form, and this endpoint mails a link to an address they type.
     */
    public function testCallApiPasswordRetrieveIsForGuestsOnly(): void
    {
        $module = $this->makeUsersModule($this->createStub(PdoDatabase::class));
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        try {
            $this->expectException(ForbiddenException::class);
            $module->callApi($this->makeRetrievePage(), []);
        } finally {
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }
    }

    /**
     * The reset branch used to carry its own `mb_strlen($password) < 6` check,
     * four characters under the site's own minimum and enforced nowhere else -
     * a side door around the password policy, open to anyone holding a reset
     * link. The rule now lives in UserService::validatePassword(), which
     * resetPasswordByToken() calls; this asserts the endpoint refuses.
     */
    public function testCallApiPasswordResetEnforcesTheSiteMinimum(): void
    {
        $module = $this->makeUsersModule($this->createStub(PdoDatabase::class));

        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        // Nine characters: past the old check, short of the real one.
        PhpInputStreamMock::register(json_encode([
            'token' => 'reset-token',
            'password' => '123456789',
        ]));

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($this->makeRetrievePage(), []);
        } finally {
            PhpInputStreamMock::restore();
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }
    }

    public function testRegisterApiAddsBlogPostsEndpointUnderUsers(): void
    {
        $apiPage = new Page(
            id: 1,
            parentId: null,
            pattern: 'api',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'api.index',
        );
        $pageTree = new PageTree([$apiPage]);

        UsersController::registerApi($apiPage->id, $pageTree);

        $usersPage = $pageTree->findChildren($apiPage->id)[0];
        $blogPostsPage = $pageTree->findChildren($usersPage->id)[0];

        $this->assertSame('blog-posts', $blogPostsPage->pattern);
        $this->assertSame(['POST'], $blogPostsPage->requestMethods);
        $this->assertSame('user.post-new-create', $blogPostsPage->action);

        $blogPostIdPage = $pageTree->findChildren($blogPostsPage->id)[0];
        $this->assertSame('{id}', $blogPostIdPage->pattern);
        $this->assertSame(['PATCH', 'DELETE'], $blogPostIdPage->requestMethods);
        $this->assertSame('user.post-item', $blogPostIdPage->action);
    }

    public function testRegisterApiAddsUserPostsEndpointUnderUsersUsername(): void
    {
        $apiPage = new Page(
            id: 1,
            parentId: null,
            pattern: 'api',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'api.index',
        );
        $pageTree = new PageTree([$apiPage]);

        UsersController::registerApi($apiPage->id, $pageTree);

        $usersPage = $pageTree->findChildren($apiPage->id)[0];
        $usersChildren = $pageTree->findChildren($usersPage->id);

        // blog-posts (create) is added first, {username} second - this
        // matters because testRegisterApiAddsBlogPostsEndpointUnderUsers
        // above relies on findChildren()[0] still being 'blog-posts'.
        $this->assertCount(2, $usersChildren);
        $usernamePage = $usersChildren[1];
        $this->assertSame('{username}', $usernamePage->pattern);
        $this->assertSame(['GET'], $usernamePage->requestMethods);
        // Not null: ControllerFactory::createForPage() throws a plain,
        // uncaught-by-callApi() Exception for a null action, so a bare
        // GET /api/v1/users/{username} needs a real (if unhandled)
        // action to 400 cleanly instead of 500ing - see registerApi()'s
        // own comment on this page.
        $this->assertSame('user.posts-parent', $usernamePage->action);

        $userPostsPage = $pageTree->findChildren($usernamePage->id)[0];
        $this->assertSame('blog-posts', $userPostsPage->pattern);
        $this->assertSame(['GET'], $userPostsPage->requestMethods);
        $this->assertSame('user.posts', $userPostsPage->action);
    }

    private function makeUserPlaylistPage(): Page
    {
        return new Page(
            id: 42,
            parentId: 27,
            pattern: 'playlist',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'user.playlist',
        );
    }

    private function makeUserPostsPage(): Page
    {
        return new Page(
            id: 40,
            parentId: 27,
            pattern: 'blog-posts',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'user.posts',
        );
    }

    public function testCallApiReturnsUserPostsPageWithNextOffset(): void
    {
        $_GET['offset'] = '4';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $page = $this->makeUserPostsPage();
        $postShowPage = $this->makeBlogPostShowPage();

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow());
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setPageTree($module, new PageTree([$postShowPage]));
        $this->setUrlGeneratorPages($module, [$postShowPage]);

        $post = $this->makeBlogPostFeed();
        $blogFeed = new Feed(
            id: 900,
            parentId: null,
            ownerId: 7,
            type: 'blog',
            slug: 'nicky42',
            title: 'Nicky',
            description: null,
            imageUrl: null,
            content: '',
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: '/users/nicky42/',
        );

        $feedService = $this->createMock(FeedService::class);
        $feedService
            ->expects($this->once())
            ->method('getFeedByOwnerAndType')
            ->with(7, 'blog', $this->anything())
            ->willReturn($blogFeed);
        $feedService
            ->expects($this->once())
            ->method('getFeedsByParentAndTypePage')
            ->with(900, 'blog-post', $this->anything(), 4, 4)
            ->willReturn(['items' => [$post], 'total' => 10]);
        $feedService->method('countCommentsForFeeds')->willReturn([902 => 3]);
        $this->setFeedService($module, $feedService);

        try {
            ob_start();
            $module->callApi($page, ['username' => 'nicky42']);
            $output = ob_get_clean();
        } finally {
            unset($_GET['offset'], $_SERVER['REQUEST_METHOD']);
        }

        $decoded = json_decode($output, true);
        $this->assertSame('My Post Title', $decoded['items'][0]['title']);
        // buildPostCards() just reads $post->canonicalUrl now (set by
        // UrlGenerator::feed() upstream, see makeBlogPostFeed()'s fixture
        // value) - the postShowPage/PageTree wiring above is no longer
        // needed to build this URL, only kept for other assertions in this
        // suite that still rely on it.
        $this->assertSame('/blog/my-post-title/', $decoded['items'][0]['url']);
        $this->assertSame(3, $decoded['items'][0]['commentCount']);
        $this->assertSame(10, $decoded['meta']['total']);
        // offset(4) + count(items)(1) = 5 < total(10) -> more to load.
        $this->assertSame(5, $decoded['meta']['nextOffset']);
    }

    public function testCallApiReturnsUserPlaylistWithStableTrackIds(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $post = $this->makeBlogPostFeed();
        $imagePost = new Feed(
            id: 903,
            parentId: 900,
            ownerId: 7,
            type: 'blog-post',
            slug: 'image-post',
            title: 'Image Post',
            description: null,
            imageUrl: null,
            content: '<p>Body</p>',
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: '/blog/image-post/',
        );

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow());
        $db->method('fetchAll')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'FROM feed_metadata')) {
                return [
                    ['feed_id' => 902, 'name' => 'track_upload_id', 'content' => '55'],
                    ['feed_id' => 903, 'name' => 'track_upload_id', 'content' => '66'],
                ];
            }

            if (str_contains($sql, 'FROM uploads')) {
                return [
                    ['id' => 55, 'user_id' => 7, 'path' => '7/song.mp3', 'mime' => 'audio/mpeg', 'size' => 4096, 'original_name' => 'song.mp3', 'created_at' => 1_700_000_000],
                    ['id' => 66, 'user_id' => 7, 'path' => '7/picture.webp', 'mime' => 'image/webp', 'size' => 2048, 'original_name' => 'picture.webp', 'created_at' => 1_700_000_001],
                ];
            }

            return [];
        });

        $module = $this->makeUsersModule($db);
        $blogFeed = new Feed(
            id: 900,
            parentId: null,
            ownerId: 7,
            type: 'blog',
            slug: 'nicky42',
            title: 'Nicky',
            description: null,
            imageUrl: null,
            content: '',
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: '/users/nicky42/',
        );

        $feedService = $this->createMock(FeedService::class);
        $feedService
            ->expects($this->once())
            ->method('getFeedByOwnerAndType')
            ->with(7, 'blog', $this->anything())
            ->willReturn($blogFeed);
        $feedService
            ->expects($this->once())
            ->method('getFeedsByParentAndTypePage')
            ->with(900, 'blog-post', $this->anything(), 100)
            ->willReturn(['items' => [$post, $imagePost], 'total' => 2]);
        $this->setFeedService($module, $feedService);

        try {
            ob_start();
            $module->callApi($this->makeUserPlaylistPage(), ['username' => 'nicky42']);
            $output = ob_get_clean();
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }

        $decoded = json_decode($output, true);

        $this->assertSame(1, $decoded['meta']['trackCount']);
        $this->assertSame('user', $decoded['meta']['scope']);
        $this->assertSame(7, $decoded['meta']['ownerId']);
        $this->assertSame('post-902-track-55', $decoded['items'][0]['id']);
        $this->assertSame(902, $decoded['items'][0]['postId']);
        $this->assertSame(55, $decoded['items'][0]['trackUploadId']);
        $this->assertSame('/uploads/7/song.mp3', $decoded['items'][0]['url']);
        $this->assertSame('/blog/my-post-title/', $decoded['items'][0]['postUrl']);
    }

    public function testCallApiReturnsUserPostsPageWithNullNextOffsetWhenExhausted(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $page = $this->makeUserPostsPage();

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow());
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedsByOwnerAndTypePage')->willReturn(['items' => [], 'total' => 0]);
        $this->setFeedService($module, $feedService);

        try {
            ob_start();
            $module->callApi($page, ['username' => 'nicky42']);
            $output = ob_get_clean();
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }

        $decoded = json_decode($output, true);
        $this->assertSame([], $decoded['items']);
        $this->assertNull($decoded['meta']['nextOffset']);
    }

    public function testCallApiThrowsNotFoundForUnknownUsernameOnUserPosts(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $page = $this->makeUserPostsPage();

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(null);

        $module = $this->makeUsersModule($db);

        try {
            $this->expectException(NotFoundException::class);
            $module->callApi($page, ['username' => 'missing']);
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    public function testCallApiRejectsNonGetMethodForUserPosts(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $page = $this->makeUserPostsPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($page, ['username' => 'nicky42']);
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    public function testRegisterApiAddsFriendEndpointsUnderUsersUsername(): void
    {
        $apiPage = new Page(
            id: 1,
            parentId: null,
            pattern: 'api',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'api.index',
        );
        $pageTree = new PageTree([$apiPage]);

        UsersController::registerApi($apiPage->id, $pageTree);

        $usersPage = $pageTree->findChildren($apiPage->id)[0];
        $usernamePage = $pageTree->findChildren($usersPage->id)[1];
        $usernameChildren = $pageTree->findChildren($usernamePage->id);

        // blog-posts (existing), friends/friend, and the music playlist.
        $this->assertCount(4, $usernameChildren);

        $byPattern = [];
        foreach ($usernameChildren as $child) {
            $byPattern[$child->pattern] = $child;
        }

        $this->assertSame('user.friends', $byPattern['friends']->action);
        $this->assertSame(['GET'], $byPattern['friends']->requestMethods);

        $this->assertSame('user.friend', $byPattern['friend']->action);
        $this->assertSame(['POST', 'DELETE'], $byPattern['friend']->requestMethods);

        $this->assertSame('user.playlist', $byPattern['playlist']->action);
        $this->assertSame(['GET'], $byPattern['playlist']->requestMethods);
    }

    public function testRegisterApiUsesOneCommunityBlogPostsCollectionForListingAndCreating(): void
    {
        $apiPage = new Page(
            id: 1,
            parentId: null,
            pattern: 'api',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'api.index',
        );
        $pageTree = new PageTree([$apiPage]);

        UsersController::registerApi($apiPage->id, $pageTree);

        $communitiesPage = null;
        foreach ($pageTree->findChildren($apiPage->id) as $child) {
            if ($child->pattern === 'communities') {
                $communitiesPage = $child;
                break;
            }
        }

        $this->assertNotNull($communitiesPage);
        $communityIdPage = $pageTree->findChildren($communitiesPage->id)[0];

        $byPattern = [];
        foreach ($pageTree->findChildren($communityIdPage->id) as $child) {
            $byPattern[$child->pattern] = $child;
        }

        $this->assertSame('community.scoped-blog-posts', $byPattern['blog-posts']->action);
        $this->assertSame(['GET', 'POST'], $byPattern['blog-posts']->requestMethods);
    }

    private function makeUserFriendPage(): Page
    {
        return new Page(
            id: 41,
            parentId: 27,
            pattern: 'friend',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['POST', 'DELETE'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'user.friend',
        );
    }

    private function makeUserFriendsPage(): Page
    {
        return new Page(
            id: 42,
            parentId: 27,
            pattern: 'friends',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'user.friends',
        );
    }

    private function makeCommunityShowPage(): Page
    {
        return new Page(
            id: 64,
            parentId: 63,
            pattern: '{slug}',
            pageName: 'Страница сообщества',
            settings: null,
            feedType: 'community',
            listFeedType: 'blog-post',
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'community.show',
        );
    }

    private function makeCommunityMembershipPage(): Page
    {
        return new Page(
            id: 70,
            parentId: 69,
            pattern: 'membership',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['POST', 'DELETE'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'community.membership',
        );
    }

    private function makeCommunityPlaylistPage(): Page
    {
        return new Page(
            id: 71,
            parentId: 69,
            pattern: 'playlist',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'community.playlist',
        );
    }

    public function testCallApiReturnsCommunityPlaylistForCommunityPosts(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $community = new Feed(
            id: 42,
            parentId: null,
            ownerId: 7,
            type: 'community',
            slug: 'devs',
            title: 'Разработчики',
            description: null,
            imageUrl: null,
            content: null,
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: '/communities/devs/',
        );
        $post = $this->makeBlogPostFeed();

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'FROM feed_metadata')) {
                return [
                    ['feed_id' => 902, 'name' => 'track_upload_id', 'content' => '55'],
                ];
            }

            if (str_contains($sql, 'FROM uploads')) {
                return [
                    ['id' => 55, 'user_id' => 7, 'path' => '7/community-song.ogg', 'mime' => 'audio/ogg', 'size' => 8192, 'original_name' => 'community-song.ogg', 'created_at' => 1_700_000_000],
                ];
            }

            return [];
        });

        $module = $this->makeUsersModule($db);

        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->with(42, $this->anything())->willReturn($community);
        $feedService
            ->expects($this->once())
            ->method('getFeedsByParentAndType')
            ->with(42, 'blog-post', $this->anything(), 100)
            ->willReturn([$post]);
        $this->setFeedService($module, $feedService);

        try {
            ob_start();
            $module->callApi($this->makeCommunityPlaylistPage(), ['id' => '42']);
            $output = ob_get_clean();
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }

        $decoded = json_decode($output, true);

        $this->assertSame('community', $decoded['meta']['scope']);
        $this->assertSame(42, $decoded['meta']['communityId']);
        $this->assertSame(1, $decoded['meta']['trackCount']);
        $this->assertSame('post-902-track-55', $decoded['items'][0]['id']);
        $this->assertSame('community-song.ogg', $decoded['items'][0]['title']);
        $this->assertSame('audio/ogg', $decoded['items'][0]['mime']);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeCommunityRow(int $id, int $ownerId, string $slug, string $title): array
    {
        return [
            'id' => $id,
            'parent_id' => null,
            'owner_id' => $ownerId,
            'type' => 'community',
            'slug' => $slug,
            'title' => $title,
            'content' => '',
            'description' => 'A description',
            'image_url' => null,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => time(),
            'nick' => '',
            'avatar_url' => '',
        ];
    }

    public function testShowCommunityShowPageBuildsViewModelForFoundCommunity(): void
    {
        $page = $this->makeCommunityShowPage();
        $db = $this->createStub(PdoDatabase::class);

        $module = $this->makeUsersModule($db);
        $this->setUrlGenerator($module, $page);
        $this->setPageTree($module, new PageTree([$page, $this->makeUserShowPage()]));

        $community = Feed::fromRow($this->makeCommunityRow(500, 7, 'my-community', 'My Community'));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$community]);
        // The sidebar's "Рейтинг" card is now an aggregate across this
        // community's own posts (not a vote on the community feed itself)
        // - see UsersController::buildCommunityRatingViewData(). 14/3 ~=
        // 4.7, exercising both the full-star and half-star math.
        $feedService->method('getRatingTotalsByParentAndType')->willReturn(['sum' => 14, 'count' => 3]);
        $feedService->method('getFeedsByParentAndType')->willReturn([]);
        $feedService->method('getFeedsByParentAndTypePage')->willReturn(['items' => [$this->makeBlogPostFeed()], 'total' => 12]);
        // Sidebar's "Статистика" card (community_stats block) - see
        // UsersController::buildCommunityStatsViewData().
        $feedService->method('countFeedsByParentAndType')->willReturn(2);
        $feedService->method('countCommentsByParentContainer')->willReturn(9);
        $this->setFeedService($module, $feedService);

        $communityService = $this->createStub(CommunityService::class);
        $communityService->method('getRelationshipStatus')->willReturn('member');
        $communityService->method('getMembers')->willReturn(['items' => [], 'total' => 0]);
        $this->setCommunityService($module, $communityService);

        $view = $module->show($page, ['slug' => 'my-community']);

        $this->assertSame('modules/users/community-show.twig', $view->template);
        $this->assertSame('My Community', $view->data['title']);
        $this->assertSame('member', $view->data['relationshipStatus']);
        $this->assertSame('/api/v1/communities/500/membership', $view->data['membershipActionUrl']);
        $this->assertTrue($view->data['hasPosts']);
        $this->assertFalse($view->data['isEmpty']);
        $this->assertSame('/api/v1/communities/500/blog-posts', $view->data['postsApiUrl']);
        $this->assertSame(1, $view->data['nextOffset']);
        $this->assertSame(3, $view->data['ratingCount']);
        $this->assertSame(4.7, $view->data['ratingAverage']);
        $this->assertSame(4, $view->data['ratingFullStars']);
        $this->assertTrue($view->data['ratingHasHalfStar']);
        $this->assertSame('На основе 3 оценки записей сообщества', $view->data['ratingHint']);
        $this->assertSame(2, $view->data['postCount']);
        $this->assertSame(9, $view->data['commentCount']);
        $this->assertNotNull($view->data['createdAtLabel']);
    }

    public function testShowCommunityShowPageThrowsNotFoundWhenFeedIsNotACommunity(): void
    {
        $page = $this->makeCommunityShowPage();
        $db = $this->createStub(PdoDatabase::class);

        $module = $this->makeUsersModule($db);
        $this->setUrlGenerator($module, $page);

        $blogPostRow = $this->makeCommunityRow(900, 7, 'my-community', 'Not a community');
        $blogPostRow['type'] = 'blog-post';

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([Feed::fromRow($blogPostRow)]);
        $this->setFeedService($module, $feedService);

        $this->expectException(NotFoundException::class);

        $module->show($page, ['slug' => 'my-community']);
    }

    /**
     * community.main's own sidebar widgets - "Новые сообщества"
     * (UsersController::buildNewCommunitiesWidget(), backed by
     * FeedService::getFeedsByType()) and "Популярные сообщества"
     * (buildPopularCommunitiesWidget(), a direct $this->db query ranking
     * by member count - see that method's own docblock for why it skips
     * FeedRepository/FeedService entirely) - land in the view model as
     * newCommunities/popularCommunities. Only the popular widget's own
     * cards carry a real memberCount; the new-communities cards never
     * set it (buildCommunityWidgetCards() always leaves it null).
     */
    public function testShowCommunityPageBuildsNewAndPopularCommunityWidgets(): void
    {
        $_GET = ['tag' => ' magic & art ', 'utm_source' => 'test'];
        $page = new Page(
            id: 63,
            parentId: null,
            pattern: 'community',
            pageName: 'Сообщество',
            settings: null,
            feedType: null,
            listFeedType: 'blog-post',
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'community.main',
        );

        $communityShowPage = new Page(
            id: 64,
            parentId: 63,
            pattern: '{slug}',
            pageName: 'Страница сообщества',
            settings: null,
            feedType: 'community',
            listFeedType: 'blog-post',
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'community.show',
        );

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, "f.type = 'community'")
                    && str_contains($sql, "f.visibility = 'public'")
                    && str_contains($sql, 'AS member_count')
                    && str_contains($sql, 'membership_role_id != 1')
                    && str_contains($sql, 'ORDER BY member_count DESC, f.id DESC')
                    && str_contains($sql, 'LIMIT 5'))
            )
            ->willReturn([
                [
                    'slug' => 'popular-club',
                    'title' => 'Популярный клуб',
                    'image_url' => null,
                    'member_count' => 42,
                ],
            ]);

        $module = $this->makeUsersModule($db);

        $reflection = new ReflectionClass(UsersController::class);
        $urlGeneratorProperty = $reflection->getProperty('urlGenerator');
        $urlGeneratorProperty->setValue(
            $module,
            new UrlGenerator(new PageTree([$page, $communityShowPage]), new FakeFeedRepository([]), new ArrayCache())
        );

        // buildPopularCommunitiesWidget() resolves 'community.show' via
        // the controller's own pageTree property (separate from the
        // UrlGenerator's own tree set just above) to build each row's URL.
        $this->setPageTree($module, new PageTree([$communityShowPage]));

        $newCommunity = Feed::fromRow($this->makeCommunityRow(10, 2, 'new-club', 'Новый клуб'));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedsByType')->willReturn([$newCommunity]);
        $feedService->method('getTopTermsByVocabulary')->willReturn([]);
        $feedService->method('getFeedsByTypePage')->willReturn(['items' => [], 'total' => 0]);
        $this->setFeedService($module, $feedService);

        // buildCommunityWidgetCards() (used for both widgets below) calls
        // communityService->resolveImageUrl() per card - not defaulted by
        // makeUsersModule() (readonly property, see comment there), so
        // tests that reach show()'s community-list path must set it.
        $communityService = $this->createStub(CommunityService::class);
        $this->setCommunityService($module, $communityService);

        $view = $module->show($page, []);

        $this->assertSame('modules/users/community.twig', $view->template);
        $this->assertSame('/community/?tag=magic%20%26%20art', $view->data['canonical']);
        $this->assertSame('/community/', $view->data['communityUrl']);
        $_GET = [];

        $this->assertCount(1, $view->data['newCommunities']);
        $this->assertSame('Новый клуб', $view->data['newCommunities'][0]['title']);
        $this->assertNull($view->data['newCommunities'][0]['memberCount']);

        $this->assertCount(1, $view->data['popularCommunities']);
        $this->assertSame('Популярный клуб', $view->data['popularCommunities'][0]['title']);
        $this->assertSame(42, $view->data['popularCommunities'][0]['memberCount']);
        $this->assertSame('/community/popular-club/', $view->data['popularCommunities'][0]['url']);
    }

    public function testShowCommunityPageBuildsMyCommunitiesForSignedInUser(): void
    {
        $page = new Page(
            id: 63,
            parentId: null,
            pattern: 'community',
            pageName: 'Сообщество',
            settings: null,
            feedType: null,
            listFeedType: 'blog-post',
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'community.main',
        );

        $communityShowPage = new Page(
            id: 64,
            parentId: 63,
            pattern: '{slug}',
            pageName: 'Страница сообщества',
            settings: null,
            feedType: 'community',
            listFeedType: 'blog-post',
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'community.show',
        );

        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'WHERE m.user_id = ?')) {
                return [
                    [
                        'id' => 500,
                        'slug' => 'my-club',
                        'title' => 'Мой клуб',
                        'image_url' => null,
                        'role_level' => 3,
                        'member_count' => 12,
                        'joined_at' => 1_700_000_000,
                    ],
                ];
            }

            if (str_contains($sql, "f.type = 'community'") && str_contains($sql, "f.visibility = 'public'")) {
                return [];
            }

            return [];
        });

        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER, username: 'viewer'));
        $this->setPageTree($module, new PageTree([$page, $communityShowPage]));

        $reflection = new ReflectionClass(UsersController::class);
        $urlGeneratorProperty = $reflection->getProperty('urlGenerator');
        $urlGeneratorProperty->setValue(
            $module,
            new UrlGenerator(new PageTree([$page, $communityShowPage]), new FakeFeedRepository([]), new ArrayCache())
        );

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedsByType')->willReturn([]);
        $feedService->method('getTopTermsByVocabulary')->willReturn([]);
        $feedService->method('getFeedsByTypePage')->willReturn(['items' => [], 'total' => 0]);
        $this->setFeedService($module, $feedService);

        $communityService = $this->createStub(CommunityService::class);
        $this->setCommunityService($module, $communityService);

        $view = $module->show($page, []);

        $this->assertCount(1, $view->data['myCommunities']);
        $this->assertSame('Мой клуб', $view->data['myCommunities'][0]['title']);
        $this->assertSame('/community/my-club/', $view->data['myCommunities'][0]['url']);
        $this->assertSame('/assets/img/default-community.svg', $view->data['myCommunities'][0]['imageUrl']);
        $this->assertSame('Владелец', $view->data['myCommunities'][0]['statusLabel']);
    }

    public function testCallApiJoinsCommunityAndReturnsResultingStatus(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $page = $this->makeCommunityMembershipPage();
        $db = $this->createStub(PdoDatabase::class);

        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER));

        $community = Feed::fromRow($this->makeCommunityRow(500, 7, 'my-community', 'My Community'));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($community);
        $this->setFeedService($module, $feedService);

        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->once())->method('join');
        $communityService->method('getRelationshipStatus')->willReturn('member');
        $this->setCommunityService($module, $communityService);

        try {
            ob_start();
            $module->callApi($page, ['id' => '500']);
            $output = ob_get_clean();
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }

        $decoded = json_decode($output, true);
        $this->assertSame('member', $decoded['status']);
    }

    public function testCallApiLeavesCommunityOnDeleteAndReturnsResultingStatus(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';

        $page = $this->makeCommunityMembershipPage();
        $db = $this->createStub(PdoDatabase::class);

        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER));

        $community = Feed::fromRow($this->makeCommunityRow(500, 7, 'my-community', 'My Community'));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($community);
        $this->setFeedService($module, $feedService);

        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->once())->method('leave');
        $communityService->method('getRelationshipStatus')->willReturn('none');
        $this->setCommunityService($module, $communityService);

        try {
            ob_start();
            $module->callApi($page, ['id' => '500']);
            $output = ob_get_clean();
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }

        $decoded = json_decode($output, true);
        $this->assertSame('none', $decoded['status']);
    }

    public function testCallApiThrowsNotFoundForUnknownCommunityIdOnMembership(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $page = $this->makeCommunityMembershipPage();
        $db = $this->createStub(PdoDatabase::class);

        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn(null);
        $this->setFeedService($module, $feedService);

        try {
            $this->expectException(NotFoundException::class);
            $module->callApi($page, ['id' => '0']);
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    public function testCallApiRejectsUnsupportedMethodForCommunityMembershipAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $page = $this->makeCommunityMembershipPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($page, ['id' => '500']);
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    private function makeCommunityMainPage(): Page
    {
        return new Page(
            id: 63,
            parentId: null,
            pattern: 'community',
            pageName: 'Сообщество',
            settings: null,
            feedType: null,
            listFeedType: 'blog-post',
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'community.main',
        );
    }

    private function makeCommunityManagePage(): Page
    {
        return new Page(
            id: 65,
            parentId: 64,
            pattern: 'manage',
            pageName: 'Управление сообществом',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_AUTHENTICATED,
            action: 'community.manage',
        );
    }

    private function makeCommunityManageSettingsPage(): Page
    {
        return new Page(
            id: 80,
            parentId: 68,
            pattern: 'manage',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['PATCH'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'community.manage-settings',
        );
    }

    private function makeCommunityManageMemberPage(): Page
    {
        return new Page(
            id: 82,
            parentId: 81,
            pattern: '{userId}',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['PATCH', 'DELETE'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'community.manage-member',
        );
    }

    public function testShowCommunityManagePageRendersSettingsAndMembersForOwner(): void
    {
        $mainPage = $this->makeCommunityMainPage();
        $showPage = $this->makeCommunityShowPage();
        $managePage = $this->makeCommunityManagePage();

        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER));
        $this->setUrlGeneratorPages($module, [$mainPage, $showPage, $managePage]);
        $this->setPageTree($module, new PageTree([$mainPage, $showPage, $managePage]));

        $community = Feed::fromRow($this->makeCommunityRow(500, 7, 'my-community', 'My Community'));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$community]);
        $this->setFeedService($module, $feedService);

        $communityService = $this->createStub(CommunityService::class);
        $communityService->method('getMembers')->willReturn(['items' => [], 'total' => 0]);
        $communityService->method('getSubscribers')->willReturn(['items' => [], 'total' => 0]);
        $this->setCommunityService($module, $communityService);

        $view = $module->show($managePage, ['slug' => 'my-community']);

        $this->assertSame('modules/users/community-manage.twig', $view->template);
        $this->assertCount(2, $view->data['tabs']);
        $this->assertSame('/api/v1/communities/500/manage', $view->data['settingsApiUrl']);
        $this->assertSame('/api/v1/communities/500/manage/members/', $view->data['memberActionUrlBase']);
        $this->assertSame('/community/my-community/', $view->data['cancelUrl']);
        $this->assertSame('open', $view->data['membershipType']);
        $this->assertSame([], $view->data['members']);
        $this->assertSame([], $view->data['subscribers']);
    }

    public function testShowCommunityManagePageThrowsForbiddenForNonOwnerViewer(): void
    {
        $managePage = $this->makeCommunityManagePage();

        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 42, email: 'viewer@example.com', role: AccessService::ROLE_USER));

        $community = Feed::fromRow($this->makeCommunityRow(500, 7, 'my-community', 'My Community'));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedBySlug')->willReturn([$community]);
        $this->setFeedService($module, $feedService);

        $this->expectException(ForbiddenException::class);

        $module->show($managePage, ['slug' => 'my-community']);
    }

    public function testCallApiSavesCommunitySettingsImmediately(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        $page = $this->makeCommunityManageSettingsPage();
        $db = $this->createStub(PdoDatabase::class);

        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER));

        $community = Feed::fromRow($this->makeCommunityRow(500, 7, 'my-community', 'My Community'));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($community);
        $this->setFeedService($module, $feedService);

        $updatedFeed = Feed::fromRow($this->makeCommunityRow(500, 7, 'my-community', 'New name'));
        $updatedFeed->metadata = ['membership_type' => 'open'];

        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->once())
            ->method('updateSettings')
            ->willReturn([
                'feed' => $updatedFeed,
                'needsConfirmation' => false,
                'pendingCount' => 0,
                'promotedCount' => null,
            ]);
        $this->setCommunityService($module, $communityService);

        PhpInputStreamMock::register(json_encode([
            'name' => 'New name',
            'description' => 'Desc',
            'imageUrl' => null,
            'membershipType' => 'open',
        ]));

        try {
            ob_start();
            $module->callApi($page, ['id' => '500']);
            $output = ob_get_clean();
        } finally {
            PhpInputStreamMock::restore();
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }

        $decoded = json_decode($output, true);
        $this->assertFalse($decoded['needsConfirmation']);
        $this->assertNull($decoded['promotedCount']);
        $this->assertSame('open', $decoded['membershipType']);
    }

    public function testCallApiReturnsNeedsConfirmationWhenSwitchingMembershipTypeToOpen(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        $page = $this->makeCommunityManageSettingsPage();
        $db = $this->createStub(PdoDatabase::class);

        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER));

        $community = Feed::fromRow($this->makeCommunityRow(500, 7, 'my-community', 'My Community'));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($community);
        $this->setFeedService($module, $feedService);

        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->once())
            ->method('updateSettings')
            ->willReturn([
                'feed' => null,
                'needsConfirmation' => true,
                'pendingCount' => 3,
                'promotedCount' => null,
            ]);
        $this->setCommunityService($module, $communityService);

        PhpInputStreamMock::register(json_encode([
            'name' => 'My Community',
            'description' => null,
            'imageUrl' => null,
            'membershipType' => 'open',
        ]));

        try {
            ob_start();
            $module->callApi($page, ['id' => '500']);
            $output = ob_get_clean();
        } finally {
            PhpInputStreamMock::restore();
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }

        $decoded = json_decode($output, true);
        $this->assertTrue($decoded['needsConfirmation']);
        $this->assertSame(3, $decoded['pendingCount']);
    }

    public function testCallApiSettingsUpdateRequiresCsrf(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        $page = $this->makeCommunityManageSettingsPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);

        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->never())->method('updateSettings');
        $this->setCommunityService($module, $communityService);

        PhpInputStreamMock::register(json_encode(['name' => 'New name']));

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($page, ['id' => '500']);
        } finally {
            PhpInputStreamMock::restore();
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    public function testCallApiApprovesSubscriberOnPatchMemberAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        $page = $this->makeCommunityManageMemberPage();
        $db = $this->createStub(PdoDatabase::class);

        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER));

        $community = Feed::fromRow($this->makeCommunityRow(500, 7, 'my-community', 'My Community'));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($community);
        $this->setFeedService($module, $feedService);

        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->once())
            ->method('approveSubscriber')
            ->with(
                $this->callback(static fn (User $u): bool => $u->id === 7),
                $community,
                99,
            );
        $this->setCommunityService($module, $communityService);

        try {
            ob_start();
            $module->callApi($page, ['id' => '500', 'userId' => '99']);
            $output = ob_get_clean();
        } finally {
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }

        $decoded = json_decode($output, true);
        $this->assertTrue($decoded['success']);
    }

    public function testCallApiRemovesMemberOnDeleteMemberAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        $page = $this->makeCommunityManageMemberPage();
        $db = $this->createStub(PdoDatabase::class);

        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'owner@example.com', role: AccessService::ROLE_USER));

        $community = Feed::fromRow($this->makeCommunityRow(500, 7, 'my-community', 'My Community'));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($community);
        $this->setFeedService($module, $feedService);

        $communityService = $this->createMock(CommunityService::class);
        $communityService->expects($this->once())
            ->method('removeMember')
            ->with(
                $this->callback(static fn (User $u): bool => $u->id === 7),
                $community,
                42,
            );
        $this->setCommunityService($module, $communityService);

        try {
            ob_start();
            $module->callApi($page, ['id' => '500', 'userId' => '42']);
            $output = ob_get_clean();
        } finally {
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }

        $decoded = json_decode($output, true);
        $this->assertTrue($decoded['success']);
    }

    public function testCallApiRejectsUnsupportedMethodForManageMemberAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $page = $this->makeCommunityManageMemberPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($page, ['id' => '500', 'userId' => '99']);
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    /**
     * Builds a real FriendService (it's `final`, same reasoning as
     * makeEmptyFriendServiceStub()) backed by a FakePdoDatabase with a
     * personal blog feed seeded for both $actorId and $targetId, so
     * sendRequest()/removeFriend() actually persist and
     * getRelationshipStatus() reflects it - unlike
     * makeEmptyFriendServiceStub()'s empty default, these tests care about
     * the real outcome.
     */
    private function makeFriendServiceWithBlogsFor(int $actorId, int $targetId): array
    {
        $fakeDb = new \Tests\Support\FakePdoDatabase();
        $fakeDb->addBlogFeed(701, $targetId);
        $fakeDb->addBlogFeed(702, $actorId);

        $blogPostService = $this->createStub(BlogPostService::class);
        $blogPostService->method('getOrCreateUserBlogFeed')->willReturnCallback(
            fn (User $user): Feed => Feed::fromRow([
                'id' => $user->id === $targetId ? 701 : 702,
                'parent_id' => null,
                'owner_id' => $user->id,
                'type' => 'blog',
                'slug' => null,
                'title' => 'Blog',
                'description' => null,
                'image_url' => null,
                'content' => null,
                'container_id' => null,
                'visibility' => 'public',
                'position' => 0,
                'created_at' => 1700000000,
            ])
        );

        $messageService = new \StreamEngine\Service\MessageService(
            $fakeDb,
            new \StreamEngine\Repository\MessageRepository($fakeDb),
            new \StreamEngine\Repository\ParticipantRepository($fakeDb),
            new \StreamEngine\Repository\ConversationRepository($fakeDb),
            new UserRepository($fakeDb),
            new \StreamEngine\Repository\UserSessionRepository($fakeDb),
            new \StreamEngine\Repository\UploadRepository($fakeDb)
        );

        $pageTree = new PageTree([]);

        $friendService = new FriendService(
            $blogPostService,
            new FeedRepository($fakeDb),
            new MembershipRepository($fakeDb),
            new NotificationService(
                $messageService,
                new NotificationDeliveryRepository($fakeDb),
                new NotificationPreferenceRepository($fakeDb),
                new UserRepository($fakeDb),
                (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
            ),
            new TranslationManager('ru', 'en'),
            $pageTree,
            new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache()),
        );

        return [$friendService, $fakeDb];
    }

    public function testCallApiSendsFriendRequestAndReturnsResultingStatus(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        $page = $this->makeUserFriendPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow()); // target: id 7
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 99, email: 'actor@example.com', role: AccessService::ROLE_USER, username: 'actor99'));

        [$friendService, $fakeDb] = $this->makeFriendServiceWithBlogsFor(actorId: 99, targetId: 7);
        $this->setFriendService($module, $friendService);

        try {
            ob_start();
            $module->callApi($page, ['username' => 'nicky42']);
            $output = ob_get_clean();
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }

        $decoded = json_decode($output, true);
        $this->assertSame('subscribed', $decoded['status']);
        $this->assertCount(1, $fakeDb->notificationDeliveries);
        $this->assertSame([], $fakeDb->messages);
    }

    public function testCallApiRemovesFriendOnDeleteAndReturnsResultingStatus(): void
    {
        $target = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42');
        $actor = new User(id: 99, email: 'actor@example.com', role: AccessService::ROLE_USER, username: 'actor99');

        [$friendService, $fakeDb] = $this->makeFriendServiceWithBlogsFor(actorId: 99, targetId: 7);
        $friendService->sendRequest($actor, $target);

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        $page = $this->makeUserFriendPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow());
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setContext($module, $actor);
        $this->setFriendService($module, $friendService);

        try {
            ob_start();
            $module->callApi($page, ['username' => 'nicky42']);
            $output = ob_get_clean();
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }

        $decoded = json_decode($output, true);
        $this->assertSame('none', $decoded['status']);
    }

    public function testCallApiThrowsNotFoundForUnknownUsernameOnFriendRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        $page = $this->makeUserFriendPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(null);

        $module = $this->makeUsersModule($db);

        try {
            $this->expectException(NotFoundException::class);
            $module->callApi($page, ['username' => 'missing']);
        } finally {
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }
    }

    /**
     * The last mutating endpoint that was missing a CSRF check, found by
     * auditing every Page::api() registration with a mutating method against
     * its handler. Guest rejection lives in FriendService, which is
     * authorization - it says nothing about whether the logged-in visitor meant
     * to make the request.
     */
    public function testCallApiFriendActionFailsWithoutCsrfToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        $page = $this->makeUserFriendPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow());

        $module = $this->makeUsersModule($db);

        // No FriendService stub needed, and that is the assertion: the check
        // sits ahead of both the username lookup and the service call, so a
        // ValidationException - rather than a NotFound, or a write - is proof
        // nothing downstream ran. FriendService is `final` anyway, so it can
        // only be faked, not mocked with expectations.
        try {
            $this->expectException(ValidationException::class);
            $module->callApi($page, ['username' => 'nicky42']);
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    public function testCallApiRejectsUnsupportedMethodForFriendAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $page = $this->makeUserFriendPage();
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($page, ['username' => 'nicky42']);
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    public function testCallApiReturnsFriendsListWithEmptyDefaults(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $page = $this->makeUserFriendsPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow());
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setFriendService($module, $this->makeEmptyFriendServiceStub());

        try {
            ob_start();
            $module->callApi($page, ['username' => 'nicky42']);
            $output = ob_get_clean();
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }

        $decoded = json_decode($output, true);
        $this->assertSame([], $decoded['items']);
        $this->assertSame(0, $decoded['meta']['total']);
        $this->assertNull($decoded['meta']['nextOffset']);
    }

    public function testCallApiReturnsFriendsListWithARealMutualFriend(): void
    {
        $target = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42');
        $actor = new User(id: 99, email: 'actor@example.com', role: AccessService::ROLE_USER, username: 'actor99');

        [$friendService, $fakeDb] = $this->makeFriendServiceWithBlogsFor(actorId: 99, targetId: 7);
        $fakeDb->addUser(99, 'Actor', 'actor99');
        $friendService->sendRequest($actor, $target);
        $friendService->sendRequest($target, $actor); // reciprocated -> mutual

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $page = $this->makeUserFriendsPage();
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($this->makeUserRow()); // target: id 7
        $db->method('fetchAll')->willReturn([]);

        $module = $this->makeUsersModule($db);
        $this->setPageTree($module, new PageTree([$this->makeUserShowPage()]));
        $this->setUrlGeneratorPages($module, [$this->makeUserShowPage()]);
        $this->setFriendService($module, $friendService);

        try {
            ob_start();
            $module->callApi($page, ['username' => 'nicky42']);
            $output = ob_get_clean();
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }

        $decoded = json_decode($output, true);
        $this->assertSame(1, $decoded['meta']['total']);
        $this->assertNull($decoded['meta']['nextOffset']);
        $this->assertSame('Actor', $decoded['items'][0]['displayName']);
        $this->assertSame('/actor99/', $decoded['items'][0]['url']);
        // Actor has no avatar_url (FakePdoDatabase::addUser()'s own
        // default) - buildFriendCards() is expected to resolve that to the
        // generic default avatar (UserService::DEFAULT_AVATAR_URL) rather
        // than hand back an empty string for the view/client script to
        // fall back on itself.
        $this->assertSame(UserService::DEFAULT_AVATAR_URL, $decoded['items'][0]['avatarUrl']);
    }

    private function makeBlogPostCreatePage(): Page
    {
        return new Page(
            id: 30,
            parentId: 29,
            pattern: 'blog-posts',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['POST'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'user.post-new-create',
        );
    }

    public function testCallApiCreatesBlogPostAndReturnsCanonicalUrl(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42');
        $this->setContext($module, $user);

        $createdPost = new Feed(
            id: 902,
            parentId: 900,
            ownerId: 7,
            type: 'blog-post',
            slug: 'my-post-title',
            title: 'My Post Title',
            description: null,
            imageUrl: null,
            content: 'Body text',
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: '/blog/nicky42/my-post-title/',
        );

        $blogPostService = $this->createMock(BlogPostService::class);
        $blogPostService
            ->expects($this->once())
            ->method('createBlogPost')
            ->with(
                'My Post Title',
                'Body text',
                null,
                null,
                $this->callback(static fn (User $u): bool => $u->id === 7),
                'public',
                null,
                null,
            )
            ->willReturn($createdPost);

        $this->setBlogPostService($module, $blogPostService);

        $page = $this->makeBlogPostCreatePage();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        PhpInputStreamMock::register(json_encode([
            'title' => 'My Post Title',
            'content' => 'Body text',
        ]));

        try {
            ob_start();
            $module->callApi($page);
            $output = ob_get_clean();
        } finally {
            PhpInputStreamMock::restore();
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }

        $this->assertSame(201, http_response_code());
        $decoded = json_decode($output, true);
        $this->assertSame(902, $decoded['id']);
        $this->assertSame('my-post-title', $decoded['slug']);
        $this->assertSame('public', $decoded['visibility']);
        $this->assertSame('/blog/nicky42/my-post-title/', $decoded['canonicalUrl']);
    }

    public function testCallApiCreateBlogPostRequiresCsrf(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42'));

        $blogPostService = $this->createMock(BlogPostService::class);
        $blogPostService->expects($this->never())->method('createBlogPost');
        $this->setBlogPostService($module, $blogPostService);

        $page = $this->makeBlogPostCreatePage();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        PhpInputStreamMock::register(json_encode(['title' => 'T', 'content' => 'C']));

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($page);
        } finally {
            PhpInputStreamMock::restore();
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    public function testCallApiCreateBlogPostRejectsUnownedTrackUpload(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42'));

        $blogPostService = $this->createMock(BlogPostService::class);
        $blogPostService->expects($this->never())->method('createBlogPost');
        $this->setBlogPostService($module, $blogPostService);

        $uploadService = $this->createMock(UploadService::class);
        $uploadService
            ->expects($this->once())
            ->method('findOwnedUpload')
            ->with(55, $this->callback(static fn (User $u): bool => $u->id === 7), 'audio/')
            ->willReturn(null);
        $this->setUploadService($module, $uploadService);

        $page = $this->makeBlogPostCreatePage();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        PhpInputStreamMock::register(json_encode([
            'title' => 'T',
            'content' => 'C',
            'trackUploadId' => 55,
        ]));

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($page);
        } finally {
            PhpInputStreamMock::restore();
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }
    }

    private function makeBlogPostItemPage(): Page
    {
        return new Page(
            id: 31,
            parentId: 30,
            pattern: '{id}',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['PATCH', 'DELETE'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'user.post-item',
        );
    }

    public function testCallApiDeletesBlogPost(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42');
        $this->setContext($module, $user);

        $blogPostService = $this->createMock(BlogPostService::class);
        $blogPostService
            ->expects($this->once())
            ->method('deleteBlogPost')
            ->with(902, $this->callback(static fn (User $u): bool => $u->id === 7));
        $this->setBlogPostService($module, $blogPostService);

        $page = $this->makeBlogPostItemPage();

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        try {
            $module->callApi($page, ['id' => '902']);
        } finally {
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }

        $this->assertSame(204, http_response_code());
    }

    public function testCallApiDeleteBlogPostRequiresCsrf(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42'));

        $blogPostService = $this->createMock(BlogPostService::class);
        $blogPostService->expects($this->never())->method('deleteBlogPost');
        $this->setBlogPostService($module, $blogPostService);

        $page = $this->makeBlogPostItemPage();

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($page, ['id' => '902']);
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    public function testCallApiDeleteBlogPostRequiresDeleteMethod(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42'));

        $blogPostService = $this->createMock(BlogPostService::class);
        $blogPostService->expects($this->never())->method('deleteBlogPost');
        $this->setBlogPostService($module, $blogPostService);

        $page = $this->makeBlogPostItemPage();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        try {
            $this->expectException(ValidationException::class);
            $module->callApi($page, ['id' => '902']);
        } finally {
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }
    }

    public function testCallApiDeleteBlogPostRejectsInvalidId(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $module = $this->makeUsersModule($db);
        $this->setContext($module, new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42'));

        $blogPostService = $this->createMock(BlogPostService::class);
        $blogPostService->expects($this->never())->method('deleteBlogPost');
        $this->setBlogPostService($module, $blogPostService);

        $page = $this->makeBlogPostItemPage();

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        try {
            $this->expectException(NotFoundException::class);
            $module->callApi($page, ['id' => '0']);
        } finally {
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }
    }

    /* ===============================
       Cron
    =============================== */

    /**
     * The sweep deletes rows on a schedule with nobody watching, so the only
     * thing standing between it and a table full of nothing is the WHERE
     * clause. It is a raw `execute()` with no parameters, which is exactly why
     * it is worth pinning: there is no argument to get wrong, only a statement.
     */
    public function testUsersCleanupOnlyDeletesExpiredPasswordResets(): void
    {
        $captured = [];
        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturnCallback(
            function (string $sql) use (&$captured): int {
                $captured[] = $sql;

                return 1;
            }
        );

        $module = $this->makeUsersModule($db);
        $module->runCron('users:cleanup');

        $this->assertCount(1, $captured);
        $this->assertStringContainsString('DELETE FROM password_resets', $captured[0]);
        // An hour old, not "all of them" - a reset link that was issued a
        // minute ago must survive the sweep that runs every hour.
        $this->assertStringContainsString('created_at < UNIX_TIMESTAMP() - 60 * 60', $captured[0]);
    }

    public function testSessionsCleanupTaskDelegatesToTheService(): void
    {
        $calls = 0;
        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturnCallback(function () use (&$calls): int {
            $calls++;

            // A short batch, so UserService::cleanupExpiredSessions() stops
            // after one round rather than looping to its cap.
            return 0;
        });

        $this->makeUsersModule($db)->runCron('users:sessions-cleanup');

        $this->assertSame(1, $calls);
    }

    /**
     * `match` with no default arm, on purpose: a task registered in
     * registerCron() but not handled here would otherwise be a silent no-op
     * that looks like a working cron for as long as nobody checks.
     */
    public function testAnUnknownCronTaskFailsLoudly(): void
    {
        $module = $this->makeUsersModule($this->createStub(PdoDatabase::class));

        $this->expectException(\UnhandledMatchError::class);

        $module->runCron('users:does-not-exist');
    }

    /**
     * Both registered tasks have to be dispatchable - a name that drifts
     * between registerCron() and runCron() is the same silent failure as the
     * missing arm above, just spelled differently.
     */
    public function testEveryRegisteredTaskIsDispatchable(): void
    {
        $registry = new CronRegistry();
        UsersController::registerCron($registry);

        // add() keys by task name, so all() is a map rather than a list.
        $tasks = array_keys($registry->all());
        $this->assertSame(['users:cleanup', 'users:sessions-cleanup'], $tasks);

        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturn(0);
        $module = $this->makeUsersModule($db);

        // No assertion needed after this loop: a name that drifted between the
        // two methods raises UnhandledMatchError, which fails the test on its
        // own. The assertSame above is what keeps the list itself honest.
        foreach ($tasks as $task) {
            $module->runCron($task);
        }
    }
}
