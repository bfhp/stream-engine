<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The one rule that ties the asset sources to the templates that load them.
 *
 * Rollup emits an entry that imports shared code as a real ES module - the
 * built file literally begins `import { … } from "./chunks/…"`. A classic
 * `<script src>` cannot execute that: the browser reports a syntax error and
 * the page's whole behaviour silently disappears. So the moment a bundle
 * starts importing anything, its `<script>` tag has to carry `type="module"`.
 *
 * This has already caught us twice - `profile.js` when it started importing
 * `shared/uploads.ts`, and then five bundles at once when they were converted
 * from `.js` to `.ts` and picked up `shared/api-errors.ts` and
 * `shared/escape.ts`. Both times the mistake was invisible in review and
 * invisible in the build; it shows only in a browser, on one page.
 *
 * Structural rather than a rendering test on purpose: it reads `vite.config.ts`
 * for the entry list and the sources for their imports, so it covers every
 * bundle at once and keeps covering a new one without anybody remembering to
 * add it here.
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
     * Whether an entry's source has any top-level import - CSS side effects
     * included.
     *
     * Leading whitespace is allowed on purpose: a module entry had its import
     * indented by four spaces, which is legal, has no effect on the build, and
     * made a stricter `/^import/` version of this quietly report the bundle as
     * self-contained. A check that depends on formatting is worse than no
     * check, because it reads as a passing one.
     */
    private static function imports(string $sourcePath): bool
    {
        $source = (string) file_get_contents(self::root().'/'.$sourcePath);

        return (bool) preg_match('/^\s*import\s/m', $source);
    }

    /**
     * Every `<script … src="/assets/js/<name>">` written anywhere in src/.
     *
     * @return list<array{0: string, 1: string}> [file name, tag]
     */
    private static function scriptTagsFor(string $bundle): array
    {
        $tags = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::root().'/src')
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                '/<script[^>]*src="\/assets\/js\/'.preg_quote($bundle, '/').'"[^>]*>/',
                (string) file_get_contents($file->getPathname()),
                $matches
            );

            foreach ($matches[0] as $tag) {
                $tags[] = [$file->getFilename(), $tag];
            }
        }

        return $tags;
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

    public function testEveryBundleThatImportsSharedCodeIsLoadedAsAModule(): void
    {
        $checked = 0;

        foreach (self::entries() as $bundle => $source) {
            if (! self::imports($source)) {
                continue;
            }

            foreach (self::scriptTagsFor($bundle) as [$file, $tag]) {
                $checked++;

                $this->assertStringContainsString(
                    'type="module"',
                    $tag,
                    sprintf(
                        '%s imports shared code, so %s must be loaded with type="module" - '
                        .'a classic script cannot execute an ES module and the page goes dead. '
                        .'Tag found in %s: %s',
                        $source,
                        $bundle,
                        $file,
                        $tag
                    )
                );
            }
        }

        $this->assertGreaterThan(10, $checked, 'suspiciously few script tags matched');
    }

    /**
     * Recorded as a fact about today rather than asserted as a requirement:
     * every page entry now imports something, which is why every one of them is
     * a module. A future self-contained entry may stay a classic script - the
     * rule above is one-directional.
     */
    public function testEveryEntryCurrentlyImportsSomething(): void
    {
        $selfContained = [];

        foreach (self::entries() as $bundle => $source) {
            if (! self::imports($source)) {
                $selfContained[] = $bundle;
            }
        }

        $this->assertSame([], $selfContained);
    }
}
