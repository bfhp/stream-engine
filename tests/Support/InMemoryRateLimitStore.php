<?php

declare(strict_types=1);

namespace Tests\Support;

use StreamEngine\Repository\RateLimitStore;

final class InMemoryRateLimitStore implements RateLimitStore
{
    /** @var array<string, array{attempts:int, windowEndsAt:int}> */
    public array $buckets = [];

    public function consume(
        string $scope,
        string $subjectHash,
        int $limit,
        int $windowSeconds,
        int $now,
    ): int {
        $key = $scope.':'.$subjectHash;
        $bucket = $this->buckets[$key] ?? null;

        if ($bucket === null || $bucket['windowEndsAt'] <= $now) {
            $bucket = ['attempts' => 0, 'windowEndsAt' => $now + $windowSeconds];
        }

        $bucket['attempts'] = min($limit + 1, $bucket['attempts'] + 1);
        $this->buckets[$key] = $bucket;

        return $bucket['attempts'];
    }

    public function delete(string $scope, string $subjectHash): void
    {
        unset($this->buckets[$scope.':'.$subjectHash]);
    }
}
