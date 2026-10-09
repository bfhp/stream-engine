# Uninstallation

Stream Engine has no in-application uninstall action. Removing a site is an
infrastructure operation that must stop every writer, preserve any required
recovery or legal record, and then remove application state and external
access deliberately.

Uninstallation is not the same as taking a site offline. A reversible
retirement keeps a final recovery set and quarantined deployment for an agreed
period. Permanent removal destroys the database, uploads, secrets, and other
site-owned state after that period and after the responsible owner approves
the loss.

> [!CAUTION]
> Database drops, volume removal, and recursive filesystem deletion are
> irreversible without a usable backup. Resolve and inspect every exact target
> before running a destructive command. Never substitute a filesystem root,
> home directory, broad wildcard, unresolved variable, or shared service.

## Decide the retirement outcome

Before touching production, document:

- who owns the site and who authorizes shutdown and permanent deletion;
- the shutdown time, public communication, and expected HTTP/DNS behavior;
- whether the hostname will show a static retirement page, return `410 Gone`,
  redirect, or be removed from DNS;
- legal, contractual, privacy, audit, and user-data export obligations;
- the final backup retention period and who can approve its later destruction;
- whether another site shares the host, PHP-FPM pool, Nginx, MariaDB,
  Memcached, SMTP account, filesystem path, container network, or credentials;
  and
- the point after which restoration is no longer promised.

Do not describe data as erased while copies remain in backups, database binary
logs, provider snapshots, replicas, CDN caches, email systems, monitoring,
support attachments, or administrator workstations. Record each remaining copy
and its independent expiry or deletion process.

## Inventory every site resource

Build the inventory from the active deployment, `.env`, infrastructure
configuration, and backup records. Do not rely on memory after the application
directory has been removed.

At minimum, identify:

| Resource | What to record |
| --- | --- |
| Application releases | Active root, previous/staged release directories, immutable version, and site-owned overlays. |
| Persistent files | `.env`, complete `storage/`, external `UPLOADS_DIR`, `THEME_DIR`, and any site-specific temporary or Twig-cache path. |
| Database | Server/provider, database name, dedicated application account including its MariaDB host part, backup accounts, replicas, snapshots, and binary-log retention. |
| Web entry point | DNS records, CDN/WAF/load balancer, TLS certificate and private key, Nginx virtual host, document root, and any dedicated PHP-FPM pool. |
| Scheduled work | User crontab or `/etc/cron.d/` entry, systemd service/timer, container scheduler, backup jobs, and external monitors. |
| Shared services | Memcached endpoint and `CACHE_PREFIX`, SMTP provider, CAPTCHA provider, logging, alerting, and secret manager references. |
| Containers | Compose project name, bind mounts, named volumes, networks, locally built images, and external managed services. |
| Object storage | Normally inactive for current uploads; nevertheless inventory any custom module bucket, access key, CDN, lifecycle, and backup. |
| Recovery material | Final recovery-set identifier, encryption keys, checksums, off-host copies, retention schedule, and restore owner. |

The current standard upload backend is local. `OBJECT_STORAGE_*` and the
included MinIO service do not contain standard Stream Engine uploads unless
site-specific code used them separately. Do not assume either that a bucket is
safe to delete or that it replaces the local uploads inventory.

## Create the final recovery set

If any recovery, audit, or user-export requirement exists, create one final
consistent set before deletion:

1. announce the final write cutoff;
2. stop the external scheduler and wait for active tasks to finish;
3. block new application requests and drain active PHP work;
4. dump MariaDB and archive `.env`, complete `storage/`, external uploads,
   site-owned files, and infrastructure configuration;
5. record the exact release and resource inventory;
6. checksum, encrypt, and transfer the set off the application host;
7. perform the required integrity or isolated restore check; and
8. record who owns the decryption key and when the set must be destroyed.

Use the complete
[Backups and restore](https://github.com/bfhp/stream-engine/wiki/Administrator-Backups-and-Restore)
procedure. A last-minute database dump without matching uploads, migration
state, and configuration is not a complete recovery set.

Backups commonly contain personal data and every application secret. Removing
the public site does not remove those obligations. Disable normal backup jobs
only after the final set is verified, and remove incomplete staging files that
are not part of retained recovery material.

## Stop traffic and background work

Use this order to prevent new data and notifications after the final cutoff:

1. place the reverse proxy/load balancer in maintenance or retirement mode;
2. remove all application instances from public traffic and drain requests;
3. disable the external scheduler and confirm no task process is running;
4. stop PHP-FPM workers or the site's dedicated pool/containers;
5. confirm no old, staged, recovery, or autoscaled instance can still reach the
   production database or send mail; and
6. take the final recovery set if it was not already completed with writers
   stopped.

Changing **Administration → Scheduler** to **Off** is not enough: the external
cron entry, timer, or container still exists and can become active again after
a restore or configuration change. Remove the infrastructure trigger.

Do not start shutdown by dropping the database. A still-running web or
scheduler process will fail unpredictably and can flood logs, retries, alerts,
or external providers.

## Remove a traditional-host scheduler

For a user crontab, edit the exact service account's crontab and remove only
the Stream Engine line:

```bash
sudo crontab -u www-data -e
```

Do not use `crontab -r`; it removes every job belonging to that account. If the
deployment used `/etc/cron.d/`, identify the exact site-owned file, preserve it
with the retirement record when required, and remove only that file.

For the systemd example used by this guide:

```bash
sudo systemctl disable --now stream-engine-cron.timer
sudo systemctl stop stream-engine-cron.service
sudo systemctl status stream-engine-cron.timer stream-engine-cron.service
```

After confirming both units are inactive, remove or archive only these exact
site-owned definitions:

```text
/etc/systemd/system/stream-engine-cron.timer
/etc/systemd/system/stream-engine-cron.service
```

Then run `sudo systemctl daemon-reload` and verify that the timer no longer
appears in `systemctl list-timers`. Unit names can differ in a real deployment;
do not remove a similarly named shared service.

Also disable any separate backup timer, certificate hook, queue consumer, or
monitor that invokes the application.

## Remove the public web entry point

First replace the application upstream with the approved static retirement
response or remove it from the load balancer. Then:

- disable only this site's Nginx virtual host and test the complete Nginx
  configuration before reloading;
- remove a dedicated PHP-FPM pool only after its workers are stopped;
- retain shared Nginx, PHP-FPM, MariaDB, and Memcached services for other sites;
- remove CDN/WAF routes, origin access, health checks, and cache content for
  this hostname; and
- ensure the hostname cannot fall through to another site's default virtual
  host and expose unrelated content.

On distributions using `sites-enabled`, moving or unlinking the exact
Stream Engine site entry is normally safer than editing shared configuration.
Run `sudo nginx -t` before reloading Nginx. Preserve a copy of the retired
virtual-host and pool configuration with the recovery inventory when it is
required for restoration.

Decide DNS and TLS only after the intended retirement response is working. A
long DNS TTL can keep traffic reaching the old endpoint after records change.
If the name is removed, monitor for unexpected residual traffic. If the name
is retained, keep its TLS certificate valid for as long as clients are expected
to receive an HTTPS retirement response.

## Remove the MariaDB database and accounts

Do this only after web workers and schedulers are stopped, the final database
dump is verified, and the database name and account ownership are confirmed.
MariaDB account identity includes both user and host; for example,
`'stream_engine'@'127.0.0.1'` and `'stream_engine'@'localhost'` are different
accounts.

As a MariaDB administrator, inspect the exact targets first:

```sql
SELECT SCHEMA_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME = 'stream_engine';

SELECT User, Host
FROM mysql.user
WHERE User = 'stream_engine';

SHOW GRANTS FOR 'stream_engine'@'127.0.0.1';
```

Confirm that the result names only this site. Then, after explicit approval,
the destructive operations for the example installation are:

```sql
DROP DATABASE `stream_engine`;
DROP USER 'stream_engine'@'127.0.0.1';
```

> [!CAUTION]
> `DROP DATABASE` permanently removes every table and row in that database.
> `DROP USER` removes the exact MariaDB account. Replace both examples with
> verified identifiers; do not delete an account used by another database,
> backup job, migration process, or site.

Remove dedicated backup/migration accounts and network grants separately.
For a managed database, use the provider's deletion and snapshot-retention
workflow and verify replicas, automated backups, point-in-time recovery, and
deletion-protection settings. Dropping the logical database does not
necessarily erase provider snapshots or MariaDB binary logs immediately.

## Retire and delete application files

A quarantine period makes path mistakes and missed dependencies recoverable.
On a traditional host, first create a protected retirement directory and move
the exact stopped application tree into it:

```bash
sudo install -d -m 0700 /srv/retired
sudo mv -- /srv/stream-engine /srv/retired/stream-engine-2026-10-09
sudo realpath /srv/retired/stream-engine-2026-10-09
sudo find /srv/retired/stream-engine-2026-10-09 -mindepth 1 -maxdepth 1 -print
```

The date is an example. Verify that `realpath` reports the intended retired
site and inspect its top level. Keep it inaccessible to Nginx and PHP-FPM.
Record its deletion date and prevent routine deployment automation from
recreating the live path.

Inventory external paths before removing the application tree:

- an absolute `UPLOADS_DIR` is not removed with `/srv/stream-engine`;
- an external `THEME_DIR` and its public assets may be elsewhere;
- site-specific `TWIG_CACHE_DIR` and temporary directories may be outside the
  release; and
- previous or staged release directories may contain additional `.env` copies
  and persistent-state copies.

Never recursively remove a general `/tmp`, cache root, shared uploads parent,
home directory, or `/srv`. Resolve symlinks and mount points so deletion does
not cross into a shared or remote filesystem unintentionally.

After the approved quarantine and recovery periods end, and after checking the
path again, permanent deletion of the example quarantine directory would be:

```bash
sudo rm -rf -- /srv/retired/stream-engine-2026-10-09
```

> [!CAUTION]
> Run that command only for the exact verified quarantine directory. It has no
> undo. Delete each verified external uploads, theme, cache, or temporary path
> separately rather than adding wildcards.

Normal file deletion is not guaranteed secure erasure on SSDs, copy-on-write
filesystems, snapshots, replicas, or managed storage. Use the storage
provider's cryptographic-erasure and media-destruction controls when the
retirement policy requires them.

Do not delete retained recovery sets with application files. Their later
destruction is a separate, logged retention event including every replica and
decryption key where policy requires key destruction.

## Remove the included Compose environment

The repository Compose stack is a development environment. Its application
tree is a host bind mount, while MariaDB, MinIO, and Node.js dependencies use
named volumes. Work from the exact checkout and the same Compose project name
used to start it. Complete and verify the final database and file backup using
the Compose backup procedure before stopping the database. Then stop containers
without deleting data and inspect the project:

```bash
docker compose stop
docker compose ps --all
docker compose config --volumes
```

Inspect project-labelled resources so a similarly named volume from another
project is not mistaken for this one:

```bash
docker volume ls --filter label=com.docker.compose.project=engine
docker network ls --filter label=com.docker.compose.project=engine
```

`engine` is the example project name derived from the checkout directory;
replace it with the recorded project identity.

After confirming the project and approving destruction, remove its containers,
network, and declared named volumes:

```bash
docker compose down --volumes --remove-orphans
```

> [!CAUTION]
> `--volumes` destroys the project's `db_data`, `minio_data`, and
> `node_modules` volumes. The database cannot be recovered from `db_data`
> without a separate backup. Any intentional custom MinIO data is also lost.

This command does **not** delete the bind-mounted repository checkout, external
upload paths, backups, separately created volumes, external databases,
credentials, or images. Delete those only after identifying them independently.
Do not use a global Docker prune as an uninstallation shortcut; it can remove
unrelated stopped resources and caches.

If Compose was started with `COMPOSE_PROJECT_NAME`, `-p`, or from a renamed
directory, use the same project identity for inspection and removal. An empty
`docker compose ps` from the wrong project is not proof that the original site
has stopped.

## Revoke secrets and external integrations

Deleting `.env` or the database removes the application's copy of a credential;
it does not revoke that credential at its issuer. Review and revoke or delete:

- MariaDB application, backup, and migration accounts;
- SMTP/API credentials, sender-domain authorization, webhooks, suppression or
  routing rules dedicated to the site;
- Cloudflare Turnstile or hCaptcha site/secret keys stored in the database;
- object-storage access keys, custom buckets, bucket policies, CDN routes, and
  lifecycle rules when site-specific code used them;
- DNS-provider, CDN/WAF, deployment, monitoring, backup, and secret-manager
  credentials dedicated to the site;
- TLS private keys, certificate renewal jobs, and ACME DNS/API tokens; and
- SSH deploy keys, CI/CD variables, GitHub environment secrets, registry
  credentials, and provider service accounts created for this deployment.

Revoke shared credentials only after every remaining consumer has been moved
to a replacement. Prefer eliminating a site-specific credential over leaving
it active but undocumented.

The current standard runtime does not write uploads to object storage. If a
custom integration exists, enumerate objects and references before deleting a
bucket: bucket removal is not part of Stream Engine uninstallation and can
destroy unrelated data when prefixes or credentials are shared.

## Clean up cache, logs, monitoring, and backups

Memcached data is disposable. If the instance is shared, do not flush it.
Keys under the retired `CACHE_PREFIX` can expire or be evicted naturally; the
site's database and secrets must not be kept merely to manage cache entries.
Delete a dedicated Memcached instance only after confirming it serves no other
application.

Apply the documented retention or deletion policy to:

- Nginx, PHP-FPM, scheduler, database, SMTP, CDN, WAF, and audit logs;
- metrics, traces, dashboards, uptime checks, paging rules, and status-page
  components;
- database snapshots, replicas, binary logs, and point-in-time recovery;
- backup staging areas, off-host archives, immutable copies, and decryption
  keys; and
- incident reports or support artifacts containing site data.

Do not immediately disable every alert. Keep a temporary check for unexpected
DNS traffic, scheduler execution, database connections, email, or resource
recreation. Then remove or archive monitors deliberately so the retired site
does not generate permanent false alarms or hide under an ignored alert.

## Verify completion

Verification must use external observations as well as the retired host:

- the hostname has the approved retirement behavior and does not expose a
  default virtual host, old upload, asset, or administration page;
- no load balancer, PHP-FPM worker, container, scheduler, backup job, or timer
  can execute the application;
- MariaDB shows no unintended site database, account, active connection,
  replica, or retained snapshot outside policy;
- application, external upload, theme, cache, temporary, container, and volume
  targets are either quarantined with dates or verified deleted;
- dedicated credentials and provider integrations are revoked;
- shared services and credentials used by other sites still work;
- DNS, TLS, CDN/WAF, monitoring, billing, and ownership records match the
  retirement decision; and
- every retained backup has an owner, protected key, expiry date, and tested
  destruction process.

Record commands, provider actions, exact resource identifiers, approvals,
backup identifiers, verification results, and remaining retention deadlines.
The uninstallation is complete only when there is no undocumented live
resource or credential and every intentional retained copy has an owner.

## Uninstallation checklist

- [ ] Shutdown, deletion, user communication, legal, and retention decisions
      are approved.
- [ ] Application, database, filesystem, scheduler, network, provider, and
      backup resources are inventoried.
- [ ] A final consistent recovery set is verified, or irreversible deletion
      without one is explicitly approved.
- [ ] Public writes and all scheduler/task processes are stopped.
- [ ] The exact Nginx/load-balancer and dedicated PHP-FPM configuration is
      disabled without affecting other sites.
- [ ] The verified MariaDB database and dedicated accounts are removed or have
      a documented retention deadline.
- [ ] Application, uploads, themes, caches, temporary files, containers, and
      volumes are quarantined or deleted by exact target.
- [ ] SMTP, CAPTCHA, object-storage, TLS, DNS, deployment, and provider secrets
      are revoked where dedicated.
- [ ] Logs, monitoring, backups, replicas, snapshots, and encryption keys have
      explicit retention or destruction records.
- [ ] External checks find no unintended site response, task execution,
      database connection, notification, or resource recreation.
- [ ] Shared infrastructure was rechecked after site-specific resources were
      removed.
- [ ] The final record names every remaining copy, owner, and deletion date.

[Back: Troubleshooting](https://github.com/bfhp/stream-engine/wiki/Administrator-Troubleshooting) · [Administrator Guide](https://github.com/bfhp/stream-engine/wiki/Administrator-Guide)
