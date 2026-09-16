<?php

declare(strict_types=1);

namespace Tests\View;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Service\AccessService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class SearchFormTest extends TestCase
{
    public function testUsesResolvedSearchPageUrl(): void
    {
        $html = $this->render('find');

        $this->assertStringContainsString('action="/find/"', $html);
        $this->assertStringContainsString('name="q"', $html);
    }

    public function testFollowsChangedSearchPagePattern(): void
    {
        $html = $this->render('discover');

        $this->assertStringContainsString('action="/discover/"', $html);
        $this->assertStringNotContainsString('action="/find/"', $html);
    }

    public function testIsNotRenderedWithoutSearchPage(): void
    {
        $html = $this->render(null);

        $this->assertSame('', trim($html));
    }

    private function render(?string $searchPattern): string
    {
        $pages = [self::page(1, null, '', null)];
        if ($searchPattern !== null) {
            $pages[] = self::page(2, 1, $searchPattern, 'search.results');
        }

        $urlGenerator = new UrlGenerator(
            new PageTree($pages),
            new FakeFeedRepository([]),
            new ArrayCache(),
        );
        $twig = new Environment(new FilesystemLoader(__DIR__.'/../../views/themes/default'));
        $twig->addFunction(new TwigFunction('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('action_url', [$urlGenerator, 'action']));

        return $twig->render('components/nav/search-form.twig');
    }

    private static function page(int $id, ?int $parentId, string $pattern, ?string $action): Page
    {
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
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: $action,
        );
    }
}
