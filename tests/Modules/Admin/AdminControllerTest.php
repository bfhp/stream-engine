<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Router;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Admin\AdminController;
use StreamEngine\Service\AccessService;
use Tests\Support\PhpInputStreamMock;

final class AdminControllerTest extends TestCase
{
    private array $writes = [];

    protected function tearDown(): void
    {
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_X_CSRF_TOKEN'],
            $_COOKIE['csrfToken'],
        );

        PhpInputStreamMock::restore();

        $this->writes = [];
    }

    /**
     * @param array<string, string> $settings seeded settings rows
     * @param list<array<string, mixed>> $rows what every other SELECT returns
     */
    private function makeModule(
        bool $isAdmin = true,
        array $settings = [],
        array $rows = [],
        ?array $row = null,
        int $lastInsertId = 77,
    ): AdminController {
        $db = $this->createStub(PdoDatabase::class);

        $db->method('fetchAll')->willReturnCallback(
            function (string $sql) use ($settings, $rows): array {
                if (str_contains($sql, 'FROM settings')) {
                    $out = [];
                    foreach ($settings as $key => $value) {
                        $out[] = ['setting_key' => $key, 'setting_value' => $value, 'updated_at' => 0];
                    }

                    return $out;
                }

                return $rows;
            }
        );
        $db->method('fetchOne')->willReturn($row);
        $db->method('lastInsertId')->willReturn($lastInsertId);
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->writes[] = [$sql, $params];

                return 1;
            }
        );

        $accessService = $this->createStub(AccessService::class);
        $accessService->method('isAdmin')->willReturn($isAdmin);

        return new AdminController(
            $db,
            new RequestContext(
                new User(id: 1, email: 'admin@example.com', role: AccessService::ROLE_ADMIN),
                new DateTimeZone('UTC')
            ),
            $accessService,
            new TranslationManager('ru', 'en'),
        );
    }

    /** Issues a token the controller will accept, and returns it. */
    private function withValidCsrf(): string
    {
        $token = str_repeat('a', 64);

        $_COOKIE['csrfToken'] = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        return $token;
    }

    /**
     * Runs callApi() and returns the JSON it echoed, decoded.
     *
     * @return array<string, mixed>
     */
    private function callAndDecode(AdminController $module, Page $page, array $args = []): array
    {
        ob_start();

        try {
            $module->callApi($page, $args);
        } finally {
            $output = ob_get_clean();
        }

        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, 'the endpoint must answer with a JSON object');

        return $decoded;
    }

    /**
     * @param list<string> $requestMethods
     */
    private function makeApiPage(string $action, array $requestMethods): Page
    {
        return Page::api(
            id: 40,
            parentId: 10,
            pattern: 'stub',
            requestMethods: $requestMethods,
            action: $action,
            accessRule: AccessService::ACCESS_ADMIN,
        );
    }

    /* ===============================
       Pages editor API
    =============================== */

    public function testPagesListReturnsRoutingRowsForTheAdminEditor(): void
    {
        $module = $this->makeModule(rows: [[
            'id' => 5,
            'parent' => 1,
            'pattern' => 'books',
            'action' => 'reports.index',
            'page_name' => 'Books',
            'settings' => '{"perPage":20,"commentsEnabled":true}',
            'feed_type' => 'book',
            'list_feed_type' => 'book-page',
            'term_vocabulary' => 'author',
            'feed_id' => 42,
            'changefreq' => 'daily',
            'updated' => 123,
            'access_rule' => 'public',
        ]]);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->callAndDecode($module, $this->makeApiPage('admin.pages', ['GET', 'POST']));

        $this->assertSame(5, $response['data'][0]['id']);
        $this->assertSame('reports.index', $response['data'][0]['action']);
        $this->assertTrue(json_decode($response['data'][0]['settings'], true)['commentsEnabled']);
    }

    public function testCreatingAPageRequiresCsrfBeforeValidation(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);
        PhpInputStreamMock::register(json_encode(['action' => 'article.show']));

        $this->expectException(ValidationException::class);

        $module->callApi($this->makeApiPage('admin.pages', ['GET', 'POST']), []);
    }

    public function testUpdatingAPageRequiresCsrfBeforeSaving(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);
        PhpInputStreamMock::register(json_encode(['action' => 'article.show']));

        $this->expectException(ValidationException::class);

        $module->callApi($this->makeApiPage('admin.page', ['GET', 'PATCH']), ['id' => 5]);
    }

    #[DataProvider('invalidAccessRules')]
    public function testPageWritesRejectInvalidOrMissingAccessRule(mixed $rule): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode(['action' => 'article.show', 'accessRule' => $rule]));

        try {
            $module->callApi($this->makeApiPage('admin.pages', ['GET', 'POST']), []);
            $this->fail('Invalid audience must not become public.');
        } catch (ValidationException $e) {
            $this->assertSame('Invalid access rule', $e->getMessage());
            $this->assertSame([], $this->writes);
        }
    }

    public static function invalidAccessRules(): array
    {
        return [[null], [''], [0], [3], ['unknown'], [['admin']]];
    }

    public function testAValidPageCreateIsSavedAndReturned(): void
    {
        $module = $this->makeModule(lastInsertId: 77);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'parentId' => 1,
            'pattern' => 'about',
            'action' => 'article.show',
            'pageName' => 'About',
            'accessRule' => 'public',
            'settings' => '{"template":"about","commentsEnabled":true}',
        ]));

        $response = $this->callAndDecode($module, $this->makeApiPage('admin.pages', ['GET', 'POST']));

        $this->assertSame(77, $response['id']);
        $this->assertSame('article.show', $response['action']);
        $this->assertSame('about', $this->writes[0][1][1]);
        $this->assertSame('{"template":"about","commentsEnabled":true}', $this->writes[0][1][4]);
    }

    public function testAValidPageUpdateIsSaved(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'pattern' => 'contacts',
            'action' => 'feedback.show',
            'updated' => 987,
            'accessRule' => 'admin',
        ]));

        $response = $this->callAndDecode($module, $this->makeApiPage('admin.page', ['GET', 'PATCH']), ['id' => 9]);

        $this->assertSame(9, $response['id']);
        $this->assertSame('feedback.show', $response['action']);
        $this->assertSame(987, $this->writes[0][1][10]);
        $this->assertSame('admin', $this->writes[0][1][11]);
        $this->assertSame(9, $this->writes[0][1][12]);
    }

    public function testInvalidPageSettingsJsonIsRefusedAndNothingIsSaved(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'action' => 'article.show',
            'settings' => '{broken',
        ]));

        try {
            $module->callApi($this->makeApiPage('admin.pages', ['GET', 'POST']), []);
            $this->fail('invalid settings JSON should have been refused');
        } catch (ValidationException) {
            $this->assertSame([], $this->writes);
        }
    }

    public function testSettingsListReturnsKeyValueRowsForTheAdminEditor(): void
    {
        $module = $this->makeModule(settings: [
            'locale' => 'ru',
            'site_name' => 'Stream Engine',
        ]);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->callAndDecode($module, $this->makeApiPage('admin.settings', ['GET', 'POST']));

        $this->assertSame('locale', $response['data'][0]['key']);
        $this->assertSame('ru', $response['data'][0]['value']);
        $this->assertSame('site_name', $response['data'][1]['key']);
    }

    public function testCreatingASettingRequiresCsrfBeforeValidation(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);
        PhpInputStreamMock::register(json_encode(['key' => 'site_name', 'value' => 'Site']));

        $this->expectException(ValidationException::class);

        $module->callApi($this->makeApiPage('admin.settings', ['GET', 'POST']), []);
    }

    public function testUpdatingASettingRequiresCsrfBeforeSaving(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);
        PhpInputStreamMock::register(json_encode(['value' => 'Site']));

        $this->expectException(ValidationException::class);

        $module->callApi($this->makeApiPage('admin.setting', ['GET', 'PATCH']), ['key' => 'site_name']);
    }

    public function testAValidSettingCreateIsSavedAndReturned(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode(['key' => 'site_name', 'value' => 'Stream Engine']));

        $response = $this->callAndDecode($module, $this->makeApiPage('admin.settings', ['GET', 'POST']));

        $this->assertSame('site_name', $response['key']);
        $this->assertSame('Stream Engine', $response['value']);
        $this->assertSame(['site_name', 'Stream Engine', 'Stream Engine'], $this->writes[0][1]);
    }

    public function testAValidSettingUpdateIsSavedUnderTheRouteKey(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode(['key' => 'ignored', 'value' => 'New name']));

        $response = $this->callAndDecode(
            $module,
            $this->makeApiPage('admin.setting', ['GET', 'PATCH']),
            ['key' => 'site_name']
        );

        $this->assertSame('site_name', $response['key']);
        $this->assertSame('New name', $response['value']);
        $this->assertSame(['site_name', 'New name', 'New name'], $this->writes[0][1]);
    }

    public function testAnInvalidSettingKeyIsRefusedAndNothingIsSaved(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode(['key' => 'bad key', 'value' => 'Site']));

        try {
            $module->callApi($this->makeApiPage('admin.settings', ['GET', 'POST']), []);
            $this->fail('invalid setting key should have been refused');
        } catch (ValidationException) {
            $this->assertSame([], $this->writes);
        }
    }

    /* ===============================
       show()
    =============================== */

    public function testTheAdminPageIsRefusedToNonAdmins(): void
    {
        $module = $this->makeModule(isAdmin: false);

        // The Admin role gets you past canAccessPage(); isAdmin() is the
        // stricter inner gate, and it is what this page actually asks.
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Forbidden');

        $module->show($this->makeApiPage('admin.index', ['GET']));
    }

    public function testTheAdminPageRendersTheSpaShellForAnAdmin(): void
    {
        $view = $this->makeModule()->show($this->makeApiPage('admin.index', ['GET']));

        $this->assertNotNull($view);
        $this->assertSame('modules/admin/page.twig', $view->template);
    }

    public function testEveryPagesEditorEndpointIsRegisteredAsAdminOnly(): void
    {
        $tree = new PageTree([]);
        AdminController::registerApi(10, $tree);

        foreach ([
            'admin.pages' => ['GET', 'POST'],
            'admin.page' => ['GET', 'PATCH'],
            'admin.settings' => ['GET', 'POST'],
            'admin.setting' => ['GET', 'PATCH'],
        ] as $action => $methods) {
            $page = $tree->findByAction($action);

            $this->assertNotNull($page, $action.' is not registered');
            $this->assertSame(AccessService::ACCESS_ADMIN, $page->accessRule, $action.' is not admin-only');
            $this->assertSame($methods, $page->requestMethods, $action.' accepts the wrong methods');
        }
    }

    public function testPagesEditorEndpointsResolveThroughTheRouter(): void
    {
        $tree = new PageTree([
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
                responseType: 'html',
                accessRule: AccessService::ACCESS_PUBLIC,
                action: 'home',
            ),
            Page::api(id: 10, parentId: 1, pattern: 'api', requestMethods: ['GET']),
            Page::api(id: 11, parentId: 10, pattern: 'v1', requestMethods: ['GET']),
        ]);
        AdminController::registerApi(11, $tree);

        $list = (new Router($tree))->resolve('/api/v1/admin/pages');
        $item = (new Router($tree))->resolve('/api/v1/admin/pages/42');
        $settings = (new Router($tree))->resolve('/api/v1/admin/settings');
        $setting = (new Router($tree))->resolve('/api/v1/admin/settings/site_name');

        $this->assertSame('admin.pages', $list['page']->action ?? null);
        $this->assertSame('admin.page', $item['page']->action ?? null);
        $this->assertSame(['id' => '42'], $item['params']);
        $this->assertSame('admin.settings', $settings['page']->action ?? null);
        $this->assertSame('admin.setting', $setting['page']->action ?? null);
        $this->assertSame(['key' => 'site_name'], $setting['params']);
    }
}
