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

    public function testColorModeWithLightAndDarkAlsoOffersSystem(): void
    {
        $this->writeTheme('default', [
            'settings' => [
                'color_mode' => [
                    'type' => 'select',
                    'label' => 'Color mode',
                    'default' => 'dark',
                    'options' => ['dark' => 'Dark', 'light' => 'Light'],
                ],
            ],
        ]);

        $theme = (new ThemeCatalog($this->root.'/themes', $this->root.'/public'))->find('default');

        self::assertSame(
            ['dark' => 'Dark', 'light' => 'Light', 'system' => 'System'],
            $theme['settings']['color_mode']['options'],
        );
    }

    public function testNoFrameworkIsPrependedImplicitly(): void
    {
        file_put_contents($this->root.'/public/assets/css/bootstrap.css', 'a{}');

        $theme = (new ThemeCatalog($this->root.'/themes', $this->root.'/public'))->find('default');

        self::assertCount(1, $theme['assets']['styles']);
        self::assertMatchesRegularExpression('#^/assets/css/site\.css\?v=\d+$#', $theme['assets']['styles'][0]);
    }

    public function testRtlSwapsOnlyStylesheetsTheManifestDeclares(): void
    {
        file_put_contents($this->root.'/public/assets/css/bootstrap.css', 'a{}');
        $this->writeTheme('default', [
            'assets' => [
                'styles' => ['/assets/css/bootstrap.css', '/assets/css/site.css'],
                'rtl' => ['/assets/css/bootstrap.css' => '/assets/css/bootstrap-rtl.css'],
            ],
        ]);
        $catalog = new ThemeCatalog($this->root.'/themes', $this->root.'/public');
        $theme = $catalog->find('default');

        self::assertSame($theme, $catalog->forDirection($theme, true), 'No RTL build deployed: unchanged.');

        file_put_contents($this->root.'/public/assets/css/bootstrap-rtl.css', 'a{}');
        $catalog = new ThemeCatalog($this->root.'/themes', $this->root.'/public');
        $theme = $catalog->find('default');
        self::assertSame($theme, $catalog->forDirection($theme, false));
        $rtl = $catalog->forDirection($theme, true);
        self::assertMatchesRegularExpression('#^/assets/css/bootstrap-rtl\.css\?v=\d+$#', $rtl['assets']['styles'][0]);
        self::assertSame($theme['assets']['styles'][1], $rtl['assets']['styles'][1]);
        self::assertCount(2, $rtl['assets']['styles']);
    }

    public function testChildInheritsParentChainAndAssets(): void
    {
        file_put_contents($this->root.'/public/assets/css/bootstrap.css', 'a{}');
        file_put_contents($this->root.'/public/assets/css/bootstrap-rtl.css', 'a{}');
        mkdir($this->root.'/public/themes/framework', 0777, true);
        mkdir($this->root.'/public/themes/brand', 0777, true);
        file_put_contents($this->root.'/public/themes/framework/site.css', 'a{}');
        file_put_contents($this->root.'/public/themes/brand/site.css', 'a{}');

        $this->writeTheme('framework', [
            'assets' => [
                'styles' => ['/assets/css/bootstrap.css', '/themes/framework/site.css'],
                'rtl' => ['/assets/css/bootstrap.css' => '/assets/css/bootstrap-rtl.css'],
            ],
        ]);
        $this->writeTheme('brand', [
            'parent' => 'framework',
            'assets' => ['styles' => ['/assets/css/site.css', '/themes/brand/site.css']],
        ]);

        $catalog = new ThemeCatalog($this->root.'/themes', $this->root.'/public');
        $brand = $catalog->find('brand');

        self::assertSame(['brand', 'framework', 'default'], $brand['chain']);
        self::assertSame(['brand', 'framework', 'default'], array_column($catalog->lineage($brand), 'id'));
        self::assertSame(
            ['/assets/css/site.css', '/assets/css/bootstrap.css', '/themes/framework/site.css', '/themes/brand/site.css'],
            array_map(static fn (string $url): string => strtok($url, '?'), $brand['assets']['styles']),
            'Ancestors first, duplicates keep their first position.',
        );
        self::assertStringStartsWith(
            '/assets/css/bootstrap-rtl.css?v=',
            $catalog->forDirection($brand, true)['assets']['styles'][1],
            'RTL replacements are inherited.',
        );
    }

    public function testInheritAssetsFalseDropsAncestorAssets(): void
    {
        mkdir($this->root.'/public/themes/standalone', 0777, true);
        file_put_contents($this->root.'/public/themes/standalone/app.css', 'a{}');
        $this->writeTheme('standalone', [
            'inheritAssets' => false,
            'assets' => ['styles' => ['/themes/standalone/app.css']],
        ]);

        $theme = (new ThemeCatalog($this->root.'/themes', $this->root.'/public'))->find('standalone');

        self::assertSame(['standalone', 'default'], $theme['chain'], 'Templates still inherit.');
        self::assertCount(1, $theme['assets']['styles']);
        self::assertStringStartsWith('/themes/standalone/app.css?v=', $theme['assets']['styles'][0]);
    }

    public function testMissingOrCyclicParentMakesThemeUnselectable(): void
    {
        $this->writeTheme('orphan', ['parent' => 'missing']);
        $this->writeTheme('loop-a', ['parent' => 'loop-b']);
        $this->writeTheme('loop-b', ['parent' => 'loop-a']);
        $this->writeTheme('self', ['parent' => 'self']);

        $catalog = new ThemeCatalog($this->root.'/themes', $this->root.'/public');

        self::assertNull($catalog->find('orphan'));
        self::assertNull($catalog->find('loop-a'));
        self::assertNull($catalog->find('loop-b'));
        self::assertNull($catalog->find('self'));
        self::assertSame('default', $catalog->resolve('orphan')['id']);
    }

    public function testLineageAlwaysEndsWithDefault(): void
    {
        $catalog = new ThemeCatalog($this->root.'/themes', $this->root.'/public');

        self::assertSame(['default'], array_column($catalog->lineage($catalog->find('default')), 'id'));
        self::assertSame(
            ['default'],
            array_column($catalog->lineage(['id' => 'ghost', 'path' => $this->root.'/missing']), 'id'),
            'A theme without a chain or a directory still gets default.',
        );
    }

    public function testDefaultNeverHasAParent(): void
    {
        $this->writeTheme('default', ['parent' => 'other']);
        $this->writeTheme('other', []);

        $catalog = new ThemeCatalog($this->root.'/themes', $this->root.'/public');

        self::assertNull($catalog->find('default')['parent']);
        self::assertSame(['default'], $catalog->find('default')['chain']);
        self::assertSame(['other', 'default'], $catalog->find('other')['chain']);
    }

    /** @param array<string, mixed> $manifest */
    private function writeTheme(string $id, array $manifest): void
    {
        if (! is_dir($this->root.'/themes/'.$id)) {
            mkdir($this->root.'/themes/'.$id, 0777, true);
        }
        file_put_contents($this->root.'/themes/'.$id.'/theme.json', json_encode($manifest + [
            'id' => $id,
            'name' => ucfirst($id),
            'settings' => [],
            'assets' => [],
        ], JSON_THROW_ON_ERROR));
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
