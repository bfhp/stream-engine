# Backups and Restore

Stream Engine does not create, schedule, retain, encrypt, or upload backups.
The site operator must integrate database and filesystem protection with the
operating system, hosting provider, or backup platform. This keeps backup
credentials and retention policy outside the web application and allows the
operator to use infrastructure appropriate to the site's recovery objectives.

A backup is not considered successful until it has passed integrity checks and
an isolated restore test. A copied archive that has never been restored is only
an untested recovery assumption.

> [!IMPORTANT]
> Protect the database and files as one recovery set. Restoring a database from
> one point in time with uploads or migration state from another can leave
> broken file references, missing assets, or an application release that no
> longer matches its schema state.

## Define recovery objectives

Before choosing a schedule, document:

- **Recovery point objective (RPO):** the maximum acceptable amount of recent
  data loss;
- **Recovery time objective (RTO):** the maximum acceptable time to restore
  service;
- which incidents are covered, including operator error, disk loss, database
  corruption, compromised credentials, and loss of the complete host;
- who can start a restore and who approves a production cutover; and
- how operators obtain backup decryption keys and replacement infrastructure
  when the normal site is unavailable.

A daily backup cannot satisfy an RPO shorter than one day. An off-host archive
that takes many hours to retrieve may not satisfy a short RTO. Measure real
backup and restore duration instead of estimating it from archive size.

## Required backup set

Keep these items in every recoverable set:

| State | Why it is required |
| --- | --- |
| MariaDB application database | Contains settings, users, content, routes, menus, notifications, scheduler state, and other application records. |
| `.env` | Contains the canonical URL, `APP_SECRET`, database and cache connection details, SMTP configuration, path overrides, and secrets. |
| Complete `storage/` directory | Contains installation and migration state, local uploads by default, cron logs, and other persistent runtime files. |
| External `UPLOADS_DIR` | Required separately when uploads are configured outside `storage/`. |
| Site-owned files | Custom themes and assets, deployment overlays, or modules not reproducible from the tagged Stream Engine release. |
| Release identity | Exact Stream Engine version or immutable commit, release artifact checksum, deployment configuration revision, and PHP/MariaDB versions. |
| Infrastructure configuration | Nginx virtual host, PHP-FPM pool settings, scheduler definition, filesystem ownership/ACL policy, and secret references needed to rebuild the service. |

Preserve `.env` and its original `APP_SECRET`. Replacing that secret during an
ordinary restore can invalidate signed state and is a credential rotation, not
a neutral recovery step.

Application code, Composer dependencies, and compiled frontend assets may be
redeployed from a verified immutable release instead of copied in every backup.
If the exact artifact cannot be guaranteed to remain available, store the
artifact and its checksum with the recovery set. Site-owned changes must exist
in version control or the backup; editing a released package in place is not a
recoverable deployment process.

### State that does not need backup

- Memcached is a disposable cache and must be rebuilt, not restored.
- `TMP_DIR` and Twig cache contents are disposable.
- `storage/cron.lock` has no useful lock ownership after a restore, although it
  may harmlessly be present inside a complete `storage/` archive.
- `vendor/` and generated public assets are reproducible from the exact release
  artifact.
- The included Compose `node_modules` volume is reproducible.

The current runtime upload backend is local filesystem storage.
`OBJECT_STORAGE_*` variables and the included development MinIO service do not
move application uploads into object storage. Do not replace the local upload
backup with a MinIO or S3 bucket copy unless a future release explicitly adds
and enables that backend. The exact present boundary is documented under
[Current object-storage integration](https://github.com/bfhp/stream-engine/wiki/Administrator-Configuration#current-object-storage-integration).
When such an integration exists, its bucket data, versions, metadata, and
consistency point must join the same recovery set.

## Consistency and write control

The safest general backup uses a brief period with no application writes:

1. announce or schedule the maintenance window;
2. record the release identity and current paths;
3. stop the external scheduler;
4. stop or drain PHP-FPM, or use the reverse proxy to block all application
   requests and wait for active requests to finish;
5. create the MariaDB dump and filesystem archives;
6. run local integrity checks;
7. restart PHP-FPM and exactly one scheduler; and
8. verify the public site and Scheduler heartbeat.

Stopping Nginx is not required when the reverse proxy can serve a static
maintenance response without forwarding requests to PHP. Stream Engine has no
built-in maintenance mode. Closing registration is not sufficient because
signed-in users, administrators, and background tasks can still write data.

`mariadb-dump --single-transaction` creates a consistent transaction view of
InnoDB tables without holding table locks for the complete dump. It cannot make
a separately copied filesystem atomic with that database view, and schema
changes during the dump are unsafe. Pausing writers remains the documented
portable procedure.

Storage or database-provider snapshots can reduce downtime, but only when the
provider documents application-consistent coordination across every relevant
volume and database. A crash-consistent snapshot of unrelated volumes is not
automatically a Stream Engine-consistent backup.

## Traditional Linux backup example

The following example uses a fixed run directory so every command has an
explicit target. Replace the timestamp, paths, database name, and credential
file. Never place the backup directory under `public/`, the uploads directory,
or another web-accessible path.

Create a root-controlled staging directory:

```bash
sudo install -d -m 0700 /var/backups/stream-engine/2026-10-09T120000Z
```

Configure a protected MariaDB option file such as
`/root/.config/stream-engine-backup.cnf` with the backup account's host, port,
user, and password. Set its mode to `0600`. The account needs only the database
permissions required by the tested dump method; do not put its password in the
command line, shell history, archive name, or logs.

After stopping the scheduler and application writers, dump the application
database:

```bash
mariadb-dump \
  --defaults-extra-file=/root/.config/stream-engine-backup.cnf \
  --single-transaction \
  --quick \
  --hex-blob \
  --routines \
  --events \
  --triggers \
  stream_engine \
  > /var/backups/stream-engine/2026-10-09T120000Z/database.sql
```

The `--defaults-extra-file` option must be the first client option. Test the
backup account in advance: provider-managed MariaDB services may restrict
routine, event, or metadata privileges even when the current schema contains no
such objects.

Archive `.env` and the complete default runtime state:

```bash
sudo tar \
  --directory=/srv/stream-engine \
  --create \
  --gzip \
  --file=/var/backups/stream-engine/2026-10-09T120000Z/runtime-state.tar.gz \
  .env storage
```

If `UPLOADS_DIR=/srv/stream-engine-data/uploads`, archive it separately because
it is outside `storage/`:

```bash
sudo tar \
  --directory=/srv/stream-engine-data \
  --create \
  --gzip \
  --file=/var/backups/stream-engine/2026-10-09T120000Z/uploads.tar.gz \
  uploads
```

Archive site-owned files separately or, preferably, record the immutable
version-control revision and verify that its remote copy is available. Include
both custom theme templates and their public assets; a `THEME_DIR` override
alone may not contain every asset used by that theme.

Record non-secret recovery metadata in a plain text inventory next to the
archives: release tag or commit, artifact checksum, MariaDB and PHP versions,
application root, actual uploads path, database name, archive creation time,
and whether writers were paused. Do not copy `.env` values into that inventory.

Create checksums after every output file has closed:

```bash
cd /var/backups/stream-engine/2026-10-09T120000Z
sha256sum database.sql runtime-state.tar.gz > SHA256SUMS
```

Include `uploads.tar.gz` and any site-files archive in the checksum command when
they exist. A checksum detects later corruption or incomplete transfer; it does
not authenticate an archive unless the checksum itself is protected by a
trusted signature or backup system.

Before resuming traffic, confirm the dump is non-empty, list each archive with
`tar --list --gzip`, and verify `sha256sum --check SHA256SUMS`. Then transfer or
snapshot the completed set into the protected backup system. A directory left
only on the application host is not protection against host loss.

## Schedule through the operating system

Turn the tested backup procedure into a root-owned script or a job managed by
the backup platform. The job must:

- fail on the first unsuccessful command;
- use a unique staging directory with restrictive permissions;
- publish a backup set only after dumps, archives, checksums, encryption, and
  off-host transfer succeed;
- clean incomplete staging data without following untrusted paths;
- enforce retention only after a newer verified copy exists; and
- send success and failure status to external monitoring.

Do not put a long chain of dump, archive, upload, and deletion commands directly
in crontab. A reviewed script provides error handling, logging, locking, and a
single command that can be tested manually.

A systemd service can invoke that operator-owned script:

```ini
[Unit]
Description=Back up Stream Engine

[Service]
Type=oneshot
User=root
ExecStart=/usr/local/sbin/backup-stream-engine
```

Pair it with a timer appropriate to the RPO, for example a daily run with a
small randomized start delay:

```ini
[Unit]
Description=Daily Stream Engine backup

[Timer]
OnCalendar=*-*-* 02:17:00
RandomizedDelaySec=10m
Persistent=true

[Install]
WantedBy=timers.target
```

After installing the units, run the service manually, inspect its journal,
verify the off-host result, and perform a restore test before relying on the
timer. The backup script is infrastructure owned by the operator; it is not a
Stream Engine command.

## Included Docker Compose example

The repository's Compose stack is for local development and evaluation, not a
production backup design. Its application root is a host bind mount and its
MariaDB data is in the project-scoped `db_data` volume. Use a logical database
dump rather than copying that volume while MariaDB is running.

For a consistent local copy, stop application writers but leave MariaDB
running:

```bash
docker compose stop scheduler php nginx
docker compose exec -T db \
  mariadb-dump -uuser -ppassword --single-transaction --quick --hex-blob app \
  > /private/backup/path/database.sql
tar --directory=. --create --gzip \
  --file=/private/backup/path/runtime-state.tar.gz .env storage
docker compose start php scheduler nginx
```

`user` and `password` above are the public development credentials from the
included Compose file. Never copy this credential pattern into production.
Choose an explicit private backup path outside the repository and uploads
tree. If the development setup uses an external `UPLOADS_DIR` or contains
site-owned files, archive those separately.

The `minio_data` volume is not part of current Stream Engine runtime uploads,
and `node_modules` is reproducible. Back them up only if they contain separate
development data that you intentionally need.

To test a Compose restore, use a fresh checkout or directory with a different
Compose project name so it receives an empty `db_data` volume. Restore `.env`
and `storage`, start MariaDB, import `database.sql` into the empty `app`
database, and only then start the web and scheduler services. Keep the restored
stack isolated from production SMTP and public traffic.

## Retention, encryption, and off-host copies

Apply a documented retention schedule with more than one recovery point. Keep
recent copies for operator error, older copies for delayed discovery, and any
legally required archives. Retention must also define when deleted user data
finally disappears from backups.

Use the 3-2-1 principle as a minimum design goal: at least three copies, on two
different storage systems, with one off-site. For destructive attacks, add an
immutable or offline copy whose retention cannot be shortened by credentials
available on the application host.

Backups contain passwords, email addresses, private messages, content, tokens,
and configuration secrets. Encrypt them in transit and at rest. Keep encryption
keys separate from the backup data and application host, provide controlled
access to at least two responsible operators, and verify that older retained
sets remain decryptable after key rotation.

Restrict listing, download, restore, and deletion permissions independently
where the backup system supports it. Log access to backup data. Do not use a
public object-storage bucket, email attachment, source repository, or normal
uploads directory as backup storage.

## Restore to an isolated environment

Practise restoration without overwriting the production site. Use a network,
database, cache namespace, hostname, and storage paths that cannot collide with
production. Block outbound email and other external side effects until the
restored data has been reviewed.

1. Select one complete recovery set and verify its protected checksum or
   signature before extraction.
2. Provision the recorded PHP and compatible MariaDB versions plus required
   extensions and services.
3. Deploy the exact recorded Stream Engine release. Do not run the installer.
4. Create an empty database with `utf8mb4` and
   `utf8mb4_uca1400_ai_ci`, and create a dedicated application account.
5. Restore `.env`, `storage/`, any external uploads, and site-owned files to
   their recorded paths.
6. Adjust only environment-specific endpoints needed for isolation, such as
   the test hostname, database endpoint, cache prefix, and safe SMTP sink.
   Preserve the original file securely for comparison.
7. Import the database dump into the empty database.
8. restore the documented ownership and least-privilege filesystem access;
9. start MariaDB and Memcached, then PHP-FPM and the private web endpoint, but
   leave the external scheduler stopped; and
10. complete the validation checklist below before any production cutover.

For the Linux example, inspect archives before extracting them. From the
application parent directory, restoration resembles:

```bash
sudo tar --directory=/srv/stream-engine \
  --extract --gzip \
  --file=/secure/restore-set/runtime-state.tar.gz

mariadb \
  --defaults-extra-file=/root/.config/stream-engine-restore.cnf \
  stream_engine \
  < /secure/restore-set/database.sql
```

Extract only into an empty, explicitly prepared restore target. Confirm archive
paths first; do not unpack an untrusted archive as root. When uploads were
archived separately, restore them under the configured `UPLOADS_DIR` and apply
the service account's expected ownership and permissions.

Memcached is not restored. Use an empty dedicated instance or a new
`CACHE_PREFIX` so stale production entries cannot contaminate the test. Use an
empty Twig cache directory or clear the exact configured cache path through the
deployment mechanism; never target a broad temporary or filesystem root.

## Validate the restored site

Keep the restored environment private and external delivery disabled while
checking:

- [ ] Archive checksums or signatures pass.
- [ ] The release identity matches the backup inventory.
- [ ] `.env`, `storage/installation.json`, and `storage/migrations.json` exist
      with protected permissions.
- [ ] `composer migrate:status` reports `Pending: 0` for the restored release.
- [ ] MariaDB contains the expected tables and representative row counts.
- [ ] Administrator login works with a recovery account.
- [ ] Site settings, pages, routes, menus, themes, widgets, and user roles are
      present.
- [ ] Representative public, protected, and dynamic pages render correctly.
- [ ] Sample image, audio, PDF, branding, and site-icon URLs resolve from the
      restored uploads directory.
- [ ] The File Browser lists expected files without changing them.
- [ ] Nginx and PHP-FPM logs contain no missing-file, permission, or template
      errors.
- [ ] The Scheduler page loads and its task history matches the recovered point.
- [ ] A controlled scheduler and email test succeeds only against isolated
      dependencies.
- [ ] The measured restore time and recovered timestamp satisfy the RTO and
      RPO.

Do not run a normal scheduler tick against a production SMTP account during a
restore test. Pending deliveries in the recovered database may be sent again.
Use a safe mail sink, keep automatic scheduling stopped, and choose controlled
tests deliberately.

Record the backup identifier, restore start and finish times, operator,
commands or automation version, defects found, and final result. Fix the backup
procedure when the restore required undocumented knowledge.

## Production recovery and cutover

Use the tested isolated procedure, then add these production safeguards:

1. confirm the incident scope and approved recovery point;
2. preserve the failed environment for investigation when safe to do so;
3. prevent all writes to both the failed and replacement environments;
4. restore and validate privately;
5. confirm no old PHP-FPM or scheduler instance can reach the restored database
   or send notifications;
6. switch traffic through the load balancer, reverse proxy, or DNS plan;
7. verify reads and controlled writes;
8. enable exactly one once-per-minute scheduler;
9. watch notification delivery, error logs, capacity, and user reports; and
10. retain the previous environment until the rollback decision window closes.

Never allow two application instances with independent schedulers to operate
the same restored state unintentionally. Database task locks reduce overlap but
do not make split-brain operation a supported recovery strategy.

After recovery, rotate credentials only when compromise is suspected or the
recovery environment exposed them. Rotate in a planned order and verify the
application after each change; do not discard the only working secrets before
access to the restored site is confirmed.

## Backup checklist

- [ ] RPO, RTO, owner, schedule, and retention are documented.
- [ ] MariaDB, `.env`, complete `storage/`, external uploads, site-owned files,
      and release identity are in one recovery set.
- [ ] Writers and the scheduler are coordinated for a consistent point.
- [ ] Backup credentials are not exposed in arguments or logs.
- [ ] Completed sets are checksummed, encrypted, and transferred off-host.
- [ ] At least one immutable or offline copy protects against destructive loss.
- [ ] Success and failure are externally monitored.
- [ ] Retention deletion is tested and respects privacy obligations.
- [ ] An isolated restore test has passed within the required interval.
- [ ] Recovery documentation is available when the application host is not.

[Back: Routine operations](https://github.com/bfhp/stream-engine/wiki/Administrator-Routine-Operations) · [Administrator Guide](https://github.com/bfhp/stream-engine/wiki/Administrator-Guide) · [Next: Upgrades and rollback](https://github.com/bfhp/stream-engine/wiki/Administrator-Upgrades-and-Rollback)
