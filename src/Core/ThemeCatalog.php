<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use Composer\InstalledVersions;
use JsonException;

/**
 * Discovers themes from server-controlled directories and validates their
 * public contract. Paths never cross the admin API boundary.
 *
 * Themes form a chain: every theme except `default` has a parent (`default`
 * unless its manifest says otherwise). The chain decides template lookup
 * order (see `lineage()`) and, unless a theme sets `inheritAssets: false`,
 * which parent assets are loaded before its own.
 */
final class ThemeCatalog
{
    /** The base every theme inherits from, and the error fallback. */
    public const string DEFAULT_ID = 'default';

    /** Used when the site has never chosen a theme, if it is installed. */
    public const string PREFERRED_ID = 'bootstrap';

    /** Longest allowed parent chain, `default` included. */
    private const int MAX_DEPTH = 8;

    private const string ID_PATTERN = '/\A[a-z][a-z0-9-]{0,49}\z/';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $themes = null;

    /** @param list<string> $additionalThemeDirs */
    public function __construct(
        private readonly string $themesDir,
        private readonly string $publicDir,
        private readonly ?string $legacyThemeDir = null,
        private readonly array $additionalThemeDirs = [],
    ) {
    }

    public static function forApplication(Config $config): self
    {
        $rootPackage = InstalledVersions::getRootPackage();
        $applicationRoot = realpath((string) ($rootPackage['install_path'] ?? ''));
        if ($applicationRoot === false) {
            $applicationRoot = dirname(__DIR__, 2);
        }

        $engineRoot = dirname(__DIR__, 2);

        return new self(
            $engineRoot.'/views/themes',
            $applicationRoot.'/public',
            $config->themeDir(),
            $applicationRoot !== $engineRoot ? [$applicationRoot.'/views/themes'] : [],
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

    /**
     * The theme followed by its ancestors, always ending with `default`, and
     * only directories that exist. This is the template search order before
     * module views.
     *
     * @param array<string, mixed> $theme
     * @return list<array{id: string, path: string}>
     */
    public function lineage(array $theme): array
    {
        $ids = (array) ($theme['chain'] ?? [$theme['id'] ?? self::DEFAULT_ID]);
        if (end($ids) !== self::DEFAULT_ID) {
            $ids[] = self::DEFAULT_ID;
        }

        $result = [];
        foreach ($ids as $id) {
            $member = $id === ($theme['id'] ?? null) ? $theme : $this->resolve((string) $id);
            $path = (string) ($member['path'] ?? '');
            if (is_dir($path) && ! in_array($member['id'], array_column($result, 'id'), true)) {
                $result[] = ['id' => (string) $member['id'], 'path' => $path];
            }
        }

        return $result;
    }

    /**
     * Swaps entries for the right-to-left builds the theme chain declares in
     * `assets.rtl` (entry name => RTL entry name), so only one of each pair
     * is ever loaded.
     *
     * @param array<string, mixed> $theme
     * @return array<string, mixed>
     */
    public function forDirection(array $theme, bool $rtl): array
    {
        $map = $theme['assets']['rtl'] ?? [];
        if (! $rtl || ! is_array($map) || $map === [] || ! is_array($theme['assets']['entries'] ?? null)) {
            return $theme;
        }

        $theme['assets']['entries'] = array_map(
            static fn (string $entry): string => $map[$entry] ?? $entry,
            $theme['assets']['entries'],
        );

        return $theme;
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
        foreach ($this->additionalThemeDirs as $themesDir) {
            if (is_string($themesDir)) {
                array_push($directories, ...(glob(rtrim($themesDir, '/').'/*', GLOB_ONLYDIR) ?: []));
            }
        }
        if ($this->legacyThemeDir !== null && is_dir($this->legacyThemeDir)) {
            $directories[] = $this->legacyThemeDir;
        }

        $raw = [];
        foreach (array_unique($directories) as $directory) {
            $theme = $this->readManifest($directory);
            if ($theme !== null && ! isset($raw[$theme['id']])) {
                $raw[$theme['id']] = $theme;
            }
        }

        if (! isset($raw[self::DEFAULT_ID])) {
            $raw[self::DEFAULT_ID] = $this->builtInDefault();
        }
        $raw[self::DEFAULT_ID]['parent'] = null;

        $themes = [];
        foreach ($raw as $id => $theme) {
            $chain = $this->chain($id, $raw);
            if ($chain === null) {
                continue;
            }
            $theme['chain'] = $chain;
            $theme['assets'] = $this->effectiveAssets($chain, $raw);
            $themes[$id] = $theme;
        }

        ksort($themes);

        return $this->themes = $themes;
    }

    /**
     * Walks parents up to `default`. A missing parent, a cycle or a chain
     * deeper than MAX_DEPTH makes the theme unselectable.
     *
     * @param array<string, array<string, mixed>> $raw
     * @return list<string>|null
     */
    private function chain(string $id, array $raw): ?array
    {
        $chain = [];
        $current = $id;
        while ($current !== null) {
            if (! isset($raw[$current]) || in_array($current, $chain, true) || count($chain) >= self::MAX_DEPTH) {
                return null;
            }
            $chain[] = $current;
            $current = $raw[$current]['parent'];
        }

        return $chain;
    }

    /**
     * Parent assets first, then the child's, deduplicated. A theme with
     * `inheritAssets: false` starts a fresh list; its ancestors' assets are not
     * loaded. RTL replacements merge the same way, child wins.
     *
     * @param list<string> $chain
     * @param array<string, array<string, mixed>> $raw
     * @return array{entries: list<string>, styles: list<string>, rtl: array<string, string>}
     */
    private function effectiveAssets(array $chain, array $raw): array
    {
        $contributing = [];
        foreach ($chain as $id) {
            array_unshift($contributing, $raw[$id]);
            if (! $raw[$id]['inheritAssets']) {
                break;
            }
        }

        $result = ['entries' => [], 'styles' => [], 'rtl' => []];
        foreach ($contributing as $theme) {
            foreach (['entries', 'styles'] as $kind) {
                $result[$kind] = array_values(array_unique([...$result[$kind], ...$theme['assets'][$kind]]));
            }
            $result['rtl'] = $theme['assets']['rtl'] + $result['rtl'];
        }

        return $result;
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
        if (! preg_match(self::ID_PATTERN, $id) || $name === '' || strlen($name) > 100) {
            return null;
        }

        // `default` is the root: whatever its manifest says, it has no parent.
        $parent = $id === self::DEFAULT_ID ? null : ($manifest['parent'] ?? self::DEFAULT_ID);
        if ($parent !== null
            && (! is_string($parent) || ! preg_match(self::ID_PATTERN, $parent) || $parent === $id)) {
            return null;
        }

        $inheritAssets = $manifest['inheritAssets'] ?? true;
        if (! is_bool($inheritAssets)) {
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

        $assets = $this->assets($manifest['assets'] ?? [], $id);
        if ($assets === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'version' => (string) ($manifest['version'] ?? '1'),
            'themeColor' => $themeColor,
            'path' => $directory,
            'parent' => $parent,
            'inheritAssets' => $inheritAssets,
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
                if ($key === 'color_mode'
                    && isset($options['light'], $options['dark'])
                    && ! isset($options['system'])) {
                    $options['system'] = 'System';
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

    /**
     * Validates the theme's assets:
     *
     * - `entries`: names of Vite entries (Core\Assets resolves them to the
     *   built, content-hashed files with their stylesheets and imports);
     * - `styles`: the theme's own static stylesheets under /themes/<id>/,
     *   versioned with the file's modification time - they are not built;
     * - `rtl`: entry name => entry name to load instead for right-to-left
     *   locales.
     *
     * @return array{entries: list<string>, styles: list<string>, rtl: array<string, string>}|null
     */
    private function assets(mixed $input, string $id): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        if (array_diff(array_keys($input), ['entries', 'styles', 'rtl']) !== []) {
            return null;
        }

        $entries = $input['entries'] ?? [];
        $styles = $input['styles'] ?? [];
        $rtl = $input['rtl'] ?? [];
        if (! is_array($entries) || ! is_array($styles) || ! is_array($rtl)) {
            return null;
        }

        $result = ['entries' => [], 'styles' => [], 'rtl' => []];
        foreach ($entries as $entry) {
            if (! self::isEntryName($entry)) {
                return null;
            }
            $result['entries'][] = $entry;
        }
        foreach ($styles as $url) {
            if (! is_string($url)
                || preg_match('#\A/themes/'.preg_quote($id, '#').'/[A-Za-z0-9_./-]+\.css\z#', $url) !== 1
                || str_contains($url, '..')
                || ! is_file($this->publicDir.$url)) {
                return null;
            }
            $result['styles'][] = $url.'?v='.(int) filemtime($this->publicDir.$url);
        }
        foreach ($rtl as $ltr => $rtlEntry) {
            if (! self::isEntryName($ltr) || ! self::isEntryName($rtlEntry)) {
                return null;
            }
            $result['rtl'][$ltr] = $rtlEntry;
        }

        return $result;
    }

    private static function isEntryName(mixed $name): bool
    {
        return is_string($name) && preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $name) === 1;
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
            'parent' => null,
            'inheritAssets' => true,
            'settings' => [
                'color_mode' => [
                    'type' => 'select', 'label' => 'Color mode', 'default' => 'dark',
                    'options' => ['dark' => 'Dark', 'light' => 'Light', 'system' => 'System'],
                ],
            ],
            'assets' => [
                'entries' => ['base', 'site'],
                'styles' => [],
                'rtl' => [],
            ],
        ];
    }
}
