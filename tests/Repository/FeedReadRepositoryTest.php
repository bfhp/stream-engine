<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\FeedReadRepository;

final class FeedReadRepositoryTest extends TestCase
{
    public function testMarkAsReadUpsertsPositionAndReadAt(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'INSERT INTO feed_reads')
                        && str_contains($sql, 'ON DUPLICATE KEY UPDATE position = VALUES(position), read_at = VALUES(read_at)')
                ),
                [10, 7, 3]
            )
            ->willReturn(1);

        $repository = new FeedReadRepository($db);
        $repository->markAsRead(10, 7, 3);
    }

    public function testMarkAsReadWithoutPositionPassesNull(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with($this->anything(), [10, 7, null])
            ->willReturn(1);

        $repository = new FeedReadRepository($db);
        $repository->markAsRead(10, 7);
    }

    public function testFindReadAtReturnsNullWhenNoRowExists(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'feed_reads')),
                [10, 7]
            )
            ->willReturn(null);

        $repository = new FeedReadRepository($db);

        $this->assertNull($repository->findReadAt(10, 7));
    }

    public function testFindReadAtReturnsTimestampWhenRowExists(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->willReturn(['read_at' => '1700000000']);

        $repository = new FeedReadRepository($db);

        $this->assertSame(1700000000, $repository->findReadAt(10, 7));
    }

    public function testFindReadAtForFeedsReturnsEmptyArrayWithoutQueryingWhenNoIds(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchAll');

        $repository = new FeedReadRepository($db);

        $this->assertSame([], $repository->findReadAtForFeeds([], 7));
    }

    public function testFindReadAtForFeedsReturnsMapKeyedByParentId(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'feed_reads')
                        && str_contains($sql, 'parent_id IN (?,?,?)')
                ),
                [7, 10, 20, 30]
            )
            ->willReturn([
                ['parent_id' => '10', 'read_at' => '1700000000'],
                ['parent_id' => '30', 'read_at' => '1700000500'],
            ]);

        $repository = new FeedReadRepository($db);

        $this->assertSame(
            [10 => 1700000000, 30 => 1700000500],
            $repository->findReadAtForFeeds([10, 20, 30], 7)
        );
    }

    public function testFindParentIdsForUserReturnsIdsInOrder(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'feed_reads')
                        && str_contains($sql, 'ORDER BY read_at DESC')
                        && str_contains($sql, 'LIMIT 50 OFFSET 0')
                ),
                [7]
            )
            ->willReturn([
                ['parent_id' => '30'],
                ['parent_id' => '20'],
            ]);

        $repository = new FeedReadRepository($db);

        $this->assertSame([30, 20], $repository->findParentIdsForUser(7));
    }

    public function testFindReadParentsByTypeReturnsParentPositionTuples(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'JOIN feeds f ON f.id = fr.parent_id')
                        && str_contains($sql, 'f.type = ?')
                        && str_contains($sql, 'ORDER BY fr.read_at DESC')
                        && str_contains($sql, 'LIMIT 500')
                ),
                [7, 'publication']
            )
            ->willReturn([
                ['parent_id' => '58', 'position' => '12'],
                ['parent_id' => '61', 'position' => '0'],
            ]);

        $repository = new FeedReadRepository($db);

        $this->assertSame(
            [
                ['parentId' => 58, 'position' => 12],
                ['parentId' => 61, 'position' => 0],
            ],
            $repository->findReadParentsByType(7, 'publication')
        );
    }

    public function testDeleteForParentDeletesByUserAndParentId(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'DELETE FROM feed_reads')
                        && str_contains($sql, 'parent_id = ?')
                ),
                [7, 58]
            )
            ->willReturn(1);

        $repository = new FeedReadRepository($db);
        $repository->deleteForParent(7, 58);
    }
}
