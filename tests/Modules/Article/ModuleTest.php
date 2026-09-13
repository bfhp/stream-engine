<?php

declare(strict_types=1);

namespace Tests\Modules\Article;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Article\ArticleController;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;

final class ModuleTest extends TestCase
{
    public function testResolvesThroughControllerFactory(): void
    {
        $modules = new ModuleRegistry();
        $factory = new ControllerFactory(
            $modules,
            $this->createStub(PdoDatabase::class),
            $this->createStub(FeedService::class),
        );
        $context = new RequestContext(new User(1, '', AccessService::ROLE_USER), new \DateTimeZone('UTC'));

        $controller = $factory->createForPage($this->pageWithAction('article.show'), $context);

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
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action,
        );
    }
}
