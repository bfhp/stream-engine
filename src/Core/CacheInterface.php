<?php

declare(strict_types=1);

namespace StreamEngine\Core;

interface CacheInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    public function delete(string $key): bool;

    public function getOrSet(string $key, callable $callback, ?int $ttl = null): mixed;
}
