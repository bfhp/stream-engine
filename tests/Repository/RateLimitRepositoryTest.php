<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\RateLimitRepository;

final class RateLimitRepositoryTest extends TestCase
{
    public function testConsumeUsesAtomicUpsertAndReturnsPersistedCount(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())
            ->method('execute')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'INSERT INTO auth_rate_limits')
                    && str_contains($sql, 'ON DUPLICATE KEY UPDATE')
                    && str_contains($sql, 'LAST_INSERT_ID(1)')
                    && str_contains($sql, 'LEAST(attempts + 1, ?)')),
                ['login-account', str_repeat('a', 64), 1_700_000_900, 1_700_000_000, 6, 1_700_000_000]
            )
            ->willReturn(1);
        $db->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT LAST_INSERT_ID() AS attempts')
            ->willReturn(['attempts' => 4]);

        $count = (new RateLimitRepository($db))->consume(
            'login-account',
            str_repeat('a', 64),
            5,
            900,
            1_700_000_000,
        );

        self::assertSame(4, $count);
    }

    public function testConsumeFailsClosedWhenPersistedCounterCannotBeRead(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturn(1);
        $db->method('fetchOne')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not persisted');

        (new RateLimitRepository($db))->consume('login-ip', str_repeat('b', 64), 50, 900, 1000);
    }

    public function testDeleteClearsOnlyTheRequestedBucket(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())
            ->method('execute')
            ->with(
                'DELETE FROM auth_rate_limits WHERE scope = ? AND subject_hash = ?',
                ['login-account', str_repeat('c', 64)]
            );

        (new RateLimitRepository($db))->delete('login-account', str_repeat('c', 64));
    }
}
