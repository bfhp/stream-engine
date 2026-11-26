<?php

declare(strict_types=1);

namespace StreamEngine\Core;

interface CacheInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    public function delete(string $key): bool;

    public function getOrSet(string $key, callable $callback, ?int $ttl = null): mixed;

    /**
     * The cached values of $keys, by key; a key that is not cached (or holds
     * null, as get() sees it) is absent. Batched: a sitemap file asked for
     * 30,000 URLs one get at a time, 1.3-1.7 s of round trips on production.
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function getMany(array $keys): array;

    /**
     * Stores every value of $values under its key, batched as getMany().
     *
     * @param array<string, mixed> $values
     */
    public function setMany(array $values, ?int $ttl = null): bool;
}
