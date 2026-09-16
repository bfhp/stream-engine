<?php

declare(strict_types=1);

namespace Tests\Modules\Sitemap;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Config;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Sitemap\SitemapController;
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
        $db = $this->createStub(PdoDatabase::class);
        $pageTree = new PageTree([]);
        $urlGenerator = new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache());

        $services = [
            $db,
            $pageTree,
            $urlGenerator,
            $this->createStub(FeedService::class),
            new TermService(new FeedTermRepository($db), $urlGenerator),
            new Config(['SITE_URL' => 'https://example.test']),
        ];

        $modules = new ModuleRegistry();

        // Unlike most modules, Sitemap declares no pageActions() - its routes
        // (added via registerApi()) get bound to its module id by
        // StreamEngine::initCronAndApi() at boot, not by ModuleRegistry's
        // constructor. Mirror that one explicit registerAction() call here.
        $modules->registerAction('sitemap.robots', 'Sitemap');

        $factory = new ControllerFactory($modules, ...$services);
        $context = new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'));

        $controller = $factory->createForPage($this->pageWithAction('sitemap.robots'), $context);

        $this->assertInstanceOf(SitemapController::class, $controller);
    }

    private function pageWithAction(string $action): Page
    {
        return new Page(
            id: 100,
            parentId: 1,
            pattern: 'robots.txt',
            pageName: null,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'raw',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action,
        );
    }
}
