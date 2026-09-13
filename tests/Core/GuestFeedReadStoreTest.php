<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\GuestFeedReadStore;

final class GuestFeedReadStoreTest extends TestCase
{
    /** Mirrors GuestFeedReadStore::MAX_ENTRIES, which is private. */
    private const int MAX_ENTRIES = 100;

    private array $cookieBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cookieBackup = $_COOKIE;
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookieBackup;

        parent::tearDown();
    }

    public function testFindReadAtReturnsNullWhenNoCookieSet(): void
    {
        $store = new GuestFeedReadStore();

        $this->assertNull($store->findReadAt(58));
    }

    public function testMarkAsReadIsReadableInTheSameRequest(): void
    {
        $store = new GuestFeedReadStore();

        $before = time();
        $store->markAsRead(58, 3);
        $after = time();

        $readAt = $store->findReadAt(58);

        $this->assertNotNull($readAt);
        $this->assertGreaterThanOrEqual($before, $readAt);
        $this->assertLessThanOrEqual($after, $readAt);
    }

    public function testMarkAsReadOverwritesThePreviousPositionForTheSameParent(): void
    {
        $store = new GuestFeedReadStore();

        $store->markAsRead(58, 10);
        $store->markAsRead(58, 3);

        $this->assertSame(['position' => 3, 'readAt' => $store->findReadAt(58)], $store->all()[58]);
    }

    public function testFindReadAtForFeedsReturnsEmptyArrayForEmptyInput(): void
    {
        $store = new GuestFeedReadStore();
        $store->markAsRead(58);

        $this->assertSame([], $store->findReadAtForFeeds([]));
    }

    public function testFindReadAtForFeedsReturnsOnlyRequestedIds(): void
    {
        $store = new GuestFeedReadStore();
        $store->markAsRead(58);
        $store->markAsRead(60);
        $store->markAsRead(70);

        $result = $store->findReadAtForFeeds([58, 60, 999]);

        $this->assertSame([58, 60], array_keys($result));
    }

    public function testMalformedCookieIsIgnored(): void
    {
        $_COOKIE['feed_reads'] = 'not-json';

        $store = new GuestFeedReadStore();

        $this->assertNull($store->findReadAt(58));
    }

    public function testMalformedEntriesAreIgnored(): void
    {
        $_COOKIE['feed_reads'] = json_encode([
            '58' => ['position' => 3, 'readAt' => 1700000000],
            'not-a-parent-id' => ['position' => null, 'readAt' => 1700000000],
            '60' => ['position' => 1, 'readAt' => 'not-a-timestamp'],
            '61' => 'not-an-object',
        ]);

        $store = new GuestFeedReadStore();

        $this->assertSame(1700000000, $store->findReadAt(58));
        $this->assertNull($store->findReadAt(60));
        $this->assertNull($store->findReadAt(61));
    }

    public function testMarkAsReadPrunesOldestEntryOnceOverCapacity(): void
    {
        $now = time();
        $map = [];

        for ($parentId = 1; $parentId <= self::MAX_ENTRIES; $parentId++) {
            // Strictly increasing and safely in the past, so parent 1 is the
            // unambiguous oldest entry and every timestamp here is older
            // than the "now" markAsRead() below will stamp.
            $map[(string) $parentId] = ['position' => null, 'readAt' => $now - 100000 + $parentId];
        }

        $_COOKIE['feed_reads'] = json_encode($map, JSON_FORCE_OBJECT);

        $store = new GuestFeedReadStore();
        $store->markAsRead(9999);

        $this->assertNull($store->findReadAt(1), 'oldest entry should have been pruned');
        $this->assertNotNull($store->findReadAt(2), 'second-oldest entry should survive');
        $this->assertNotNull($store->findReadAt(self::MAX_ENTRIES));
        $this->assertNotNull($store->findReadAt(9999), 'newly read parent should be present');

        $remaining = $store->findReadAtForFeeds(array_merge(range(1, self::MAX_ENTRIES), [9999]));
        $this->assertCount(self::MAX_ENTRIES, $remaining);
    }

    public function testAllReturnsEmptyArrayWhenNoCookieSet(): void
    {
        $store = new GuestFeedReadStore();

        $this->assertSame([], $store->all());
    }

    public function testAllReturnsTheFullMap(): void
    {
        $store = new GuestFeedReadStore();
        $store->markAsRead(58, 4);
        $store->markAsRead(60);

        $all = $store->all();

        $this->assertSame([58, 60], array_keys($all));
        $this->assertSame(4, $all[58]['position']);
        $this->assertNull($all[60]['position']);
    }

    public function testRemoveForParentIsNoopWhenNeverRead(): void
    {
        $_COOKIE['feed_reads'] = json_encode(['58' => ['position' => null, 'readAt' => 1700000000]]);

        $store = new GuestFeedReadStore();
        $store->removeForParent(999);

        $this->assertSame(1700000000, $store->findReadAt(58));
    }

    public function testRemoveForParentDropsOnlyTheGivenParent(): void
    {
        $store = new GuestFeedReadStore();
        $store->markAsRead(58);
        $store->markAsRead(60);

        $store->removeForParent(58);

        $this->assertNull($store->findReadAt(58));
        $this->assertNotNull($store->findReadAt(60));
    }
}
