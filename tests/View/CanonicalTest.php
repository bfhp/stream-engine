<?php

declare(strict_types=1);

namespace Tests\View;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class CanonicalTest extends TestCase
{
    #[DataProvider('pages')]
    public function testHeadContainsOneEscapedCanonicalAndMatchingOpenGraphUrl(array $data, ?string $expected): void
    {
        $twig = new Environment(new FilesystemLoader(__DIR__.'/../../views/themes/default'));
        $html = $twig->load('layouts/base.twig')->renderBlock('layout_head', $data + [
            'siteUrl' => 'https://example.com',
            'head_ext' => ['<script src="/assets/js/users.js"></script>'],
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
        $twig = new Environment(new FilesystemLoader(__DIR__.'/../../views/themes/default'));
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
        $twig = new Environment(new FilesystemLoader(__DIR__.'/../../views/themes/default'));
        $html = $twig->load('layouts/base.twig')->renderBlock('layout_head', [
            'siteUrl' => null,
            'image' => '/uploads/cover.webp',
        ]);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        self::assertCount(0, $xpath->query('//meta[@property="og:image"]'));
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
