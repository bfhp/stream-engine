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

    private function load(string $locale): array
    {
        $file = __DIR__ . "/../Lang/$locale.php";

        if (!file_exists($file)) {
            return [];
        }

        return require $file;
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
