# Developer Guide: Database and migrations

Stream Engine uses ordered SQL files for database evolution and a generated
schema snapshot for new installations. Existing sites apply migrations;
fresh sites start from the snapshot and record the migrations that it already
contains. This chapter defines the contract between those two paths.

> [!IMPORTANT]
> Migration history is forward-only. There are no down migrations and the
> runner does not wrap a migration file in a transaction. Back up the database
> and matching migration state before applying a release, and restore both
> when rollback is required.

## The two database paths

| Path | Input | Result |
| --- | --- | --- |
| Existing installation | Pending files from the combined engine and site migration directories | SQL runs in version order and each completed file is recorded in `storage/migrations.json`. |
| Fresh installation | `resources/install/schema.sql` and `resources/install/manifest.json` | The schema is applied once, manifest migrations are baselined without rerunning them, then any current migration not listed in the manifest runs normally. |

The snapshot contains schema, not site data. The installer owns initial data,
including the system user, administrator, settings, membership roles, welcome
content, root page, and initial menu. Never add environment-specific users,
credentials, content, or current `AUTO_INCREMENT` values to the snapshot.

## Migration sources and ordering

The migration CLI combines:

1. the root project's `migrations/` directory; and
2. the Stream Engine package's `migrations/` directory.

They form one global history. Files are sorted by version regardless of their
source directory. If both directories resolve to the same physical directory,
the file is included once. A duplicate version in different files is rejected.

The first directory is also where `make` creates a new migration. This allows
a site project to own site-specific migrations while the installed engine
retains its release migrations.

Module-local directories such as `modules/<Name>/migrations/` are not scanned.
A site extension that needs schema changes must add uniquely versioned files to
the root project's migration history; installing or removing a module never
silently runs SQL from its own directory.

Every filename must match:

```text
YYYYMMDDHHMMSS_lowercase_name.sql
```

For example:

```text
20261011143000_add_article_summary.sql
```

The complete timestamp and name form the migration version. Before committing,
check that it is unique across every migration source. If two branches create
the same timestamp, rename the unapplied migration in one branch. Never rename
or reorder a migration after it has been released or applied.

## Migration state is persistent state

Applied history is stored in `storage/migrations.json`. Each record contains:

- the migration version;
- the filename;
- the SHA-256 checksum; and
- the UTC application time.

`storage/migrations.json.lock` serializes runner processes. The lock file is
runtime coordination; `migrations.json` is durable application state.

The database and `storage/migrations.json` must describe the same point in
history. Back them up, restore them, copy them, and promote them together. If a
database is restored without its matching state file, the runner may consider
old SQL pending. If the state file is restored without the matching database,
the runner may skip schema changes that the database does not contain.

The runner checks the checksum of every still-available applied migration and
refuses to continue when a file changed after execution. Do not edit the state
file or replace a checksum to bypass this protection. Restore a consistent
database/state pair or add a new corrective migration.

## Inspect migration status

The Composer commands are wrappers around `bin/migrate.php`:

```bash
composer migrate:status
composer migrate:check
```

`migrate:status` prints applied and pending counts. `migrate:check` prints a
warning and the pending filenames when work remains. These commands inspect
files and `storage/migrations.json`; they do not connect to the database and do not
prove that the schema was modified outside the migration system.

`migrate:check` currently exits successfully after printing its warning. Treat
its output as a release/deployment signal, not as a failing CI gate.

To apply pending migrations, load `.env`, connect to the configured database,
and run:

```bash
composer migrate
composer migrate:status
```

`migrate` takes an exclusive filesystem lock, verifies existing checksums, and
executes pending files in version order. The state record is written only after
every statement in that file completes.

## Create a migration

Generate a correctly named file:

```bash
composer migrate:make -- add_article_summary
```

The generated file contains a placeholder comment. Replace it with plain
MariaDB SQL that MySQL 8.4 also accepts after the dialect adjustments below. The runner splits statements at semicolons outside single- or
double-quoted strings and removes `--` and `/* ... */` comments.

Keep migration files within the supported SQL-script subset:

- use ordinary MariaDB DDL and DML statements that MySQL 8.4 also supports;
- terminate statements with semicolons;
- use `--` or block comments; `#` comments are not recognized by the parser;
- do not use `DELIMITER` directives; and
- do not introduce triggers, stored routines, or database events. The release
  snapshot builder rejects these objects.

Migrations execute through `PDO::exec()`. They do not run through a database
command-line client, so client directives and interactive commands are not
available.

### MySQL and MariaDB

Migration files and the installation snapshot are written in MariaDB SQL and
are never rewritten on disk, so their checksums stay valid. Before each
statement runs, `SqlDialect` (in `src/Core/Migrations/`) detects the server
from `PDO::ATTR_SERVER_VERSION` and, on MySQL only:

- replaces `utf8mb4_uca1400_ai_ci`, `_as_ci`, and `_as_cs` (including the
  `_nopad` variants) with the matching `utf8mb4_0900_*` collation;
- wraps a function used as a column default, such as
  `DEFAULT unix_timestamp()`, in the parentheses MySQL requires;
- turns `STORED` (or `PERSISTENT`) generated columns into `VIRTUAL` ones,
  because MySQL rejects a cascading foreign key on a base column of a stored
  generated column (error 1215). Indexes and unique keys behave the same, but
  a generated column must therefore not be part of a primary key, a foreign
  key, or a `FULLTEXT` index; and
- drops a `CREATE TABLE` check constraint that uses a column whose foreign key
  has `ON DELETE` or `ON UPDATE` `CASCADE`, `SET NULL`, or `SET DEFAULT`,
  because MySQL rejects it (error 3823). Keep such invariants in the
  application as well.

Everything else must already be valid on both servers. In particular, avoid
MariaDB-only syntax such as `RETURNING`, `CREATE OR REPLACE`,
`ADD COLUMN IF NOT EXISTS`, sequences, `INET6`/`UUID` column types, system
versioning, and long unique keys without a prefix (`USING HASH`). Avoid
MySQL-only syntax too, such as `INSERT ... AS new` row aliases. New tables
should use the canonical `COLLATE=utf8mb4_uca1400_ai_ci` so the translation
applies.

## Design a safe migration

### Preserve released history

Once a migration can have reached another checkout or installation, treat its
filename and contents as immutable. A later change needs a new migration. The
pre-release history was already consolidated into the initial migration;
released history must not be squashed again.

### Treat every migration as forward-only

There is no automatic reverse operation. Write the forward schema/data change,
document its operational effect, and rely on a pre-migration database and state
backup for rollback. Do not put a misleading `down` section into the SQL file.

### Account for partial execution

The runner does not start a transaction around a migration file, and DDL on
both MariaDB and MySQL commonly commits implicitly. If statement three fails after statements one
and two succeeded, the migration remains pending even though the database may
be partially changed.

Before retrying:

1. stop application writers;
2. inspect the exact failed statement and current schema;
3. restore the pre-migration database and matching `migrations.json` whenever
   practical; and
4. correct the migration only if it has not been published or applied outside
   that disposable environment. Otherwise create a new corrective release.

Do not mark a failed migration as applied merely to make the warning disappear.

### Make data changes deterministic

A data migration must define which legacy rows change and what happens to
unexpected values. Prefer set-based, bounded SQL over loading an entire table
through application code. Preserve foreign-key and uniqueness invariants and
decide how collisions are resolved before adding the new constraint.

Test with representative legacy rows, including empty tables, duplicate or
nullable historical values, and the largest realistic data shape. For changes
that may lock or rewrite large tables, measure them against a production-sized
copy and document the required maintenance window.

### Keep runtime and schema changes coordinated

The documented upgrade model uses a maintenance window rather than rolling or
zero-downtime deployment. Even so, the release must contain the application
code, migration files, and generated assets that expect the same final schema.
Do not deploy code that requires a column before its migration has completed.

## Baseline is not migration

The baseline command records all migrations through an exact version without
executing SQL:

```bash
composer migrate:baseline -- 20261011143000_add_article_summary
```

This command does not connect to the database or verify that the schema exists. It
is an exceptional adoption tool for a database that was deliberately prepared
and independently verified. It is not a way to skip a slow or failing
migration. Normal fresh installation baselines the signed snapshot manifest
automatically; developers and operators should not reproduce that process by
hand.

Before a manual baseline, create a database and state backup, compare the
actual schema with the expected result, and record why execution is safe to
skip. A wrong baseline can leave the state green while the schema is unusable.

## Fresh-install snapshot

The release snapshot consists of two generated files:

| File | Purpose |
| --- | --- |
| `resources/install/schema.sql` | Schema-only `CREATE TABLE` and `CREATE VIEW` statements for a clean database. |
| `resources/install/manifest.json` | Snapshot format, release, schema filename and checksum, plus the ordered filename and checksum of every incorporated engine migration. |

`SchemaSnapshot::load()` verifies the manifest structure, schema checksum,
migration ordering, and migration record shapes. Installer preflight also
verifies that the listed migration files and checksums still match the release.
Do not edit either generated file by hand.

The builder:

1. creates a random database named `stream_engine_schema_build_*`;
2. applies the migration chain to that empty database;
3. rejects triggers, stored routines, and events;
4. reads deterministic table and view definitions;
5. removes current `AUTO_INCREMENT` counters and deployment-specific view
   definers;
6. atomically writes the schema and manifest; and
7. drops the disposable database even when generation fails.

The configured application database is never used as the source of a snapshot.
The builder account still needs permission to create and drop only the
restricted schema-build database pattern.

## Build a release snapshot

The development Compose database grants its `user` account access to
`stream_engine_schema_build_%` when the MariaDB volume is first initialized.
For an older volume, apply
`docker/mariadb/init/01-schema-builder.sql` once as the local MariaDB root user.

Build with the version from `package.json`:

```bash
composer install-schema:build
```

During explicit release preparation, a version may be supplied:

```bash
composer install-schema:build -- --release=0.6.0
```

The explicit version is useful for a prerelease build, but it must match the
artifact being prepared. Commit `schema.sql` and `manifest.json` together with
the migration and release changes that produced them.

After generation, review the diff for unexpected object removal, verify the
manifest release and migration list, and run both the migration and fresh
installation tests. A successful build proves that the migration chain can
construct the schema; it does not prove that a data migration handles every
legacy dataset.

## Test migration changes

At minimum, run the migration runner and snapshot unit tests:

```bash
vendor/bin/phpunit tests/Core/Migrations
vendor/bin/phpunit tests/Core/Installation/SchemaSnapshotBuilderTest.php
```

Add an integration test under `tests/Integration/InstallationSchemaTest.php`
for every meaningful schema or data transformation. Seed the old shape, apply
the migration, and assert both the intended result and important invariants.
The broader rules for test doubles and disposable infrastructure are in
[Testing](https://github.com/bfhp/stream-engine/wiki/Developer-Testing).

The destructive integration suite is opt-in because it creates temporary
MariaDB databases. Against the local Compose database, run:

```bash
docker compose exec -T \
  -e 'INSTALL_TEST_DB_DSN=mysql:host=db;port=3306;charset=utf8mb4' \
  -e INSTALL_TEST_DB_USERNAME=root \
  -e INSTALL_TEST_DB_PASSWORD=root \
  php vendor/bin/phpunit tests/Integration/InstallationSchemaTest.php
```

The test account must be allowed to create and drop databases. The suite uses
random `stream_engine_install_test_*` names and removes them in `finally`
blocks. Never point these variables at a server where the account can access
valuable databases.

Before submitting the change, also run the full baseline from
[Getting started](https://github.com/bfhp/stream-engine/wiki/Developer-Getting-Started)
and verify both upgrade paths:

1. apply the new migration to a database at the previous release state; and
2. install an empty database through the regenerated release snapshot.

For query/index annotations, representative execution plans, and access-path
review, follow [Performance](https://github.com/bfhp/stream-engine/wiki/Developer-Performance).
For versioned snapshot generation and full lifecycle verification, follow
[Releases](https://github.com/bfhp/stream-engine/wiki/Developer-Releases).

## Migration pull-request checklist

- The filename matches the required format and is globally unique.
- No released or previously applied migration was edited.
- SQL is valid on MariaDB and, after `SqlDialect` translation, on MySQL 8.4; no client directives.
- CI passed on both the MariaDB and MySQL jobs.
- Partial execution and operational lock duration were considered.
- Data transformations have representative integration fixtures.
- `storage/migrations.json` was treated as part of the database state.
- The generated schema and manifest were updated together when preparing a
  release snapshot.
- Existing-site migration and clean installation both pass.
- Rollback instructions restore matching database, migration state, code, and
  persistent files rather than attempting a down migration.

[Back: Architecture and request flow](https://github.com/bfhp/stream-engine/wiki/Developer-Architecture) · [Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide) · [Next: Modules](https://github.com/bfhp/stream-engine/wiki/Developer-Modules)
