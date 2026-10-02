<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use JsonException;

/**
 * Discovers themes from server-controlled directories and validates their
 * public contract. Paths never cross the admin API boundary.
 */
final class ThemeCatalog
{
    public const string DEFAULT_ID = 'default';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $themes = null;

    public function __construct(
        private readonly string $themesDir,
        private readonly string $publicDir,
        private readonly ?string $legacyThemeDir = null,
    ) {
    }

    public static function forApplication(Config $config): self
    {
        return new self(
            dirname(__DIR__, 2).'/views/themes',
            dirname(__DIR__, 2).'/public',
            $config->themeDir(),
        );
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values($this->load());
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->load()[$id] ?? null;
    }

    /** @return array<string, mixed> */
    public function resolve(string $id): array
    {
        return $this->find($id)
            ?? $this->find(self::DEFAULT_ID)
            ?? $this->builtInDefault();
    }

    public function legacyThemeId(): ?string
    {
        if ($this->legacyThemeDir === null) {
            return null;
        }

        $realLegacy = realpath($this->legacyThemeDir);
        foreach ($this->load() as $theme) {
            if ($realLegacy !== false && realpath((string) $theme['path']) === $realLegacy) {
                return (string) $theme['id'];
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    private function load(): array
    {
        if ($this->themes !== null) {
            return $this->themes;
        }

        $directories = glob(rtrim($this->themesDir, '/').'/*', GLOB_ONLYDIR) ?: [];
        if ($this->legacyThemeDir !== null && is_dir($this->legacyThemeDir)) {
            $directories[] = $this->legacyThemeDir;
        }

        $themes = [];
        foreach (array_unique($directories) as $directory) {
            $theme = $this->readManifest($directory);
            if ($theme !== null && ! isset($themes[$theme['id']])) {
                $themes[$theme['id']] = $theme;
            }
        }

        if (! isset($themes[self::DEFAULT_ID])) {
            $themes[self::DEFAULT_ID] = $this->builtInDefault();
        }

        ksort($themes);

        return $this->themes = $themes;
    }

    /** @return array<string, mixed>|null */
    private function readManifest(string $directory): ?array
    {
        $manifestPath = rtrim($directory, '/').'/theme.json';
        if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
            return null;
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($manifest)) {
            return null;
        }

        $id = (string) ($manifest['id'] ?? '');
        $name = trim((string) ($manifest['name'] ?? ''));
        if (! preg_match('/\A[a-z][a-z0-9-]{0,49}\z/', $id) || $name === '' || strlen($name) > 100) {
            return null;
        }

        $settings = $this->settingsSchema($manifest['settings'] ?? []);
        if ($settings === null) {
            return null;
        }

        $themeColor = (string) ($manifest['themeColor'] ?? '#0F172A');
        if (! preg_match('/\A#[0-9a-fA-F]{6}\z/', $themeColor)) {
            return null;
        }

        $assets = $this->assets($manifest['assets'] ?? [], $id, $manifestPath);
        if ($assets === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'version' => (string) ($manifest['version'] ?? '1'),
            'themeColor' => $themeColor,
            'path' => $directory,
            'settings' => $settings,
            'assets' => $assets,
        ];
    }

    /** @return array<string, array<string, mixed>>|null */
    private function settingsSchema(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $schema = [];
        foreach ($input as $key => $definition) {
            if (! is_string($key)
                || ! preg_match('/\A[a-z][a-z0-9_]{0,49}\z/', $key)
                || ! is_array($definition)) {
                return null;
            }

            $type = (string) ($definition['type'] ?? '');
            $label = trim((string) ($definition['label'] ?? $key));
            $default = (string) ($definition['default'] ?? '');
            if (! in_array($type, ['select', 'color', 'string', 'boolean'], true)
                || $label === '' || strlen($label) > 100) {
                return null;
            }

            $options = [];
            if ($type === 'select') {
                if (! is_array($definition['options'] ?? null) || $definition['options'] === []) {
                    return null;
                }
                foreach ($definition['options'] as $value => $optionLabel) {
                    if (! is_string($value) || ! is_string($optionLabel) || $value === '') {
                        return null;
                    }
                    $options[$value] = $optionLabel;
                }
                if (! array_key_exists($default, $options)) {
                    return null;
                }
            }
            if ($type === 'color' && ! preg_match('/\A#[0-9a-fA-F]{6}\z/', $default)) {
                return null;
            }
            if ($type === 'boolean' && ! in_array($default, ['0', '1'], true)) {
                return null;
            }

            $schema[$key] = [
                'type' => $type,
                'label' => $label,
                'default' => $default,
                'options' => $options,
            ];
        }

        return $schema;
    }

    /** @return array{styles: list<string>, scripts: list<string>}|null */
    private function assets(mixed $input, string $id, string $manifestPath): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $versionTime = (int) filemtime($manifestPath);
        $result = ['styles' => [], 'scripts' => []];
        foreach (['styles', 'scripts'] as $kind) {
            $items = $input[$kind] ?? [];
            if (! is_array($items)) {
                return null;
            }
            foreach ($items as $url) {
                if (! is_string($url)
                    || ! preg_match('#\A/(?:assets|themes/'.preg_quote($id, '#').')/[A-Za-z0-9_./-]+\z#', $url)
                    || str_contains($url, '..')) {
                    return null;
                }
                $file = $this->publicDir.$url;
                if (! is_file($file)) {
                    return null;
                }
                $versionTime = max($versionTime, (int) filemtime($file));
                $result[$kind][] = $url.'?v='.$versionTime;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function builtInDefault(): array
    {
        return [
            'id' => self::DEFAULT_ID,
            'name' => 'Default',
            'version' => '1',
            'themeColor' => '#0F172A',
            'path' => rtrim($this->themesDir, '/').'/default',
            'settings' => [
                'color_mode' => [
                    'type' => 'select', 'label' => 'Color mode', 'default' => 'dark',
                    'options' => ['dark' => 'Dark', 'light' => 'Light'],
                ],
            ],
            'assets' => [
                'styles' => ['/assets/css/site.css', '/assets/css/custom-content.css'],
                'scripts' => ['/assets/js/site.js'],
            ],
        ];
    }
}
