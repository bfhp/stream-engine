<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\FeedFavoriteRepository;

final class FeedFavoriteRepositoryTest extends TestCase
{
    public function testIsFavoritedReturnsFalseWhenNoRowExists(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'feed_favorites')),
                [10, 7]
            )
            ->willReturn(null);

        $repository = new FeedFavoriteRepository($db);

        $this->assertFalse($repository->isFavorited(10, 7));
    }

    public function testIsFavoritedReturnsTrueWhenRowExists(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->willReturn(['1' => 1]);

        $repository = new FeedFavoriteRepository($db);

        $this->assertTrue($repository->isFavorited(10, 7));
    }

    public function testAddInsertsFavoriteIdempotently(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'INSERT INTO feed_favorites')
                        && str_contains($sql, 'ON DUPLICATE KEY UPDATE feed_id = feed_id')
                ),
                [10, 7]
            )
            ->willReturn(1);

        $repository = new FeedFavoriteRepository($db);
        $repository->add(10, 7);
    }

    public function testRemoveDeletesFavorite(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'DELETE FROM feed_favorites')),
                [10, 7]
            )
            ->willReturn(1);

        $repository = new FeedFavoriteRepository($db);
        $repository->remove(10, 7);
    }

    public function testFindFeedIdsForUserReturnsIdsInOrder(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'feed_favorites')
                        && str_contains($sql, 'ORDER BY created_at DESC')
                        && str_contains($sql, 'LIMIT 50 OFFSET 0')
                ),
                [7]
            )
            ->willReturn([
                ['feed_id' => '30'],
                ['feed_id' => '20'],
            ]);

        $repository = new FeedFavoriteRepository($db);

        $this->assertSame([30, 20], $repository->findFeedIdsForUser(7));
    }
}
