<?php

declare(strict_types=1);

namespace Tests\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class SearchFormTest extends TestCase
{
    private Environment $twig;
    private ?string $searchUrl = null;

    protected function setUp(): void
    {
        $this->twig = new Environment(new FilesystemLoader(__DIR__.'/../../views/themes/default'));
        $this->twig->addFunction(new TwigFunction('trans', static fn (string $key): string => $key));
        $this->twig->addFunction(new TwigFunction(
            'action_url',
            fn (string $action): ?string => $action === 'search.results' ? $this->searchUrl : null,
        ));
    }

    public function testUsesResolvedSearchPageUrl(): void
    {
        $this->searchUrl = '/find/';
        $html = $this->twig->render('components/nav/search-form.twig');

        $this->assertStringContainsString('action="/find/"', $html);
        $this->assertStringContainsString('name="q"', $html);
    }

    public function testIsNotRenderedWithoutSearchPage(): void
    {
        $html = $this->twig->render('components/nav/search-form.twig');

        $this->assertSame('', trim($html));
    }
}
