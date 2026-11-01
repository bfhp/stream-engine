<?php

declare(strict_types=1);

namespace Tests\Modules\Article;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Article\ArticleController;
use StreamEngine\Repository\FeedTermRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\TermService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

final class ModuleTest extends TestCase
{
    public function testResolvesThroughControllerFactory(): void
    {
        $modules = new ModuleRegistry();
        $db = $this->createStub(PdoDatabase::class);
        $pageTree = new PageTree([]);
        $factory = new ControllerFactory(
            $modules,
            $db,
            $this->createStub(FeedService::class),
            $pageTree,
            new TermService(
                new FeedTermRepository($db),
                new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache()),
            ),
        );
        $context = new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'));

        $controller = $factory->createForPage($this->pageWithAction('article.show-slug'), $context);

        $this->assertInstanceOf(ArticleController::class, $controller);
    }

    private function pageWithAction(string $action): Page
    {
        return new Page(
            id: 100,
            parentId: 1,
            pattern: '{slug}',
            pageName: 'Article',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action,
        );
    }
}
