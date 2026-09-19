<?php

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\PageTree;
use StreamEngine\Domain\Page;
use StreamEngine\Service\AccessService;

class PageTreeTest extends TestCase
{
    private PageTree $tree;

    private static function makePage(
        int $id,
        ?int $parentId,
        string $pattern,
        ?string $termVocabulary = null,
        ?string $feedType = null,
        ?int $feedId = null,
        ?string $action = null
    ): Page {
        return new Page(
            id: $id,
            parentId: $parentId,
            pattern: $pattern,
            pageName: 'Page '.$id,
            settings: null,
            feedType: $feedType,
            listFeedType: null,
            feedId: $feedId,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'raw',
            accessRule: AccessService::ACCESS_PUBLIC,
            termVocabulary: $termVocabulary,
            action: $action
        );
    }

    protected function setUp(): void
    {
        $pages = [
            self::makePage(1, null, ''),          // root
            self::makePage(2, 1, 'blog'),
            self::makePage(3, 2, 'post'),
            self::makePage(4, 99, 'orphan'),      // broken parent
            self::makePage(5, 2, '{slug}', termVocabulary: 'author'),
            self::makePage(6, 2, ''),              // empty pattern in the middle of a chain
            self::makePage(7, 6, 'archive'),
            self::makePage(8, 1, 'gallery-page', feedType: 'gallery', feedId: 500),
            self::makePage(9, 8, 'sub', feedType: 'gallery-child'),
        ];

        $this->tree = new PageTree($pages);
    }

    public function testGetReturnsPageById(): void
    {
        $page = $this->tree->get(2);

        $this->assertNotNull($page);
        $this->assertSame(2, $page->id);
    }

    public function testGetReturnsNullIfNotExists(): void
    {
        $this->assertNull($this->tree->get(999));
    }

    public function testAllReturnsAllPages(): void
    {
        $all = $this->tree->all();

        $this->assertCount(9, $all);
    }

    public function testBuildPathForRoot(): void
    {
        $root = $this->tree->get(1);

        $this->assertSame('/', $this->tree->buildPath($root));
    }

    public function testBuildPathSingleLevel(): void
    {
        $page = $this->tree->get(2);

        $this->assertSame('/blog/', $this->tree->buildPath($page));
    }

    public function testBuildPathMultiLevel(): void
    {
        $page = $this->tree->get(3);

        $this->assertSame('/blog/post/', $this->tree->buildPath($page));
    }

    public function testBuildPathSkipsEmptyPatterns(): void
    {
        $page = $this->tree->get(7);

        $this->assertSame('/blog/archive/', $this->tree->buildPath($page));
    }

    public function testBuildPathHandlesMissingParentGracefully(): void
    {
        $orphan = $this->tree->get(4);

        // Since parent 99 does not exist,
        // path should contain only its own segment.
        $this->assertSame('/orphan/', $this->tree->buildPath($orphan));
    }

    public function testBuildPathFailsFastForAParentCycle(): void
    {
        $first = self::makePage(20, 21, 'first');
        $tree = new PageTree([$first, self::makePage(21, 20, 'second')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Page hierarchy cycle detected');

        $tree->buildPath($first);
    }

    public function testFindByTermVocabulary(): void
    {
        $page = $this->tree->findByTermVocabulary('author');

        $this->assertNotNull($page);
        $this->assertSame(5, $page->id);
    }

    public function testFindByFeedIdReturnsMatchingPage(): void
    {
        $page = $this->tree->findByFeedId(500);

        $this->assertNotNull($page);
        $this->assertSame(8, $page->id);
    }

    public function testFindByFeedIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->tree->findByFeedId(999999));
    }

    public function testFindByFeedTypeReturnsMatchingPage(): void
    {
        $page = $this->tree->findByFeedType('gallery');

        $this->assertNotNull($page);
        $this->assertSame(8, $page->id);
    }

    public function testFindByFeedTypeReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->tree->findByFeedType('does-not-exist'));
    }

    public function testFindByActionReturnsMatchingPage(): void
    {
        $newPage = self::makePage(10, 1, 'favorites', action: 'catalog.favorites');

        $this->tree->add($newPage);

        $this->assertSame($newPage, $this->tree->findByAction('catalog.favorites'));
    }

    public function testFindByActionReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->tree->findByAction('does-not-exist'));
    }

    public function testFindChildrenReturnsOnlyDirectChildren(): void
    {
        $children = $this->tree->findChildren(2);
        $ids = array_map(static fn (Page $page): int => $page->id, $children);
        sort($ids);

        $this->assertSame([3, 5, 6], $ids);
    }

    public function testFindChildrenReturnsEmptyArrayWhenNoChildrenExist(): void
    {
        $this->assertSame([], $this->tree->findChildren(999));
    }

    public function testFindChildByFeedTypeReturnsMatchingChild(): void
    {
        $page = $this->tree->findChildByFeedType(8, 'gallery-child');

        $this->assertNotNull($page);
        $this->assertSame(9, $page->id);
    }

    public function testFindChildByFeedTypeReturnsNullWhenNoMatch(): void
    {
        $this->assertNull($this->tree->findChildByFeedType(8, 'no-such-type'));
        $this->assertNull($this->tree->findChildByFeedType(999, 'gallery-child'));
    }

    public function testAddIndexesNewPageById(): void
    {
        $newPage = self::makePage(10, 1, 'new-page');

        $this->tree->add($newPage);

        $this->assertSame($newPage, $this->tree->get(10));
        $this->assertCount(10, $this->tree->all());
    }

    public function testAddIndexesNewPageByFeedIdAndFeedType(): void
    {
        $newPage = self::makePage(11, 1, 'video-page', feedType: 'video', feedId: 777);

        $this->tree->add($newPage);

        $this->assertSame($newPage, $this->tree->findByFeedId(777));
        $this->assertSame($newPage, $this->tree->findByFeedType('video'));
    }

    public function testGetMaxPageIdReturnsHighestIdPlusOne(): void
    {
        $this->assertSame(10, $this->tree->getMaxPageId());
    }

    public function testGetMaxPageIdReturnsOneForEmptyTree(): void
    {
        $emptyTree = new PageTree([]);

        $this->assertSame(1, $emptyTree->getMaxPageId());
    }
}
