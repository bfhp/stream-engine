<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Installation\InstallationPreflightReport;
use StreamEngine\Core\Installation\PreflightCheck;

final class InstallationPreflightReportTest extends TestCase
{
    public function testPassingReportHasSuccessSummary(): void
    {
        $report = new InstallationPreflightReport([
            new PreflightCheck('php', 'PHP version', true, 'Supported.'),
        ]);

        self::assertTrue($report->passes());
        self::assertSame('Installation preflight passed.', $report->summary());
    }

    public function testFailureSummaryContainsOnlyFailedChecks(): void
    {
        $report = new InstallationPreflightReport([
            new PreflightCheck('php', 'PHP version', true, 'Supported.'),
            new PreflightCheck('extensions', 'PHP extensions', false, 'Missing: intl.'),
            new PreflightCheck('storage', 'Installation storage', false, 'Not writable.'),
        ]);

        self::assertFalse($report->passes());
        self::assertSame(
            "Installation preflight failed:\n- PHP extensions: Missing: intl.\n- Installation storage: Not writable.",
            $report->summary(),
        );
    }
}
