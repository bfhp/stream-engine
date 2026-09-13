<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use RuntimeException;

final class InstallationPreflightException extends RuntimeException
{
    public function __construct(public readonly InstallationPreflightReport $report)
    {
        parent::__construct($report->summary());
    }
}
