<?php

declare(strict_types=1);

namespace StreamEngine\Core;

class TranslationManager
{
    private string $locale;
    private array $messages;

    public function __construct(string $locale, string $fallback = 'en')
    {
        $this->locale = $locale;

        $this->messages = $this->load($locale);

        // fallback
        if ($locale !== $fallback) {
            $fallbackMessages = $this->load($fallback);
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

    /** @return list<string> */
    public static function availableLocales(): array
    {
        $files = glob(__DIR__.'/../Lang/*.php') ?: [];
        $locales = array_map(
            static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME),
            $files,
        );
        sort($locales);

        return array_values($locales);
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
}
