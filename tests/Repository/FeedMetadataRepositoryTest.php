<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\FeedMetadataRepository;

final class FeedMetadataRepositoryTest extends TestCase
{
    public function testFindByFeedIdsGroupsMetadataByFeedId(): void
    {
        $db = $this->createMock(PdoDatabase::class);

        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'feed_metadata')
                    && str_contains($sql, 'feed_id IN')),
                [10, 20]
            )
            ->willReturn([
                [
                    'feed_id' => 10,
                    'name' => 'book-type',
                    'content' => 'fragment',
                ],
                [
                    'feed_id' => 10,
                    'name' => 'isbn',
                    'content' => '978-5-17-123456-7',
                ],
            ]);

        $repository = new FeedMetadataRepository($db);
        $result = $repository->findByFeedIds([10, 20, 10]);

        $this->assertSame('fragment', $result[10]['book-type']);
        $this->assertSame('978-5-17-123456-7', $result[10]['isbn']);
        $this->assertArrayNotHasKey(20, $result);
    }

    public function testReplaceForFeedNormalizesAndReplacesMetadata(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $executed = [];

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');
        $db
            ->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use (&$executed): int {
                $executed[] = [$sql, $params];

                return 1;
            });

        $repository = new FeedMetadataRepository($db);
        $repository->replaceForFeed(10, [
            'Book-Type' => ' fragment ',
            'isbn' => ' 978-5-17-123456-7 ',
            'empty' => '',
            'nil' => null,
        ]);

        $this->assertSame([10], $executed[0][1]);
        $this->assertSame([10, 'book-type', 'fragment'], $executed[1][1]);
        $this->assertSame([10, 'isbn', '978-5-17-123456-7'], $executed[2][1]);
    }
}
