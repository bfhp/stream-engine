<?php

declare(strict_types=1);

namespace Tests\View;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Assets;
use StreamEngine\StreamEngine;
use StreamEngine\View\AssetsExtension;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class CanonicalTest extends TestCase
{
    #[DataProvider('pages')]
    public function testHeadContainsOneEscapedCanonicalAndMatchingOpenGraphUrl(array $data, ?string $expected): void
    {
        $twig = self::twig();
        $html = $twig->load('layouts/base.twig')->renderBlock('layout_head', $data + [
            'siteUrl' => 'https://example.com',
            'head_ext' => ['<script src="/page.js"></script>'],
        ]);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $links = $xpath->query('//head/link[@rel="canonical"]');
        $this->assertCount($expected === null ? 0 : 1, $links);
        if ($expected !== null) {
            $this->assertSame($expected, $links->item(0)->getAttribute('href'));
            $this->assertSame($expected, $xpath->evaluate('string(//meta[@property="og:url"]/@content)'));
        }
        $this->assertCount(0, $xpath->query('//*[@onload]'));
    }

    public function testOpenGraphImageUsesConfiguredSiteUrl(): void
    {
        $twig = self::twig();
        $html = $twig->load('layouts/base.twig')->renderBlock('layout_head', [
            'siteUrl' => 'https://canonical.example',
            'image' => '/uploads/cover.webp',
        ]);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        self::assertSame(
            'https://canonical.example/uploads/cover.webp',
            $xpath->evaluate('string(//meta[@property="og:image"]/@content)'),
        );
    }

    public function testOpenGraphImageIsOmittedWithoutConfiguredSiteUrl(): void
    {
        $twig = self::twig();
        $html = $twig->load('layouts/base.twig')->renderBlock('layout_head', [
            'siteUrl' => null,
            'image' => '/uploads/cover.webp',
        ]);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        self::assertCount(0, $xpath->query('//meta[@property="og:image"]'));
    }

    public function testALongDescriptionIsCutTo155CharactersWithAnEllipsis(): void
    {
        $html = self::twig()->load('layouts/base.twig')->renderBlock('layout_head', [
            'siteUrl' => 'https://example.com',
            'canonical' => '/blog/post/',
            'description' => '<p>'.str_repeat('Длинный текст поста & ещё ', 40).'</p>',
        ]);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);

        foreach (['//meta[@name="description"]/@content', '//meta[@property="og:description"]/@content'] as $query) {
            $content = $xpath->evaluate('string('.$query.')');
            self::assertLessThanOrEqual(155, mb_strlen($content));
            self::assertStringEndsWith('…', $content);
            self::assertStringStartsWith('Длинный текст поста & ещё', $content);
            self::assertStringNotContainsString('<p>', $content);
        }
    }

    public function testAShortDescriptionIsKeptWhole(): void
    {
        $html = self::twig()->load('layouts/base.twig')->renderBlock('layout_head', [
            'description' => 'Форум «Кошки & собаки»',
        ]);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        // Escaped once: the meta holds the text itself, not `&amp;`.
        self::assertSame(
            'Форум «Кошки & собаки»',
            (new DOMXPath($dom))->evaluate('string(//meta[@name="description"]/@content)'),
        );
    }

    /**
     * Twig compiles the whole layout even when one block is rendered, so the
     * functions used outside <head> (the skip link's trans()) must exist.
     */
    private static function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(__DIR__.'/../../views/themes/default'));
        $twig->addFunction(new TwigFunction('trans', static fn (string $key): string => $key));
        $twig->addExtension(new AssetsExtension(new Assets(__DIR__.'/../Support/assets-manifest.php'), 'en'));
        $twig->addFilter(StreamEngine::metaDescriptionFilter());

        return $twig;
    }

    public static function pages(): array
    {
        return [
            'forum page two' => [['canonical' => '/forums/example/?page=2'], 'https://example.com/forums/example/?page=2'],
            'nested path' => [['canonical' => '/catalog/authors/'], 'https://example.com/catalog/authors/'],
            'book page differs from book root' => [['canonical' => '/book/2/', 'bookUrl' => '/book/'], 'https://example.com/book/2/'],
            'escaped filter' => [['canonical' => '/users/?q=" onload="x&sort=name'], 'https://example.com/users/?q=" onload="x&sort=name'],
            'disabled' => [['canonical' => null], null],
            'missing site URL' => [['canonical' => '/book/2/', 'siteUrl' => null], null],
            '404 without canonical' => [[], null],
        ];
    }
}
