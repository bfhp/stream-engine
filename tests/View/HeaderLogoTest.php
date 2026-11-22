<?php

declare(strict_types=1);

namespace Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class HeaderLogoTest extends TestCase
{
    #[DataProvider('themes')]
    public function testThemeHeaderUsesAccessibleLogoWhenConfigured(string $theme): void
    {
        $html = $this->renderLogoBlock($theme, '/uploads/site-brand/header-logo-a83f42c1d92e176a.webp');

        self::assertStringContainsString(
            'src="/uploads/site-brand/header-logo-a83f42c1d92e176a.webp"',
            $html,
        );
        self::assertStringContainsString('alt=""', $html);
        self::assertStringContainsString('class="header-logo"', $html);
        self::assertStringContainsString('<span', $html);
        self::assertStringContainsString('Stream &amp; Engine</span>', $html);
    }

    #[DataProvider('themes')]
    public function testThemeHeaderReservesTheLogoBoxWhenItsSizeIsKnown(string $theme): void
    {
        $html = $this->renderLogoBlock(
            $theme,
            '/uploads/site-brand/header-logo-a83f42c1d92e176a.webp',
            ['width' => 120, 'height' => 32],
        );

        self::assertStringContainsString('alt="" width="120" height="32"', $html);
        self::assertStringContainsString('style="--header-logo-block-size: min(32px, 2.5rem)"', $html);
    }

    #[DataProvider('themes')]
    public function testThemeHeaderOmitsTheSizeWhenItIsUnknown(string $theme): void
    {
        $html = $this->renderLogoBlock($theme, '/uploads/site-brand/header-logo-a83f42c1d92e176a.webp');

        self::assertStringContainsString('alt="">', $html);
        self::assertStringNotContainsString('width=', $html);
    }

    #[DataProvider('themes')]
    public function testThemeHeaderFallsBackToSiteNameWithoutLogo(string $theme): void
    {
        $html = $this->renderLogoBlock($theme, null);

        self::assertStringContainsString('Stream &amp; Engine</span>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    /** @return iterable<string, array{string}> */
    public static function themes(): iterable
    {
        yield 'default' => ['default'];
        yield 'bootstrap' => ['bootstrap'];
    }

    /** @param array{width: int, height: int}|null $headerLogoSize */
    private function renderLogoBlock(string $theme, ?string $headerLogo, ?array $headerLogoSize = null): string
    {
        $views = dirname(__DIR__, 2).'/views/themes';
        $loader = new FilesystemLoader();
        $loader->addPath($views.'/'.$theme);
        $loader->addPath($views.'/default', 'default');
        $twig = new Environment($loader);

        return $twig->createTemplate("{{ block('header_logo', 'partials/header.twig') }}")->render([
            'siteName' => 'Stream & Engine',
            'headerLogo' => $headerLogo,
            'headerLogoSize' => $headerLogoSize,
        ]);
    }
}
