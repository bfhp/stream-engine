<?php

declare(strict_types=1);

namespace Tests\Support;

use StreamEngine\Core\CacheInterface;

final class ArrayCache implements CacheInterface
{
    private array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->data[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key]);
        return true;
    }

    /**
     * The same miss condition as Cache::getOrSet() - `null` means miss, so a
     * cached null is recomputed every time. Copied deliberately: a fake that
     * is kinder than the real thing hides the bug it was meant to catch.
     */
    public function getOrSet(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function flush(): void
    {
        $this->data = [];
    }
}
