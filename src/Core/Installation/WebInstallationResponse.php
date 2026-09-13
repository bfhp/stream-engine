<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

final readonly class WebInstallationResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {
    }
}
