<?php

declare(strict_types=1);

namespace StreamEngine\Core\Migrations;

use PDO;
use Throwable;

/**
 * Adapts the project's canonical SQL to the database server it runs on.
 *
 * Migrations and the installation schema are written for MariaDB (the
 * snapshot is a MariaDB `SHOW CREATE TABLE` dump). The files themselves are
 * never rewritten, so their checksums stay valid; each statement is
 * translated just before execution instead. On MariaDB every statement is
 * returned unchanged. On MySQL 8.4+ four MariaDB-only DDL forms are
 * rewritten:
 *
 * - `utf8mb4_uca1400_*` collations become the matching `utf8mb4_0900_*`
 *   collation (both are case- and accent-insensitive Unicode collations;
 *   MySQL's 0900 family is NO PAD, so trailing spaces are significant);
 * - a function call used as a column DEFAULT, such as
 *   `DEFAULT unix_timestamp()`, gets the parentheses MySQL requires around
 *   default expressions;
 * - a CHECK constraint in CREATE TABLE that uses a column with a foreign-key
 *   referential action (CASCADE, SET NULL, SET DEFAULT) is dropped, because
 *   MySQL rejects that combination (error 3823). The application keeps such
 *   invariants itself;
 * - a STORED (or PERSISTENT) generated column becomes VIRTUAL, because InnoDB
 *   in MySQL rejects a cascading foreign key on a base column of a stored
 *   generated column (error 1215), while an indexed virtual column allows it.
 *   Indexes and unique keys on the column behave the same.
 */
final readonly class SqlDialect
{
    public const MARIADB = 'mariadb';
    public const MYSQL = 'mysql';

    private const COLLATIONS = [
        'ai_ci' => 'utf8mb4_0900_ai_ci',
        'as_ci' => 'utf8mb4_0900_as_ci',
        'as_cs' => 'utf8mb4_0900_as_cs',
    ];

    /** Functions MySQL accepts as a DEFAULT without extra parentheses. */
    private const BARE_DEFAULT_FUNCTIONS = ['current_timestamp', 'now', 'localtime', 'localtimestamp'];

    private function __construct(public string $name)
    {
    }

    public static function mariaDb(): self
    {
        return new self(self::MARIADB);
    }

    public static function mySql(): self
    {
        return new self(self::MYSQL);
    }

    /**
     * MariaDB reports a version such as "11.4.2-MariaDB-ubu2404" (or
     * "5.5.5-10.11.6-MariaDB" through older clients); MySQL reports a plain
     * version. An unknown version keeps the canonical MariaDB SQL.
     */
    public static function fromServerVersion(string $version): self
    {
        $version = trim($version);
        if ($version === '' || stripos($version, 'mariadb') !== false) {
            return self::mariaDb();
        }

        return self::mySql();
    }

    public static function forPdo(PDO $pdo): self
    {
        try {
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            $version = null;
        }

        return self::fromServerVersion(is_string($version) ? $version : '');
    }

    public function isMySql(): bool
    {
        return $this->name === self::MYSQL;
    }

    /** Translates one statement, as produced by SqlScript::statements(). */
    public function translate(string $statement): string
    {
        if (! $this->isMySql()) {
            return $statement;
        }

        $statement = preg_replace_callback(
            '/\butf8mb4_uca1400(?:_nopad)?_(ai_ci|as_ci|as_cs)\b/i',
            static fn (array $m): string => self::COLLATIONS[strtolower($m[1])],
            $statement,
        ) ?? $statement;

        if (! preg_match('/^\s*(CREATE|ALTER)\s+TABLE\b/i', $statement)) {
            return $statement;
        }

        $statement = preg_replace_callback(
            '/\bDEFAULT\s+([a-z_][a-z0-9_]*)\s*\(([^()\'"]*)\)/i',
            static function (array $m): string {
                if (in_array(strtolower($m[1]), self::BARE_DEFAULT_FUNCTIONS, true)) {
                    return $m[0];
                }

                return 'DEFAULT ('.$m[1].'('.$m[2].'))';
            },
            $statement,
        ) ?? $statement;

        $statement = $this->storedGeneratedColumnsAsVirtual($statement);

        if (preg_match('/^\s*CREATE\s+TABLE\b/i', $statement)) {
            $statement = $this->dropChecksOnReferentialActionColumns($statement);
        }

        return $statement;
    }

    private function storedGeneratedColumnsAsVirtual(string $statement): string
    {
        $offset = 0;
        while (preg_match('/\bGENERATED\s+ALWAYS\s+AS\s*\(/i', $statement, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $open = $m[0][1] + strlen($m[0][0]) - 1;
            $close = $this->matchingParenthesis($statement, $open);
            if ($close === false) {
                break;
            }

            $rest = substr($statement, $close + 1);
            if (preg_match('/^(\s+)(STORED|PERSISTENT)\b/i', $rest, $kind)) {
                $statement = substr($statement, 0, $close + 1).$kind[1].'VIRTUAL'
                    .substr($rest, strlen($kind[0]));
            }
            $offset = $close + 1;
        }

        return $statement;
    }

    private function dropChecksOnReferentialActionColumns(string $statement): string
    {
        $open = strpos($statement, '(');
        $close = $open === false ? false : $this->matchingParenthesis($statement, $open);
        if ($open === false || $close === false) {
            return $statement;
        }

        $elements = $this->splitTopLevel(substr($statement, $open + 1, $close - $open - 1));

        $actionColumns = [];
        foreach ($elements as $element) {
            if (preg_match(
                '/FOREIGN\s+KEY\s*(?:`[^`]*`\s*)?\(([^)]*)\).*\bON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|SET\s+NULL|SET\s+DEFAULT)\b/is',
                $element,
                $fk,
            )) {
                foreach ($this->identifiers($fk[1]) as $column) {
                    $actionColumns[$column] = true;
                }
            }
        }

        if ($actionColumns === []) {
            return $statement;
        }

        $kept = [];
        $dropped = false;
        foreach ($elements as $element) {
            if (preg_match('/^\s*(?:CONSTRAINT\s+(?:`[^`]*`|\w+)\s+)?CHECK\s*\((.*)\)\s*$/is', $element, $check)) {
                foreach ($this->identifiers($check[1]) as $identifier) {
                    if (isset($actionColumns[$identifier])) {
                        $dropped = true;

                        continue 2;
                    }
                }
            }

            $kept[] = $element;
        }

        if (! $dropped) {
            return $statement;
        }

        // Keep the whitespace before the closing parenthesis when the last
        // element is the one that was dropped.
        preg_match('/\s*$/', (string) end($elements), $trailing);
        $body = rtrim(implode(',', $kept)).($trailing[0] ?? '');

        return substr($statement, 0, $open + 1).$body.substr($statement, $close);
    }

    /** @return list<string> lower-cased backtick-quoted or bare identifiers */
    private function identifiers(string $sql): array
    {
        $sql = preg_replace('/\'(?:[^\'\\\\]|\\\\.|\'\')*\'/s', "''", $sql) ?? $sql;
        preg_match_all('/`([^`]+)`|\b([a-z_][a-z0-9_]*)\b/i', $sql, $matches, PREG_SET_ORDER);

        return array_values(array_unique(array_map(
            static fn (array $m): string => strtolower($m[1] !== '' ? $m[1] : $m[2]),
            $matches,
        )));
    }

    private function matchingParenthesis(string $sql, int $open): int|false
    {
        $depth = 0;
        $quote = null;
        $length = strlen($sql);
        for ($i = $open; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return $i;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function splitTopLevel(string $body): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $body[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }
}
