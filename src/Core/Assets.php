<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use Composer\InstalledVersions;
use LogicException;
use RuntimeException;

/**
 * Built browser assets by entry name. The build (assetManifestPlugin in
 * vite-build-data.ts) names every file by its content and writes
 * `public/assets/.vite/manifest.php`: per Vite entry its script, the
 * stylesheets it pulls in and the static imports to preload, plus the
 * per-locale translation files. Templates and controllers name entries
 * (`site`, `forums`, `admin`) and never a built file, and every URL here is
 * safe to cache for good.
 *
 * The manifest is a PHP array read with `include`, so opcache keeps it in
 * memory; nothing else is looked up on disk.
 */
final class Assets
{
    public const string BASE = '/assets/';
    public const string MANIFEST = '/assets/.vite/manifest.php';

    /** Import specifiers the browser code uses for its catalogs (see vite-build-data.ts). */
    private const array TRANSLATION_SPECIFIERS = [
        'site' => 'stream-engine-translations',
        'admin' => 'stream-engine-translations/admin',
    ];

    /** @var array{entries: array<string, array{js: ?string, css: list<string>, preload: list<string>}>, translations: array<string, array<string, string>>}|null */
    private ?array $manifest = null;

    public function __construct(private readonly string $manifestFile)
    {
    }

    /** The application's build: `<root package>/public/assets/.vite/manifest.php`. */
    public static function forApplication(): self
    {
        $root = realpath((string) (InstalledVersions::getRootPackage()['install_path'] ?? ''));

        return new self(($root === false ? dirname(__DIR__, 2) : $root).'/public'.self::MANIFEST);
    }

    /**
     * Stylesheets, module preloads and scripts for the given entries, each
     * file once, in that order. CSS-only entries contribute stylesheets only.
     *
     * @param list<string> $entries
     */
    public function tags(array $entries): string
    {
        $styles = [];
        $preloads = [];
        $scripts = [];
        foreach ($this->entries($entries) as $entry) {
            array_push($styles, ...$entry['css']);
            array_push($preloads, ...$entry['preload']);
            if ($entry['js'] !== null) {
                $scripts[] = $entry['js'];
            }
        }

        $html = $this->styleTags($styles);
        foreach (array_unique(array_diff($preloads, $scripts)) as $file) {
            $html .= '<link rel="modulepreload" href="'.self::url($file).'">'."\n";
        }
        foreach (array_unique($scripts) as $file) {
            $html .= '<script type="module" src="'.self::url($file).'"></script>'."\n";
        }

        return $html;
    }

    /**
     * Only the stylesheets of the given entries - for pages that run no
     * script, such as error pages.
     *
     * @param list<string> $entries
     */
    public function styles(array $entries): string
    {
        $styles = [];
        foreach ($this->entries($entries) as $entry) {
            array_push($styles, ...$entry['css']);
        }

        return $this->styleTags($styles);
    }

    /**
     * The import map that points the translation specifiers at the files for
     * $locale: exact code, then its base language, then English. It must come
     * before any module script or preload on the page. The admin catalog is
     * mapped only for the admin panel.
     */
    public function importMap(string $locale, bool $admin = false): string
    {
        $imports = [self::TRANSLATION_SPECIFIERS['site'] => self::url($this->translationFile('site', $locale))];
        if ($admin) {
            $imports[self::TRANSLATION_SPECIFIERS['admin']] = self::url($this->translationFile('admin', $locale));
        }

        $json = json_encode(
            ['imports' => $imports],
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR,
        );

        return '<script type="importmap">'.$json.'</script>'."\n";
    }

    /** The built translation file for a scope and locale, relative to BASE. */
    public function translationFile(string $scope, string $locale): string
    {
        $files = $this->manifest()['translations'][$scope] ?? [];
        $locale = strtolower(str_replace('_', '-', trim($locale)));
        foreach ([$locale, explode('-', $locale)[0], 'en'] as $candidate) {
            if (isset($files[$candidate])) {
                return $files[$candidate];
            }
        }

        throw new LogicException("The asset build has no $scope translations for '$locale' or English.");
    }

    /**
     * @param list<string> $names
     * @return list<array{js: ?string, css: list<string>, preload: list<string>}>
     */
    private function entries(array $names): array
    {
        $known = $this->manifest()['entries'];
        $result = [];
        foreach (array_unique($names) as $name) {
            if (! isset($known[$name])) {
                throw new LogicException("Unknown asset entry '$name': add it to the Vite build inputs.");
            }
            $result[] = $known[$name];
        }

        return $result;
    }

    /** @param list<string> $files */
    private function styleTags(array $files): string
    {
        $html = '';
        foreach (array_unique($files) as $file) {
            $html .= '<link rel="stylesheet" href="'.self::url($file).'">'."\n";
        }

        return $html;
    }

    private static function url(string $file): string
    {
        return htmlspecialchars(self::BASE.$file, ENT_QUOTES | ENT_HTML5);
    }

    /** @return array{entries: array<string, array{js: ?string, css: list<string>, preload: list<string>}>, translations: array<string, array<string, string>>} */
    private function manifest(): array
    {
        if ($this->manifest === null) {
            // A missing file makes include return false (the warning is silenced): not built.
            $manifest = @include $this->manifestFile;
            if (! is_array($manifest) || ! is_array($manifest['entries'] ?? null)) {
                throw new RuntimeException("Browser assets are not built ({$this->manifestFile} is missing): run `npm run build`.");
            }
            $this->manifest = $manifest;
        }

        return $this->manifest;
    }
}
