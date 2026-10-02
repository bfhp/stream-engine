<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\ThemeCatalog;

final class ThemeCatalogTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/stream-engine-themes-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/themes/default', 0777, true);
        mkdir($this->root.'/public/assets/css', 0777, true);
        file_put_contents($this->root.'/public/assets/css/site.css', 'body{}');
        file_put_contents($this->root.'/themes/default/theme.json', json_encode([
            'id' => 'default',
            'name' => 'Default',
            'themeColor' => '#123456',
            'settings' => [
                'mode' => [
                    'type' => 'select',
                    'label' => 'Mode',
                    'default' => 'dark',
                    'options' => ['dark' => 'Dark', 'light' => 'Light'],
                ],
            ],
            'assets' => ['styles' => ['/assets/css/site.css']],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testDiscoversValidatedManifestAndVersionsAssets(): void
    {
        $theme = (new ThemeCatalog($this->root.'/themes', $this->root.'/public'))->find('default');

        self::assertSame('Default', $theme['name']);
        self::assertSame('#123456', $theme['themeColor']);
        self::assertSame('dark', $theme['settings']['mode']['default']);
        self::assertMatchesRegularExpression('#^/assets/css/site\.css\?v=\d+$#', $theme['assets']['styles'][0]);
    }

    public function testBootstrapIsPrependedForThemesReusingCoreSiteCss(): void
    {
        file_put_contents($this->root.'/public/assets/css/bootstrap.css', 'a{}');

        $theme = (new ThemeCatalog($this->root.'/themes', $this->root.'/public'))->find('default');

        self::assertMatchesRegularExpression('#^/assets/css/bootstrap\.css\?v=\d+$#', $theme['assets']['styles'][0]);
        self::assertMatchesRegularExpression('#^/assets/css/site\.css\?v=\d+$#', $theme['assets']['styles'][1]);
    }

    public function testRtlSwapsBootstrapForItsRtlBuildOnly(): void
    {
        file_put_contents($this->root.'/public/assets/css/bootstrap.css', 'a{}');
        $catalog = new ThemeCatalog($this->root.'/themes', $this->root.'/public');
        $theme = $catalog->find('default');

        self::assertSame($theme, $catalog->forDirection($theme, true), 'No RTL build deployed: unchanged.');

        file_put_contents($this->root.'/public/assets/css/bootstrap-rtl.css', 'a{}');
        self::assertSame($theme, $catalog->forDirection($theme, false));
        $rtl = $catalog->forDirection($theme, true);
        self::assertMatchesRegularExpression('#^/assets/css/bootstrap-rtl\.css\?v=\d+$#', $rtl['assets']['styles'][0]);
        self::assertSame($theme['assets']['styles'][1], $rtl['assets']['styles'][1]);
        self::assertCount(2, $rtl['assets']['styles']);
    }

    public function testInvalidThemeIsNotSelectableAndFallsBackToDefault(): void
    {
        mkdir($this->root.'/themes/broken');
        file_put_contents($this->root.'/themes/broken/theme.json', '{broken');
        $catalog = new ThemeCatalog($this->root.'/themes', $this->root.'/public');

        self::assertNull($catalog->find('broken'));
        self::assertSame('default', $catalog->resolve('missing')['id']);
    }

    public function testRejectsAssetsOutsideThePublicThemeContract(): void
    {
        mkdir($this->root.'/themes/unsafe');
        file_put_contents($this->root.'/themes/unsafe/theme.json', json_encode([
            'id' => 'unsafe',
            'name' => 'Unsafe',
            'settings' => [],
            'assets' => ['scripts' => ['https://example.test/tracker.js']],
        ], JSON_THROW_ON_ERROR));

        self::assertNull((new ThemeCatalog($this->root.'/themes', $this->root.'/public'))->find('unsafe'));
    }
}
