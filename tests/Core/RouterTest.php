<?php

namespace Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\Router;
use StreamEngine\Domain\Page;
use StreamEngine\Service\AccessService;

class RouterTest extends TestCase
{
    protected static array $testPages;
    protected Router $router;

    /**
     * Create fake Page instance for testing.
     */
    private static function makeFakePage(
        int $id,
        ?int $parentId = null,
        string $pattern = '',
        ?string $feedType = null,
    ): Page {
        return new Page(
            id: $id,
            parentId: $parentId,
            pattern: $pattern,
            pageName: 'Page '.$id,
            settings: null,
            feedType: $feedType,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'raw',
            accessRule: AccessService::ACCESS_PUBLIC
        );
    }

    private const ROOT = 1;
    private const TEST = 2;
    private const TEST2 = 3;
    private const SUB_PAGE = 4;
    private const LEAF = 5;
    private const DYNAMIC = 6;

    public static function setUpBeforeClass(): void
    {
        self::$testPages = [
            self::makeFakePage(self::ROOT),
            self::makeFakePage(self::TEST, self::ROOT, 'test'),
            self::makeFakePage(self::TEST2, self::ROOT, 'test2'),
            self::makeFakePage(self::SUB_PAGE, self::TEST, 'sub-page'),
            self::makeFakePage(self::LEAF, self::SUB_PAGE, '123'),
            self::makeFakePage(self::DYNAMIC, self::ROOT, '{id}'),
        ];
    }

    public static function routeProvider(): array
    {
        return [
            'root' => ['/', self::ROOT],
            'first level' => ['/test/', self::TEST],
            'first level 2' => ['/test2/', self::TEST2],
            'second level' => ['/test/sub-page/', self::SUB_PAGE],
            'third level' => ['/test/sub-page/123/', self::LEAF],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pageTree = new PageTree(self::$testPages);
        $this->router = new Router($pageTree);
    }

    public function testBreadcrumbsAreBuiltCorrectly(): void
    {
        $result = $this->router->resolve('/test/sub-page/123/');

        $this->assertNotNull($result);
        $this->assertCount(4, $result['breadcrumbs']);

        $ids = array_map(fn ($p) => $p->id, $result['breadcrumbs']);

        $this->assertSame([self::ROOT, self::TEST, self::SUB_PAGE, self::LEAF], $ids);
    }

    #[DataProvider('routeProvider')]
    public function testRouteMatchesCorrectly(string $path, int $expectedId): void
    {
        $pageData = $this->router->resolve($path);
        $this->assertEquals($expectedId, $pageData['page']->id);
    }

    public function testDynamicRouteMatches(): void
    {
        $result = $this->router->resolve('/123/');
        $this->assertNotNull($result);
        $this->assertSame('123', $result['params']['id']);
    }

    public function testStaticHasPriorityOverDynamic(): void
    {
        $result = $this->router->resolve('/test/');
        $this->assertSame(self::TEST, $result['page']->id);
    }

    public function test404()
    {
        $pageData = $this->router->resolve('/test2/some-not-exist-page');
        $this->assertNull($pageData);
    }

    public function testEmptyRootReturnsNullIfNoRootPage(): void
    {
        $pageTree = new PageTree([]);
        $router = new Router($pageTree);
        $this->assertNull($router->resolve('/'));
    }

    public function testRouteWithoutTrailingSlash(): void
    {
        $result = $this->router->resolve('/test');
        $this->assertSame(self::TEST, $result['page']->id);
    }

    public function testDynamicRouteWithRegex(): void
    {
        $pageTree = new PageTree([
            self::makeFakePage(1),
            self::makeFakePage(7, 1, '{id:\d+}')
        ]);

        $router = new Router($pageTree);

        $this->assertNotNull($router->resolve('/123/'));
        $this->assertNull($router->resolve('/abc/'));
    }

    public function testNestedDynamicRoutesCanUseDifferentParamNames(): void
    {
        $pageTree = new PageTree([
            self::makeFakePage(1),
            self::makeFakePage(2, 1, 'books'),
            self::makeFakePage(3, 2, '{slug}'),
            self::makeFakePage(4, 3, '{pageId}'),
        ]);

        $router = new Router($pageTree);
        $result = $router->resolve('/books/tajiny_kazahskih_shamanov/2/');

        $this->assertNotNull($result);
        $this->assertSame(4, $result['page']->id);
        $this->assertSame('tajiny_kazahskih_shamanov', $result['params']['slug']);
        $this->assertSame('2', $result['params']['pageId']);
        $this->assertSame(['pageId' => '2'], $result['page']->params);
        $this->assertSame(['slug' => 'tajiny_kazahskih_shamanov'], $pageTree->get(3)->params);
    }

    public function testNestedFeedRoutesCanReuseSlugParamName(): void
    {
        $pageTree = new PageTree([
            self::makeFakePage(1),
            self::makeFakePage(2, 1, 'books'),
            self::makeFakePage(3, 2, '{slug}', 'publication'),
            self::makeFakePage(4, 3, '{slug}', 'chapter'),
        ]);

        $router = new Router($pageTree);
        $result = $router->resolve('/books/tajiny_kazahskih_shamanov/2/');

        $this->assertNotNull($result);
        $this->assertSame(4, $result['page']->id);
        $this->assertSame('2', $result['params']['slug']);
        $this->assertSame(['slug' => '2'], $result['page']->params);
        $this->assertSame(['slug' => 'tajiny_kazahskih_shamanov'], $pageTree->get(3)->params);
        $this->assertSame(['slug' => '2'], $pageTree->get(4)->params);
        $this->assertSame(['slug' => 'tajiny_kazahskih_shamanov'], $result['breadcrumbs'][2]->params);
    }

    public function testResolveDoesNotLeakParamsIntoUnrelatedSubsequentCall(): void
    {
        $pageTree = new PageTree([
            self::makeFakePage(1),
            self::makeFakePage(2, 1, 'books'),
            self::makeFakePage(3, 2, '{slug}', 'publication'),
            self::makeFakePage(4, 1, 'authors'),
            self::makeFakePage(5, 4, '{code}', 'author-page'),
        ]);

        $router = new Router($pageTree);

        $first = $router->resolve('/books/foo/');
        $this->assertNotNull($first);
        $this->assertSame(['slug' => 'foo'], $first['params']);

        // Second call resolves a completely different dynamic page.
        // Its params must reflect only its own match, not the previous call's data.
        $second = $router->resolve('/authors/99/');
        $this->assertNotNull($second);
        $this->assertSame(['code' => '99'], $second['params']);
        $this->assertArrayNotHasKey('slug', $second['params']);

        // The unrelated page from the first call is untouched by the second call.
        $this->assertSame(['slug' => 'foo'], $pageTree->get(3)->params);
        $this->assertSame(['code' => '99'], $pageTree->get(5)->params);
    }

    public function testResolveOverwritesRatherThanMergesParamsOnRepeatedCalls(): void
    {
        $pageTree = new PageTree([
            self::makeFakePage(1),
            self::makeFakePage(2, 1, 'books'),
            self::makeFakePage(3, 2, '{slug}', 'publication'),
        ]);

        $router = new Router($pageTree);

        $first = $router->resolve('/books/foo/');
        $this->assertNotNull($first);
        $this->assertSame(['slug' => 'foo'], $first['params']);
        $this->assertSame(['slug' => 'foo'], $pageTree->get(3)->params);

        $second = $router->resolve('/books/bar/');
        $this->assertNotNull($second);

        // The second resolution must be a clean overwrite: no leftover 'foo'
        // value, and the params array must contain exactly the new match.
        $this->assertSame(['slug' => 'bar'], $second['params']);
        $this->assertSame(['slug' => 'bar'], $second['page']->params);
        $this->assertSame(['slug' => 'bar'], $pageTree->get(3)->params);

        // Each resolve() call produces a fresh Page instance rather than
        // mutating the object returned by a previous call.
        $this->assertNotSame($first['page'], $second['page']);
        $this->assertSame(['slug' => 'foo'], $first['page']->params);
    }
}
