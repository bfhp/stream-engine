# Cron deployment

Stream Engine uses an external once-per-minute scheduler in production. The
minute cadence is required because notification deliveries are due every 60
seconds; hourly maintenance tasks use their own stored intervals and simply
skip the other ticks.

Set the production environment to:

```dotenv
CRON_MODE=os
```

This prevents page requests from starting PHP processes. The scheduled command
is:

```bash
cd /srv/stream-engine && /usr/bin/php bin/cron.php
```

Replace both paths with absolute paths from the deployment. Run the command as
the same operating-system user as PHP-FPM so it can read `.env` and write
`storage/cron.lock` and `storage/cron-error.log`. The runner has both a process
lock and per-task database locks, so an overlapping tick exits without running
the same work twice.

## Cron

Install this entry with the application user's crontab:

```cron
* * * * * cd /srv/stream-engine && /usr/bin/php bin/cron.php
```

Cron normally emails output and launch errors to the owner of the crontab.
Configure that mail or redirect output to logging managed by the deployment;
do not redirect failures to `/dev/null` in production. Unhandled runner failures
are also written to `storage/cron-error.log`, and PHP exits non-zero.

## systemd timer

For a systemd deployment, `/etc/systemd/system/stream-engine-cron.service` can
contain:

```ini
[Unit]
Description=Stream Engine scheduled tasks

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/srv/stream-engine
ExecStart=/usr/bin/php bin/cron.php
```

Create `/etc/systemd/system/stream-engine-cron.timer` alongside it:

```ini
[Unit]
Description=Run Stream Engine scheduled tasks every minute

[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
Unit=stream-engine-cron.service

[Install]
WantedBy=timers.target
```

Then enable the timer:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now stream-engine-cron.timer
```

Container schedulers should invoke the same `php bin/cron.php` command once per
minute in an application container with the same release, environment, database
access and writable `storage` volume as the web application. Do not run one
permanent copy of the script: it performs one tick and exits.

## Verification

Before enabling the schedule, run one tick as the service account and check its
exit status:

```bash
cd /srv/stream-engine
/usr/bin/php bin/cron.php
echo $?
```

An exit status of `0` means the runner completed (or another tick held the
process lock); a non-zero status means initialization or the runner failed.
Individual task failures are logged and retried on a later tick. After enabling the schedule,
verify its logs and confirm that due rows in `cron_runs` receive a recent
`last_run` value.

## Request-driven fallback

`CRON_MODE=web` is intended for development and compatibility deployments that
cannot run an external scheduler. Development starts a tick on every request.
In other environments one request out of 50 starts a tick.

This mode has no wall-clock guarantee: its expected delay is 50 requests, and a
site with no traffic does no background work at all. It is therefore unsuitable
for production sites that require timely notifications. Configure new
installations with `os` or `web`.
