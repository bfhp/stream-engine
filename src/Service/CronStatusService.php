<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\Cron\CronRepository;

final readonly class CronStatusService
{
    private const int SCHEDULER_STALE_AFTER_SECONDS = 180;
    private const int DUE_GRACE_SECONDS = 120;

    public function __construct(
        private CronRegistry $registry,
        private CronRepository $repository,
        private SettingsService $settings,
    ) {
    }

    /** @return array<string, mixed> */
    public function payload(?int $now = null): array
    {
        $now ??= time();
        $mode = $this->settings->cronMode();
        $stateByTask = [];
        foreach ($this->repository->states() as $state) {
            $stateByTask[(string) ($state['task'] ?? '')] = $state;
        }

        $tasks = [];
        foreach ($this->registry->all() as $definition) {
            $tasks[] = $this->taskPayload(
                $definition,
                $stateByTask[$definition['task']] ?? [],
                $now,
                $mode === 'off',
            );
        }
        usort($tasks, static fn (array $left, array $right): int => $left['task'] <=> $right['task']);

        $summary = ['total' => count($tasks), 'running' => 0, 'overdue' => 0, 'failed' => 0];
        foreach ($tasks as $task) {
            if ($task['status'] === 'running') {
                $summary['running']++;
            }
            if (in_array($task['status'], ['overdue', 'timed_out'], true)) {
                $summary['overdue']++;
            }
            if (in_array($task['status'], ['failed', 'start_failed'], true)) {
                $summary['failed']++;
            }
        }

        return [
            'mode' => $mode,
            'scheduler' => $mode === 'off'
                ? ['status' => 'disabled', 'lastStartedAt' => null, 'lastFinishedAt' => null]
                : $this->schedulerPayload($this->repository->schedulerState(), $now),
            'summary' => $summary,
            'data' => $tasks,
        ];
    }

    /** @param array<string, mixed> $definition @param array<string, mixed> $state */
    private function taskPayload(array $definition, array $state, int $now, bool $disabled): array
    {
        $interval = max(1, (int) $definition['interval']);
        $lastSucceededAt = self::nullableTimestamp($state['last_run'] ?? null);
        $lastStartedAt = self::nullableTimestamp($state['last_started_at'] ?? null);
        $lastFinishedAt = self::nullableTimestamp($state['last_finished_at'] ?? null);
        $lockedAt = self::nullableTimestamp($state['locked_at'] ?? null);
        $manualRequestedAt = self::nullableTimestamp($state['manual_requested_at'] ?? null);
        $enabled = ! array_key_exists('is_enabled', $state) || (int) $state['is_enabled'] === 1;
        $nextRunAt = $lastSucceededAt === null ? null : $lastSucceededAt + $interval;
        $runtimeStatus = $this->taskStatus(
            $state,
            $lockedAt,
            $manualRequestedAt,
            $lastStartedAt,
            $lastSucceededAt,
            $nextRunAt,
            $now,
        );
        $status = match (true) {
            ! $enabled => 'disabled',
            $disabled && ! in_array($runtimeStatus, ['queued', 'running', 'start_failed', 'timed_out'], true) => 'disabled',
            default => $runtimeStatus,
        };

        return [
            'task' => (string) $definition['task'],
            'module' => (string) $definition['controller'],
            'interval' => $interval,
            'enabled' => $enabled,
            'status' => $status,
            'lastStartedAt' => $lastStartedAt,
            'lastFinishedAt' => $lastFinishedAt,
            'lastSucceededAt' => $lastSucceededAt,
            'nextRunAt' => in_array($status, ['disabled', 'queued', 'running', 'timed_out', 'never'], true) ? null : $nextRunAt,
            'durationMs' => self::nullableNonNegativeInt($state['last_duration_ms'] ?? null),
            'lockedAt' => $lockedAt,
            'consecutiveFailures' => max(0, (int) ($state['consecutive_failures'] ?? 0)),
            'lastTrigger' => in_array($state['last_trigger'] ?? null, ['scheduled', 'manual'], true)
                ? $state['last_trigger']
                : null,
            'manualRequestedAt' => $manualRequestedAt,
            'lastError' => is_string($state['last_error'] ?? null) && $state['last_error'] !== ''
                ? $state['last_error']
                : null,
        ];
    }

    /** @param array<string, mixed> $state */
    private function taskStatus(
        array $state,
        ?int $lockedAt,
        ?int $manualRequestedAt,
        ?int $lastStartedAt,
        ?int $lastSucceededAt,
        ?int $nextRunAt,
        int $now,
    ): string {
        if ($lockedAt !== null) {
            return $now - $lockedAt > CronRepository::LOCK_STALE_AFTER_SECONDS ? 'timed_out' : 'running';
        }
        if ($manualRequestedAt !== null) {
            return $now - $manualRequestedAt > CronRepository::MANUAL_REQUEST_STALE_AFTER_SECONDS ? 'start_failed' : 'queued';
        }
        if (($state['last_status'] ?? null) === 'failed') {
            return 'failed';
        }
        if ($lastStartedAt === null && $lastSucceededAt === null) {
            return 'never';
        }
        if ($nextRunAt !== null && $now < $nextRunAt) {
            return 'scheduled';
        }

        return $nextRunAt !== null && $now <= $nextRunAt + self::DUE_GRACE_SECONDS ? 'due' : 'overdue';
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
