<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use Memcached;
use RuntimeException;

/**
 * Simple production-ready wrapper for Memcached key-value caching.
 *
 * Requires PHP extension: ext-memcached
 */
class Cache implements CacheInterface
{
    /**
     * Memcached instance.
     */
    private ?Memcached $client = null;

    /**
     * Key prefix to avoid collisions.
     */
    private string $prefix;

    /**
     * Default TTL in seconds.
     */
    private int $defaultTtl;

    /**
     * In-memory fallback when ext-memcached is unavailable.
     */
    private array $fallback = [];

    /**
     * Constructor.
     *
     * @param  array  $servers  List of servers [['host' => '127.0.0.1', 'port' => 11211]]
     * @param  string  $prefix  Optional key prefix
     * @param  int  $defaultTtl  Default TTL in seconds
     * @param  string|null  $persistentId  Persistent connection ID (optional)
     *
     * @throws RuntimeException
     */
    public function __construct(
        array $servers = [['host' => '127.0.0.1', 'port' => 11211]],
        string $prefix = '',
        int $defaultTtl = 3600,
        ?string $persistentId = null
    ) {
        $this->prefix = $prefix;
        $this->defaultTtl = $defaultTtl;

        if (class_exists(Memcached::class)) {
            $this->client = new Memcached($persistentId);

            if (!$this->client->getServerList()) {
                foreach ($servers as $server) {
                    $this->client->addServer($server['host'], $server['port']);
                }
            }

            // Recommended options
            $this->client->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
            $this->client->setOption(Memcached::OPT_TCP_NODELAY, true);
            $this->client->setOption(Memcached::OPT_COMPRESSION, true);
        }
    }

    /**
     * Builds namespaced key.
     */
    private function buildKey(string $key): string
    {
        return $this->prefix.$key;
    }

    /**
     * Stores a value in cache.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($this->client) {
            return $this->client->set(
                $this->buildKey($key),
                $value,
                $ttl ?? $this->defaultTtl
            );
        }

        $this->fallback[$this->buildKey($key)] = $value;
        return true;
    }

    /**
     * Retrieves a value from cache.
     *
     *
     * @return mixed|null Returns null if key not found
     */
    public function get(string $key): mixed
    {
        if ($this->client) {
            $value = $this->client->get($this->buildKey($key));

            if ($this->client->getResultCode() === Memcached::RES_NOTFOUND) {
                return null;
            }

            return $value;
        }

        return $this->fallback[$this->buildKey($key)] ?? null;
    }

    /**
     * Checks whether a key exists.
     */
    public function has(string $key): bool
    {
        if ($this->client) {
            $this->client->get($this->buildKey($key));

            return $this->client->getResultCode() !== Memcached::RES_NOTFOUND;
        }

        return array_key_exists($this->buildKey($key), $this->fallback);
    }

    /**
     * Deletes a key from cache.
     */
    public function delete(string $key): bool
    {
        if ($this->client) {
            return $this->client->delete($this->buildKey($key));
        }

        unset($this->fallback[$this->buildKey($key)]);

        return true;
    }

    /**
     * Clears entire cache.
     */
    public function flush(): bool
    {
        if ($this->client) {
            return $this->client->flush();
        }

        $this->fallback = [];

        return true;
    }

    /**
     * Returns value or stores it using callback if missing.
     *
     * @param  callable  $callback  Function that generates value
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
}
