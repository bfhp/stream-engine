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
                'SELECT id, user_id, path, mime, size, original_name, created_at, purpose FROM uploads WHERE id IN (?, ?)',
                [5, 7]
            )
            ->willReturn([
                ['id' => 7, 'user_id' => 3, 'path' => '3/song.mp3', 'mime' => 'audio/mpeg', 'size' => 4096, 'original_name' => 'song.mp3', 'created_at' => 1_700_000_000, 'purpose' => null],
                ['id' => 5, 'user_id' => null, 'path' => 'shared/clip.ogg', 'mime' => 'audio/ogg', 'size' => 2048, 'original_name' => 'clip.ogg', 'created_at' => 1_700_000_001, 'purpose' => 'forum-attachment'],
            ]);

        $repository = new UploadRepository($db);
        $uploads = $repository->findByIds([5, 7, 5, 0]);

        $this->assertSame([7, 5], array_keys($uploads));
        $this->assertSame('song.mp3', $uploads[7]->originalName);
        $this->assertNull($uploads[5]->userId);
        $this->assertSame('forum-attachment', $uploads[5]->purpose);
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

    public function testRelocatePathUpdatesOneFileOrADirectoryTree(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $sql, array $params): int {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    $this->assertSame('UPDATE uploads SET path = ? WHERE path = ?', $sql);
                    $this->assertSame(['new.jpg', 'old.jpg'], $params);
                } else {
                    $this->assertStringContainsString('SET path = CONCAT(?, SUBSTRING(path, ?))', $sql);
                    $this->assertSame(['archive', 8, 'gallery', 8, 'gallery'], $params);
                }

                return 1;
            });

        $repository = new UploadRepository($db);
        $repository->relocatePath('old.jpg', 'new.jpg', false);
        $repository->relocatePath('gallery', 'archive', true);
    }

    public function testDeletePathRemovesOneFileOrADirectoryTree(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $sql, array $params): int {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    $this->assertSame('DELETE FROM uploads WHERE path = ?', $sql);
                    $this->assertSame(['old.jpg'], $params);
                } else {
                    $this->assertStringContainsString('DELETE FROM uploads', $sql);
                    $this->assertSame(['gallery', 8, 'gallery'], $params);
                }

                return 1;
            });

        $repository = new UploadRepository($db);
        $repository->deletePath('old.jpg', false);
        $repository->deletePath('gallery', true);
    }

    public function testCopyPathDuplicatesOneFileOrADirectoryTree(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $sql, array $params): int {
                static $call = 0;
                $call++;
                $this->assertStringContainsString('INSERT INTO uploads', $sql);
                if ($call === 1) {
                    $this->assertSame(['new.jpg', 'old.jpg'], $params);
                } else {
                    $this->assertStringContainsString('CONCAT(?, SUBSTRING(path, ?))', $sql);
                    $this->assertSame(['archive', 8, 'gallery', 8, 'gallery'], $params);
                }

                return 1;
            });

        $repository = new UploadRepository($db);
        $repository->copyPath('old.jpg', 'new.jpg', false);
        $repository->copyPath('gallery', 'archive', true);
    }

    public function testForumAttachmentReservationIsOwnerAndPurposeScoped(): void
    {
        $row = [
            'id' => 41,
            'user_id' => 7,
            'path' => '7/file.pdf',
            'mime' => 'application/pdf',
            'size' => 100,
            'original_name' => 'file.pdf',
            'created_at' => 1_700_000_000,
            'purpose' => UploadRepository::PURPOSE_FORUM_ATTACHMENT_RESERVED,
        ];
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('execute')->with(
            $this->stringContains('SET purpose = ?, created_at = UNIX_TIMESTAMP()'),
            [
                UploadRepository::PURPOSE_FORUM_ATTACHMENT_RESERVED,
                41,
                7,
                UploadRepository::PURPOSE_FORUM_ATTACHMENT,
                UploadRepository::PURPOSE_FORUM_ATTACHMENT_RESERVED,
            ]
        )->willReturn(1);
        $db->expects($this->once())->method('fetchOne')->willReturn($row);

        $upload = (new UploadRepository($db))->reserveOwnedForumAttachment(41, 7);

        $this->assertSame(41, $upload?->id);
        $this->assertSame(UploadRepository::PURPOSE_FORUM_ATTACHMENT_RESERVED, $upload?->purpose);
    }

    public function testOrphanCandidatesExcludeReferencedTopicAttachments(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchAll')->with(
            $this->callback(static fn (string $sql): bool =>
                str_contains($sql, "fm.name = 'attachment_upload_ids'")
                && str_contains($sql, 'JSON_CONTAINS')
                && str_contains($sql, 'u.created_at < ?')
                && str_contains($sql, 'LIMIT 100')),
            [
                UploadRepository::PURPOSE_FORUM_ATTACHMENT,
                UploadRepository::PURPOSE_FORUM_ATTACHMENT_RESERVED,
                UploadRepository::PURPOSE_FORUM_ATTACHMENT_DELETING,
                1_700_000_000,
            ]
        )->willReturn([['id' => '41'], ['id' => '42']]);

        $this->assertSame(
            [41, 42],
            (new UploadRepository($db))->findOrphanedForumAttachmentIds(1_700_000_000, 100)
        );
    }

    public function testClaimRepeatsTheReferenceAndAgeGuardsBeforeDeletion(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('execute')->with(
            $this->callback(static fn (string $sql): bool =>
                str_contains($sql, 'NOT EXISTS')
                && str_contains($sql, 'JSON_CONTAINS')
                && str_contains($sql, 'u.created_at < ?')),
            [
                UploadRepository::PURPOSE_FORUM_ATTACHMENT_DELETING,
                41,
                UploadRepository::PURPOSE_FORUM_ATTACHMENT,
                UploadRepository::PURPOSE_FORUM_ATTACHMENT_RESERVED,
                UploadRepository::PURPOSE_FORUM_ATTACHMENT_DELETING,
                1_700_000_000,
            ]
        )->willReturn(0);
        $db->expects($this->never())->method('fetchOne');

        $this->assertNull(
            (new UploadRepository($db))->claimOrphanedForumAttachment(41, 1_700_000_000)
        );
    }
}
