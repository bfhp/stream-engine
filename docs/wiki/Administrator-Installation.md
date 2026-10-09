# Administrator Guide: Installation

This chapter installs a tagged Stream Engine release on a traditional
PHP-FPM/Nginx host. It also documents the included Docker Compose environment
for local evaluation. Complete the [requirements](https://github.com/bfhp/stream-engine/wiki/Administrator-Requirements)
before starting.

> [!WARNING]
> Stream Engine is under active development and is not yet recommended for
> production use. Practise the complete procedure in an isolated environment
> and keep recoverable copies of any state you intend to preserve.

## Installation overview

1. Obtain a release with Composer.
2. Prepare filesystem ownership and persistent storage.
3. Configure the web server with `public/` as its document root.
4. Run either the CLI installer or the protected web installer.
5. Restrict installation-time write access.
6. Sign in, open the administration interface, and verify the installation.
7. Configure the once-per-minute scheduler before accepting production use.

The CLI and web installers use the same installation service and preflight
checks. Both validate all inputs before changing `.env`, installation state, or
the database.

## Obtain a release

From the parent directory of the intended application path, install the current
`0.5` release series without development dependencies:

```bash
cd /srv
composer create-project --no-dev bfhp/stream-engine stream-engine "^0.5"
cd /srv/stream-engine
```

Use a version constraint or exact version that matches your deployment policy.
Do not deploy a moving source branch as though it were a release. Release
packages contain compiled frontend assets, so Node.js and npm are not required
on an administrator's production host.

Confirm that Composer accepted the platform:

```bash
composer check-platform-reqs
```

The application root must contain `vendor/autoload.php`,
`resources/install/schema.sql`, `resources/install/manifest.json`, and the
migration files referenced by that manifest. The installer verifies the
release-file checksums.

## Prepare ownership and writable paths

Choose whether the installation command runs as the deployment account or the
PHP-FPM service account.

For a CLI installation, the deployment account can own the release while the
PHP-FPM account receives write access only to runtime state. Ensure that:

- the installer can create `.env` in `/srv/stream-engine`;
- the installer can create and write `/srv/stream-engine/storage`;
- PHP-FPM can subsequently read `.env`; and
- PHP-FPM and the scheduler can write `storage/` and local uploads.

For a web installation, PHP-FPM temporarily needs permission to create or
replace `.env` in the application root as well as write `storage/`. Remove that
application-root write permission after installation while keeping the runtime
paths writable.

Use your operating system's ownership, group, ACL, or deployment mechanism to
grant the narrowest required access. Account names differ between systems, so
this guide does not prescribe a recursive `chmod` or `chown` command that could
expose secrets or make application code writable through the web server.

## Configure Nginx and PHP-FPM

The application must be served from `public/`. The following HTTP-only server
block illustrates the required routing. Replace the server name, PHP-FPM
socket, and paths. Add production TLS according to your operating system or
hosting provider before exposing the site publicly.

```nginx
server {
    listen 80;
    server_name example.com;

    root /srv/stream-engine/public;
    index index.php;
    client_max_body_size 64m;

    location /uploads/ {
        alias /srv/stream-engine/storage/uploads/;
    }

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /srv/stream-engine/public$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT /srv/stream-engine/public;
    }
}
```

If `UPLOADS_DIR` points elsewhere, update the `/uploads/` alias. Runtime uploads
in the current release use local filesystem storage; setting the available
`OBJECT_STORAGE_*` reference variables does not switch this backend.

Test and reload Nginx using the commands provided by your distribution. For
example:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Confirm that requests reach the intended PHP-FPM pool and that the pool does
not expose `.env` or the application root as web content.

## Choose an installer

Use the CLI installer for repeatable production deployments. Use the web
installer when command-line installation is unavailable or when an interactive
browser workflow is more practical.

### Option A: interactive CLI installation

Run the command from the application root:

```bash
cd /srv/stream-engine
composer cms:install
```

The installer asks for:

- application environment (`prod`, `dev`, or `test`);
- canonical absolute site URL without a trailing slash;
- MariaDB host, port, database, account, and password;
- site name and interface locale;
- administrator email and display name; and
- administrator password.

Use `prod` for a public deployment and an HTTPS canonical URL. The
administrator password must contain 10–255 characters. Spaces, Unicode, and
special characters are allowed; no character class is mandatory.

If a value is invalid, the interactive installer reports the reason and asks
only for that value again. No installation state or database data is changed
until the complete input set passes validation and preflight checks.

### Option B: unattended CLI installation

Keep the administrator password out of shell history and process arguments by
placing it in a root-controlled deployment secret file:

```bash
cd /srv/stream-engine
CMS_ADMIN_PASSWORD_FILE=/run/secrets/stream-engine-admin-password \
composer cms:install -- \
  --no-interaction \
  --app-env=prod \
  --site-url=https://example.com \
  --db-host=127.0.0.1 \
  --db-port=3306 \
  --db-name=stream_engine \
  --db-username=stream_engine \
  --db-password='replace-with-database-password' \
  --site-name='Example site' \
  --locale=en \
  --admin-email=admin@example.com \
  --admin-name=Administrator
```

The example shows the database password as an argument for completeness, but
process arguments and shell history may be visible to other users or retained
by automation logs. Prefer supplying `DB_PASSWORD` through the deployment
environment or an owner-only `.env` prepared by the deployment system, then
omit `--db-password`. `CMS_ADMIN_PASSWORD` is also supported when the platform
cannot mount an administrator password file.

Non-interactive installation exits on the first invalid or missing value. It
never waits for terminal input.

### Option C: protected web installation

An uninstalled site redirects all requests to `/install`. Before opening a
newly deployed public site in a browser, enable explicit installation-token
protection:

```bash
cd /srv/stream-engine
composer cms:install-token
```

The command creates `storage/installation-token` with owner-only permissions
and prints the token once. Open `https://example.com/install`, enter the token,
and complete the form. The form uses CSRF protection and does not echo the
database password, administrator password, or installation token back into the
page.

Without an explicit token, the first browser to open the uninstalled site
receives a random HttpOnly, SameSite installation claim valid for one hour.
Only that browser can submit the installer. This mode is appropriate only when
you control the first access, such as an isolated host or local evaluation. It
must not be relied on for a new site already reachable through an untrusted
network.

If the claiming browser is lost, delete
`storage/installation-claim.json` to allow another browser to claim the
installer. Do not delete `storage/installation.json` from a completed or failed
installation merely to bypass an error.

The token and browser-claim files are removed after successful installation.
The installer becomes unavailable when installation state is `ready`.

## What the installer changes

After all preflight checks pass, the installer:

1. creates or updates `.env` atomically while preserving unrelated existing
   settings;
2. generates `APP_SECRET` when no value already exists;
3. creates the schema in an empty database, records the release snapshot
   baseline, and applies newer migrations;
4. creates the reserved system account and first active administrator;
5. stores the site name and locale;
6. creates the initial profile and administration routes and user menu;
7. publishes an English welcome article as the root page; and
8. writes ready state to `storage/installation.json`.

A file lock prevents CLI and web installations from running concurrently. A
failed stage is recorded without storing credentials or detailed exception
data in the state file. Correct the reported problem and rerun the same
installer; preserve the state file so the installer can assess the existing
installation safely.

## Restrict access after installation

After a successful web installation, remove temporary write access to the
application root. Keep the following access:

- PHP-FPM and maintenance commands can read `.env`;
- PHP-FPM and the scheduler can write `storage/`;
- PHP-FPM can write the configured local upload, temporary, and Twig-cache
  directories; and
- the deployment account can replace application files during a controlled
  update.

Do not commit `.env`, copy it into `public/`, include it in support reports, or
make it world-readable. Treat `APP_SECRET`, database, SMTP, CAPTCHA, and object
storage credentials as secrets.

## Verify the installation

Perform every check before directing public traffic to the site:

1. Open the canonical site URL over HTTPS. Confirm that the welcome page loads
   without a redirect to `/install`.
2. Sign in with the administrator email and password created during
   installation.
3. Open `/admin/`. Confirm that Dashboard, Feeds, Pages, Menus, Settings,
   Registration, Themes, Widgets, File Browser, Scheduler, and Users are
   available.
4. Check migration state from the application root:

   ```bash
   composer migrate:status
   ```

   Investigate any pending or failed migration before proceeding.

5. Confirm that PHP-FPM can write `storage/` without making the complete
   application tree writable.
6. Upload a disposable test image through the administration File Browser,
   open it through its public URL, and remove it.
7. Check Nginx and PHP-FPM logs for errors generated during these requests.
8. Confirm that `.env`, `vendor/`, `src/`, and `storage/installation.json` are
   not downloadable over HTTP.

Production installations select OS scheduler mode by default. The site is not
operationally complete until `bin/cron.php` runs once per minute and its result
has been verified. The Scheduler chapter will consolidate those instructions;
until then, use the repository's existing
[`docs/CRON.md`](https://github.com/bfhp/stream-engine/blob/main/docs/CRON.md)
runbook.

## Local evaluation with Docker Compose

The included environment is not a production template. It is useful for
testing the installation flow on a trusted workstation:

```bash
git clone https://github.com/bfhp/stream-engine.git
cd stream-engine
git checkout v0.5.0
docker compose build php
docker compose run --rm php composer install
docker compose up -d
```

Open `http://localhost:5000`. Use these installer values:

| Setting | Value |
| --- | --- |
| Application environment | `dev` |
| Site URL | `http://localhost:5000` |
| Database host | `db` |
| Database port | `3306` |
| Database name | `app` |
| Database username | `user` |
| Database password | `password` |

The Compose environment also starts Memcached, MinIO, and the scheduler. MinIO
is present for object-storage development, but current runtime uploads remain
local. The environment's credentials, published ports, bind mounts, and
development mode are unsuitable for an untrusted network.

Stop it without removing data:

```bash
docker compose down
```

> [!CAUTION]
> `docker compose down --volumes` permanently removes the Compose database,
> object-storage, and Node.js volumes. Use it only when you intend to destroy
> the local environment.

## Installation problems

Start with the failed preflight item or installer message:

| Symptom | Check |
| --- | --- |
| PHP version or extension failure | Compare the CLI and PHP-FPM runtimes with the requirements chapter. |
| `.env` failure | Confirm that the installer can write the application directory and read any existing `.env`. |
| Storage failure | Confirm that `storage/` exists or can be created and is writable by the installer. |
| Release-file failure | Redeploy an unmodified tagged release; do not edit the schema snapshot, manifest, or baselined migrations. |
| Database connection failure | Verify host resolution, port, firewall, TLS requirements, account host, password, and database grants. |
| Database is not empty | Use a new empty database unless the schema was deliberately prepared by Stream Engine migrations. Do not point the installer at unrelated data. |
| Another installation is running | Confirm that no web or CLI installer is active. Do not remove locks while another process may still be working. |
| Web installer is unavailable | Check the PHP error log and permissions for installation state, claim, and token files under `storage/`. |

Do not include `.env`, passwords, installation tokens, session cookies, or raw
secret-bearing logs in a public issue. Report the Stream Engine release, PHP
and MariaDB versions, deployment model, failed check, and a redacted error.

[Back: Requirements](https://github.com/bfhp/stream-engine/wiki/Administrator-Requirements) · [Next: Initial configuration](https://github.com/bfhp/stream-engine/wiki/Administrator-Configuration)
