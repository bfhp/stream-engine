<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use StreamEngine\Repository\SettingsRepository;

final class SettingsService
{
    public const array CRON_MODES = ['os', 'web', 'off'];
    public const string SITE_ICON_KEY = 'site_icon';
    public const string SITE_ICON_SVG_KEY = 'site_icon_svg';

    /**
     * @var array<string, string>
     */
    private array $data = [];

    private int $lastModified = 0;

    private bool $loaded = false;

    public function __construct(
        private readonly SettingsRepository $repository,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureLoaded();

        return $this->data[$key] ?? $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $this->ensureLoaded();

        return $this->data[$key] ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $this->ensureLoaded();

        return (int) ($this->data[$key] ?? $default);
    }

    public function lastModified(): int
    {
        $this->ensureLoaded();

        return $this->lastModified;
    }

    public function cronMode(): string
    {
        $mode = $this->getString('cron.mode', 'os');

        return in_array($mode, self::CRON_MODES, true) ? $mode : 'off';
    }

    /**
     * @return array{svg:?string, ico:string, apple:string}
     */
    public function siteIcons(): array
    {
        $base = $this->getString(self::SITE_ICON_KEY);
        if (preg_match('~\A/uploads/site-icons/favicon-[a-f0-9]{16}\z~', $base) !== 1) {
            return [
                'svg' => '/favicon.svg',
                'ico' => '/favicon.ico',
                'apple' => '/apple-touch-icon.png',
            ];
        }

        $svg = $this->getString(self::SITE_ICON_SVG_KEY);
        if ($svg !== $base.'.svg') {
            $svg = null;
        }

        return [
            'svg' => $svg,
            'ico' => $base.'.ico',
            'apple' => $base.'.png',
        ];
    }

    public function set(string $key, string $value): void
    {
        $this->repository->set($key, $value);
        $this->reload();
    }

    private function ensureLoaded(): void
    {
        if (! $this->loaded) {
            $this->reload();
        }
    }

    private function reload(): void
    {
        $settings = $this->repository->load();
        $this->data = $settings['data'];
        $this->lastModified = $settings['lastModified'];
        $this->loaded = true;
    }
}
