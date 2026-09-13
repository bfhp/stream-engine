<?php

declare(strict_types=1);

namespace StreamEngine\Core\Migrations;

final class SqlScript
{
    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $statements = [];
        $statement = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote === null && $char === '-' && $next === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($quote === null && $char === '/' && $next === '*') {
                $i += 2;
                while ($i < $length - 1 && ! ($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i++;

                continue;
            }

            if (($char === '\'' || $char === '"') && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $quote = $quote === $char ? null : ($quote ?? $char);
            }

            if ($quote === null && $char === ';') {
                $statement = trim($statement);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $statement = '';

                continue;
            }

            $statement .= $char;
        }

        $statement = trim($statement);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }
}
