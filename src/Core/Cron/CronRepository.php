<?php

declare(strict_types=1);

namespace StreamEngine\Core\Cron;

use StreamEngine\Core\PdoDatabase;

final readonly class CronRepository
{
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
    private const int LOCK_STALE_AFTER_SECONDS = 3600;

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
    public function lock(string $task): bool
    {
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
                SET locked_at = UNIX_TIMESTAMP()
              WHERE task = ?
                AND (locked_at IS NULL OR locked_at < UNIX_TIMESTAMP() - ?)",
            [$task, self::LOCK_STALE_AFTER_SECONDS]
        ) === 1;
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
    public function markDone(string $task): void
    {
        $this->db->execute(
            "UPDATE cron_runs
             SET last_run = UNIX_TIMESTAMP(),
                 locked_at = NULL
             WHERE task = ?",
            [$task]
        );
    }
}
