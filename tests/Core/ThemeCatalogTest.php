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
