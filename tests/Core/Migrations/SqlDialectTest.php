<?php

declare(strict_types=1);

namespace Tests\Core\Migrations;

use PDO;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Migrations\SqlDialect;
use StreamEngine\Core\Migrations\SqlScript;

final class SqlDialectTest extends TestCase
{
    public function testDetectsTheServerFromItsVersionString(): void
    {
        self::assertSame(SqlDialect::MARIADB, SqlDialect::fromServerVersion('11.4.2-MariaDB-ubu2404')->name);
        self::assertSame(SqlDialect::MARIADB, SqlDialect::fromServerVersion('5.5.5-10.11.6-MariaDB')->name);
        self::assertSame(SqlDialect::MARIADB, SqlDialect::fromServerVersion('')->name);
        self::assertSame(SqlDialect::MYSQL, SqlDialect::fromServerVersion('8.4.3')->name);
        self::assertSame(SqlDialect::MYSQL, SqlDialect::fromServerVersion('9.1.0-commercial')->name);
    }

    public function testAnUnknownServerKeepsTheCanonicalSql(): void
    {
        $pdo = self::createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn(null);

        self::assertFalse(SqlDialect::forPdo($pdo)->isMySql());
    }

    public function testMariaDbStatementsAreReturnedUnchanged(): void
    {
        $sql = 'CREATE TABLE `t` (`a` int NOT NULL DEFAULT unix_timestamp()) COLLATE=utf8mb4_uca1400_ai_ci';

        self::assertSame($sql, SqlDialect::mariaDb()->translate($sql));
    }

    public function testMySqlGetsMatchingUnicodeCollations(): void
    {
        $dialect = SqlDialect::mySql();

        self::assertSame(
            'ALTER TABLE `t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
            $dialect->translate('ALTER TABLE `t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci'),
        );
        self::assertSame(
            "SELECT 'a' COLLATE utf8mb4_0900_as_cs",
            $dialect->translate("SELECT 'a' COLLATE utf8mb4_uca1400_nopad_as_cs"),
        );
        self::assertSame(
            'CREATE TABLE `t` (`a` varchar(5) COLLATE utf8mb4_general_ci)',
            $dialect->translate('CREATE TABLE `t` (`a` varchar(5) COLLATE utf8mb4_general_ci)'),
        );
    }

    public function testMySqlGetsParenthesesAroundFunctionDefaults(): void
    {
        $dialect = SqlDialect::mySql();

        self::assertSame(
            'CREATE TABLE `t` (`a` int unsigned NOT NULL DEFAULT (unix_timestamp()), `b` timestamp NOT NULL DEFAULT current_timestamp())',
            $dialect->translate('CREATE TABLE `t` (`a` int unsigned NOT NULL DEFAULT unix_timestamp(), `b` timestamp NOT NULL DEFAULT current_timestamp())'),
        );
        self::assertSame(
            'ALTER TABLE `t` ADD COLUMN `a` int NOT NULL DEFAULT (UNIX_TIMESTAMP())',
            $dialect->translate('ALTER TABLE `t` ADD COLUMN `a` int NOT NULL DEFAULT UNIX_TIMESTAMP()'),
        );
        self::assertSame(
            "UPDATE `t` SET `a` = 'DEFAULT unix_timestamp()'",
            $dialect->translate("UPDATE `t` SET `a` = 'DEFAULT unix_timestamp()'"),
        );
    }

    public function testMySqlDropsChecksOnColumnsWithReferentialActions(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `m` (
              `feed_id` int DEFAULT NULL,
              `message_id` int DEFAULT NULL,
              `role` varchar(20) NOT NULL CHECK (`role` in ('a','b')),
              CONSTRAINT `m_feed_fk` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
              CONSTRAINT `m_message_fk` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`),
              CONSTRAINT `m_one_target` CHECK ((`feed_id` IS NULL) <> (`message_id` IS NULL)),
              CONSTRAINT `m_message_positive` CHECK (`message_id` > 0)
            ) ENGINE=InnoDB
            SQL;

        $translated = SqlDialect::mySql()->translate($sql);

        self::assertStringNotContainsString('m_one_target', $translated);
        self::assertStringContainsString('CONSTRAINT `m_message_positive` CHECK (`message_id` > 0)'."\n) ENGINE=InnoDB", $translated);
        self::assertStringContainsString("CHECK (`role` in ('a','b'))", $translated);
        self::assertStringContainsString('ON DELETE CASCADE,', $translated);
    }

    public function testTheLastElementCanBeDroppedWithoutBreakingTheStatement(): void
    {
        $translated = SqlDialect::mySql()->translate(
            "CREATE TABLE `m` (\n  `a` int,\n  FOREIGN KEY (`a`) REFERENCES `x` (`id`) ON DELETE SET NULL,\n  CHECK (`a` > 0)\n) ENGINE=InnoDB"
        );

        self::assertSame(
            "CREATE TABLE `m` (\n  `a` int,\n  FOREIGN KEY (`a`) REFERENCES `x` (`id`) ON DELETE SET NULL\n) ENGINE=InnoDB",
            $translated,
        );
    }

    public function testMySqlMakesStoredGeneratedColumnsVirtual(): void
    {
        $dialect = SqlDialect::mySql();

        self::assertSame(
            'CREATE TABLE `f` (`k` int unsigned GENERATED ALWAYS AS (coalesce(`parent_id`,0)) VIRTUAL, `p` int GENERATED ALWAYS AS (`a` + 1) VIRTUAL)',
            $dialect->translate('CREATE TABLE `f` (`k` int unsigned GENERATED ALWAYS AS (coalesce(`parent_id`,0)) STORED, `p` int GENERATED ALWAYS AS (`a` + 1) PERSISTENT)'),
        );
        self::assertSame(
            "ALTER TABLE feeds\n  ADD COLUMN k int GENERATED ALWAYS AS (coalesce(parent_id, 0)) VIRTUAL AFTER slug",
            $dialect->translate("ALTER TABLE feeds\n  ADD COLUMN k int GENERATED ALWAYS AS (coalesce(parent_id, 0)) STORED AFTER slug"),
        );
    }

    public function testShippedSchemaAndMigrationsHaveNoMariaDbOnlyDdlLeftForMySql(): void
    {
        $root = dirname(__DIR__, 3);
        $files = [$root.'/resources/install/schema.sql', ...glob($root.'/migrations/*.sql')];
        $dialect = SqlDialect::mySql();

        foreach ($files as $file) {
            $statements = SqlScript::statements((string) file_get_contents($file));
            foreach ($statements as $statement) {
                $translated = $dialect->translate($statement);
                $name = basename($file);

                self::assertDoesNotMatchRegularExpression('/uca1400/i', $translated, $name);
                self::assertDoesNotMatchRegularExpression('/DEFAULT\s+unix_timestamp\(\)/i', $translated, $name);
                self::assertStringNotContainsString('mentions_exactly_one_target', $translated, $name);
                self::assertDoesNotMatchRegularExpression('/\)\s+(STORED|PERSISTENT)\b/i', $translated, $name);
                self::assertSame(
                    substr_count($translated, '('),
                    substr_count($translated, ')'),
                    $name.': unbalanced parentheses after translation',
                );
            }
        }
    }
}
