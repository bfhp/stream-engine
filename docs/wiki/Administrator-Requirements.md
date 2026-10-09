# Administrator Guide: Requirements

Prepare and verify the application host before installing Stream Engine. This
chapter distinguishes the production application requirements from the
development-oriented Docker Compose environment included in the repository.

## Supported application stack

| Component | Requirement |
| --- | --- |
| Stream Engine | Use a tagged release. This guide currently describes the `0.5` release series. |
| PHP | PHP 8.3 or newer, using PHP-FPM or another supported web-server integration. The command-line PHP used for installation, migrations, and scheduled tasks must load the same required extensions. |
| PHP extensions | `curl`, `dom`, `fileinfo`, `gd`, `intl`, `libxml`, `mbstring`, `memcached`, `pdo`, `pdo_mysql`, `simplexml`, `xmlreader`, and `zip`. |
| Dependency manager | Composer 2. A release archive may already contain `vendor/`, but Composer is required by the installation and migration commands documented here. |
| Database | MariaDB with InnoDB and `utf8mb4_uca1400_ai_ci` collation support. The included development environment currently uses MariaDB 12. Do not substitute MySQL without a separately tested compatibility statement. |
| Cache | A reachable Memcached service. Use a unique cache prefix when several sites share one service. |
| Web server | Nginx is the documented production example. The public document root must be the application's `public/` directory, never the application root. Equivalent servers must provide front-controller routing to `public/index.php`. |
| Scheduler | A mechanism that runs `php bin/cron.php` once per minute in production, such as cron, a systemd timer, or a container scheduler. |
| Storage | Persistent storage for `.env`, `storage/`, and local uploads. External S3-compatible storage is optional. |
| TLS | HTTPS for every public production site. TLS normally terminates at Nginx, a load balancer, or a trusted reverse proxy. |

The installer performs its own PHP, extension, release-file, filesystem, and
database checks before changing installation state. Meeting this table does
not bypass those checks.

## PHP configuration

PHP must be able to:

- create and update `.env` during installation;
- read the application and Composer dependencies;
- write installation state, task locks, error logs, Twig cache files, and local
  uploads to their configured locations;
- connect to MariaDB and Memcached; and
- accept uploads large enough for the limits configured for the site and web
  server.

Check the CLI runtime before installation:

```bash
php -v
php -m
composer check-platform-reqs
```

`composer check-platform-reqs` is useful after dependencies have been
installed. The web runtime can still differ from the CLI runtime, so confirm
the PHP-FPM version and loaded extensions through service configuration or
logs as well.

The optional Imagick extension with SVG support improves administrator-uploaded
site-icon conversion. Without it, raster icons still work, but the application
cannot publish an SVG version from an uploaded raster image.

## Database preparation

Create an empty database and a dedicated application account. The application
account must be able to create and alter the Stream Engine schema during
installation and future migrations. It should not receive global MariaDB
administration privileges.

For example, run the following as a MariaDB administrator after replacing the
database name, account, host, and password:

```sql
CREATE DATABASE stream_engine
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_uca1400_ai_ci;

CREATE USER 'stream_engine'@'127.0.0.1'
    IDENTIFIED BY 'replace-with-a-random-database-password';

GRANT ALL PRIVILEGES ON stream_engine.*
    TO 'stream_engine'@'127.0.0.1';
```

MariaDB treats accounts from `localhost` and `127.0.0.1` separately on many
installations. Create the account for the actual connection host. For a remote
database, restrict inbound network access to the application hosts and require
transport encryption according to the database provider's instructions.

The installer accepts an empty database. It can also adopt an exact Stream
Engine schema prepared by the migration command when the migration state is
complete. It refuses unrelated or partially compatible databases.

## Filesystem layout and ownership

A typical production layout is:

```text
/srv/stream-engine/
├── .env
├── bin/
├── public/              web document root
├── storage/             writable persistent state
├── vendor/
└── ...                  read-only application release files
```

Before installation, the account running the installer needs to create or
replace `.env` and write `storage/`. After installation:

- `.env` must remain readable by PHP-FPM and CLI maintenance commands but must
  never be served by the web server;
- `storage/` and the configured upload, temporary, and Twig-cache directories
  must be writable by PHP-FPM;
- the scheduler must run as an account with the same effective access to
  `.env` and `storage/`; and
- other application files should be read-only to the web service account.

New `.env` files created by the installer use owner-only permissions. Do not
solve permission problems by making the complete application tree
world-writable.

## Persistent state

Plan storage and backups around the state that cannot be recreated from a
release:

| State | Default location | Persistence requirement |
| --- | --- | --- |
| Environment and secrets | `.env` | Required. Protect as a secret. |
| Database | MariaDB | Required. Contains configuration, users, content, routing, and task state. |
| Installation and migration state | `storage/installation.json` and `storage/migrations.json` | Required with the deployed site. |
| Local uploads | `storage/uploads/` | Required when local storage is used. |
| External uploads | Configured S3-compatible bucket | Required when object storage is used; protect it through provider-side backup or replication. |
| Runtime locks and error logs | `storage/` | Must be writable; retention requirements depend on the file. |

Application code, Composer dependencies, and compiled frontend assets should
come from a known release and can be redeployed. They are not a substitute for
a database and upload backup.

## Network and external services

Allow only the connections required by the deployment:

- public HTTPS traffic to the web server or load balancer;
- application-to-MariaDB and application-to-Memcached traffic on private or
  otherwise protected networks;
- application-to-SMTP traffic if email delivery is configured; and
- application-to-object-storage traffic if S3-compatible storage is used.

Do not expose MariaDB, Memcached, MinIO administration, PHP-FPM, or internal
service ports to the public internet.

## Choose a deployment model

### Composer installation on a managed host or VPS

This is the documented production path. It gives the operator control over
PHP-FPM, Nginx, filesystem permissions, MariaDB, Memcached, TLS, scheduling,
and backup integration. Continue to
[Installation](Administrator-Installation.md).

### Included Docker Compose environment

The repository's `docker-compose.yml` is intended for development and local
evaluation. It uses `APP_ENV=dev`, contains public example credentials, mounts
the working tree into containers, and publishes database and object-storage
ports. It is **not** a production deployment template.

You may use it to evaluate installation locally:

```bash
docker compose build php
docker compose run --rm php composer install
docker compose up -d
```

Then open `http://localhost:5000` and use the local database values documented
in the installation chapter. Do not expose this environment to an untrusted
network.

### Shared hosting

Shared hosting is suitable only when it provides every required PHP extension,
a compatible MariaDB service, Memcached, a document root that can point to
`public/`, command-line access for installation and migrations, and a reliable
once-per-minute scheduler. If any of these capabilities is missing, use a VPS,
managed application host, or container platform instead.

## Pre-installation checklist

- [ ] A specific Stream Engine release series has been selected.
- [ ] PHP 8.3 or newer and every required extension are available to both
      PHP-FPM and the CLI.
- [ ] Composer 2 is available.
- [ ] An empty MariaDB database and dedicated account have been created.
- [ ] Memcached is reachable from the application host.
- [ ] The web document root can be set to `public/`.
- [ ] Persistent, writable storage has been allocated.
- [ ] The canonical HTTPS site URL and TLS termination are known.
- [ ] A once-per-minute scheduler can be configured.
- [ ] Database, `.env`, and upload backup destinations have been planned.

[Back: Guide overview](Administrator-Guide.md) · [Next: Installation](Administrator-Installation.md)
