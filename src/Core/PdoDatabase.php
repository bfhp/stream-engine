<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

class PdoDatabase
{
    private PDO $pdo;

    private array $queryLog = [];

    // Plain property with a class-body default (not constructor-promoted):
    // ReflectionClass::newInstanceWithoutConstructor() (used by tests) skips
    // the constructor entirely, so a promoted property's default would
    // never be assigned and reading it would throw "must not be accessed
    // before initialization". A class-body default is applied regardless.
    private bool $logQueries = true;
    private bool $development = false;

    public function __construct(Config $config, bool $logQueries = true)
    {
        $this->logQueries = $logQueries;
        $this->development = $config->isDevelopment();
        $this->connect($config);
    }

    private function connect(Config $config): void
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;port=%d;charset=utf8mb4',
            $config->dbHost(),
            $config->dbName(),
            $config->dbPort()
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                $config->dbUserName(),
                $config->dbPassword(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            $this->pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed', 0, $e);
        }
    }

    /* =========================
    READ
    ========================== */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->executeStatement($sql, $params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->executeStatement($sql, $params);

        return $stmt->fetchAll();
    }

    /* =========================
    WRITE
    ========================== */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->executeStatement($sql, $params);

        return $stmt->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /* =========================
    TRANSACTIONS
    ========================== */

    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /* =========================
    INTERNAL
    ========================== */

    private function executeStatement(string $sql, array $params): PDOStatement
    {
        $start = microtime(true);

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            if ($this->logQueries) {
                $this->logQuery($sql, $params, microtime(true) - $start);
            }

            return $stmt;

        } catch (PDOException $e) {
            if ($this->logQueries) {
                $this->logQuery($sql, $params, microtime(true) - $start, $e->getMessage());
            }
            $message = $this->development ? 'Query failed: '.$e->getMessage() : 'Query failed';
            throw new RuntimeException($message, 0, $e);
        }
    }

    private function logQuery(string $sql, array $params, float $time, ?string $error = null): void
    {
        $this->queryLog[] = [
            'query' => $sql,
            'params' => $params,
            'time_ms' => $time * 1000,
            'error' => $error,
        ];
    }

    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
