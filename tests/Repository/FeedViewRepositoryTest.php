<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\FeedViewRepository;

final class FeedViewRepositoryTest extends TestCase
{
    public function testClaimAndCounterIncrementAreCommittedTogether(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');
        $db->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params): int {
                if (str_contains($sql, 'INSERT INTO feed_views')) {
                    self::assertSame([58, 7, 86400], $params);

                    return 1;
                }

                self::assertStringContainsString('UPDATE feeds SET views = views + 1', $sql);
                self::assertSame([58], $params);

                return 1;
            });

        self::assertTrue((new FeedViewRepository($db))->recordForUser(58, 7, 86400));
    }

    public function testRecentMarkerDoesNotIncrementCounter(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');
        $db->expects($this->once())
            ->method('execute')
            ->with($this->stringContains('INSERT INTO feed_views'), [58, 7, 86400])
            ->willReturn(0);

        self::assertFalse((new FeedViewRepository($db))->recordForUser(58, 7, 86400));
    }
}
