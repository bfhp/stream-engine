# Troubleshooting

Use this chapter when a Stream Engine installation, request, upload, email, or
background task does not behave as expected. Start with the visible symptom,
preserve evidence, and test one layer at a time. A restart can restore service,
but it is not a diagnosis unless the failed component and reason are known.

> [!IMPORTANT]
> If data may be corrupt, an upgrade or migration failed, credentials may be
> compromised, or continued requests could create loss or duplicate external
> actions, stop public writes and the scheduler. Preserve the failed state and
> use the documented backup or rollback procedure instead of experimenting on
> production data.

## First response

Before changing configuration, permissions, caches, code, or data:

1. record the exact time, timezone, URL or task, account role, HTTP status, and
   user-visible message;
2. determine whether every request fails or only one route, account, file,
   theme, task, or browser is affected;
3. record the deployed Stream Engine release and the most recent deployment,
   configuration, infrastructure, or content change;
4. preserve relevant Nginx, PHP-FPM, scheduler, database, and provider logs
   before rotation removes them;
5. check disk space, inodes, memory pressure, process restarts, and dependency
   availability;
6. decide whether the site may remain writable while diagnosis continues; and
7. make one reversible change at a time and record its result.

Do not begin by deleting lock files, flushing a shared cache, changing database
rows, recursively changing ownership, enabling development mode, or restarting
every service. Those actions can remove evidence or create a second problem.

## Evidence locations

Stream Engine has no administration page that aggregates application logs.
Correlate these sources by timestamp:

| Evidence | Location and meaning |
| --- | --- |
| Web request errors | PHP's configured error log, commonly available through the PHP-FPM service journal, pool log, container log, or hosting panel. Stream Engine calls `error_log()` but does not choose a separate web log file. |
| Web access and proxy failures | Nginx access/error logs or the equivalent reverse-proxy/load-balancer logs. These distinguish requests rejected before PHP from application responses. |
| Scheduler runtime errors | `storage/cron-error.log` after the runner has initialized logging. Errors before that point can appear only in cron mail, the systemd journal, or container scheduler output. |
| Task state | **Administration → Scheduler**, including heartbeat, status, duration, last error, consecutive failures, and up to 25 recent attempts per task. |
| Database | MariaDB service, error, connection, and slow-query logs, when enabled. |
| Email | Scheduler history, PHP/cron logs, and SMTP-provider acceptance, rejection, deferral, bounce, and suppression records. |
| Runtime state | `storage/installation.json` and `storage/migrations.json`. Inspect them only through documented workflows; do not edit them to remove an error. |
| Files | The configured `UPLOADS_DIR`, `TMP_DIR`, `TWIG_CACHE_DIR`, site-owned theme directory, and complete `storage/`. |

The files `storage/cron.lock`, `storage/migrations.json.lock`, and installation
lock files can remain on disk when no process owns their operating-system lock.
File existence alone does not prove a stuck process. Do not remove a lock until
the owning workflow and active processes have been identified.

For a traditional Linux host, useful read-only checks include:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://example.com/
sudo nginx -t
sudo systemctl status nginx php8.3-fpm --no-pager
sudo journalctl -u nginx -u php8.3-fpm --since '2026-10-09 12:00:00'
df -h /srv/stream-engine
df -i /srv/stream-engine
```

Replace the PHP-FPM unit and time with values used by the host. In Compose:

```bash
docker compose ps
docker compose logs --since=30m nginx php scheduler db memcached
```

These commands can expose request paths, addresses, and error data. Store their
output as incident evidence and redact it before sharing.

## HTTP symptom index

| Symptom | Start here |
| --- | --- |
| DNS, TLS, or connection failure | Check DNS records, certificate validity, firewall/load balancer, Nginx listener, and host reachability before investigating PHP. |
| `404 Not Found` | Confirm the canonical host and path, then inspect page/action/route publication. A missing static asset also requires checking the release artifact and Nginx document root. |
| `403 Forbidden` | Confirm the account, global role, page access rule, ownership/community rule, request method, and CSRF state. Do not assume every 403 is a filesystem permission error. |
| `413 Request Entity Too Large` | Compare Nginx `client_max_body_size`, PHP `post_max_size` and `upload_max_filesize`, and Stream Engine's file limit. The request may never reach PHP. |
| `500 Internal Server Error` | Correlate the exact request time and path with PHP-FPM logs. Production responses deliberately hide exception details. |
| `502 Bad Gateway` | Check whether PHP-FPM is running, the configured socket or upstream matches Nginx, the service account can access the socket, and the pool is not exhausted. |
| `504 Gateway Timeout` | Check PHP-FPM saturation, database latency/locks, external service timeouts, slow filesystem operations, and proxy/FPM timeout logs before increasing a limit. |
| Blank or unstyled page | Inspect HTTP status, browser network requests, Nginx document root, release assets, theme validity, and Twig/PHP errors. |

Reproduce with a private browser window only when doing so is safe. Capture the
failing request and response status, but never publish session cookies,
authorization headers, CSRF tokens, private response bodies, or sensitive query
parameters.

## Site returns HTTP 500

For unexpected application exceptions, Stream Engine writes a line similar to
`Request GET /path failed: ExceptionClass: message in file:line` to PHP's error
logger. JSON API requests return code `internal_error`; HTML and JSON responses
show a generic production message rather than the exception text.

1. find the corresponding PHP-FPM log entry by time, method, and path;
2. confirm the release is complete and that `vendor/` belongs to that release;
3. from the application root, run the non-mutating checks:

   ```bash
   composer check-platform-reqs --no-dev
   composer migrate:status
   ```

4. check `.env` readability, database and Memcached endpoints, filesystem
   capacity, and writable runtime paths;
5. determine whether the error follows one theme, module, page action, or data
   record; and
6. if the failure began after deployment, follow the upgrade rollback decision
   rather than changing production code in place.

Do not set `APP_ENV=dev` on a public site to reveal an exception. It changes
cache behavior and can disclose sensitive internal details. Keep `APP_ENV=prod`
and use protected server logs.

## Database connection or query failures

Typical signals include a site-wide 500, failed installer preflight, a failed
scheduler tick, connection-refused or authentication messages in PHP logs, or
MariaDB reporting unavailable connections.

Check in this order:

1. MariaDB is running and listening on the expected private interface and port;
2. the application host/container resolves `DB_HOST` to the intended server;
3. firewall, security-group, container network, and TLS requirements permit the
   connection;
4. `DB_NAME`, `DB_USERNAME`, and the account's allowed source host and grants
   match the deployment;
5. PHP-FPM and scheduler loaded the current `.env` after its last change;
6. MariaDB has free disk space and has not reached connection, memory, or lock
   limits; and
7. database logs show whether the failure is authentication, capacity, schema,
   lock, or query related.

Test with the MariaDB client using a protected option file or provider console;
do not put the password in the command line or source `.env` into a shell. A
successful `SELECT 1` proves only basic connectivity. It does not prove that
the schema matches the release or that the application account has every
required grant.

Do not repair a query failure with ad hoc schema or data changes. Preserve the
exact error and compare the release and migration state first.

## Pending, changed, or failed migrations

Run from the deployed application root:

```bash
composer migrate:status
```

Normal output after deployment reports `Pending: 0`. This command reads
migration files and `storage/migrations.json`; it does not connect to MariaDB
or validate database contents.

- **Pending migrations after a deployment:** stop writes and the scheduler,
  identify why the deployment procedure did not run them, take a verified
  backup, and follow the target release's upgrade procedure.
- **Changed applied migration:** redeploy an unmodified copy of the exact
  release. Released migration files must not be edited.
- **Invalid or missing migration state:** recover
  `storage/migrations.json` from the matching recovery set. Do not reconstruct
  it by guessing from database tables.
- **Migration command failed:** assume the database may contain partial changes
  even when the migration is absent from the state file. Do not rerun blindly;
  use the rollback procedure and restore the complete pre-upgrade recovery set.

Never use installer baseline commands as a production repair and never import
the fresh-install schema into an existing database.

## Filesystem permissions, full disks, and read-only mounts

Permission and capacity failures can affect installation state, migrations,
cron logs and locks, Twig cache, uploads, branding, and temporary files.

Confirm the PHP-FPM and scheduler accounts can traverse parent directories,
read `.env` and release files, and write only the required runtime paths. For
example:

```bash
namei -l /srv/stream-engine/storage
sudo -u www-data test -r /srv/stream-engine/.env
sudo -u www-data test -w /srv/stream-engine/storage
sudo -u www-data test -w /actual/uploads/path
df -h /srv/stream-engine /actual/uploads/path
df -i /srv/stream-engine /actual/uploads/path
```

The `test` commands report success through their exit status. Replace paths and
the service account. Also check mount status, quotas, SELinux/AppArmor denials,
container volume ownership, and the configured temporary and Twig-cache paths.

Fix the narrowest incorrect owner, group, ACL, mount, or service-account
setting. Do not use recursive `chmod 777`, make `.env` world-readable, or make
the complete release writable by PHP-FPM. If storage filled during a write,
verify the affected file, database, migration, or backup before resuming work.

## Memcached and stale cache symptoms

Cache failures can appear as slower requests, stale routes or settings, values
that differ between workers, or errors in service metrics without a clear UI
warning. The administration dashboard does not perform a Memcached health
test.

Check that:

- the PHP-FPM and CLI runtimes load `ext-memcached`;
- `MEMCACHED_HOST` and `MEMCACHED_PORT` are reachable on the private network;
- every installation sharing the service uses a distinct `CACHE_PREFIX`;
- the service has not restarted, exhausted memory, or begun evicting heavily;
  and
- all application instances received the same `.env` and were restarted after
  it changed.

When the extension is absent, the cache wrapper falls back to memory within one
PHP process; that is not a supported production replacement for Memcached and
can make workers disagree. When Memcached operations fail, reads can behave as
cache misses, so public availability alone does not prove cache health.

To isolate incompatible cached values, use a new unique `CACHE_PREFIX` and
restart PHP-FPM as a controlled configuration change. Record the old prefix
for rollback. Do not flush a shared Memcached instance, and do not expect cache
changes to repair persistent database or filesystem data.

## Scheduler and background-task failures

Open **Administration → Scheduler** first. Distinguish the scheduler heartbeat
from individual task status:

- **Never/Stale heartbeat:** confirm OS scheduler mode, external cron/timer or
  scheduler container, CLI PHP, `.env`, database access, and `storage/` access.
- **Failed/Overdue task:** correlate **Last error** and task history with
  `storage/cron-error.log` and the dependency used by the task.
- **Queued/Start failed:** check the CLI path, disabled PHP `exec`, permissions,
  and web-worker ability to launch the manual process.
- **Timed out:** inspect the worker and database before retrying; a task can
  still be running even after its stored lock becomes stale.

Run a single task manually only after understanding its side effects. Detailed
commands and lock behavior are in
[Scheduler and background tasks](https://github.com/bfhp/stream-engine/wiki/Administrator-Scheduler#diagnose-common-failures).
Do not delete `storage/cron.lock` or update task lock rows merely because a task
looks old.

## Email is delayed or missing

Start with the **System health** notification counts and Scheduler history for
notification delivery. A future daily digest can legitimately be pending;
look for old due items, growing counts, final failures, or user reports.

Check:

1. the scheduler is healthy and the delivery task succeeds;
2. `SMTP_HOST`, port, username, encryption mode, password, and sender match the
   provider;
3. the application host can reach the provider and its clock and TLS trust
   store are correct;
4. provider logs show acceptance, deferral, rejection, suppression, bounce, or
   rate limiting;
5. SPF, DKIM, DMARC, sender authorization, and recipient spam placement are
   appropriate for the site's domain; and
6. `SITE_URL` generates usable HTTPS links in delivered messages.

Use one controlled recipient and workflow after correcting the cause. Do not
bulk-reset `notification_deliveries`: the current administration interface has
no supported bulk retry, and direct state changes can duplicate mail or in-site
notifications. Never publish SMTP debug output without removing credentials,
tokens, addresses, message content, and provider identifiers.

## Upload fails or an existing file is missing

For a new upload failure, identify which layer rejected it:

- Nginx can return 413 before PHP when `client_max_body_size` is too small;
- PHP can reject the request through `post_max_size`, `upload_max_filesize`,
  temporary-path, or upload error limits;
- Stream Engine validates allowed type, detected MIME content, per-file size,
  path length, duplicate names, and user quota; and
- `FileStorage` requires a writable local `UPLOADS_DIR` with free bytes and
  inodes.

Administrator File Browser uploads have a 50 MB application limit; other
workflows and account quotas can impose additional limits. Inspect the browser
network response, Nginx and PHP logs, filesystem ownership, temporary path,
disk/inodes, and the relevant account quota.

For a missing existing file:

1. record its `/uploads/...` URL and the page or record that references it;
2. verify the Nginx `/uploads/` alias points to the active `UPLOADS_DIR`;
3. check the exact relative path and filename case on disk;
4. determine whether a File Browser move/delete, restore, deployment, or direct
   filesystem change separated the database record and file; and
5. restore the matching file and database state from a consistent recovery set
   when necessary.

Do not manufacture an empty replacement, alter upload records directly, or
move files in bulk while requests remain active. `OBJECT_STORAGE_*` and MinIO
do not provide a fallback for current runtime uploads.

## Theme, template, or frontend asset problems

If the configured theme is missing or invalid, Stream Engine selects the
built-in `default` theme and the Themes page reports the fallback. If a
non-default theme throws a Twig loader, runtime, or syntax error while
rendering, the application logs the theme ID and error and attempts the default
theme for that request.

1. open **Administration → Themes** and check for the fallback warning;
2. verify that the theme, its parent chain, manifest, templates, and assets all
   belong to the deployed release or site-owned version;
3. inspect PHP logs for `Theme '<id>' failed to render` and the underlying Twig
   error;
4. test the built-in default theme privately;
5. use a new empty `TWIG_CACHE_DIR` or clear only the exact configured Twig
   cache through the deployment procedure; and
6. restart PHP-FPM so old workers and opcode cache no longer serve old code.

Missing CSS or JavaScript usually indicates an incomplete release artifact,
wrong Nginx document root, failed asset deployment, cached proxy/CDN response,
or theme asset path—not a database problem. Check the browser network panel and
request the missing asset directly. Do not run `npm update` in the live release
as a repair.

## Incorrect route, stale page, or access result

For an unexpected 404, redirect, content page, or access denial:

1. confirm `SITE_URL`, requested host, path, method, and trailing-slash form;
2. inspect the page tree, page action, route pattern, feed binding, publication
   state, and access rule;
3. test as guest and as the affected role, including a direct URL rather than
   relying on menu visibility;
4. check recent route, slug, page, menu, theme, or cache changes; and
5. allow for cached canonical feed URLs, or isolate the site's cache namespace
   without flushing other sites.

A hidden menu entry is not an authorization check, and a visible menu entry
does not grant access. Follow
[Content, pages, and routing](https://github.com/bfhp/stream-engine/wiki/Administrator-Content-and-Routing)
and [Users and permissions](https://github.com/bfhp/stream-engine/wiki/Administrator-Users-and-Permissions)
before changing data directly.

## Installation cannot start or resume

Use the failed installer preflight item or CLI message as the primary symptom.
Check the PHP version/extensions, release integrity, `.env` and `storage/`
permissions, database reachability and emptiness, and installation state.

`storage/installation.json` records the workflow. A `ready` state intentionally
disables the installer. A failed or interrupted install must be investigated
with its existing state and logs; deleting the state, token, claim, or lock can
remove recovery information or allow an unsafe second installer.

The web installer uses either the server-side installation token, when one was
created, or a browser claim that is valid for one hour. Verify the system clock
and retrieve or create the token through `composer cms:install-token` when that
protection mode is intended. If the claiming browser was lost, follow the
documented claim-reset procedure only after confirming that no installer is
active. Never share the token in an issue, URL capture, screenshot, or log
excerpt. Follow the complete
[Installation problems](https://github.com/bfhp/stream-engine/wiki/Administrator-Installation#installation-problems)
table before retrying.

## Failure during or after an upgrade

Keep traffic and the scheduler stopped. Preserve migration output, logs, the
failed release, and database state. Do not point the old release at the changed
database merely to test it.

- If no migration or production write occurred, switch back to the exact old
  release and its cache configuration, then test privately.
- If migrations began or any production write occurred, restore the complete
  pre-upgrade database, `.env`, `storage/`, uploads, site-owned files, and old
  release as one recovery point.
- If a migration failed, assume partial unrecorded database changes.

Use the full
[Upgrades and rollback](https://github.com/bfhp/stream-engine/wiki/Administrator-Upgrades-and-Rollback#rollback-decision)
procedure. Do not trade a controlled outage for an improvised mixed-version
deployment.

## Prepare a safe support report

Before opening a public issue, reproduce on an unmodified tagged release when
possible and search existing release notes and issues. Include:

- Stream Engine version or immutable commit and whether site-owned code exists;
- deployment model, operating system/container image, web server, PHP and
  MariaDB versions;
- relevant PHP extensions without configuration values;
- exact UTC timestamp, request method/path without sensitive query data, HTTP
  status, and a minimal reproduction sequence;
- expected and observed behavior;
- the output of `composer check-platform-reqs --no-dev` and
  `composer migrate:status` when relevant;
- a short, timestamped, redacted log excerpt; and
- whether the problem reproduces with the built-in theme and in an isolated
  restored environment.

Never attach `.env`, database dumps, complete `storage/`, uploads, backup
archives, private themes/modules, cookies, authorization or CSRF headers,
installation tokens, password hashes, SMTP credentials, object-storage keys,
private message/email content, or unredacted personal data. Replace secrets
with labels such as `[REDACTED_DB_PASSWORD]`; do not merely blur a screenshot
when the original text remains embedded in the file.

## Troubleshooting checklist

- [ ] Exact symptom, scope, timestamp, timezone, release, and recent change are
      recorded.
- [ ] Continued public and scheduler writes are safe, or they have been stopped.
- [ ] Relevant logs and failed state are preserved before rotation or restart.
- [ ] HTTP/proxy, PHP, database, cache, filesystem, and scheduler layers were
      separated rather than changed together.
- [ ] Platform requirements and migration status were checked read-only.
- [ ] No lock/state file, database row, or shared cache was modified casually.
- [ ] One reversible correction was tested privately before reopening traffic.
- [ ] Uploads, email, scheduler, protected access, and logs were rechecked when
      relevant.
- [ ] Shared evidence is minimal and fully redacted.
- [ ] Root cause, recovery, user impact, and prevention work are recorded.

[Back: Upgrades and rollback](https://github.com/bfhp/stream-engine/wiki/Administrator-Upgrades-and-Rollback) · [Administrator Guide](https://github.com/bfhp/stream-engine/wiki/Administrator-Guide)
