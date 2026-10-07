<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use StreamEngine\Core\Config;
use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\Cron\CronRepository;

final readonly class CronStatusService
{
    private const int SCHEDULER_STALE_AFTER_SECONDS = 180;
    private const int DUE_GRACE_SECONDS = 120;

    public function __construct(
        private CronRegistry $registry,
        private CronRepository $repository,
        private Config $config,
    ) {
    }

    /** @return array<string, mixed> */
    public function payload(?int $now = null): array
    {
        $now ??= time();
        $stateByTask = [];
        foreach ($this->repository->states() as $state) {
            $stateByTask[(string) ($state['task'] ?? '')] = $state;
        }

        $tasks = [];
        foreach ($this->registry->all() as $definition) {
            $tasks[] = $this->taskPayload($definition, $stateByTask[$definition['task']] ?? [], $now);
        }
        usort($tasks, static fn (array $left, array $right): int => $left['task'] <=> $right['task']);

        $summary = ['total' => count($tasks), 'running' => 0, 'overdue' => 0, 'failed' => 0];
        foreach ($tasks as $task) {
            if ($task['status'] === 'running') {
                $summary['running']++;
            }
            if (in_array($task['status'], ['overdue', 'stale'], true)) {
                $summary['overdue']++;
            }
            if ($task['status'] === 'failed') {
                $summary['failed']++;
            }
        }

        return [
            'mode' => $this->config->cronMode(),
            'scheduler' => $this->schedulerPayload($this->repository->schedulerState(), $now),
            'summary' => $summary,
            'data' => $tasks,
        ];
    }

    /** @param array<string, mixed> $definition @param array<string, mixed> $state */
    private function taskPayload(array $definition, array $state, int $now): array
    {
        $interval = max(1, (int) $definition['interval']);
        $lastSucceededAt = self::nullableTimestamp($state['last_run'] ?? null);
        $lastStartedAt = self::nullableTimestamp($state['last_started_at'] ?? null);
        $lastFinishedAt = self::nullableTimestamp($state['last_finished_at'] ?? null);
        $lockedAt = self::nullableTimestamp($state['locked_at'] ?? null);
        $nextRunAt = ($lastSucceededAt ?? 0) + $interval;
        $status = $this->taskStatus(
            $state,
            $lockedAt,
            $lastStartedAt,
            $lastSucceededAt,
            $nextRunAt,
            $now,
        );

        return [
            'task' => (string) $definition['task'],
            'module' => (string) $definition['controller'],
            'interval' => $interval,
            'status' => $status,
            'lastStartedAt' => $lastStartedAt,
            'lastFinishedAt' => $lastFinishedAt,
            'lastSucceededAt' => $lastSucceededAt,
            'nextRunAt' => $status === 'running' || $status === 'never' ? null : $nextRunAt,
            'durationMs' => self::nullableNonNegativeInt($state['last_duration_ms'] ?? null),
            'lockedAt' => $lockedAt,
            'consecutiveFailures' => max(0, (int) ($state['consecutive_failures'] ?? 0)),
            'lastError' => is_string($state['last_error'] ?? null) && $state['last_error'] !== ''
                ? $state['last_error']
                : null,
        ];
    }

    /** @param array<string, mixed> $state */
    private function taskStatus(
        array $state,
        ?int $lockedAt,
        ?int $lastStartedAt,
        ?int $lastSucceededAt,
        int $nextRunAt,
        int $now,
    ): string {
        if ($lockedAt !== null) {
            return $now - $lockedAt > CronRepository::LOCK_STALE_AFTER_SECONDS ? 'stale' : 'running';
        }
        if (($state['last_status'] ?? null) === 'failed') {
            return 'failed';
        }
        if ($lastStartedAt === null && $lastSucceededAt === null) {
            return 'never';
        }
        if ($now < $nextRunAt) {
            return 'scheduled';
        }

        return $now <= $nextRunAt + self::DUE_GRACE_SECONDS ? 'due' : 'overdue';
    }

    /** @param array<string, mixed>|null $state */
    private function schedulerPayload(?array $state, int $now): array
    {
        $lastStartedAt = self::nullableTimestamp($state['last_started_at'] ?? null);
        $lastFinishedAt = self::nullableTimestamp($state['last_finished_at'] ?? null);
        $lastStatus = is_string($state['last_status'] ?? null) ? $state['last_status'] : null;

        $status = match (true) {
            $lastStartedAt === null => 'never',
            ($lastFinishedAt === null || $lastStartedAt > $lastFinishedAt)
                && $now - $lastStartedAt > self::SCHEDULER_STALE_AFTER_SECONDS => 'stale',
            $lastFinishedAt === null || $lastStartedAt > $lastFinishedAt => 'running',
            $lastStatus === 'failed' => 'failed',
            $now - $lastFinishedAt > self::SCHEDULER_STALE_AFTER_SECONDS => 'stale',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'lastStartedAt' => $lastStartedAt,
            'lastFinishedAt' => $lastFinishedAt,
        ];
    }

    private static function nullableTimestamp(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function nullableNonNegativeInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }
}
