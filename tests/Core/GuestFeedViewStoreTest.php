<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\GuestFeedViewStore;

final class GuestFeedViewStoreTest extends TestCase
{
    private array $cookieBackup = [];

    protected function setUp(): void
    {
        $this->cookieBackup = $_COOKIE;
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookieBackup;
    }

    public function testClaimDeduplicatesWithinWindowAndRenewsAtBoundary(): void
    {
        $store = new GuestFeedViewStore();

        self::assertTrue($store->claim(58, 86400, 1_700_000_000));
        self::assertFalse($store->claim(58, 86400, 1_700_086_399));
        self::assertTrue($store->claim(58, 86400, 1_700_086_400));
    }

    public function testMalformedCookieStartsFresh(): void
    {
        $_COOKIE['feed_views'] = '{broken';

        self::assertTrue((new GuestFeedViewStore())->claim(58, 86400, 1_700_000_000));
    }

    public function testCookieStaysUnderTheBrowserLimitOnceUrlEncoded(): void
    {
        $store = new GuestFeedViewStore();

        for ($feedId = 1_000_000_000; $feedId < 1_000_000_100; $feedId++) {
            $store->claim($feedId, 86400, 1_700_000_000);
        }

        self::assertLessThanOrEqual(3500, strlen(urlencode($_COOKIE['feed_views'])));
        self::assertFalse($store->claim(1_000_000_099, 86400, 1_700_000_001), 'newest view must survive pruning');
    }
}
