<?php

declare(strict_types=1);

namespace StreamEngine\Core\Cron;

use StreamEngine\Core\PdoDatabase;
use Throwable;

final readonly class CronRepository
{
    public const int LOCK_STALE_AFTER_SECONDS = 3600;
    public const int MANUAL_REQUEST_STALE_AFTER_SECONDS = 180;
    public const int HISTORY_LIMIT_PER_TASK = 25;

    public function __construct(
        private PdoDatabase $db
    ) {

    }

    /**
     * Uses index: PRIMARY(task)
     */
    public function getLastRun(string $task): int
    {
        $runData = $this->db->fetchOne(
            "SELECT last_run FROM cron_tasks WHERE task = ?",
            [$task]
        );

        if (!$runData
            || !array_key_exists('last_run', $runData)
            || !is_numeric($runData['last_run'])) {
            return 0;
        }

        return (int) $runData['last_run'];
    }

    /**
     * How long a held lock is honoured before another runner may take it over.
     *
     * Needed because a process killed outright - OOM, a deploy, `kill -9` -
     * never reaches release(), and without a reclaim window its task would be
     * blocked forever. Ordinary failures don't rely on this: CronRunner::run()
     * releases in its catch.
     *
     * One hour is a compromise. Too short and a long task gets a second runner
     * on top of it, which is the exact thing this lock exists to prevent; too
     * long and a hard-killed task stalls. It assumes no task runs for an hour -
     * any task that could exceed it needs
     * either a bigger window or a heartbeat that refreshes locked_at as it
     * works.
     */
    /**
     * Takes the lock for a task, reporting whether *this* call got it.
     *
     * It never used to report anything: the old implementation upserted
     * locked_at and then `return true`, so CronRunner's `if (!lock()) continue;`
     * was dead and two runners would happily execute the same task at the same
     * time. That still matters in the request-driven compatibility mode,
     * where overlapping requests can start competing runners, and the tasks
     * behind it delete rows
     * (`users:cleanup`) or perform other non-idempotent work.
     *
     * Uses index: PRIMARY(task)
     */
    public function lock(string $task, string $trigger = 'scheduled'): bool
    {
        if (! in_array($trigger, ['scheduled', 'manual'], true)) {
            throw new \InvalidArgumentException('Unsupported cron trigger.');
        }

        $this->db->begin();
        try {
            $locked = $this->takeLock($task, $trigger);
            if (! $locked) {
                $this->db->rollback();

                return false;
            }

            $this->db->commit();

            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function takeLock(string $task, string $trigger): bool
    {

        // Make sure the row exists, without touching an existing one - the
        // `task = task` no-op is what keeps a concurrently held locked_at
        // intact. INSERT IGNORE would read as well here but downgrades every
        // error to a warning, not just the duplicate key.
        $this->db->execute(
            "INSERT INTO cron_tasks (task, last_run, locked_at)
             VALUES (?, 0, NULL)
             ON DUPLICATE KEY UPDATE task = task",
            [$task]
        );

        // A single conditional UPDATE, so the acquisition is atomic: two
        // runners arriving together both run this, and the loser's WHERE no
        // longer matches because it sees the winner's locked_at. Deciding on
        // affected rows rather than on a separate SELECT is the whole point -
        // read-then-write would leave a window between the two.
        //
        // Note this relies on rowCount() reporting *changed* rows, which is
        // PDO's MySQL default: the only case where the WHERE matches but
        // nothing changes would be locked_at already equal to the value being
        // written, and that can't happen - such a row is neither NULL nor
        // stale, so the WHERE excludes it.
        $locked = $this->db->execute(
            "UPDATE cron_tasks
                SET locked_at = UNIX_TIMESTAMP(),
                    last_started_at = UNIX_TIMESTAMP(),
                    last_trigger = ?,
                    manual_requested_at = NULL
              WHERE task = ?
                AND is_enabled = 1
                AND (locked_at IS NULL OR locked_at < UNIX_TIMESTAMP() - ?)
                AND (? = 'manual' OR manual_requested_at IS NULL)",
            [$trigger, $task, self::LOCK_STALE_AFTER_SECONDS, $trigger]
        ) === 1;
        if (! $locked) {
            return false;
        }

        $activeRunId = $this->activeRunId($task);
        if ($trigger === 'manual' && $activeRunId !== null) {
            $this->markHistoryRunning($activeRunId);
        } else {
            if ($activeRunId !== null) {
                $this->markHistoryTimedOut($activeRunId);
            }
            $activeRunId = $this->createHistory($task, $trigger, 'running', null, true);
            $this->db->execute(
                'UPDATE cron_tasks SET active_run_id = ? WHERE task = ?',
                [$activeRunId, $task]
            );
        }

        return true;
    }

    public function isEnabled(string $task): bool
    {
        $state = $this->db->fetchOne(
            'SELECT is_enabled FROM cron_tasks WHERE task = ?',
            [$task]
        );

        return $state === null || (int) ($state['is_enabled'] ?? 1) === 1;
    }

    public function setEnabled(string $task, bool $enabled): void
    {
        if (! $enabled) {
            $this->db->execute(
                "UPDATE cron_run_history AS h
                 INNER JOIN cron_tasks AS task_state ON task_state.active_run_id = h.id
                 SET h.status = 'start_failed', h.finished_at = UNIX_TIMESTAMP(),
                     h.error = 'The task was disabled before its worker started.'
                 WHERE task_state.task = ? AND h.status = 'queued'",
                [$task]
            );
        }
        $this->db->execute(
            'INSERT INTO cron_tasks (task, is_enabled, last_run, locked_at)
             VALUES (?, ?, 0, NULL)
             ON DUPLICATE KEY UPDATE
                 is_enabled = VALUES(is_enabled),
                 manual_requested_at = IF(VALUES(is_enabled) = 0, NULL, manual_requested_at),
                 active_run_id = IF(VALUES(is_enabled) = 0 AND locked_at IS NULL, NULL, active_run_id)',
            [$task, $enabled ? 1 : 0]
        );
    }

    /**
     * Persist the request before forking a worker so the UI can observe it.
     * Returns null when the task is disabled, running, or already queued.
     */
    public function queueManualRun(string $task, ?int $requestedByUserId = null): ?int
    {
        $this->db->begin();
        try {
            $runId = $this->persistManualRun($task, $requestedByUserId);
            if ($runId === null) {
                $this->db->rollback();

                return null;
            }

            $this->db->commit();

            return $runId;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function persistManualRun(string $task, ?int $requestedByUserId): ?int
    {
        $this->db->execute(
            'INSERT INTO cron_tasks (task, last_run, locked_at)
             VALUES (?, 0, NULL)
             ON DUPLICATE KEY UPDATE task = task',
            [$task]
        );

        $queued = $this->db->execute(
            'UPDATE cron_tasks
             SET manual_requested_at = UNIX_TIMESTAMP()
             WHERE task = ?
               AND is_enabled = 1
               AND (locked_at IS NULL OR locked_at < UNIX_TIMESTAMP() - ?)
               AND (manual_requested_at IS NULL OR manual_requested_at < UNIX_TIMESTAMP() - ?)',
            [$task, self::LOCK_STALE_AFTER_SECONDS, self::MANUAL_REQUEST_STALE_AFTER_SECONDS]
        ) === 1;
        if (! $queued) {
            return null;
        }

        $previousRunId = $this->activeRunId($task);
        if ($previousRunId !== null) {
            $this->expireHistory($previousRunId);
        }
        $runId = $this->createHistory($task, 'manual', 'queued', $requestedByUserId);
        $this->db->execute(
            'UPDATE cron_tasks SET active_run_id = ? WHERE task = ?',
            [$runId, $task]
        );
        $this->pruneHistory($task);

        return $runId;
    }

    public function markManualStartFailed(string $task, int $runId, string $error): void
    {
        $this->db->execute(
            "UPDATE cron_run_history
             SET status = 'start_failed', finished_at = UNIX_TIMESTAMP(), error = ?
             WHERE id = ? AND task = ? AND status = 'queued'",
            [self::truncateError($error), $runId, $task]
        );
        $this->db->execute(
            'UPDATE cron_tasks
             SET manual_requested_at = NULL, active_run_id = NULL
             WHERE task = ? AND active_run_id = ?',
            [$task, $runId]
        );
        $this->pruneHistory($task);
    }

    /**
     * Gives the lock back without recording a run.
     *
     * For the task that threw: markDone() would say it succeeded and push
     * last_run forward, but leaving locked_at set would block it until the
     * stale window expired - an hour of not retrying something that should
     * retry on the next tick.
     *
     * Uses index: PRIMARY(task)
     */
    public function release(string $task): void
    {
        $this->db->execute(
            "UPDATE cron_tasks
                SET locked_at = NULL
              WHERE task = ?",
            [$task]
        );
    }

    /**
     * Uses index: PRIMARY(task)
     */
    public function markDone(string $task, int $durationMs = 0): void
    {
        $durationMs = max(0, $durationMs);
        $this->finishHistory($task, 'success', $durationMs, null);
        $this->db->execute(
            "UPDATE cron_tasks
             SET last_run = UNIX_TIMESTAMP(),
                 last_finished_at = UNIX_TIMESTAMP(),
                 last_status = 'success',
                 last_duration_ms = ?,
                 last_error = NULL,
                 consecutive_failures = 0,
                 manual_requested_at = NULL,
                 active_run_id = NULL,
                 locked_at = NULL
             WHERE task = ?",
            [$durationMs, $task]
        );
        $this->pruneHistory($task);
    }

    /** Records a failed attempt without moving last_run, so it remains due. */
    public function markFailed(string $task, int $durationMs, string $error): void
    {
        $durationMs = max(0, $durationMs);
        $error = self::truncateError($error);
        $this->finishHistory($task, 'failed', $durationMs, $error);
        $this->db->execute(
            "UPDATE cron_tasks
             SET last_finished_at = UNIX_TIMESTAMP(),
                 last_status = 'failed',
                 last_duration_ms = ?,
                 last_error = ?,
                 consecutive_failures = consecutive_failures + 1,
                 manual_requested_at = NULL,
                 active_run_id = NULL,
                 locked_at = NULL
             WHERE task = ?",
            [$durationMs, $error, $task]
        );
        $this->pruneHistory($task);
    }

    /** @return list<array<string, mixed>> */
    public function states(): array
    {
        return $this->db->fetchAll(
            'SELECT task, is_enabled, last_run, locked_at, last_started_at, last_finished_at,
                    last_status, last_trigger, manual_requested_at, last_duration_ms, last_error,
                    consecutive_failures
             FROM cron_tasks'
        );
    }

    /** @return list<array<string, mixed>> */
    public function history(string $task): array
    {
        return $this->db->fetchAll(
            'SELECT id, task, `trigger`, status, requested_at, started_at, finished_at,
                    duration_ms, error, requested_by_user_id
             FROM cron_run_history
             WHERE task = ?
             ORDER BY id DESC
             LIMIT '.self::HISTORY_LIMIT_PER_TASK,
            [$task]
        );
    }

    /** @return array<string, mixed>|null */
    public function schedulerState(): ?array
    {
        return $this->db->fetchOne(
            'SELECT last_started_at, last_finished_at, last_status
             FROM cron_scheduler_state
             WHERE id = 1'
        );
    }

    public function markSchedulerStarted(): void
    {
        $this->db->execute(
            "INSERT INTO cron_scheduler_state (id, last_started_at, last_finished_at, last_status)
             VALUES (1, UNIX_TIMESTAMP(), NULL, NULL)
             ON DUPLICATE KEY UPDATE
                 last_started_at = VALUES(last_started_at),
                 last_finished_at = NULL,
                 last_status = NULL"
        );
    }

    public function markSchedulerFinished(bool $successful): void
    {
        $this->db->execute(
            'UPDATE cron_scheduler_state
             SET last_finished_at = UNIX_TIMESTAMP(), last_status = ?
             WHERE id = 1',
            [$successful ? 'success' : 'failed']
        );
    }

    private function activeRunId(string $task): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT active_run_id FROM cron_tasks WHERE task = ?',
            [$task]
        );
        $runId = $row['active_run_id'] ?? null;

        return is_numeric($runId) && (int) $runId > 0 ? (int) $runId : null;
    }

    private function createHistory(
        string $task,
        string $trigger,
        string $status,
        ?int $requestedByUserId = null,
        bool $started = false,
    ): int {
        $this->db->execute(
            'INSERT INTO cron_run_history
                (task, `trigger`, status, requested_at, started_at, requested_by_user_id)
             VALUES (?, ?, ?, UNIX_TIMESTAMP(), '.($started ? 'UNIX_TIMESTAMP()' : 'NULL').', ?)',
            [$task, $trigger, $status, $requestedByUserId]
        );

        return $this->db->lastInsertId();
    }

    private function markHistoryRunning(int $runId): void
    {
        $this->db->execute(
            "UPDATE cron_run_history
             SET status = 'running', started_at = UNIX_TIMESTAMP()
             WHERE id = ? AND status = 'queued'",
            [$runId]
        );
    }

    private function markHistoryTimedOut(int $runId): void
    {
        $this->db->execute(
            "UPDATE cron_run_history
             SET status = 'timed_out', finished_at = UNIX_TIMESTAMP(),
                 error = 'The previous worker stopped without releasing its lock.'
             WHERE id = ? AND status IN ('queued', 'running')",
            [$runId]
        );
    }

    private function expireHistory(int $runId): void
    {
        $this->db->execute(
            "UPDATE cron_run_history
             SET error = IF(
                     status = 'queued',
                     'The worker did not start before the queue timeout.',
                     'The worker stopped without releasing its lock.'
                 ),
                 status = IF(status = 'queued', 'start_failed', 'timed_out'),
                 finished_at = UNIX_TIMESTAMP()
             WHERE id = ? AND status IN ('queued', 'running')",
            [$runId]
        );
    }

    private function finishHistory(string $task, string $status, int $durationMs, ?string $error): void
    {
        $this->db->execute(
            'UPDATE cron_run_history AS h
             INNER JOIN cron_tasks AS task_state ON task_state.active_run_id = h.id
             SET h.status = ?, h.finished_at = UNIX_TIMESTAMP(),
                 h.duration_ms = ?, h.error = ?
             WHERE task_state.task = ?',
            [$status, $durationMs, $error, $task]
        );
    }

    private function pruneHistory(string $task): void
    {
        $this->db->execute(
            'DELETE h
             FROM cron_run_history AS h
             INNER JOIN (
                 SELECT id
                 FROM cron_run_history
                 WHERE task = ?
                 ORDER BY id DESC
                 LIMIT 1 OFFSET '.(self::HISTORY_LIMIT_PER_TASK - 1).'
             ) AS cutoff ON h.id < cutoff.id
             WHERE h.task = ?',
            [$task, $task]
        );
    }

    private static function truncateError(string $error): string
    {
        $error = trim($error);
        if (strlen($error) <= 2000) {
            return $error;
        }

        $error = substr($error, 0, 2000);
        while ($error !== '' && preg_match('//u', $error) !== 1) {
            $error = substr($error, 0, -1);
        }

        return $error;
    }
}
