<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Router;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\ThemeCatalog;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Admin\AdminController;
use StreamEngine\Service\AccessService;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Service\SettingsService;
use StreamEngine\Service\ThemeService;
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
        $_GET = [];

        $this->writes = [];
    }

    /**
     * @param array<string, string> $settings seeded settings rows
     * @param list<array<string, mixed>> $rows what every other SELECT returns
     * @param array<int, string> $feedTypes feed id => type
     */
    private function makeModule(
        bool $isAdmin = true,
        array $settings = [],
        array $rows = [],
        ?array $row = null,
        int $lastInsertId = 77,
        ?array $fetchOneRows = null,
        array $feedTypes = [],
        int $currentUserId = 1,
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
        $fetchOneIndex = 0;
        $db->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params = []) use (
                $fetchOneRows,
                &$fetchOneIndex,
                $feedTypes,
                $row
            ): ?array {
                if (str_contains($sql, 'FROM feeds')) {
                    $id = (int) ($params[0] ?? 0);

                    return isset($feedTypes[$id]) ? ['type' => $feedTypes[$id]] : null;
                }

                return $fetchOneRows !== null
                    ? ($fetchOneRows[$fetchOneIndex++] ?? null)
                    : $row;
            }
        );
        $db->method('lastInsertId')->willReturn($lastInsertId);
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->writes[] = [$sql, $params];

                return 1;
            }
        );

        $accessService = $this->createStub(AccessService::class);
        $accessService->method('isAdmin')->willReturn($isAdmin);
        $settingsRepository = new SettingsRepository($db);
        $themeService = new ThemeService(
            new ThemeCatalog(
                dirname(__DIR__, 3).'/views/themes',
                dirname(__DIR__, 3).'/public',
            ),
            new SettingsService($settingsRepository),
            $settingsRepository,
        );

        return new AdminController(
            $db,
            new RequestContext(
                new User(id: $currentUserId, email: 'admin@example.com', role: AccessService::ROLE_ADMIN),
                new DateTimeZone('UTC')
            ),
            $accessService,
            new TranslationManager('en', 'en'),
            new ModuleRegistry(),
            $themeService,
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

    public function testPageActionsReturnsActionsExportedByModules(): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->callAndDecode(
            $module,
            $this->makeApiPage('admin.page-actions', ['GET'])
        );

        $adminAction = array_values(array_filter(
            $response['data'],
            static fn (array $item): bool => $item['action'] === 'admin.index'
        ));

        $this->assertCount(1, $adminAction);
        $this->assertSame('Administrator interface', $adminAction[0]['label']);
        $this->assertSame('Admin', $adminAction[0]['module']);
        $this->assertSame([], $adminAction[0]['requirements']);
        $this->assertSame([
            'feedId' => ['status' => 'unsupported'],
            'feedType' => ['status' => 'unsupported'],
            'listFeedType' => ['status' => 'unsupported'],
            'termVocabulary' => ['status' => 'unsupported'],
        ], $adminAction[0]['fields']);
    }

    public function testCreatingAPageRequiresCsrfBeforeValidation(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);
        PhpInputStreamMock::register(json_encode(['action' => 'article.show-slug']));

        $this->expectException(ValidationException::class);

        $module->callApi($this->makeApiPage('admin.pages', ['GET', 'POST']), []);
    }

    public function testUpdatingAPageRequiresCsrfBeforeSaving(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);
        PhpInputStreamMock::register(json_encode(['action' => 'article.show-slug']));

        $this->expectException(ValidationException::class);

        $module->callApi($this->makeApiPage('admin.page', ['GET', 'PATCH']), ['id' => 5]);
    }

    #[DataProvider('invalidAccessRules')]
    public function testPageWritesRejectInvalidOrMissingAccessRule(mixed $rule): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode(['action' => 'article.show-slug', 'accessRule' => $rule]));

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
        $parent = $this->legacyPageRow();
        $parent['id'] = 1;
        $parent['parent'] = null;
        $module = $this->makeModule(rows: [$parent], lastInsertId: 77, feedTypes: [42 => 'article']);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'parentId' => 1,
            'pattern' => 'about',
            'action' => 'article.show-id',
            'pageName' => 'About',
            'feedId' => 42,
            'updated' => 123,
            'accessRule' => 'public',
            'settings' => '{"template":"about","commentsEnabled":true}',
        ]));

        $response = $this->callAndDecode($module, $this->makeApiPage('admin.pages', ['GET', 'POST']));

        $this->assertSame(77, $response['id']);
        $this->assertSame('article.show-id', $response['action']);
        $this->assertSame('about', $this->writes[0][1][1]);
        $this->assertSame('{"template":"about","commentsEnabled":true}', $this->writes[0][1][4]);
        $this->assertStringContainsString('UNIX_TIMESTAMP()', $this->writes[0][0]);
        $this->assertNotContains(123, $this->writes[0][1]);
    }

    public function testPageCannotBeMovedBelowItsDescendant(): void
    {
        $current = $this->legacyPageRow();
        $current['id'] = 10;
        $current['parent'] = null;
        $descendant = $this->legacyPageRow();
        $descendant['id'] = 11;
        $descendant['parent'] = 10;
        $module = $this->makeModule(rows: [$current, $descendant], row: $current);

        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'parentId' => 11,
            'action' => 'feedback.show',
            'accessRule' => 'public',
        ]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('descendant');

        $module->callApi($this->makeApiPage('admin.page', ['GET', 'PATCH']), ['id' => 10]);
    }

    public function testPageCannotBeItsOwnParent(): void
    {
        $current = $this->legacyPageRow();
        $current['id'] = 10;
        $module = $this->makeModule(row: $current);

        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'parentId' => 10,
            'action' => 'feedback.show',
            'accessRule' => 'public',
        ]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('own parent');

        $module->callApi($this->makeApiPage('admin.page', ['GET', 'PATCH']), ['id' => 10]);
    }

    #[DataProvider('invalidRootPageConfigurations')]
    public function testRootPageCannotHaveAParentOrPattern(array $input): void
    {
        $current = $this->legacyPageRow();
        $current['id'] = 1;
        $current['parent'] = null;
        $current['pattern'] = '';
        $module = $this->makeModule(row: $current);

        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode($input + [
            'action' => 'feedback.show',
            'accessRule' => 'public',
        ]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('root page');

        $module->callApi($this->makeApiPage('admin.page', ['GET', 'PATCH']), ['id' => 1]);
    }

    public static function invalidRootPageConfigurations(): array
    {
        return [
            'parent' => [['parentId' => 2]],
            'pattern' => [['pattern' => 'home']],
        ];
    }

    public function testPageCreateRejectsAnUnknownAction(): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'action' => 'removed.show',
            'accessRule' => 'public',
        ]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unknown page action 'removed.show'");

        $module->callApi($this->makeApiPage('admin.pages', ['GET', 'POST']));
    }

    #[DataProvider('invalidPageActionConfigurations')]
    public function testPageCreateValidatesTheSelectedActionContract(array $input, string $message): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode($input + ['accessRule' => 'public']));

        try {
            $module->callApi($this->makeApiPage('admin.pages', ['GET', 'POST']));
            $this->fail('Invalid page action configuration was saved.');
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame([], $this->writes);
        }
    }

    public static function invalidPageActionConfigurations(): array
    {
        return [
            'required field' => [
                ['action' => 'sections.list'],
                "Feed ID is required for page action 'sections.list'",
            ],
            'unsupported field' => [
                ['action' => 'admin.index', 'feedId' => 12],
                "Feed ID is not supported by page action 'admin.index'",
            ],
            'constrained value' => [
                ['action' => 'articles.list', 'feedType' => 'forum', 'listFeedType' => 'article'],
                "Invalid Feed type for page action 'articles.list'",
            ],
            'slug action requires a feed type' => [
                ['action' => 'article.show-slug'],
                "Feed type is required for page action 'article.show-slug'",
            ],
            'id action requires a feed id' => [
                ['action' => 'article.show-id'],
                "Feed ID is required for page action 'article.show-id'",
            ],
        ];
    }

    public function testPageCreateValidatesAReferencedFeedType(): void
    {
        $module = $this->makeModule(feedTypes: [42 => 'forum']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'action' => 'article.show-id',
            'feedId' => 42,
            'accessRule' => 'public',
        ]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Feed ID 42 has an invalid type for page action 'article.show-id'");

        $module->callApi($this->makeApiPage('admin.pages', ['GET', 'POST']));
    }

    public function testPageCreateRejectsAMissingReferencedFeed(): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'action' => 'sections.list',
            'feedId' => 404,
            'accessRule' => 'public',
        ]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Feed ID 404 does not exist');

        $module->callApi($this->makeApiPage('admin.pages', ['GET', 'POST']));
    }

    public function testUnknownLegacyActionCanBePreservedWithoutChangingItsConfiguration(): void
    {
        $row = $this->legacyPageRow();
        $module = $this->makeModule(fetchOneRows: [$row, $row]);
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'pattern' => 'legacy-new-path',
            'action' => 'removed.show',
            'pageName' => 'Legacy page',
            'feedType' => 'legacy-feed',
            'feedId' => 8,
            'accessRule' => 'public',
        ]));

        $this->callAndDecode($module, $this->makeApiPage('admin.page', ['GET', 'PATCH']), ['id' => 9]);

        $this->assertSame('legacy-new-path', $this->writes[0][1][1]);
    }

    public function testUnknownLegacyActionConfigurationCannotBeChanged(): void
    {
        $module = $this->makeModule(fetchOneRows: [$this->legacyPageRow()]);
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'action' => 'removed.show',
            'feedType' => 'different-feed',
            'feedId' => 8,
            'accessRule' => 'public',
        ]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(
            "Configuration fields of unknown page action 'removed.show' cannot be changed"
        );

        $module->callApi($this->makeApiPage('admin.page', ['GET', 'PATCH']), ['id' => 9]);
    }

    /** @return array<string, mixed> */
    private function legacyPageRow(): array
    {
        return [
            'id' => 9,
            'parent' => 1,
            'pattern' => 'legacy',
            'action' => 'removed.show',
            'page_name' => 'Legacy page',
            'settings' => null,
            'feed_type' => 'legacy-feed',
            'list_feed_type' => null,
            'term_vocabulary' => null,
            'feed_id' => 8,
            'changefreq' => null,
            'updated' => 123,
            'access_rule' => 'public',
        ];
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
        $this->assertStringContainsString('updated = UNIX_TIMESTAMP()', $this->writes[0][0]);
        $this->assertNotContains(987, $this->writes[0][1]);
        $this->assertSame('admin', $this->writes[0][1][10]);
        $this->assertSame(9, $this->writes[0][1][11]);
    }

    public function testInvalidPageSettingsJsonIsRefusedAndNothingIsSaved(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'action' => 'article.show-slug',
            'settings' => '{broken',
        ]));

        try {
            $module->callApi($this->makeApiPage('admin.pages', ['GET', 'POST']), []);
            $this->fail('invalid settings JSON should have been refused');
        } catch (ValidationException) {
            $this->assertSame([], $this->writes);
        }
    }

    /* ===============================
       Menu editor API
    =============================== */

    public function testMenusListReturnsEditableRows(): void
    {
        $module = $this->makeModule(rows: [[
            'id' => 8,
            'parent' => null,
            'menu_group' => 'main',
            'type' => 'external',
            'page_id' => null,
            'url' => 'https://example.com',
            'action' => null,
            'label' => 'Example',
            'access_rule' => 'public',
            'sort_order' => 20,
        ]]);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $response = $this->callAndDecode($module, $this->makeApiPage('admin.menus', ['GET', 'POST']));

        $this->assertSame(8, $response['data'][0]['id']);
        $this->assertSame('main', $response['data'][0]['menuGroup']);
        $this->assertSame('https://example.com', $response['data'][0]['url']);
        $this->assertSame(20, $response['data'][0]['sortOrder']);
    }

    public function testAValidMenuItemIsCreatedWithNormalizedFields(): void
    {
        $module = $this->makeModule(lastInsertId: 81);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'menuGroup' => 'bottom',
            'type' => 'external',
            'url' => 'https://example.com/help',
            'pageId' => 42,
            'action' => 'ignored',
            'label' => 'Help',
            'accessRule' => 'public',
            'sortOrder' => 30,
        ]));

        $response = $this->callAndDecode($module, $this->makeApiPage('admin.menus', ['GET', 'POST']));

        $this->assertSame(81, $response['id']);
        $this->assertNull($response['pageId']);
        $this->assertNull($response['action']);
        $this->assertSame('https://example.com/help', $this->writes[0][1][4]);
    }

    public function testMenuPageReferenceMustExist(): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'menuGroup' => 'main',
            'type' => 'internal',
            'pageId' => 999,
            'label' => 'Missing',
            'accessRule' => 'public',
            'sortOrder' => 10,
            'enabled' => true,
        ]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Referenced page does not exist');
        $module->callApi($this->makeApiPage('admin.menus', ['GET', 'POST', 'PATCH']));
    }

    public function testUnknownMenuActionIsRejected(): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'menuGroup' => 'main',
            'type' => 'action',
            'action' => 'does-not-exist',
            'label' => 'Broken',
            'accessRule' => 'public',
            'sortOrder' => 10,
            'enabled' => true,
        ]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown menu action');
        $module->callApi($this->makeApiPage('admin.menus', ['GET', 'POST', 'PATCH']));
    }

    public function testCompleteTreeOrderIsSavedAsOneBatch(): void
    {
        $first = [
            'id' => 1, 'parent' => null, 'menu_group' => 'main', 'type' => 'divider',
            'page_id' => null, 'url' => null, 'action' => null, 'label' => null,
            'access_rule' => 'public', 'sort_order' => 10, 'group_order' => 10, 'enabled' => 1,
        ];
        $second = $first;
        $second['id'] = 2;
        $second['sort_order'] = 20;
        $module = $this->makeModule(rows: [$first, $second]);
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'groups' => ['main'],
            'items' => [
                ['id' => 2, 'parentId' => null, 'menuGroup' => 'main'],
                ['id' => 1, 'parentId' => 2, 'menuGroup' => 'main'],
            ],
        ]));

        $this->callAndDecode($module, $this->makeApiPage('admin.menus', ['GET', 'POST', 'PATCH']));

        $this->assertCount(4, $this->writes);
        $this->assertSame([null, 'main', 10, 10, 2], $this->writes[2][1]);
        $this->assertSame([2, 'main', 10, 10, 1], $this->writes[3][1]);
    }

    public function testPreviewOmitsDisabledItemsAndBuildsTheTree(): void
    {
        $parent = [
            'id' => 1, 'parent' => null, 'menu_group' => 'main', 'type' => 'divider',
            'page_id' => null, 'url' => null, 'action' => null, 'label' => null,
            'access_rule' => 'public', 'sort_order' => 10, 'group_order' => 10, 'enabled' => 1,
        ];
        $child = $parent;
        $child['id'] = 2;
        $child['parent'] = 1;
        $child['label'] = 'Child';
        $hidden = $parent;
        $hidden['id'] = 3;
        $hidden['enabled'] = 0;
        $module = $this->makeModule(rows: [$parent, $child, $hidden]);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->callAndDecode($module, $this->makeApiPage('admin.menu-preview', ['GET']));

        $this->assertSame('main', $response['groups'][0]['name']);
        $this->assertSame(1, $response['groups'][0]['items'][0]['id']);
        $this->assertSame(2, $response['groups'][0]['items'][0]['children'][0]['id']);
        $this->assertCount(1, $response['groups'][0]['items']);
    }

    public function testMenuItemCannotBeMovedBelowItsDescendant(): void
    {
        $current = [
            'id' => 1,
            'parent' => null,
            'menu_group' => 'main',
            'type' => 'internal',
            'page_id' => 4,
            'url' => null,
            'action' => null,
            'label' => 'Root',
            'access_rule' => 'public',
            'sort_order' => 10,
        ];
        $descendant = $current + [];
        $descendant['id'] = 2;
        $descendant['parent'] = 1;
        $descendant['label'] = 'Child';
        $descendant['sort_order'] = 20;
        $module = $this->makeModule(rows: [$current, $descendant], row: $current);

        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'parentId' => 2,
            'menuGroup' => 'main',
            'type' => 'internal',
            'pageId' => 4,
            'label' => 'Root',
            'accessRule' => 'public',
            'sortOrder' => 10,
        ]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('descendant');
        $module->callApi($this->makeApiPage('admin.menu', ['GET', 'PATCH', 'DELETE']), ['id' => 1]);
    }

    public function testMenuItemWithChildrenCannotBeDeleted(): void
    {
        $row = [
            'id' => 8,
            'parent' => null,
            'menu_group' => 'main',
            'type' => 'divider',
            'page_id' => null,
            'url' => null,
            'action' => null,
            'label' => null,
            'access_rule' => 'public',
            'sort_order' => 10,
        ];
        $module = $this->makeModule(fetchOneRows: [$row, ['id' => 9]]);

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $this->withValidCsrf();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('child menu items');
        $module->callApi($this->makeApiPage('admin.menu', ['GET', 'PATCH', 'DELETE']), ['id' => 8]);
    }

    public function testLeafMenuItemCanBeDeleted(): void
    {
        $row = [
            'id' => 8,
            'parent' => null,
            'menu_group' => 'main',
            'type' => 'divider',
            'page_id' => null,
            'url' => null,
            'action' => null,
            'label' => null,
            'access_rule' => 'public',
            'sort_order' => 10,
        ];
        $module = $this->makeModule(fetchOneRows: [$row, null]);

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $this->withValidCsrf();
        $response = $this->callAndDecode(
            $module,
            $this->makeApiPage('admin.menu', ['GET', 'PATCH', 'DELETE']),
            ['id' => 8]
        );

        $this->assertTrue($response['deleted']);
        $this->assertStringContainsString('DELETE FROM menu', $this->writes[0][0]);
        $this->assertSame([8], $this->writes[0][1]);
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

    public function testAnUnsupportedInterfaceLocaleIsRefused(): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode(['value' => 'zz']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unsupported interface locale');

        $module->callApi(
            $this->makeApiPage('admin.setting', ['GET', 'PATCH']),
            ['key' => 'locale']
        );
    }

    #[DataProvider('invalidDisplayFormatProvider')]
    public function testAnUnsupportedDisplayFormatIsRefused(string $key, string $message): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode(['value' => 'unsupported']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);

        $module->callApi(
            $this->makeApiPage('admin.setting', ['GET', 'PATCH']),
            ['key' => $key]
        );
    }

    public static function invalidDisplayFormatProvider(): array
    {
        return [
            ['date_format', 'Unsupported date format'],
            ['time_format', 'Unsupported time format'],
        ];
    }

    public function testThemeCatalogIsReturnedWithoutFilesystemPaths(): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->callAndDecode($module, $this->makeApiPage('admin.themes', ['GET', 'PATCH']));

        $this->assertSame('default', $response['activeId']);
        $this->assertSame('default', $response['themes'][0]['id']);
        $this->assertArrayNotHasKey('path', $response['themes'][0]);
    }

    public function testThemeUpdateRequiresCsrf(): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        PhpInputStreamMock::register(json_encode(['themeId' => 'default', 'settings' => []]));

        $this->expectException(ValidationException::class);

        $module->callApi($this->makeApiPage('admin.themes', ['GET', 'PATCH']), []);
    }

    public function testThemeNamespaceCannotBypassTheSchemaThroughGenericSettings(): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode(['value' => 'anything']));

        $this->expectException(ValidationException::class);

        $module->callApi(
            $this->makeApiPage('admin.setting', ['GET', 'PATCH']),
            ['key' => 'theme.default.undeclared']
        );
    }

    /* ===============================
       User management API
    =============================== */

    public function testUserListReturnsPrivateAdminRowsAndPagination(): void
    {
        $module = $this->makeModule(
            rows: [$this->adminUserRow()],
            fetchOneRows: [['total' => 1]],
            currentUserId: 9,
        );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['q' => 'alice', 'role' => 'moderator', 'status' => 'active', 'page' => '3'];

        $response = $this->callAndDecode($module, $this->makeApiPage('admin.users', ['GET']));

        $this->assertSame('alice@example.com', $response['data'][0]['email']);
        $this->assertTrue($response['data'][0]['isActive']);
        $this->assertSame(1, $response['pagination']['total']);
        $this->assertSame(1, $response['pagination']['currentPage']);
        $this->assertSame(9, $response['meta']['currentUserId']);
        $this->assertSame(User::SYSTEM_USER_ID, $response['meta']['systemUserId']);
    }

    public function testInvalidUserListFilterIsRejected(): void
    {
        $module = $this->makeModule();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['role' => 'owner'];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid role filter');
        $module->callApi($this->makeApiPage('admin.users', ['GET']));
    }

    public function testUserUpdateRequiresCsrfBeforeReadingOrSaving(): void
    {
        $module = $this->makeModule(fetchOneRows: [$this->adminUserRow()]);
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        PhpInputStreamMock::register('{}');

        try {
            $module->callApi($this->makeApiPage('admin.user', ['GET', 'PATCH']), ['id' => 7]);
            $this->fail('Missing CSRF token must be rejected.');
        } catch (ValidationException) {
            $this->assertSame([], $this->writes);
        }
    }

    public function testValidUserProfileAndRoleChangesAreSaved(): void
    {
        $module = $this->makeModule(
            fetchOneRows: [$this->adminUserRow(), null, null],
            currentUserId: 9,
        );
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode([
            'email' => 'new@example.com',
            'nick' => 'New name',
            'username' => 'new_name',
            'role' => 'moderator',
            'isActive' => true,
        ]));

        $response = $this->callAndDecode(
            $module,
            $this->makeApiPage('admin.user', ['GET', 'PATCH']),
            ['id' => 7],
        );

        $this->assertSame('new@example.com', $response['email']);
        $this->assertSame('moderator', $response['role']);
        $this->assertCount(1, $this->writes);
        $this->assertStringContainsString('UPDATE users', $this->writes[0][0]);
        $this->assertSame(['new@example.com', 'New name', 'new_name', 'moderator', 1, 7], $this->writes[0][1]);
    }

    public function testDeactivatingAUserRevokesExistingSessions(): void
    {
        $module = $this->makeModule(
            fetchOneRows: [$this->adminUserRow(), null, null],
            currentUserId: 9,
        );
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode(['isActive' => false]));

        $this->callAndDecode(
            $module,
            $this->makeApiPage('admin.user', ['GET', 'PATCH']),
            ['id' => 7],
        );

        $this->assertCount(2, $this->writes);
        $this->assertStringContainsString('DELETE FROM user_sessions', $this->writes[1][0]);
        $this->assertSame([7], $this->writes[1][1]);
    }

    public function testSystemAccountCannotBeChanged(): void
    {
        $system = $this->adminUserRow();
        $system['id'] = User::SYSTEM_USER_ID;
        $module = $this->makeModule(fetchOneRows: [$system], currentUserId: 9);
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode(['nick' => 'Changed']));

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('system account');
        $module->callApi(
            $this->makeApiPage('admin.user', ['GET', 'PATCH']),
            ['id' => User::SYSTEM_USER_ID],
        );
    }

    public function testAdministratorCannotChangeTheirOwnPrivileges(): void
    {
        $self = $this->adminUserRow();
        $self['role'] = 'admin';
        $module = $this->makeModule(fetchOneRows: [$self], currentUserId: 7);
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode(['role' => 'user']));

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('own role');
        $module->callApi($this->makeApiPage('admin.user', ['GET', 'PATCH']), ['id' => 7]);
    }

    public function testLastActiveAdministratorCannotBeDeactivated(): void
    {
        $administrator = $this->adminUserRow();
        $administrator['role'] = 'admin';
        $module = $this->makeModule(
            fetchOneRows: [$administrator, ['total' => 1]],
            currentUserId: 9,
        );
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode(['isActive' => false]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('last active administrator');
        $module->callApi($this->makeApiPage('admin.user', ['GET', 'PATCH']), ['id' => 7]);
    }

    #[DataProvider('invalidAdminUserData')]
    public function testInvalidUserChangesAreRejected(array $change): void
    {
        $module = $this->makeModule(fetchOneRows: [$this->adminUserRow()], currentUserId: 9);
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->withValidCsrf();
        PhpInputStreamMock::register(json_encode($change));

        try {
            $module->callApi($this->makeApiPage('admin.user', ['GET', 'PATCH']), ['id' => 7]);
            $this->fail('Invalid user data must be rejected.');
        } catch (ValidationException) {
            $this->assertSame([], $this->writes);
        }
    }

    public static function invalidAdminUserData(): array
    {
        return [
            'invalid email' => [['email' => 'not-an-email']],
            'invalid username' => [['username' => 'no spaces allowed']],
            'invalid role' => [['role' => 'owner']],
            'non-boolean status' => [['isActive' => 1]],
            'long display name' => [['nick' => str_repeat('x', 51)]],
        ];
    }

    public function testUserApiIsRefusedToNonAdminsEvenWhenTheRouteIsCalledDirectly(): void
    {
        $module = $this->makeModule(isAdmin: false);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->expectException(ForbiddenException::class);
        $module->callApi($this->makeApiPage('admin.users', ['GET']));
    }

    public function testDashboardReturnsCatalogLayoutAndIsolatedCardResults(): void
    {
        $module = $this->makeModule(fetchOneRows: [
            null,
            ['total' => 4, 'active' => 3, 'inactive' => 1],
        ], currentUserId: 9);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->callAndDecode($module, $this->makeApiPage('admin.dashboard', ['GET', 'PATCH', 'DELETE']));

        $this->assertSame(1, $response['version']);
        $this->assertContains('admin.users-summary', array_column($response['catalog'], 'id'));
        $this->assertContains('admin.shortcuts', array_column($response['layout']['items'], 'id'));
        $this->assertContains('ready', array_column($response['cards'], 'status'));
    }

    public function testOneDashboardCardCanBeFetchedIndependently(): void
    {
        $module = $this->makeModule(currentUserId: 9);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->callAndDecode(
            $module,
            $this->makeApiPage('admin.dashboard-card', ['GET']),
            ['id' => 'admin.shortcuts'],
        );

        $this->assertSame('admin.shortcuts', $response['id']);
        $this->assertSame('ready', $response['status']);
        $this->assertSame('#/users', $response['data'][0]['href']);
    }

    #[DataProvider('dashboardMutationMethods')]
    public function testDashboardMutationsRequireCsrf(string $method): void
    {
        $module = $this->makeModule(currentUserId: 9);
        $_SERVER['REQUEST_METHOD'] = $method;
        PhpInputStreamMock::register('{}');

        try {
            $module->callApi($this->makeApiPage('admin.dashboard', ['GET', 'PATCH', 'DELETE']));
            $this->fail('Dashboard mutation without CSRF was accepted.');
        } catch (ValidationException) {
            $this->assertSame([], $this->writes);
        }
    }

    public static function dashboardMutationMethods(): array
    {
        return [['PATCH'], ['DELETE']];
    }

    public function testRecentActivityCardCombinesAndSortsUsersAndContent(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturnCallback(static fn (string $sql): array => str_contains($sql, 'FROM users')
            ? [['id' => 7, 'nick' => 'Alice', 'username' => 'alice', 'created_at' => 100]]
            : [['id' => 12, 'title' => 'New post', 'type' => 'article', 'created_at' => 200]]);

        $card = AdminController::dashboardCardData(
            'admin.recent-activity',
            $db,
            new RequestContext(new User(9, 'admin@example.com', AccessService::ROLE_ADMIN), new DateTimeZone('UTC')),
        );

        $this->assertSame('ready', $card['status']);
        $this->assertSame(['feed-12', 'user-7'], array_column($card['data'], 'id'));
        $this->assertSame('#/feeds/12', $card['data'][0]['href']);
    }

    public function testModerationCardCountsPendingCommunityRequests(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['pending' => 3]);

        $card = AdminController::dashboardCardData(
            'admin.moderation-summary',
            $db,
            new RequestContext(new User(9, 'admin@example.com', AccessService::ROLE_ADMIN), new DateTimeZone('UTC')),
        );

        $this->assertSame('ready', $card['status']);
        $this->assertSame(3, $card['data'][0]['value']);
    }

    public function testSystemHealthCardReportsCronAndDeliveryProblems(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $rows = [
            ['tasks' => 4, 'last_run' => 1_700_000_000, 'stale_locks' => 1],
            ['pending' => 6, 'failed' => 2],
        ];
        $index = 0;
        $db->method('fetchOne')->willReturnCallback(static function () use (&$rows, &$index): array {
            return $rows[$index++];
        });

        $card = AdminController::dashboardCardData(
            'admin.system-health',
            $db,
            new RequestContext(new User(9, 'admin@example.com', AccessService::ROLE_ADMIN), new DateTimeZone('UTC')),
        );
        $metrics = array_column($card['data'], 'value', 'label');

        $this->assertSame('ready', $card['status']);
        $this->assertSame(1, $metrics['js.admin.dashboard.stale_locks']);
        $this->assertSame(2, $metrics['js.admin.dashboard.failed_deliveries']);
    }

    /** @return array<string, mixed> */
    private function adminUserRow(): array
    {
        return [
            'id' => 7,
            'email' => 'alice@example.com',
            'nick' => 'Alice',
            'username' => 'alice',
            'role' => 'user',
            'is_active' => 1,
            'created_at' => 1_700_000_000,
        ];
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
            'admin.page-actions' => ['GET'],
            'admin.menus' => ['GET', 'POST', 'PATCH'],
            'admin.menu' => ['GET', 'PATCH', 'DELETE'],
            'admin.menu-preview' => ['GET'],
            'admin.settings' => ['GET', 'POST'],
            'admin.setting' => ['GET', 'PATCH'],
            'admin.themes' => ['GET', 'PATCH'],
            'admin.users' => ['GET'],
            'admin.user' => ['GET', 'PATCH'],
            'admin.dashboard' => ['GET', 'PATCH', 'DELETE'],
            'admin.dashboard-card' => ['GET'],
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
        $pageActions = (new Router($tree))->resolve('/api/v1/admin/page-actions');
        $menus = (new Router($tree))->resolve('/api/v1/admin/menus');
        $menu = (new Router($tree))->resolve('/api/v1/admin/menus/8');
        $menuPreview = (new Router($tree))->resolve('/api/v1/admin/menus/preview');
        $settings = (new Router($tree))->resolve('/api/v1/admin/settings');
        $setting = (new Router($tree))->resolve('/api/v1/admin/settings/site_name');
        $themes = (new Router($tree))->resolve('/api/v1/admin/themes');
        $users = (new Router($tree))->resolve('/api/v1/admin/users');
        $user = (new Router($tree))->resolve('/api/v1/admin/users/42');
        $dashboard = (new Router($tree))->resolve('/api/v1/admin/dashboard');
        $dashboardCard = (new Router($tree))->resolve('/api/v1/admin/dashboard/cards/admin.system-health');

        $this->assertSame('admin.pages', $list['page']->action ?? null);
        $this->assertSame('admin.page', $item['page']->action ?? null);
        $this->assertSame(['id' => '42'], $item['params']);
        $this->assertSame('admin.page-actions', $pageActions['page']->action ?? null);
        $this->assertSame('admin.menus', $menus['page']->action ?? null);
        $this->assertSame('admin.menu', $menu['page']->action ?? null);
        $this->assertSame(['id' => '8'], $menu['params']);
        $this->assertSame('admin.menu-preview', $menuPreview['page']->action ?? null);
        $this->assertSame('admin.settings', $settings['page']->action ?? null);
        $this->assertSame('admin.setting', $setting['page']->action ?? null);
        $this->assertSame(['key' => 'site_name'], $setting['params']);
        $this->assertSame('admin.themes', $themes['page']->action ?? null);
        $this->assertSame('admin.users', $users['page']->action ?? null);
        $this->assertSame('admin.user', $user['page']->action ?? null);
        $this->assertSame(['id' => '42'], $user['params']);
        $this->assertSame('admin.dashboard', $dashboard['page']->action ?? null);
        $this->assertSame('admin.dashboard-card', $dashboardCard['page']->action ?? null);
        $this->assertSame(['id' => 'admin.system-health'], $dashboardCard['params']);
    }
}
