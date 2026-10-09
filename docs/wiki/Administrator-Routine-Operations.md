# Routine Operations

Routine operation keeps a working Stream Engine site observable, recoverable,
and within capacity before users notice a problem. This chapter combines the
built-in administration views with host, database, mail, and network checks
that remain the site operator's responsibility.

The suggested daily, weekly, and monthly intervals are a starting point.
Shorten them for a busy or business-critical site and align alerts, retention,
and review frequency with the site's recovery objectives.

> [!IMPORTANT]
> Stream Engine does not currently provide an uptime monitor, alert delivery
> service, centralized log viewer, capacity monitor, or automatic update
> checker. The Dashboard is an operational summary, not proof that the entire
> service is healthy.

## Maintain an operational record

Keep a private record outside the application containing:

- the deployed Stream Engine release, deployment time, and responsible person;
- the application root, uploads path, database name, and service-account name;
- Nginx, PHP-FPM, scheduler, MariaDB, Memcached, and SMTP service ownership;
- monitoring and log locations, retention periods, and alert destinations;
- the last successful backup and restore test;
- current storage and database baselines; and
- open operational exceptions, disabled tasks, and planned maintenance.

Do not put passwords, `.env` contents, session cookies, private tokens, or
database dumps in that record unless it is an approved secret-management
system. The purpose is to make the installation supportable, not to create a
second copy of its secrets.

## Use the administration Dashboard

Open **Administration → Dashboard** and refresh it at the start of an
operational review. The Dashboard loads a snapshot; it does not continuously
poll for changes. The page-level refresh reloads every visible card, while the
refresh control on a card reloads only that card.

The built-in cards are:

| Card | What it shows | Important limitation |
| --- | --- | --- |
| **Users** | Total, active, and inactive account counts. | Counts do not identify dormant accounts or recent privilege changes. |
| **Content** | Number of feed items and distinct feed types. | Counts do not validate public routes, permissions, or rendered pages. |
| **Shortcuts** | Links to users, page creation, and settings. | Convenience only; it is not a status card. |
| **Recent activity** | A combined list of the newest user registrations and feed records. | It is a small recent-content list, not an audit log. |
| **Moderation** | Pending community membership requests. | Only the relevant community owner can resolve a request in the current interface. |
| **System health** | Stored cron task count, latest successful task time, stale task locks, and pending or failed notification deliveries. | It does not test services, disk, SMTP, cache, scheduler cadence, or public availability. |

The **Last cron** value in **System health** is the newest successful task time
stored in `cron_tasks`. It is not the last scheduler tick. Select that metric
and use the Scheduler page's **Last scheduler tick** status for the authoritative
scheduler heartbeat.

A **card error** means its data provider or database query failed. It does not
mean the metric is zero. Refresh once, then inspect PHP-FPM and database logs if
the error remains.

Choose **Customize** to show, hide, resize, or reorder cards. The layout is
stored per administrator account. Hiding **System health** hides the signal
only from that account; it does not disable monitoring or correct a problem.
**Restore defaults** resets the current administrator's layout and does not
change site data.

## Monitor the complete service

Use external monitoring for components that the Dashboard cannot observe.
At minimum, monitor:

- the public HTTPS home page and one representative dynamic page;
- DNS resolution, TLS certificate expiry, HTTP latency, and 4xx/5xx rates;
- Nginx and PHP-FPM availability, saturation, restarts, and worker failures;
- scheduler heartbeat and overdue or failed tasks;
- MariaDB availability, connections, query latency, storage, and backups;
- Memcached availability, memory use, evictions, and restarts;
- SMTP reachability and provider rejection or deferral rates;
- application, upload, database, log, and temporary-volume free space and
  inode availability; and
- host or container CPU, memory, load, OOM events, and restart counts.

An HTTP `200` from the home page does not test login, database writes, email,
uploads, or background work. Use separate safe probes where those capabilities
matter. Never make a synthetic probe create permanent content, send mail to
real users, or consume one-time security tokens.

Set warning and critical thresholds from measured normal behaviour and the time
required to respond. A small volume that grows quickly can be more urgent than
a large stable volume. Keep enough free space for uploads, logs, temporary
files, database growth, an upgrade, and the backup method used by the host.

## Review logs

Stream Engine writes web-request errors through PHP's configured error logger.
Their final destination depends on the PHP-FPM and operating-system setup. Cron
configures `storage/cron-error.log` after startup; scheduler launch failures may
instead appear only in cron mail, the systemd journal, or container logs.

Review these sources together:

- Nginx access and error logs;
- PHP-FPM service and PHP error logs;
- `storage/cron-error.log` plus cron, systemd, or container scheduler output;
- MariaDB error and slow-query logs when enabled;
- Memcached service metrics and restart history;
- SMTP or email-provider delivery logs; and
- host, container-runtime, kernel, and OOM logs.

There is no administration page that aggregates these logs. Configure external
rotation and retention so a busy site cannot fill a volume and an incident is
not erased before review. Synchronize system clocks and record the timezone
used by every log source before correlating events.

Treat logs as sensitive. Before attaching them to an issue or support request,
remove passwords, authorization headers, cookies, CSRF and installation tokens,
email addresses or other personal data not required for diagnosis, and private
infrastructure addresses. Preserve timestamps, HTTP status, request path,
release identifier, and a concise reproduction sequence.

## Watch notification deliveries

The **System health** card counts rows in `notification_deliveries` with
`pending` and `failed` status.

A non-zero pending count is not automatically a fault. Daily email digests are
created for a future delivery time, and transient failures return to pending
for retry. Look for a growing count, old due work, user reports, or a failed
task on the Scheduler page.

Each failed delivery is retried after five minutes, up to three attempts. After
the final failed attempt its status becomes `failed`; the current administration
interface has no supported bulk retry or delivery-detail view. Fix SMTP,
template, address, or scheduler problems before testing future delivery. Do not
reset statuses directly in MariaDB: doing so can duplicate mail or in-site
notifications.

When the Dashboard numbers are insufficient, a database administrator can run
this read-only summary against the application database:

```sql
SELECT
    status,
    channel,
    delivery,
    COUNT(*) AS deliveries,
    SUM(scheduled_at <= UNIX_TIMESTAMP()) AS due,
    FROM_UNIXTIME(MIN(scheduled_at)) AS oldest_scheduled
FROM notification_deliveries
WHERE status IN ('pending', 'processing', 'failed')
GROUP BY status, channel, delivery
ORDER BY status, channel, delivery;
```

Use an authenticated database client without putting the password on the
command line. The query deliberately avoids message payloads and recipient
addresses. Error text may still appear in application or SMTP logs and must be
redacted before sharing.

## Track storage capacity

Check both bytes and inodes on every volume that holds application state. On a
traditional Linux host, substitute the real uploads path and run:

```bash
df -h /srv/stream-engine /actual/uploads/path
df -i /srv/stream-engine /actual/uploads/path
du -sh /srv/stream-engine/storage /actual/uploads/path
```

If uploads are inside `storage/`, the two `du` targets overlap; record the
uploads subdirectory separately only when that distinction is useful. Run
capacity commands with an account allowed to traverse the paths, but do not
broaden filesystem permissions merely to collect metrics.

Track at least:

- local uploads, including branding and site icons;
- Twig cache, temporary files, cron logs, and installation/migration state;
- Nginx, PHP, database, scheduler, and container logs;
- MariaDB data and binary logs on their actual volume;
- container volumes and image layers; and
- filesystem inode use when many small files are possible.

The File Browser does not display total free space. Its upload records also do
not include every file that may exist under the uploads root, so do not treat a
database sum as the filesystem total.

To identify growing MariaDB tables, use a read-only connection to the
application database:

```sql
SELECT
    table_name,
    table_rows,
    ROUND((data_length + index_length) / 1024 / 1024, 1) AS size_mib
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY data_length + index_length DESC
LIMIT 10;
```

`table_rows` can be an estimate for InnoDB. Compare repeated measurements
rather than treating one value as exact. Investigate unexpected growth before
deleting anything; there is no supported general-purpose table-pruning action.

## Review accounts and privileges

Open **Administration → Users** and review role and status filters. The list
shows registration time, not last login, so it cannot by itself establish that
an account is dormant.

At a regular interval:

- confirm every administrator still requires global access;
- retain at least two tested active administrator accounts;
- review moderator assignments and inactive accounts;
- compare new-registration volume with the site's registration policy and
  abuse signals;
- verify that former staff or contractors no longer have access;
- review pending community membership requests with the responsible community
  owners; and
- record privilege changes in an external audit trail.

Follow the safeguards in
[Users and permissions](https://github.com/bfhp/stream-engine/wiki/Administrator-Users-and-Permissions).
Do not infer inactivity from registration age alone, and do not query password
hashes, session identifiers, or private profile data for a routine review.

## Check migration and release state

From the deployed application root, the following read-only command compares
the release's migration files with `storage/migrations.json`:

```bash
composer migrate:status
```

Normal output after a completed deployment reports `Pending: 0`. A pending
migration outside a planned deployment is a release-consistency problem. Do not
run `composer migrate` casually during a routine check; first identify the
deployed release, read its upgrade instructions, take a verified backup, and
plan rollback.

Stream Engine currently has no in-application update notification. Monitor the
project's [GitHub releases](https://github.com/bfhp/stream-engine/releases) and
security notices, and subscribe the responsible maintainers through GitHub or
the organisation's dependency-monitoring service. Also track supported PHP,
MariaDB, Nginx, operating-system, container-image, and Composer dependency
updates.

Do not run `composer update`, `npm update`, or an unpinned container pull in a
live release directory as an update check. Those commands can change deployed
code or dependencies. Evaluate a specific release in staging and follow the
project's upgrade and rollback procedure.

## Respond to an abnormal check

When a routine check finds a problem:

1. record the exact time, affected component, user-visible effect, and current
   release;
2. preserve relevant logs and monitoring data before rotation removes them;
3. determine whether the site is still writing data or sending notifications;
4. stop only the unsafe component when continued work could cause loss or
   duplicate external actions;
5. check recent deployments, configuration changes, capacity, and dependency
   failures;
6. use the narrowest supported corrective action;
7. verify public reads, authenticated work, uploads, email, and scheduled tasks
   as applicable; and
8. document the cause, repair, and prevention work.

Stream Engine has no built-in maintenance mode. Use the reverse proxy,
load balancer, or hosting platform when requests must be paused. Closing public
registration affects only new account creation and is not a site-wide
maintenance control.

Avoid direct database updates, deleting lock files, clearing directories, or
restarting every service before evidence is collected. These actions can hide
the original cause or create a second failure.

## Suggested operating schedule

### Daily

- [ ] Confirm external HTTPS and dynamic-page probes are passing.
- [ ] Refresh the Dashboard and investigate card errors or unexpected trends.
- [ ] Confirm the Scheduler heartbeat is Healthy and tasks are not repeatedly
      failed, overdue, or timed out.
- [ ] Review pending and failed delivery trends.
- [ ] Check new Nginx, PHP-FPM, cron, database, and SMTP errors.
- [ ] Confirm disk and inode alerts are clear.
- [ ] Confirm the latest external backup job completed successfully.
- [ ] Triage urgent registration, abuse, and moderation signals.

### Weekly

- [ ] Record upload, log, database, and volume growth against the baseline.
- [ ] Review the largest database tables and unexplained queue growth.
- [ ] Review administrator, moderator, inactive-account, and registration
      changes.
- [ ] Check disabled scheduler tasks and operational exceptions for an owner
      and expiry date.
- [ ] Run `composer migrate:status` and confirm the deployment inventory still
      identifies one intended release.
- [ ] Review PHP-FPM, MariaDB, Memcached, SMTP, and container capacity trends,
      restarts, and saturation.
- [ ] Confirm log rotation and backup retention are succeeding.

### Monthly or maintenance-cycle review

- [ ] Review Stream Engine releases and security notices plus platform security
      updates.
- [ ] Forecast when storage, database, and service capacity will reach warning
      thresholds.
- [ ] Review TLS expiry, DNS ownership, external credentials, and alert routing.
- [ ] Test that responsible operators can access the host, database, backup
      system, and at least two administrator accounts.
- [ ] Perform a restore test at the frequency required by the site's recovery
      objectives and record its result.
- [ ] Review monitoring coverage, log retention, false alerts, and unresolved
      incidents.
- [ ] Rehearse the next upgrade in a non-production environment when one is
      planned.

[Back: Scheduler and background tasks](https://github.com/bfhp/stream-engine/wiki/Administrator-Scheduler) · [Administrator Guide](https://github.com/bfhp/stream-engine/wiki/Administrator-Guide)
