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

    protected function setUp(): void
    {
        $this->twig = new Environment(new FilesystemLoader(__DIR__.'/../../views/themes/default'));
        $this->twig->addFunction(new TwigFunction('trans', static fn (string $key): string => $key));
    }

    public function testUsesResolvedSearchPageUrl(): void
    {
        $html = $this->twig->render('components/nav/search-form.twig', [
            'searchUrl' => '/find/',
        ]);

        $this->assertStringContainsString('action="/find/"', $html);
        $this->assertStringContainsString('name="q"', $html);
    }

    public function testIsNotRenderedWithoutSearchPage(): void
    {
        $html = $this->twig->render('components/nav/search-form.twig', [
            'searchUrl' => null,
        ]);

        $this->assertSame('', trim($html));
    }
}
