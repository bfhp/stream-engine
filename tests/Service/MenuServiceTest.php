<?php

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\MenuItem;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\MenuService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

final class MenuServiceTest extends TestCase
{
    private MenuService $service;

    // Page IDs
    private const ROOT_PAGE = 1;
    private const BLOG_PAGE = 2;
    private const POST_PAGE = 3;
    private const USER_PAGE = 4;

    // Menu item IDs
    private const ROOT_ITEM = 100;
    private const CHILD_ITEM = 101;
    private const FOOTER_ITEM = 200;
    private const RESTRICTED_ITEM = 300;
    private const EXTERNAL_ITEM = 400;

    private const GROUP_HEADER = 'header';
    private const GROUP_FOOTER = 'footer';

    private static function makePage(
        int    $id,
        ?int   $parentId,
        string $pattern,
        ?string $action = null
    ): Page {
        return new Page(
            id: $id,
            parentId: $parentId,
            pattern: $pattern,
            pageName: 'Page '.$id,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'raw',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action
        );
    }

    private static function makeMenuItem(
        int     $id,
        ?int    $parentId,
        string  $group,
        ?int    $pageId,
        string $accessRule,
        ?string $url = null,
        string $type = 'internal'
    ): MenuItem {
        return new MenuItem(
            id: $id,
            parentId: $parentId,
            menuGroup: $group,
            type: $type,
            pageId: $pageId,
            url: $url,
            action: null,
            label: 'Item ' . $id,
            accessRule: $accessRule,
            sortOrder: 0,
        );
    }

    protected function setUp(): void
    {
        $pages = [
            self::makePage(self::ROOT_PAGE, null, ''),
            self::makePage(self::BLOG_PAGE, self::ROOT_PAGE, 'blog'),
            self::makePage(self::POST_PAGE, self::BLOG_PAGE, 'post'),
            self::makePage(self::USER_PAGE, self::ROOT_PAGE, 'users/{username}', 'user.show'),
        ];

        $pageTree = new PageTree($pages);

        $feedRepository = new FakeFeedRepository([]);

        $cache = new ArrayCache();

        $urlGenerator = new UrlGenerator($pageTree, $feedRepository, $cache);

        $this->service = new MenuService($urlGenerator, $pageTree);
    }

    public function testFiltersByGroup(): void
    {
        $items = [
            self::makeMenuItem(self::ROOT_ITEM, null, self::GROUP_HEADER, self::BLOG_PAGE, AccessService::ACCESS_PUBLIC),
            self::makeMenuItem(self::FOOTER_ITEM, null, self::GROUP_FOOTER, self::BLOG_PAGE, AccessService::ACCESS_PUBLIC),
        ];

        $result = $this->service->build($items, self::GROUP_HEADER, [], new User(0, ''));

        $this->assertCount(1, $result);
        $this->assertSame(self::ROOT_ITEM, $result[0]->id);
    }

    public function testFiltersByAccessRule(): void
    {
        $items = [
            self::makeMenuItem(self::ROOT_ITEM, null, self::GROUP_HEADER, self::BLOG_PAGE, AccessService::ACCESS_PUBLIC),
            self::makeMenuItem(self::RESTRICTED_ITEM, null, self::GROUP_HEADER, self::BLOG_PAGE, AccessService::ACCESS_ADMIN),
        ];

        $result = $this->service->build($items, self::GROUP_HEADER, [], new User(0, ''));

        $this->assertCount(1, $result);
        $this->assertSame(self::ROOT_ITEM, $result[0]->id);
    }

    public function testNavigationUsesTheSameAudienceRulesAsPages(): void
    {
        foreach ([
            [new User(0, ''), [1]],
            [new User(7, ''), [1, 2]],
            [new User(7, '', AccessService::ROLE_MODERATOR), [1, 2, 3]],
            [new User(7, '', AccessService::ROLE_ADMIN), [1, 2, 3, 4]],
        ] as [$user, $expected]) {
            $items = [];
            foreach (AccessService::ACCESS_RULES as $id => $rule) {
                $items[] = self::makeMenuItem($id + 1, null, self::GROUP_HEADER, self::BLOG_PAGE, $rule);
            }
            $visible = $this->service->build($items, self::GROUP_HEADER, [], $user);
            $this->assertSame($expected, array_map(static fn (MenuItem $item): int => $item->id, $visible));
        }
    }

    public function testBuildsTreeStructure(): void
    {
        $items = [
            self::makeMenuItem(self::ROOT_ITEM, null, self::GROUP_HEADER, self::BLOG_PAGE, AccessService::ACCESS_PUBLIC),
            self::makeMenuItem(self::CHILD_ITEM, self::ROOT_ITEM, self::GROUP_HEADER, self::POST_PAGE, AccessService::ACCESS_PUBLIC),
        ];

        $result = $this->service->build($items, self::GROUP_HEADER, [], new User(0, ''));

        $this->assertCount(1, $result);
        $this->assertCount(1, $result[0]->children);
        $this->assertSame(self::CHILD_ITEM, $result[0]->children[0]->id);
    }

    public function testMarksActiveItem(): void
    {
        $items = [
            self::makeMenuItem(self::ROOT_ITEM, null, self::GROUP_HEADER, self::BLOG_PAGE, AccessService::ACCESS_PUBLIC),
            self::makeMenuItem(self::CHILD_ITEM, self::ROOT_ITEM, self::GROUP_HEADER, self::POST_PAGE, AccessService::ACCESS_PUBLIC),
        ];

        $breadcrumbs = [
            self::makePage(self::ROOT_PAGE, null, ''),
            self::makePage(self::BLOG_PAGE, self::ROOT_PAGE, 'blog'),
            self::makePage(self::POST_PAGE, self::BLOG_PAGE, 'post'),
        ];

        $result = $this->service->build($items, self::GROUP_HEADER, $breadcrumbs, new User(0, ''));

        $this->assertTrue($result[0]->children[0]->active);
        $this->assertTrue($result[0]->active);
    }

    public function testResolvesCanonicalUrl(): void
    {
        $items = [
            self::makeMenuItem(self::ROOT_ITEM, null, self::GROUP_HEADER, self::BLOG_PAGE, AccessService::ACCESS_PUBLIC),
        ];

        $result = $this->service->build($items, self::GROUP_HEADER, [], new User(0, ''));

        $this->assertSame('/blog/', $result[0]->url);
    }

    public function testExternalUrlIsPreserved(): void
    {
        $items = [
            self::makeMenuItem(
                self::EXTERNAL_ITEM,
                null,
                self::GROUP_HEADER,
                null,
                AccessService::ACCESS_PUBLIC,
                'https://example.com'
            ),
        ];

        $result = $this->service->build($items, self::GROUP_HEADER, [], new User(0, ''));

        $this->assertSame('https://example.com', $result[0]->url);
    }

    public function testInactiveWhenNoBreadcrumbMatch(): void
    {
        $items = [
            self::makeMenuItem(self::ROOT_ITEM, null, self::GROUP_HEADER, self::BLOG_PAGE, AccessService::ACCESS_PUBLIC),
        ];

        $result = $this->service->build($items, self::GROUP_HEADER, [], new User(0, ''));

        $this->assertFalse($result[0]->active);
    }

    public function testResolvesCurrentUserDynamicUrlFromUsernamePlaceholder(): void
    {
        $items = [
            self::makeMenuItem(
                self::ROOT_ITEM,
                null,
                self::GROUP_HEADER,
                self::USER_PAGE,
                AccessService::ACCESS_AUTHENTICATED,
                type: 'dynamic'
            ),
        ];

        $result = $this->service->build(
            $items,
            self::GROUP_HEADER,
            [],
            new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42')
        );

        $this->assertCount(1, $result);
        $this->assertSame('/users/nicky42/', $result[0]->url);
    }

    public function testDynamicUrlDoesNotRequirePageAction(): void
    {
        $pageTree = new PageTree([
            self::makePage(self::ROOT_PAGE, null, ''),
            self::makePage(self::USER_PAGE, self::ROOT_PAGE, 'users/{username}'),
        ]);
        $service = new MenuService(
            new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache()),
            $pageTree
        );
        $items = [
            self::makeMenuItem(
                self::ROOT_ITEM,
                null,
                self::GROUP_HEADER,
                self::USER_PAGE,
                AccessService::ACCESS_AUTHENTICATED,
                type: 'dynamic'
            ),
        ];

        $result = $service->build(
            $items,
            self::GROUP_HEADER,
            [],
            new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42')
        );

        $this->assertCount(1, $result);
        $this->assertSame('/users/nicky42/', $result[0]->url);
    }

    public function testSkipsUnresolvableDynamicLeaf(): void
    {
        $items = [
            self::makeMenuItem(
                self::ROOT_ITEM,
                null,
                self::GROUP_HEADER,
                self::USER_PAGE,
                AccessService::ACCESS_AUTHENTICATED,
                type: 'dynamic'
            ),
        ];

        $result = $this->service->build($items, self::GROUP_HEADER, [], new User(7, ''));

        $this->assertSame([], $result);
    }
}
