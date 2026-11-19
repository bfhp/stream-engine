<?php

declare(strict_types=1);

namespace Tests\Modules\Forums;

use PHPUnit\Framework\TestCase;
use StreamEngine\Modules\Forums\GuestForumReadStore;

final class GuestForumReadStoreTest extends TestCase
{
    private array $cookieBackup;

    protected function setUp(): void
    {
        $this->cookieBackup = $_COOKIE;
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookieBackup;
    }

    public function testGlobalReadCoversEverySectionWithoutPerTopicEntries(): void
    {
        $before = time();
        (new GuestForumReadStore())->markAsRead(null);
        $store = new GuestForumReadStore();

        foreach (range(1, 200) as $forumId) {
            self::assertGreaterThanOrEqual($before, $store->findReadAt($forumId));
        }
        self::assertLessThan(100, strlen($_COOKIE['forum_reads']));
    }

    public function testSectionReadKeepsGlobalAndOtherSectionMarkers(): void
    {
        $_COOKIE['forum_reads'] = json_encode([0 => 100, 20 => 200, 21 => 300]);
        $store = new GuestForumReadStore();
        $store->markAsRead([22]);

        self::assertSame(200, $store->findReadAt(20));
        self::assertSame(300, $store->findReadAt(21));
        self::assertSame(100, $store->findReadAt(999));
        self::assertGreaterThan(300, $store->findReadAt(22));
    }

    public function testMalformedCookieAndEntriesAreIgnored(): void
    {
        $_COOKIE['forum_reads'] = 'broken';
        $store = new GuestForumReadStore();
        self::assertNull($store->findReadAt(20));
        $_COOKIE['forum_reads'] = json_encode([20 => ['bad'], 21 => 'invalid', 22 => 100, 'bad' => 200]);
        self::assertNull($store->findReadAt(20));
        self::assertNull($store->findReadAt(21));
        self::assertSame(100, $store->findReadAt(22));
        $store->markAsRead([20]);
        self::assertNotNull((new GuestForumReadStore())->findReadAt(20));
    }

    public function testGlobalMarkerSurvivesSectionHistoryPruning(): void
    {
        $_COOKIE['forum_reads'] = json_encode([0 => 100]);
        (new GuestForumReadStore())->markAsRead(range(1, 150));

        self::assertSame(100, (new GuestForumReadStore())->findReadAt(999));
        self::assertLessThan(4096, strlen(urlencode($_COOKIE['forum_reads'])));
    }
}
