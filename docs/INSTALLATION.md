# Installation architecture

New installations and upgrades use different database inputs:

- a new installation applies `resources/install/schema.sql` once, baselines
  the migration files listed in `resources/install/manifest.json`, and then
  applies newer migrations supplied by the site;
- an existing installation continues to apply pending files from `migrations/`.

Before the first `0.1.0` release, the development migration chain was squashed
into `migrations/20260912000000_initial.sql`. There are no released databases
whose update history depends on the removed files. Do not squash migrations
again after a release: all subsequent schema changes must be new migration
files so installed sites can update normally.

The snapshot contains schema only. Initial application data, including the
system user, administrator, settings and stable lookup rows, is owned by the
installer and is not copied from the database used to build a release.

## Installing a site

Interactive installation:

```bash
composer cms:install
```

If validation fails, the interactive wizard explains the problem and asks only
for the invalid field again; values already accepted are preserved. No
installation state or database data is changed until a complete valid set has
been entered. Non-interactive installation exits on the first invalid value so
automated deployments cannot wait for input.

### Web installer

An uninstalled site does not boot the CMS or query application tables. Opening
it redirects to `/install`.

By default, the first browser receives a random HttpOnly, SameSite installation
claim cookie. Only that browser can submit the wizard for the next hour; the
server stores only a hash in `storage/installation-claim.json`. If the browser
session is lost, delete that file to let a new browser claim the installer.
This mode requires no setup beyond opening `/install`, but assumes the owner is
the first visitor to the new deployment.

For an empty site already exposed to an untrusted network, enable the hardened
mode before opening it:

```bash
composer cms:install-token
```

This creates `storage/installation-token` with owner-only permissions and prints
the token once. Its presence switches the wizard to explicit token validation.
The wizard also uses CSRF protection, never echoes passwords or the token back
into HTML, and invokes the same `Core\Installation\Installer` as the CLI. Claim
and token files are deleted after successful installation, and the wizard is
unreachable once the installation state is `ready`.

Non-interactive installation keeps the password out of process arguments and
shell history:

```bash
CMS_ADMIN_PASSWORD_FILE=/run/secrets/cms-admin-password \
composer cms:install -- \
  --no-interaction \
  --app-env=prod \
  --site-url=https://example.com \
  --db-host=127.0.0.1 \
  --db-port=3306 \
  --db-name=stream_engine \
  --db-username=stream_engine \
  --db-password='database-password' \
  --site-name='Example site' \
  --locale=ru \
  --admin-email=admin@example.com \
  --admin-name=Administrator
```

`CMS_ADMIN_PASSWORD` is also supported when the deployment environment cannot
mount a secret file. Administrator passwords must contain 10-255 characters.
Spaces, Unicode and special characters are allowed; there are no mandatory
character classes.

Both installers validate the environment values, establish the database
connection, and then atomically create or update the project `.env`. Before
changing `.env`, installation state, or the database, their shared preflight
verifies PHP 8.3 and required extensions, writable `.env` and `storage`
locations, snapshot and migration checksums, database connectivity, and whether
the current database is empty or can be safely adopted. The web wizard shows
system checks when it opens and a complete report after submission; CLI
failures print the same failed-check summary. Existing unmanaged keys and
secrets are preserved; missing `APP_SECRET` and `CRON_KEY` values are generated.
New `.env` files use owner-only permissions. Database and administrator
passwords may be passed as CLI arguments for fully automated deployment, but
password files or deployment secrets are preferable where the process list or
shell history is visible.

CLI and the future web wizard use the same `Core\Installation\Installer`.
It applies the snapshot to an empty database, writes the snapshot baseline,
applies newer site migrations, creates the reserved system account and active
administrator, stores the site name and locale, and publishes an English
welcome article as the root page. An exact schema already prepared with
`composer migrate` can be adopted when its migration state is complete.

Progress is written atomically to `storage/installation.json`. A non-blocking
file lock prevents CLI and web requests from installing concurrently. A failed
stage is recorded without putting exception details or credentials in the
state file.

## Building a release snapshot

Point the normal `DB_*` configuration at an empty, disposable database, then
run:

```bash
composer install-schema:build -- --release=1.0.0
```

The release defaults to the root `package.json` version. `--release` is kept
as an explicit override for preparing a prerelease artifact.

The command refuses a non-empty database, applies all migrations to it, then
writes `schema.sql` and `manifest.json` atomically under
`resources/install/`. The database is a build input and may be discarded after
the command. Table data and current `AUTO_INCREMENT` counters are not included.
Deployment-specific view definers are removed.

Triggers, stored routines and database events currently make the build fail;
silently omitting them would produce an incomplete installer.

Commit both generated files together. The manifest binds the schema checksum
and every incorporated engine migration checksum. Migrations supplied by the
site are not baselined by the engine snapshot and remain pending regardless of
where their versions fall in the combined chronological order.

## Integration check

The destructive integration test is opt-in and creates only a randomly named
temporary database:

```bash
INSTALL_TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;charset=utf8mb4' \
INSTALL_TEST_DB_USERNAME=root \
INSTALL_TEST_DB_PASSWORD=secret \
vendor/bin/phpunit tests/Integration/InstallationSchemaTest.php
```

The configured account needs permission to create and drop databases. The test
always targets its generated `stream_engine_install_test_*` database and drops
it in a `finally` block.
