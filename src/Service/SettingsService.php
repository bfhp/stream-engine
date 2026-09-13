<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use StreamEngine\Repository\SettingsRepository;

final class SettingsService
{
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
