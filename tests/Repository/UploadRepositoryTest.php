<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\UploadRepository;

final class UploadRepositoryTest extends TestCase
{
    public function testFindByIdsReturnsUploadsKeyedById(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())
            ->method('fetchAll')
            ->with(
                'SELECT id, user_id, path, mime, size, original_name, created_at FROM uploads WHERE id IN (?, ?)',
                [5, 7]
            )
            ->willReturn([
                ['id' => 7, 'user_id' => 3, 'path' => '3/song.mp3', 'mime' => 'audio/mpeg', 'size' => 4096, 'original_name' => 'song.mp3', 'created_at' => 1_700_000_000],
                ['id' => 5, 'user_id' => null, 'path' => 'shared/clip.ogg', 'mime' => 'audio/ogg', 'size' => 2048, 'original_name' => 'clip.ogg', 'created_at' => 1_700_000_001],
            ]);

        $repository = new UploadRepository($db);
        $uploads = $repository->findByIds([5, 7, 5, 0]);

        $this->assertSame([7, 5], array_keys($uploads));
        $this->assertSame('song.mp3', $uploads[7]->originalName);
        $this->assertNull($uploads[5]->userId);
    }

    public function testGetUserUsageReturnsSummedSize(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT COALESCE(SUM(size), 0) AS total FROM uploads WHERE user_id = ?', [7])
            ->willReturn(['total' => '4096']);

        $repository = new UploadRepository($db);

        $this->assertSame(4096, $repository->getUserUsage(7));
    }
}
