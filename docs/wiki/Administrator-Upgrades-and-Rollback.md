# Upgrades and Rollback

Treat every Stream Engine upgrade as a controlled change to application code,
dependencies, database schema, persistent files, and caches. A successful
upgrade is one that can also be reversed within the agreed rollback window.

Stream Engine currently has no in-application updater, maintenance mode, or
automatic database downgrade. Administrators must deploy a verified release,
run its forward migrations once, test it, and retain a complete recovery path.

> [!IMPORTANT]
> Do not use a rolling or zero-downtime deployment unless the release notes
> explicitly declare the old and new releases compatible with the same
> database schema. The current general procedure uses a maintenance window and
> permits only one application release and one scheduler to write at a time.

## Choose the target release

Use a tagged release from the project's
[GitHub Releases](https://github.com/bfhp/stream-engine/releases) page or the
corresponding Composer package version. Do not deploy an arbitrary branch,
unpinned dependency resolution, or a moving container tag to production.

Before scheduling the change:

1. record the currently deployed version, artifact checksum or immutable
   commit, PHP version, MariaDB version, and deployment configuration revision;
2. read the release notes and changelog for every version between the current
   and target releases;
3. follow any required intermediate upgrade, data conversion, or manual step;
4. confirm the target release's PHP extensions, database, Memcached, and web
   server requirements;
5. check custom themes, site-owned files, and integrations against the target
   release;
6. review new environment variables and changed defaults without replacing
   existing secrets; and
7. identify the exact old artifact and dependencies needed for rollback.

Do not infer compatibility only from a matching major or minor version. Stream
Engine is under active development, and a release note can impose a narrower
upgrade path.

## Understand the migration boundary

Existing sites are upgraded with migration files supplied by the target
release. From an application root, these commands inspect and apply them:

```bash
composer migrate:status
composer migrate
```

`composer migrate:status` is read-only. It compares available migration files
with `storage/migrations.json`; it does not prove that the database contents
are healthy. `composer migrate` connects using `.env`, takes a filesystem lock
at `storage/migrations.json.lock`, executes pending SQL files in filename
order, and records each completed file in `storage/migrations.json`.

Do not run the installer, import `resources/install/schema.sql`, or baseline
migrations during an upgrade. Those operations are for a new installation or
a release-building workflow, not for advancing a working site.
`storage/installation.json` records the completed installation workflow; its
embedded release is not the deployed-version marker. Preserve the file and do
not edit it to announce the target release.

> [!WARNING]
> There are no down migrations. Statements inside a migration file are
> executed sequentially, and the file is recorded only after all its
> statements finish. If a statement fails, the database may be partly changed
> even though that migration is not recorded as applied. Stop the deployment
> and restore the pre-upgrade recovery set. Do not edit
> `storage/migrations.json` or rerun the migration blindly.

The migration lock prevents two migration commands sharing the same state file
from running concurrently. It does not make SQL transactional, stop web
requests, coordinate a second release directory, or replace an external
maintenance window.

## Rehearse outside production

Restore a recent production recovery set into an isolated environment as
described in
[Backups and restore](https://github.com/bfhp/stream-engine/wiki/Administrator-Backups-and-Restore).
Use isolated database credentials, upload paths, cache namespace, email sink,
canonical URL, and scheduler configuration.

In that environment:

1. deploy the exact target artifact with production-equivalent PHP and
   MariaDB versions;
2. run `composer check-platform-reqs --no-dev`;
3. run `composer migrate:status` and record the expected pending files;
4. run `composer migrate` once and record its duration and output;
5. run `composer migrate:status` again and require `Pending: 0`;
6. complete the smoke tests in this chapter;
7. exercise important custom themes, routes, uploads, mail, and scheduled work;
8. measure the maintenance time required for backup, migration, and checks;
   and
9. rehearse the rollback with the old artifact and the pre-upgrade recovery
   set.

A test against an empty database is useful but does not replace a rehearsal
against a sanitized copy of the site's real state.

## Prepare the production change

Assign an operator and a rollback decision maker. Define:

- the maintenance start and expected finish;
- the last time at which rollback may be chosen;
- the maximum acceptable data loss and service interruption;
- the exact health checks that permit reopening traffic;
- how users receive maintenance and completion notices; and
- where command output, timestamps, and decisions will be recorded.

Create and verify a new recovery set immediately before the upgrade. It must
contain the database, `.env`, complete `storage/`, any external
`UPLOADS_DIR`, site-owned files, infrastructure configuration, and old release
identity. Keep it separate from routine retention until the rollback window
has closed.

Confirm that enough free space exists for the new release, old release,
backup, database growth during migration, new Twig cache, and logs. Confirm
that backup decryption credentials and database restore privileges are
available before writes are stopped.

## Stage code and dependencies

Build or download the target release away from the live application root. For
example, replace `0.5.1` with the reviewed target version:

```bash
target_version='0.5.1'
composer create-project --no-dev --no-interaction \
  bfhp/stream-engine /srv/stream-engine-next "$target_version"
cd /srv/stream-engine-next
composer check-platform-reqs --no-dev
```

The target directory must be new and must not be served by the web server.
Compare its identity with the selected release. Retain its generated
`composer.lock` and `vendor/`; do not copy `vendor/` from the old release and
do not run `composer update` in production.

Official release packages are expected to contain compiled frontend assets.
Production administrators should not run `npm update`. Run `npm ci` and
`npm run build` only when the release notes explicitly require a source build,
and build from that release's lockfile in a controlled build environment.

Before cutover, prepare how the new release will receive:

- the existing `.env`, preserving `APP_SECRET` and service credentials;
- the complete existing `storage/`, including `installation.json`,
  `migrations.json`, and local uploads;
- an external `UPLOADS_DIR`, when configured;
- custom themes through the configured `THEME_DIR`; and
- any other site-owned file declared by the deployment.

Use the site's tested deployment mechanism for shared directories or a
consistent copy made while writers are stopped. Never replace the existing
migration state with the empty/default state from a fresh release package.
Never overlay a new release on the live tree one file at a time: removed files
can remain behind and requests can observe a mixture of versions.

## Coordinate caches

Memcached data and Twig's compiled templates are disposable. They must not
force the new release to consume incompatible cached values.

Choose one of these tested approaches before deployment:

- invalidate only this site's cache namespace using the cache platform's
  supported tooling; or
- change `CACHE_PREFIX` to a new site-and-release-specific value, then retain
  the old value in the rollback record.

Never flush a Memcached instance shared with other sites. A changed prefix
leaves old keys to expire naturally, so include their temporary memory use in
capacity planning.

For Twig, point `TWIG_CACHE_DIR` at a new, empty, writable directory for the
target release, or clear only the exact configured site cache using the
deployment's tested procedure. Restart or reload PHP-FPM after cutover so old
workers and opcode caches cannot continue serving old code. Do not delete the
general system temporary directory.

## Production upgrade procedure

Use this order for both a traditional host and an orchestrated/container
deployment. Platform-specific service commands belong in the site's runbook.

1. **Open the change record.** Record the current and target releases, operator,
   start time, backup destination, and rollback deadline.
2. **Stop background work.** Disable the external cron entry, stop the systemd
   timer, or scale the scheduler service to zero. Wait for an active task to
   finish; do not delete a lock to force it.
3. **Stop application writes.** Make the reverse proxy or load balancer serve a
   maintenance response, drain active requests, and stop PHP-FPM or remove all
   application instances from service. Closing registration is not enough.
4. **Take the pre-upgrade recovery set.** Follow the consistency procedure in
   the backup chapter and verify its database dump, archives, checksums, and
   release identity before proceeding.
5. **Attach persistent state to the staged release.** Copy or mount `.env`, the
   complete `storage/`, uploads, and site-owned files while no writer is
   active. Merge only reviewed environment additions required by the target
   release; do not replace existing secrets with example values. Preserve
   ownership and restrictive permissions.
6. **Inspect migration state from the target root.** Run
   `composer migrate:status`. The pending list must match the rehearsal. An
   unexpected missing, renamed, changed, or additional file stops the change.
7. **Apply migrations once.** Run `composer migrate` as the deployment/service
   account. Save the complete output. On any error, do not start the site;
   enter the rollback procedure.
8. **Verify migration completion.** Run `composer migrate:status` again and
   require `Pending: 0`.
9. **Switch the application release.** Make the target code the only release
   reachable through the production application path. Do not start the old
   and new releases against the same database.
10. **Activate clean caches and PHP workers.** Apply the prepared cache
    namespace and Twig path, then start or reload PHP-FPM.
11. **Test privately.** Keep public traffic blocked while completing the smoke
    tests below through an operator-only route, direct backend address, or
    temporary access rule.
12. **Open traffic.** Remove the maintenance response only after all required
    checks pass.
13. **Start exactly one scheduler.** Restore the external runner, then confirm
    a recent Scheduler heartbeat and normal task history.
14. **Observe.** Watch application, PHP-FPM, Nginx, MariaDB, scheduler, email,
    capacity, and user reports through the rollback window.

For an immutable container deployment, build one image from the exact release,
run migrations as a one-off job with the production state and database, and
start web containers only after that job succeeds. Do not let every replica
run migrations during startup. The included Docker Compose stack is a
development environment; production orchestration and persistent-volume
handling remain the operator's responsibility.

## Smoke tests

Record each result rather than relying on a single successful home-page load.
At minimum:

- [ ] `composer check-platform-reqs --no-dev` succeeds.
- [ ] `composer migrate:status` reports `Pending: 0`.
- [ ] The public home page and one representative content route return the
      expected page over HTTPS.
- [ ] An administrator can sign in and open the administration dashboard.
- [ ] Content, routes, menus, widgets, and the active theme render correctly.
- [ ] A representative role can access allowed content and is denied protected
      content.
- [ ] An existing local upload can be read; a disposable test upload can be
      created and removed when that workflow is in use.
- [ ] A controlled settings read and write succeeds without changing unrelated
      values.
- [ ] A test notification reaches the configured sink or approved recipient
      when mail behavior changed.
- [ ] The scheduler runs once, updates its heartbeat, and does not duplicate
      work.
- [ ] Application, PHP-FPM, web-server, database, and scheduler logs contain no
      new unexplained errors.
- [ ] Response time, database connections, cache use, disk space, and queue
      depth remain within the site's normal bounds.

Avoid destructive tests and avoid sending real bulk notifications during the
maintenance window.

## Rollback decision

Rollback immediately when a migration fails, required state is missing, the
new release cannot complete a critical workflow, or the cause of a serious
error is not understood within the maintenance window. Extending an outage to
experiment in production is not a recovery plan.

There are two different rollback cases:

### Before any migration or production write

If the database and persistent files have not changed, keep traffic and the
scheduler stopped, switch back to the exact old release, restore its cache
configuration, restart PHP-FPM, run the old release's smoke tests, and reopen
traffic. Still preserve the failed staged release and logs for diagnosis.

### After migrations or any production write

An application-file switch alone is unsafe. The old code may not understand
the new database schema, and a failed migration may have made unrecorded
partial changes. Unless the release notes explicitly provide a tested
backward-compatible path, restore the complete pre-upgrade recovery set.

1. block public requests and stop all PHP-FPM and scheduler instances;
2. preserve the failed database, release, migration output, and logs when this
   can be done without delaying recovery;
3. make the exact old release the only deployable application code;
4. restore the pre-upgrade database;
5. restore the matching `.env`, complete `storage/`, external uploads, and
   site-owned files from the same recovery set;
6. restore the old cache configuration, but start with disposable caches empty
   or isolated so failed-release entries are not reused;
7. verify file ownership, permissions, PHP requirements, and
   `composer migrate:status` from the old application root;
8. start PHP-FPM and perform private smoke tests;
9. reopen traffic, then start exactly one scheduler; and
10. monitor the restored site and record the rollback finish time and data-loss
    boundary.

This restoration discards legitimate writes made after the recovery point.
The maintenance procedure minimizes that loss by preventing public and
background writes before the backup and keeping them blocked until the change
is accepted.

Never point both old and new releases at the database to compare them. Never
manually delete migration records, reverse DDL by guesswork, or restore only
`storage/migrations.json` to make status output look correct. Migration state,
database schema, uploads, configuration, and code must describe the same
recovery point.

## Close the change

Keep the old artifact, pre-upgrade recovery set, command output, and failed
release if applicable until the documented observation and rollback periods
end. Then apply the normal retention policy; do not leave extra `.env` copies
or database dumps readable in application or web-accessible directories.

Record:

- actual start, migration, cutover, scheduler, and finish times;
- exact old and new release identities and checksums;
- backup identifier and verification result;
- migration files applied and their output;
- smoke-test results and observed metrics;
- every manual deviation and its approval; and
- follow-up work for release notes, automation, monitoring, or capacity.

## Upgrade checklist

- [ ] Target release and every intervening release note were reviewed.
- [ ] Runtime, database, custom theme, and integration compatibility passed.
- [ ] Upgrade and rollback succeeded against a recent isolated restore.
- [ ] Exact old and new artifacts are available and identifiable.
- [ ] Maintenance, communication, owners, and rollback deadline are agreed.
- [ ] A complete verified pre-upgrade recovery set exists off the application
      path.
- [ ] Scheduler and public writes are stopped before state is copied or changed.
- [ ] Target migration list matches rehearsal and migrations run exactly once.
- [ ] Migration status is clean, caches are isolated, and PHP workers are new.
- [ ] Private smoke tests pass before traffic and one scheduler are enabled.
- [ ] Monitoring remains active through the rollback window.
- [ ] Old release and recovery material remain available until change closure.

[Back: Backups and restore](https://github.com/bfhp/stream-engine/wiki/Administrator-Backups-and-Restore) · [Administrator Guide](https://github.com/bfhp/stream-engine/wiki/Administrator-Guide)
