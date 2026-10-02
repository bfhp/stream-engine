<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\ThemeCatalog;
use StreamEngine\Repository\SettingsRepository;

final readonly class ThemeService
{
    public const string ACTIVE_KEY = 'theme.active';

    public function __construct(
        private ThemeCatalog $catalog,
        private SettingsService $settings,
        private SettingsRepository $repository,
    ) {
    }

    public function configuredId(): string
    {
        return $this->settings->getString(
            self::ACTIVE_KEY,
            $this->catalog->legacyThemeId() ?? ThemeCatalog::DEFAULT_ID,
        );
    }

    /** @return array<string, mixed> */
    public function active(?string $previewId = null): array
    {
        $configuredId = $previewId ?? $this->configuredId();
        $theme = $this->catalog->resolve($configuredId);

        return $this->withValues($theme) + [
            'configuredId' => $configuredId,
            'fallback' => $theme['id'] !== $configuredId,
            'preview' => $previewId !== null,
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $active = $this->active();

        return [
            'activeId' => $active['id'],
            'configuredId' => $active['configuredId'],
            'fallback' => $active['fallback'],
            'themes' => array_map(function (array $theme): array {
                $theme = $this->withValues($theme);
                unset($theme['path']);

                return $theme;
            }, $this->catalog->all()),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function save(string $themeId, array $values): array
    {
        $theme = $this->catalog->find($themeId);
        if ($theme === null) {
            throw new ValidationException('Unknown theme');
        }

        $writes = [];
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! isset($theme['settings'][$key])) {
                throw new ValidationException('Unknown theme setting');
            }
            $writes['theme.'.$themeId.'.'.$key] = $this->validateValue(
                $theme['settings'][$key],
                $value,
            );
        }
        $writes[self::ACTIVE_KEY] = $themeId;
        $this->repository->setMany($writes);

        return $this->payloadAfterSave($themeId, $writes);
    }

    /** @return array<string, mixed> */
    private function withValues(array $theme): array
    {
        $values = [];
        foreach ($theme['settings'] as $key => $definition) {
            $values[$key] = $this->settings->getString(
                'theme.'.$theme['id'].'.'.$key,
                (string) $definition['default'],
            );
        }
        $theme['values'] = $values;

        return $theme;
    }

    private function validateValue(array $definition, mixed $value): string
    {
        if ($definition['type'] === 'boolean') {
            if (! is_bool($value) && ! in_array($value, [0, 1, '0', '1'], true)) {
                throw new ValidationException('Theme setting must be a boolean');
            }

            return ($value === true || $value === 1 || $value === '1') ? '1' : '0';
        }

        if (! is_string($value) || strlen($value) > 500) {
            throw new ValidationException('Invalid theme setting value');
        }
        if ($definition['type'] === 'select' && ! array_key_exists($value, $definition['options'])) {
            throw new ValidationException('Unsupported theme setting value');
        }
        if ($definition['type'] === 'color' && ! preg_match('/\A#[0-9a-fA-F]{6}\z/', $value)) {
            throw new ValidationException('Theme color must use #RRGGBB');
        }

        return $value;
    }

    /**
     * SettingsService deliberately caches. Build the response from the saved
     * values so the admin sees the new state without relying on that cache.
     *
     * @param array<string, string> $writes
     * @return array<string, mixed>
     */
    private function payloadAfterSave(string $themeId, array $writes): array
    {
        $theme = $this->catalog->find($themeId);
        $theme['values'] = [];
        foreach ($theme['settings'] as $key => $definition) {
            $theme['values'][$key] = $writes['theme.'.$themeId.'.'.$key]
                ?? $this->settings->getString('theme.'.$themeId.'.'.$key, $definition['default']);
        }
        unset($theme['path']);

        return ['activeId' => $themeId, 'configuredId' => $themeId, 'fallback' => false, 'theme' => $theme];
    }
}
