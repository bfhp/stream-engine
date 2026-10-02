<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\MenuRepository;

final class MenuRepositoryTest extends TestCase
{
    private const array ORDER = [
        ['id' => 2, 'parentId' => null, 'menuGroup' => 'main', 'sortOrder' => 10, 'groupOrder' => 10],
        ['id' => 1, 'parentId' => 2, 'menuGroup' => 'main', 'sortOrder' => 10, 'groupOrder' => 10],
    ];

    public function testReorderCommitsTheCompleteTreeAtomically(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('begin');
        $db->expects($this->exactly(4))->method('execute');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');

        (new MenuRepository($db))->reorder(self::ORDER);
    }

    public function testReorderRollsBackWhenAnyTreeWriteFails(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('begin');
        $calls = 0;
        $db->method('execute')->willReturnCallback(static function () use (&$calls): int {
            if (++$calls === 3) {
                throw new RuntimeException('write failed');
            }

            return 1;
        });
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollback');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('write failed');
        (new MenuRepository($db))->reorder(self::ORDER);
    }
}
