<?php

declare(strict_types=1);

namespace Tests\View;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Assets;
use StreamEngine\View\AssetsExtension;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class HeadWidgetTest extends TestCase
{
    #[DataProvider('themes')]
    public function testWidgetElementsRenderDirectlyInsideHead(string $theme): void
    {
        $twig = self::twig($theme);
        $widget = '<meta name="widget-test" content="a&amp;b"><style id="widget-style">body{color:red}</style><script id="widget-script">window.widgetTest = true;</script>';
        $html = $twig->load('layouts/base.twig')->renderBlock('layout_head', [
            'widgets' => ['head' => $widget],
            'head_ext' => ['<script id="page-script" src="/page.js"></script>'],
        ]);

        self::assertStringContainsString($widget, $html);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        self::assertCount(1, $xpath->query('//head/meta[@name="widget-test"]'));
        self::assertSame('a&b', $xpath->evaluate('string(//head/meta[@name="widget-test"]/@content)'));
        self::assertCount(1, $xpath->query('//head/style[@id="widget-style"]'));
        self::assertCount(1, $xpath->query('//head/script[@id="widget-script"]'));
        self::assertCount(1, $xpath->query('//head/script[@id="page-script"]'));
        self::assertCount(0, $xpath->query('//div'));
    }

    #[DataProvider('themes')]
    public function testMissingWidgetRendersWithoutExtraMarkup(string $theme): void
    {
        $template = self::twig($theme)->load('layouts/base.twig');

        self::assertSame('', trim($template->renderBlock('head_widgets', [])));
        self::assertSame('', trim($template->renderBlock('head_widgets', ['widgets' => ['head' => '']])));
    }

    private static function twig(string $theme): Environment
    {
        $root = dirname(__DIR__, 2).'/views/themes';
        $loader = new FilesystemLoader([$root.'/'.$theme, $root.'/default']);
        $loader->addPath($root.'/default', 'default');
        $twig = new Environment($loader);
        $twig->addFunction(new TwigFunction('trans', static fn (string $key): string => $key));
        $twig->addExtension(new AssetsExtension(new Assets(__DIR__.'/../Support/assets-manifest.php'), 'en'));

        return $twig;
    }

    public static function themes(): iterable
    {
        yield 'default' => ['default'];
        yield 'bootstrap' => ['bootstrap'];
    }
}
