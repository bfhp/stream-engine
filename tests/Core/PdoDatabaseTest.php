<?php

declare(strict_types=1);

namespace Tests\Core;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use StreamEngine\Core\PdoDatabase;

final class PdoDatabaseTest extends TestCase
{
    private function makeDatabase(PDO $pdo): PdoDatabase
    {
        $reflection = new ReflectionClass(PdoDatabase::class);
        /** @var PdoDatabase $db */
        $db = $reflection->newInstanceWithoutConstructor();

        $pdoProperty = new ReflectionProperty(PdoDatabase::class, 'pdo');
        $pdoProperty->setValue($db, $pdo);

        $queryLogProperty = new ReflectionProperty(PdoDatabase::class, 'queryLog');
        $queryLogProperty->setValue($db, []);

        return $db;
    }

    public function testFetchOneReturnsRowAndLogsQuery(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with(['id' => 7]);
        $stmt->expects($this->once())->method('fetch')->willReturn(['id' => 7, 'name' => 'alice']);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT * FROM users WHERE id = :id')
            ->willReturn($stmt);

        $db = $this->makeDatabase($pdo);

        $result = $db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => 7]);

        $this->assertSame(['id' => 7, 'name' => 'alice'], $result);
        $this->assertCount(1, $db->getQueryLog());
        $this->assertSame('SELECT * FROM users WHERE id = :id', $db->getQueryLog()[0]['query']);
        $this->assertSame(['id' => 7], $db->getQueryLog()[0]['params']);
        $this->assertNull($db->getQueryLog()[0]['error']);
    }

    public function testFetchOneReturnsNullWhenStatementReturnsFalse(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->expects($this->once())->method('fetch')->willReturn(false);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->with('SELECT 1')->willReturn($stmt);

        $db = $this->makeDatabase($pdo);

        $this->assertNull($db->fetchOne('SELECT 1'));
    }

    public function testFetchAllReturnsAllRows(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with(['active' => 1]);
        $stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['id' => 1],
                ['id' => 2],
            ]);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->with('SELECT * FROM users WHERE is_active = :active')->willReturn($stmt);

        $db = $this->makeDatabase($pdo);

        $this->assertSame(
            [
                ['id' => 1],
                ['id' => 2],
            ],
            $db->fetchAll('SELECT * FROM users WHERE is_active = :active', ['active' => 1])
        );
    }

    public function testExecuteReturnsAffectedRowCount(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with(['email' => 'user@example.com']);
        $stmt->expects($this->once())->method('rowCount')->willReturn(3);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->with('UPDATE users SET is_active = 1 WHERE email = :email')->willReturn($stmt);

        $db = $this->makeDatabase($pdo);

        $this->assertSame(3, $db->execute('UPDATE users SET is_active = 1 WHERE email = :email', ['email' => 'user@example.com']));
    }

    public function testLastInsertIdReturnsInteger(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('lastInsertId')->willReturn('15');

        $db = $this->makeDatabase($pdo);

        $this->assertSame(15, $db->lastInsertId());
        $this->assertSame($pdo, $db->getPdo());
    }

    public function testRollbackDelegatesOnlyWhenTransactionIsActive(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->exactly(2))
            ->method('inTransaction')
            ->willReturnOnConsecutiveCalls(true, false);
        $pdo->expects($this->once())->method('rollBack');

        $db = $this->makeDatabase($pdo);

        $db->rollback();
        $db->rollback();
    }

    /**
     * begin() and commit() delegate straight through, *unguarded* - unlike
     * rollback(), which checks inTransaction() first.
     *
     * That asymmetry is the thing worth pinning, because it decides the shape
     * every caller has to use: the guard on rollback() is what makes
     * `try { begin; ...; commit } catch { rollback; throw }` safe to write
     * without knowing whether the failure happened before or after the
     * transaction opened (FeedTermRepository::replaceForFeed,
     * MessageService's send paths). Guarding begin() as well would silently
     * swallow a nested begin instead of raising it.
     */
    public function testBeginAndCommitDelegateWithoutGuarding(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('commit');
        $pdo->expects($this->never())->method('inTransaction');

        $db = $this->makeDatabase($pdo);

        $db->begin();
        $db->commit();
    }

    /**
     * A commit is not logged as a query. The query log is rendered on the debug
     * bar and counted in tests; transaction control passing through it would
     * make every write look like two statements.
     */
    public function testTransactionControlIsNotWrittenToTheQueryLog(): void
    {
        // A stub, not a mock: this asserts on the log, not on the delegation -
        // that is the test above.
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);

        $db = $this->makeDatabase($pdo);

        $db->begin();
        $db->commit();
        $db->rollback();

        $this->assertSame([], $db->getQueryLog());
    }

    public function testQueryFailureInDevIncludesOriginalMessageAndLogsError(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'dev';

        try {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->expects($this->once())
                ->method('execute')
                ->with(['id' => 99])
                ->willThrowException(new PDOException('broken SQL'));

            $pdo = $this->createMock(PDO::class);
            $pdo->expects($this->once())->method('prepare')->with('SELECT * FROM users WHERE id = :id')->willReturn($stmt);

            $db = $this->makeDatabase($pdo);

            try {
                $db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => 99]);
                $this->fail('Expected RuntimeException was not thrown');
            } catch (RuntimeException $e) {
                $this->assertSame('Query failed: broken SQL', $e->getMessage());
                $this->assertInstanceOf(PDOException::class, $e->getPrevious());
            }

            $this->assertCount(1, $db->getQueryLog());
            $this->assertSame('broken SQL', $db->getQueryLog()[0]['error']);
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previousEnv;
            }
        }
    }

    public function testQueryFailureOutsideDevHidesOriginalMessage(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'prod';

        try {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->expects($this->once())
                ->method('execute')
                ->willThrowException(new PDOException('broken SQL'));

            $pdo = $this->createMock(PDO::class);
            $pdo->expects($this->once())->method('prepare')->with('DELETE FROM users')->willReturn($stmt);

            $db = $this->makeDatabase($pdo);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Query failed');

            $db->execute('DELETE FROM users');
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previousEnv;
            }
        }
    }

    public function testQueryFailureWithoutAppEnvSetHidesOriginalMessageAndDoesNotWarn(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        unset($_ENV['APP_ENV']);

        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING);

        try {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->expects($this->once())
                ->method('execute')
                ->willThrowException(new PDOException('broken SQL'));

            $pdo = $this->createMock(PDO::class);
            $pdo->expects($this->once())->method('prepare')->with('DELETE FROM users')->willReturn($stmt);

            $db = $this->makeDatabase($pdo);

            try {
                $db->execute('DELETE FROM users');
                $this->fail('Expected RuntimeException was not thrown');
            } catch (RuntimeException $e) {
                $this->assertSame('Query failed', $e->getMessage());
            }
        } finally {
            restore_error_handler();

            if ($previousEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previousEnv;
            }
        }
    }
}
