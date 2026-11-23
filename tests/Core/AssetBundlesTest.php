<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The one rule that ties the asset sources to the code that loads them.
 *
 * Pages name Vite entries - `'entries' => ['forums']` in a controller,
 * `asset_tags(['admin'])` in a template, `assets.entries` in a theme.json -
 * and Core\Assets turns the name into the built, hashed files. A name the
 * build does not have throws at render time, on one page only. So every name
 * used anywhere must be a key of `rollupOptions.input` in vite.config.ts.
 *
 * Structural rather than a rendering test on purpose: it reads the config for
 * the entry list and the sources for the names, so it keeps covering a new
 * page without anybody remembering to add it here.
 */
final class AssetBundlesTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Vite's `rollupOptions.input` map - the authoritative list of what is an
     * entry bundle at all, as opposed to a module some entry imports.
     *
     * @return array<string, string> built file name => source path
     */
    private static function entries(): array
    {
        $config = (string) file_get_contents(self::root().'/vite.config.ts');

        $start = strpos($config, 'input: {');
        self::assertNotFalse($start, 'vite.config.ts has no rollupOptions.input');

        $block = substr($config, $start, strpos($config, '},', $start) - $start);

        preg_match_all('/["\']?([\w-]+)["\']?\s*:\s*["\']([^"\']+)["\']/', $block, $matches, PREG_SET_ORDER);

        $entries = [];
        foreach ($matches as [, $name, $path]) {
            $entries[$name.'.js'] = $path;
        }

        preg_match_all(
            '/["\']?([\w-]+)["\']?\s*:\s*path\.join\(engineAssets,\s*["\']([^"\']+)["\']\)/',
            $block,
            $matches,
            PREG_SET_ORDER,
        );
        $engineAssets = is_dir(self::root().'/vendor/bfhp/stream-engine/assets-src')
            ? 'vendor/bfhp/stream-engine/assets-src/'
            : 'assets-src/';
        foreach ($matches as [, $name, $path]) {
            $entries[$name.'.js'] = $engineAssets.$path;
        }

        return $entries;
    }

    /**
     * Every entry name used by controllers, templates and theme manifests,
     * with where it was found.
     *
     * @return list<array{0: string, 1: string}> [entry, file]
     */
    private static function usedEntries(): array
    {
        $used = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root().'/src'));
        foreach ($files as $file) {
            if (! in_array($file->getExtension(), ['php', 'twig'], true)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all(
                '/(?:[\'"]entries[\'"]\]?\s*(?:=>|=)|\$entries\s*=|asset_(?:tags|styles)\()\s*\[([^\]]*)\]/',
                $source,
                $lists,
            );
            foreach ($lists[1] as $list) {
                preg_match_all('/[\'"]([a-z0-9][a-z0-9_-]*)[\'"]/', $list, $names);
                foreach ($names[1] as $name) {
                    $used[] = [$name, $file->getFilename()];
                }
            }
        }

        foreach (glob(self::root().'/views/themes/*/theme.json') ?: [] as $manifest) {
            $assets = json_decode((string) file_get_contents($manifest), true)['assets'] ?? [];
            foreach ([...($assets['entries'] ?? []), ...array_keys($assets['rtl'] ?? []), ...array_values($assets['rtl'] ?? [])] as $name) {
                $used[] = [$name, basename(dirname($manifest)).'/theme.json'];
            }
        }

        return $used;
    }

    public function testTheEntryListIsReadable(): void
    {
        $entries = self::entries();

        // If the parse ever comes back empty every assertion below would pass
        // vacuously.
        $this->assertNotEmpty($entries);
        $this->assertArrayHasKey('site.js', $entries);

        foreach ($entries as $bundle => $path) {
            $this->assertFileExists(self::root().'/'.$path, $bundle.' points at a missing source');
        }
    }

    public function testEveryEntryNamedByAPageIsABuildInput(): void
    {
        $inputs = array_map(static fn (string $bundle): string => substr($bundle, 0, -3), array_keys(self::entries()));
        $used = self::usedEntries();

        foreach ($used as [$name, $file]) {
            $this->assertContains($name, $inputs, "$file names the asset entry '$name', which vite.config.ts does not build.");
        }

        $this->assertGreaterThan(20, count($used), 'suspiciously few entry names matched');
    }
}
