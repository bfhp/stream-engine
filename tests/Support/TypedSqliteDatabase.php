<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PDOStatement;
use ReflectionClass;
use ReflectionProperty;
use StreamEngine\Core\PdoDatabase;

/**
 * PdoDatabase over an SQLite connection that binds integers as integers.
 *
 * PdoDatabase binds every parameter as a string, which MariaDB and MySQL
 * compare with numbers by converting it. SQLite converts only against a
 * column with numeric affinity: `CASE ... END = '2'` is false for 2, so ACL
 * conditions on such expressions (a comment's publication owner, for one)
 * would silently differ from production. Binding typed values keeps the
 * SQLite results the ones MariaDB gives.
 */
final class TypedSqliteDatabase extends PdoDatabase
{
    public static function over(PDO $pdo): self
    {
        $database = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PdoDatabase::class, 'pdo'))->setValue($database, $pdo);

        return $database;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public static function bind(PDOStatement $statement, array $params): PDOStatement
    {
        foreach (array_values($params) as $index => $value) {
            $statement->bindValue($index + 1, $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }
        $statement->execute();

        return $statement;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        return self::bind($this->getPdo()->prepare($sql), $params);
    }
}
