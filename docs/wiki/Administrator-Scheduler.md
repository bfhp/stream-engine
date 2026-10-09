# Scheduler and Background Tasks

Stream Engine uses background tasks for notification delivery and periodic
cleanup. A production site requires an external scheduler to invoke the task
runner once per minute. The runner decides which registered tasks are due; do
not create a separate operating-system schedule for each task.

Open **Administration → Scheduler** to select the scheduling mode, inspect the
last scheduler tick, enable or disable individual tasks, start a task manually,
and review recent execution history.

> [!IMPORTANT]
> A working web site does not prove that scheduled work is running. Complete
> the setup and verification in this chapter before accepting production
> traffic.

## Choose a scheduling mode

The mode is stored in the database as the `cron.mode` setting.

| Mode | Behaviour | Appropriate use |
| --- | --- | --- |
| **OS scheduler** | An external cron service, systemd timer, or container scheduler runs `bin/cron.php`. Public requests never start the runner. | Required for normal production operation. |
| **Web requests** | A qualifying public request starts a background runner. Development starts one on every request; other environments use approximately one request in 50. | Development or a compatibility deployment that cannot provide a real scheduler. |
| **Off** | Automatic invocations exit without running tasks. Enabled tasks can still be started manually. | A deliberate maintenance pause or diagnosis. |

**Web requests** has no wall-clock guarantee. A site with no traffic performs
no work, and a low-traffic site can delay notifications and cleanup
indefinitely. It also depends on PHP being able to start a background CLI
process. Do not use it as a production scheduler merely because it appears to
work during testing.

Do not intentionally combine **Web requests** with an external scheduler.
Process and database locks reduce overlap, but the deployment should have one
clear owner for automatic ticks.

Changing to **Off** does not terminate a task that is already running. It also
does not disable individual task records. Return to the intended mode after a
maintenance window; otherwise queued work remains pending.

## Runner requirements

The once-per-minute command is:

```bash
cd /srv/stream-engine && /usr/bin/php bin/cron.php
```

Replace both paths with absolute paths from the deployment. The command runs
one scheduler tick and exits; it is not a permanent worker.

Run it as the same operating-system account as PHP-FPM, or as a dedicated
account with equivalent access. That account must be able to:

- read the release files, Composer autoloader, and `.env`;
- load the same required PHP extensions as PHP-FPM;
- connect to MariaDB, Memcached, SMTP, and other services used by tasks; and
- create and write `storage/cron.lock` and `storage/cron-error.log`.

Use the PHP CLI binary, not the PHP-FPM executable. Cron jobs receive a small
environment, so use absolute paths and do not depend on an interactive shell's
`PATH`, aliases, current directory, or environment variables. The application
loads its deployment environment from `.env`.

The normal runner holds a process lock in `storage/cron.lock`. Each task also
uses an atomic database lock shared by scheduled and manual runs. An overlapping
tick exits without duplicating work, while a second invocation of the same task
is refused. These locks are safeguards, not a reason to operate duplicate
schedulers.

## Traditional cron setup

First confirm the PHP binary and application paths on the host:

```bash
command -v php
cd /srv/stream-engine
sudo -u www-data /usr/bin/php --version
```

Replace `www-data` with the PHP-FPM service account. Then:

1. open **Administration → Scheduler**;
2. select **OS scheduler** and save the mode;
3. edit that service account's crontab, for example with
   `sudo crontab -u www-data -e`; and
4. add this entry:

```cron
* * * * * cd /srv/stream-engine && /usr/bin/php bin/cron.php
```

The five time fields mean once per minute. A file under `/etc/cron.d/` has a
different format and requires a user field; do not copy a user-crontab entry
into that directory unchanged.

Cron commonly emails command output and launch errors to the crontab owner.
Configure delivery of that output or route it into the host's managed logging
system. Do not discard scheduler output with `/dev/null` in production.
PHP and runner errors that occur after application logging is initialized are
also written to `storage/cron-error.log`; startup errors must be recovered from
the scheduler's own output.

## systemd timer setup

On a systemd host, create
`/etc/systemd/system/stream-engine-cron.service`:

```ini
[Unit]
Description=Stream Engine scheduled tasks

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/srv/stream-engine
ExecStart=/usr/bin/php bin/cron.php
```

Replace the user and paths for the deployment. Then create
`/etc/systemd/system/stream-engine-cron.timer`:

```ini
[Unit]
Description=Run Stream Engine scheduled tasks every minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Persistent=true
Unit=stream-engine-cron.service

[Install]
WantedBy=timers.target
```

Load and enable both definitions:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now stream-engine-cron.timer
sudo systemctl status stream-engine-cron.timer
sudo systemctl list-timers stream-engine-cron.timer
```

Inspect recent service invocations with:

```bash
sudo journalctl -u stream-engine-cron.service --since "15 minutes ago"
```

`Persistent=true` requests a missed invocation after the host returns from
downtime. The application still applies each task's stored interval and lock;
it does not replay one invocation for every missed minute.

## Included Docker Compose scheduler

The repository's development Compose configuration includes a `scheduler`
service. It uses the same application image, environment, bind mount, and
service dependencies as PHP-FPM. Supercronic runs in the foreground as
`www-data` and invokes `php bin/cron.php` once per minute.

Start and inspect it with:

```bash
docker compose up -d scheduler
docker compose ps scheduler
docker compose logs --tail=100 scheduler
```

The development installer normally selects **Web requests** because
`APP_ENV=dev`. Change the saved mode to **OS scheduler** when testing the
Compose scheduler, so public requests do not also start ticks.

The included Compose environment contains development credentials, published
service ports, and working-tree mounts. It is not a production deployment
template. A production container platform must provide its own once-per-minute
scheduler using a container with:

- the exact same Stream Engine release as the web application;
- the same `.env` or equivalent injected secrets;
- access to the same database, cache, mail service, and persistent storage;
- a writable shared `storage` location when the deployment requires it; and
- the command `php bin/cron.php` as a one-shot invocation.

Do not keep one copy of `bin/cron.php` running continuously; it exits after one
tick by design.

## Verify the scheduler

Run one tick interactively as the scheduler account:

```bash
cd /srv/stream-engine
sudo -u www-data /usr/bin/php bin/cron.php
echo $?
```

Exit status `0` means the runner initialized and completed, or another tick
already held the process lock. A non-zero result means initialization or the
top-level runner failed. An individual task failure is recorded and logged but
does not make the complete tick fail, so the exit status alone is insufficient.

After enabling the recurring schedule:

1. wait at least two minutes;
2. open **Administration → Scheduler** and refresh;
3. confirm **Last scheduler tick** is **Healthy** and recent;
4. confirm the one-minute notification task has a recent success or a
   reasonable next-run time;
5. open any task name and inspect its recent history; and
6. check the scheduler service log and `storage/cron-error.log`.

The scheduler becomes **Stale** when no tick finishes for more than three
minutes. Verify again after every release, PHP upgrade, credential rotation,
service-account change, or move of the application directory.

## Understand task state

The task table shows the registered name and module, interval, current status,
last successful execution, next expected run, last duration, enabled switch,
and **Run now** action.

Current built-in tasks include:

| Task | Interval | Purpose |
| --- | --- | --- |
| `notifications:deliveries` | One minute | Process queued notification deliveries. |
| `forums:uploads-cleanup` | One hour | Remove abandoned pending forum attachments after their grace period. |
| `users:cleanup` | One hour | Remove expired password-reset and verification state, including unfinished expired registrations. |
| `users:sessions-cleanup` | One hour | Remove expired member, guest, and crawler session rows. |

Modules can add or remove registered tasks in a release, so treat the Scheduler
page as authoritative for the installed version.

| Status | Operator interpretation |
| --- | --- |
| **Scheduled** | The last success is recent and the next interval has not arrived. |
| **Due** | The interval has arrived and is still within the short scheduling grace period. |
| **Overdue** | No success was recorded within the interval and grace period. |
| **Queued** | A manual request was recorded and its background worker has not taken the task lock yet. |
| **Running** | A worker holds the task's database lock. |
| **Failed** | The latest attempt threw an error. It remains due and can be retried on a later tick. |
| **Start failed** | A manual worker did not start within three minutes or its launch failed. |
| **Timed out** | A task lock is older than one hour. This indicates stale state or an unexpectedly long task; it does not prove the process was terminated. |
| **Never run** | No execution has yet been recorded for this registered task. |
| **Disabled** | The individual task or automatic scheduling is disabled. |

The Scheduler summary counts current problems; it is not a monitoring or alert
delivery system. External monitoring should alert when the scheduler becomes
stale, tasks remain overdue, or failures repeat.

## Enable, disable, and run tasks

Use the switch in the task row to enable or disable a registered task.
Disabling prevents new scheduled and manual starts but does not stop an
invocation that already holds the lock. Before disabling a cleanup or delivery
task, document why, expected queue or storage growth, and when it will be
enabled again.

**Run now** ignores the task's interval but uses the same lock and enabled
state as scheduled work. It may send external notifications or delete expired
data. Read the task purpose, correct the underlying problem, and confirm the
action before starting it.

A manual request first appears as **Queued** and then **Running**. The web
process must be allowed to launch the PHP CLI binary. If hosting disables PHP's
`exec` function or the CLI binary cannot be found or executed, the UI records
**Start failed**. For controlled command-line diagnosis, an operator can run a
single registered task directly:

```bash
cd /srv/stream-engine
sudo -u www-data /usr/bin/php bin/cron.php --task=notifications:deliveries
```

This command bypasses the task interval and also works while automatic mode is
**Off**, but it still respects the task's enabled state and database lock. Use
it for diagnosis, not as a replacement for the once-per-minute scheduler.
Unknown task names are rejected.

Selecting a task name opens up to its latest 25 attempts. History identifies
scheduled versus manual runs, request/start/finish times, duration, result,
error text, and the requesting administrator ID for manual work. Older entries
are pruned automatically.

## Diagnose common failures

### Last scheduler tick is Never or Stale

1. confirm the saved mode is **OS scheduler**, not **Off** or **Web requests**;
2. confirm the cron entry, timer, or scheduler container is enabled;
3. inspect cron mail, the systemd journal, or container logs for launch errors;
4. run the exact command interactively as the service account;
5. verify CLI PHP version and extensions, `.env` readability, database access,
   and write access to `storage/`; and
6. check `storage/cron-error.log`.

A leftover `storage/cron.lock` file is normal. The operating system lock, not
the file's existence, controls exclusion. Do not delete it while investigating
without first confirming that no runner process is active.

### Scheduler is Healthy but a task is Failed or Overdue

Open the task history and read **Last error**, then correlate its timestamp
with `storage/cron-error.log` and relevant service logs. Check the dependency
used by that task, such as SMTP, the database, or writable upload storage.
After correcting the cause, use **Run now** once and confirm a successful
history entry. Repeated failures remain due and are retried by later ticks.

### A task is Queued, Start failed, or Timed out

- **Queued** for more than three minutes becomes **Start failed**. Check the
  CLI path, PHP `exec` restrictions, and filesystem permissions.
- **Timed out** means the stored lock is more than one hour old. Check process,
  database, and host logs before retrying; an unusually long worker may still
  be active.
- Do not clear `cron_tasks` locks with an ad hoc database update. A later runner
  can reclaim a genuinely stale lock, while manual edits can create concurrent
  destructive work.

If a legitimate task can run for an hour, stop and raise the condition with the
project maintainers before operating it at that scale. The current stale-lock
window assumes registered tasks finish sooner.

## Scheduler checklist

- [ ] **OS scheduler** is saved for production.
- [ ] Exactly one external scheduler invokes the runner once per minute.
- [ ] The command uses absolute paths and the intended service account.
- [ ] CLI PHP has the required version and extensions.
- [ ] `.env` is readable and `storage/` is writable without broad permissions.
- [ ] Scheduler logs and `storage/cron-error.log` are retained and monitored.
- [ ] **Last scheduler tick** remains Healthy after at least two minutes.
- [ ] Registered tasks show expected recent successes and next-run times.
- [ ] Disabled tasks have an owner, reason, and re-enable date.
- [ ] Scheduler health is rechecked after deployments and infrastructure
      changes.

[Back: Appearance, navigation, and files](https://github.com/bfhp/stream-engine/wiki/Administrator-Appearance-and-Files) · [Administrator Guide](https://github.com/bfhp/stream-engine/wiki/Administrator-Guide)
