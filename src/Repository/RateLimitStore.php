<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

interface RateLimitStore
{
    public function consume(
        string $scope,
        string $subjectHash,
        int $limit,
        int $windowSeconds,
        int $now,
    ): int;

    public function delete(string $scope, string $subjectHash): void;
}
