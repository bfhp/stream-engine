<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

final readonly class PreflightCheck
{
    public function __construct(
        public string $code,
        public string $label,
        public bool $passed,
        public string $message,
    ) {
    }
}
