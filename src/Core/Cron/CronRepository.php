<?php

declare(strict_types=1);

namespace StreamEngine\Core\Cron;

use StreamEngine\Core\PdoDatabase;

final readonly class CronRepository
{
    public const int LOCK_STALE_AFTER_SECONDS = 3600;

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
            "SELECT last_run FROM cron_runs WHERE task = ?",
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

        // Make sure the row exists, without touching an existing one - the
        // `task = task` no-op is what keeps a concurrently held locked_at
        // intact. INSERT IGNORE would read as well here but downgrades every
        // error to a warning, not just the duplicate key.
        $this->db->execute(
            "INSERT INTO cron_runs (task, last_run, locked_at)
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
        return $this->db->execute(
            "UPDATE cron_runs
                SET locked_at = UNIX_TIMESTAMP(),
                    last_started_at = UNIX_TIMESTAMP(),
                    last_trigger = ?
              WHERE task = ?
                AND is_enabled = 1
                AND (locked_at IS NULL OR locked_at < UNIX_TIMESTAMP() - ?)",
            [$trigger, $task, self::LOCK_STALE_AFTER_SECONDS]
        ) === 1;
    }

    public function isEnabled(string $task): bool
    {
        $state = $this->db->fetchOne(
            'SELECT is_enabled FROM cron_runs WHERE task = ?',
            [$task]
        );

        return $state === null || (int) ($state['is_enabled'] ?? 1) === 1;
    }

    public function setEnabled(string $task, bool $enabled): void
    {
        $this->db->execute(
            'INSERT INTO cron_runs (task, is_enabled, last_run, locked_at)
             VALUES (?, ?, 0, NULL)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)',
            [$task, $enabled ? 1 : 0]
        );
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
            "UPDATE cron_runs
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
        $this->db->execute(
            "UPDATE cron_runs
             SET last_run = UNIX_TIMESTAMP(),
                 last_finished_at = UNIX_TIMESTAMP(),
                 last_status = 'success',
                 last_duration_ms = ?,
                 last_error = NULL,
                 consecutive_failures = 0,
                 locked_at = NULL
             WHERE task = ?",
            [max(0, $durationMs), $task]
        );
    }

    /** Records a failed attempt without moving last_run, so it remains due. */
    public function markFailed(string $task, int $durationMs, string $error): void
    {
        $this->db->execute(
            "UPDATE cron_runs
             SET last_finished_at = UNIX_TIMESTAMP(),
                 last_status = 'failed',
                 last_duration_ms = ?,
                 last_error = ?,
                 consecutive_failures = consecutive_failures + 1,
                 locked_at = NULL
             WHERE task = ?",
            [max(0, $durationMs), self::truncateError($error), $task]
        );
    }

    /** @return list<array<string, mixed>> */
    public function states(): array
    {
        return $this->db->fetchAll(
            'SELECT task, is_enabled, last_run, locked_at, last_started_at, last_finished_at,
                    last_status, last_trigger, last_duration_ms, last_error, consecutive_failures
             FROM cron_runs'
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
