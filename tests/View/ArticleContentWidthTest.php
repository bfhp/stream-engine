<?php

declare(strict_types=1);

namespace Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ArticleContentWidthTest extends TestCase
{
    #[DataProvider('widths')]
    public function testArticleContentUsesTheConfiguredWidth(string $width, bool $contained): void
    {
        $loader = new FilesystemLoader([
            dirname(__DIR__, 2).'/src/Modules/Article/views',
            dirname(__DIR__, 2).'/views/themes/default',
        ]);
        $twig = new Environment($loader);

        $html = $twig->load('modules/article/article.show.twig')->renderBlock('article_content', [
            'feed' => (object) ['content' => '<h1>Article</h1>'],
            'feedContentWidth' => $width,
        ]);

        self::assertStringContainsString('<h1>Article</h1>', $html);
        self::assertSame($contained, str_contains($html, 'ui-container'));
    }

    public static function widths(): iterable
    {
        yield 'contained' => ['contained', true];
        yield 'full width' => ['full', false];
    }

    public function testBootstrapUsesItsNativeContainer(): void
    {
        $root = dirname(__DIR__, 2);
        $loader = new FilesystemLoader();
        $loader->addPath($root.'/views/themes/bootstrap');
        $loader->addPath($root.'/views/themes/default');
        $loader->addPath($root.'/src/Modules/Article/views');
        $loader->addPath($root.'/src/Modules/Article/views', 'Article');
        $twig = new Environment($loader);

        $html = $twig->load('modules/article/article.show.twig')->renderBlock('article_content', [
            'feed' => (object) ['content' => '<h1>Article</h1>'],
            'feedContentWidth' => 'contained',
        ]);

        self::assertStringContainsString('class="article-content container"', $html);
        self::assertStringNotContainsString('ui-container', $html);
    }
}
