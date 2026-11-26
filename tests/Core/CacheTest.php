<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use StreamEngine\Core\Cache;

final class CacheTest extends TestCase
{
    private function makeFallbackCache(string $prefix = 'test:'): Cache
    {
        $reflection = new ReflectionClass(Cache::class);
        /** @var Cache $cache */
        $cache = $reflection->newInstanceWithoutConstructor();

        $this->setPrivateProperty($cache, 'client', null);
        $this->setPrivateProperty($cache, 'prefix', $prefix);
        $this->setPrivateProperty($cache, 'defaultTtl', 3600);
        $this->setPrivateProperty($cache, 'fallback', []);

        return $cache;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }

    public function testFallbackSetGetAndHasRespectPrefix(): void
    {
        $cache = $this->makeFallbackCache('prefix:');

        $this->assertTrue($cache->set('answer', 42));
        $this->assertSame(42, $cache->get('answer'));
        $this->assertTrue($cache->has('answer'));
        $this->assertNull($cache->get('missing'));
        $this->assertFalse($cache->has('missing'));
    }

    public function testDeleteRemovesFallbackValue(): void
    {
        $cache = $this->makeFallbackCache();
        $cache->set('foo', 'bar');

        $this->assertTrue($cache->delete('foo'));
        $this->assertNull($cache->get('foo'));
        $this->assertFalse($cache->has('foo'));
    }

    public function testBulkOperationsPreserveValuesAcrossBatches(): void
    {
        $cache = $this->makeFallbackCache('bulk:');
        $cache->set('existing', 'keep');
        $values = [];
        for ($index = 0; $index < 2005; $index++) {
            $values['item:'.$index] = 'value:'.$index;
        }
        $values += ['false' => false, 'zero' => 0, 'empty' => ''];

        $this->assertTrue($cache->setMany($values + ['null' => null]));
        $this->assertSame($values, $cache->getMany([...array_keys($values), 'null', 'missing']));
        $this->assertSame('value:2004', $cache->get('item:2004'));
        $this->assertSame('keep', $cache->get('existing'));
        $this->assertTrue($cache->setMany(['item:0' => 'updated']));
        $this->assertSame('updated', $cache->get('item:0'));
        $this->assertSame([], $cache->getMany([]));
        $this->assertTrue($cache->setMany([]));
    }

    public function testBulkFallbackWritesPreserveNumericKeys(): void
    {
        $cache = $this->makeFallbackCache('');
        $cache->set('42', 'before');

        $this->assertTrue($cache->setMany(['42' => 'after', '9001' => 'new']));
        $this->assertSame('after', $cache->get('42'));
        $this->assertSame('new', $cache->get('9001'));
        $this->assertSame([42 => 'after', 9001 => 'new'], $cache->getMany(['42', '9001']));
    }

    public function testFlushClearsFallbackValues(): void
    {
        $cache = $this->makeFallbackCache();
        $cache->set('first', 1);
        $cache->set('second', 2);

        $this->assertTrue($cache->flush());
        $this->assertNull($cache->get('first'));
        $this->assertNull($cache->get('second'));
        $this->assertFalse($cache->has('first'));
        $this->assertFalse($cache->has('second'));
    }

    public function testGetOrSetCachesComputedValue(): void
    {
        $cache = $this->makeFallbackCache();
        $calls = 0;

        $first = $cache->getOrSet('computed', function () use (&$calls): array {
            $calls++;

            return ['value' => 123];
        });

        $second = $cache->getOrSet('computed', function () use (&$calls): array {
            $calls++;

            return ['value' => 456];
        });

        $this->assertSame(['value' => 123], $first);
        $this->assertSame(['value' => 123], $second);
        $this->assertSame(1, $calls);
    }
}
