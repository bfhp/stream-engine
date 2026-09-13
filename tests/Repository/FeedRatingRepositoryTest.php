<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\FeedRatingRepository;

final class FeedRatingRepositoryTest extends TestCase
{
    public function testSubmitInsertsFirstVoteAndIncrementsCount(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $executed = [];

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'feed_ratings')
                    && str_contains($sql, 'FOR UPDATE')),
                [10, 7]
            )
            ->willReturn(null);

        $db
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use (&$executed): int {
                $executed[] = [$sql, $params];

                return 1;
            });

        $repository = new FeedRatingRepository($db);
        $repository->submit(feedId: 10, userId: 7, value: 4);

        $this->assertStringContainsString('INSERT INTO feed_ratings', $executed[0][0]);
        $this->assertSame([10, 7, 4], $executed[0][1]);

        $this->assertStringContainsString('rating_sum = rating_sum + ?', $executed[1][0]);
        $this->assertStringContainsString('rating_count = rating_count + 1', $executed[1][0]);
        $this->assertStringContainsString('rating_avg = rating_sum / rating_count', $executed[1][0]);
        $this->assertSame([4, 10], $executed[1][1]);
    }

    public function testSubmitUpdatesExistingVoteWithoutChangingCount(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $executed = [];

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(['value' => 3]);

        $db
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use (&$executed): int {
                $executed[] = [$sql, $params];

                return 1;
            });

        $repository = new FeedRatingRepository($db);
        $repository->submit(feedId: 10, userId: 7, value: 5);

        $this->assertStringContainsString('UPDATE feed_ratings', $executed[0][0]);
        $this->assertSame([5, 10, 7], $executed[0][1]);

        $this->assertStringContainsString('UPDATE feeds', $executed[1][0]);
        $this->assertStringContainsString('rating_sum = rating_sum + ?', $executed[1][0]);
        $this->assertStringContainsString('rating_avg = rating_sum / rating_count', $executed[1][0]);
        $this->assertStringNotContainsString('rating_count = rating_count', $executed[1][0]);
        // delta = newValue(5) - oldValue(3) = 2, count is untouched.
        $this->assertSame([2, 10], $executed[1][1]);
    }

    public function testSubmitIsANoOpWhenRevotingWithTheSameValue(): void
    {
        $db = $this->createMock(PdoDatabase::class);

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->once())->method('fetchOne')->willReturn(['value' => 5]);
        $db->expects($this->never())->method('execute');

        $repository = new FeedRatingRepository($db);
        $repository->submit(feedId: 10, userId: 7, value: 5);
    }

    public function testSubmitRollsBackOnFailure(): void
    {
        $db = $this->createMock(PdoDatabase::class);

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('fetchOne')->willReturn(null);
        $db->expects($this->once())->method('execute')->willThrowException(new RuntimeException('db down'));
        $db->expects($this->once())->method('rollback');
        $db->expects($this->never())->method('commit');

        $repository = new FeedRatingRepository($db);

        $this->expectException(RuntimeException::class);
        $repository->submit(feedId: 10, userId: 7, value: 5);
    }

    public function testFindUserValueReturnsNullWhenNoVoteExists(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->willReturn(null);

        $repository = new FeedRatingRepository($db);

        $this->assertNull($repository->findUserValue(10, 7));
    }

    public function testFindUserValueReturnsCastVote(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'feed_ratings')),
                [10, 7]
            )
            ->willReturn(['value' => '4']);

        $repository = new FeedRatingRepository($db);

        $this->assertSame(4, $repository->findUserValue(10, 7));
    }
}
