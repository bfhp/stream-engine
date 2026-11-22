<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use Composer\InstalledVersions;

class TranslationManager
{
    private string $locale;
    private array $messages;

    public function __construct(string $locale, string $fallback = 'en')
    {
        $this->locale = self::normalizeLocale($locale);

        $this->messages = $this->loadWithBaseFallback($this->locale);

        $fallback = self::normalizeLocale($fallback);
        if ($this->locale !== $fallback) {
            $fallbackMessages = $this->loadWithBaseFallback($fallback);
            $this->messages = array_merge($fallbackMessages, $this->messages);
        }
    }

    /** Base language codes written right-to-left. */
    private const RTL_LANGUAGES = ['ar', 'ckb', 'dv', 'fa', 'he', 'ps', 'sd', 'ug', 'ur', 'yi'];

    /** Whether the locale (e.g. "ar", "he", "fa-IR") is written right-to-left. */
    public static function isRtlLocale(string $locale): bool
    {
        $base = strtolower(preg_split('/[-_]/', trim($locale), 2)[0] ?? '');

        return in_array($base, self::RTL_LANGUAGES, true);
    }

    public function isRtl(): bool
    {
        return self::isRtlLocale($this->locale);
    }

    /** Value for the HTML `dir` attribute: "rtl" or "ltr". */
    public function direction(): string
    {
        return $this->isRtl() ? 'rtl' : 'ltr';
    }

    private function load(string $locale): array
    {
        $file = __DIR__ . "/../Lang/$locale.php";

        if (!file_exists($file)) {
            return [];
        }

        return require $file;
    }

    private function loadWithBaseFallback(string $locale): array
    {
        $base = explode('-', $locale, 2)[0];
        $messages = $base !== $locale ? $this->load($base) : [];

        return array_merge($messages, $this->load($locale));
    }

    private static function normalizeLocale(string $locale): string
    {
        $locale = strtolower(str_replace('_', '-', trim($locale)));

        return preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $locale) === 1
            ? $locale
            : '';
    }

    /** @return list<string> */
    public static function availableLocales(): array
    {
        $files = glob(__DIR__.'/../Lang/*.php') ?: [];
        $locales = [];
        foreach ($files as $file) {
            $locale = pathinfo($file, PATHINFO_FILENAME);
            if (preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/i', $locale) === 1) {
                $locales[] = strtolower($locale);
            }
        }
        sort($locales);

        return array_values(array_unique($locales));
    }

    // --- PHP Translator ---

    public function trans(string $key, array $params = []): string
    {
        $text = $this->messages[$key] ?? $key;

        foreach ($params as $k => $v) {
            $text = str_replace('{' . $k . '}', "$v", $text);
        }

        return $text;
    }

    public function getTranslator(): callable
    {
        return fn (string $key, array $params = [])
        => $this->trans($key, $params);
    }

    // --- JS ---

    public function getAll(): array
    {
        return $this->messages;
    }

    public function getForJS(string $prefix = 'js.'): array
    {
        return array_filter(
            $this->messages,
            fn ($k) => str_starts_with($k, $prefix),
            ARRAY_FILTER_USE_KEY
        );
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    // --- Browser translation files ---

    private const string ENGINE_PACKAGE = 'bfhp/stream-engine';

    public const string SCRIPT_SCOPE_SITE = 'site';
    public const string SCRIPT_SCOPE_ADMIN = 'admin';

    /**
     * The browser translation file for this locale: `/assets/js/i18n/<locale>.js`
     * (or `<locale>-admin.js`), built by translationsPlugin in vite-build-data.ts
     * from the same language files, with the English fallback merged in. The
     * layouts link it (the `translationScripts` Twig global) with `defer` ahead
     * of the page's modules, so shared/i18n.ts finds the strings registered on
     * `globalThis.__streamEngineTranslations` - one locale per page instead of
     * every locale in every bundle.
     *
     * The build writes a file for every language file, so only the locale
     * choice happens here, against availableLocales(): `pt-br`, then `pt`, then
     * `en`, matching shared/i18n.ts. Nothing is checked on disk.
     *
     * $version, when given, is appended as `?v=` so the file can be cached for
     * long and still change with a release - see browserScriptVersion().
     */
    public function browserScriptUrl(string $scope = self::SCRIPT_SCOPE_SITE, ?string $version = null): string
    {
        $available = self::availableLocales();
        $chosen = 'en';
        foreach ([$this->locale, explode('-', $this->locale)[0]] as $candidate) {
            if (in_array($candidate, $available, true)) {
                $chosen = $candidate;
                break;
            }
        }

        $url = '/assets/js/i18n/'.$chosen.($scope === self::SCRIPT_SCOPE_ADMIN ? '-admin' : '').'.js';

        return $version !== null && $version !== '' ? $url.'?v='.rawurlencode($version) : $url;
    }

    /**
     * A version for the browser translation files that changes with every
     * release without touching them at runtime: the engine's installed
     * revision (its catalogs and the plugin that builds the files) and the
     * root package's (a site's own src/Lang overrides). Both come from
     * Composer's installed.php, which Composer has loaded anyway, and change on
     * `composer install` after an update or a new site commit.
     */
    public static function browserScriptVersion(): string
    {
        $engine = InstalledVersions::isInstalled(self::ENGINE_PACKAGE)
            ? (InstalledVersions::getReference(self::ENGINE_PACKAGE) ?? InstalledVersions::getPrettyVersion(self::ENGINE_PACKAGE) ?? '')
            : '';
        $root = InstalledVersions::getRootPackage();

        return substr(sha1($engine.'|'.($root['reference'] ?? $root['pretty_version'] ?? '')), 0, 12);
    }
}
