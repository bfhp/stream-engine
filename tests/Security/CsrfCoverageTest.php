<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Tests\Support\CsrfAudit;

final class CsrfCoverageTest extends TestCase
{
    public function testEveryMutatingApiEndpointHasCsrfProtection(): void
    {
        $rows = CsrfAudit::scan(dirname(__DIR__, 2), ['src']);
        self::assertNotEmpty($rows, 'CSRF audit found no mutating API endpoints.');

        $uncovered = [];
        foreach ($rows as $action => $row) {
            if (!$row['covered']) {
                $missingMethods = array_keys(array_filter(
                    $row['methodCoverage'],
                    static fn (bool $covered): bool => !$covered,
                ));
                $uncovered[] = sprintf(
                    '%s: %s [%s] — %s',
                    CsrfAudit::status($row),
                    $action,
                    implode(',', $missingMethods),
                    $row['handlers'] ?? 'no dispatched handler',
                );
            }
        }

        self::assertSame([], $uncovered, "CSRF audit failed:\n".implode("\n", $uncovered));
    }
}
