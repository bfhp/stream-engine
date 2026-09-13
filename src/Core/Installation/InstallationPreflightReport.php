<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

final readonly class InstallationPreflightReport
{
    /** @param list<PreflightCheck> $checks */
    public function __construct(public array $checks)
    {
    }

    public function passes(): bool
    {
        foreach ($this->checks as $check) {
            if (! $check->passed) {
                return false;
            }
        }

        return true;
    }

    public function summary(): string
    {
        if ($this->passes()) {
            return 'Installation preflight passed.';
        }

        $failures = array_map(
            static fn (PreflightCheck $check): string => sprintf('- %s: %s', $check->label, $check->message),
            array_values(array_filter($this->checks, static fn (PreflightCheck $check): bool => ! $check->passed)),
        );

        return "Installation preflight failed:\n".implode("\n", $failures);
    }
}
