# Developer Guide: Getting started

This chapter takes a new contributor from an empty working directory to an
installed development site and a green baseline test run. Use the Docker path
unless you already maintain a compatible PHP, MariaDB, Memcached, and web
server stack on the host.

## Prerequisites

Every workflow requires:

- Git;
- a GitHub account if you intend to contribute; and
- enough free disk space for the repository, Composer and npm dependencies,
  container images, a development database, and generated coverage reports.

For the recommended workflow, install Docker Engine or Docker Desktop with the
Compose plugin. Confirm that both commands work:

```bash
git --version
docker compose version
```

For a host-based workflow, install PHP 8.3 with the extensions declared in
[`composer.json`](https://github.com/bfhp/stream-engine/blob/main/composer.json),
Composer 2, MariaDB 12, Memcached, Node.js 20.19 or newer within the Node 20
release line, and npm. The Node version matches continuous integration; later
supported Node release lines may also work, but must pass the complete
frontend checks.

## Fork or clone the repository

If you only need a local checkout, clone the main repository:

```bash
git clone https://github.com/bfhp/stream-engine.git
cd stream-engine
```

If you plan to open a pull request, create a GitHub fork first, then clone the
fork and retain the main repository as `upstream`:

```bash
git clone https://github.com/YOUR-GITHUB-ACCOUNT/stream-engine.git
cd stream-engine
git remote add upstream https://github.com/bfhp/stream-engine.git
git remote -v
```

Replace `YOUR-GITHUB-ACCOUNT` before running the command. Do not commit `.env`,
credentials, database dumps, uploaded files, dependency directories, or test
coverage output.

Before starting new work, synchronize a local `main` branch and create a
focused branch:

```bash
git switch main
git pull --ff-only upstream main
git switch -c describe-the-change
```

Use a short branch name that describes one coherent change. A complete pull
request workflow will be documented in the contributing chapter.

## Recommended Docker setup

The included Compose environment starts Nginx, PHP-FPM, MariaDB, Memcached,
MinIO, and the Stream Engine scheduler. It mounts the checkout into the
containers and uses public development credentials. Never expose it as a
production environment.

Build the shared PHP/Node image and install locked dependencies:

```bash
docker compose build php
docker compose run --rm php composer install --no-interaction
docker compose run --rm node npm ci
docker compose up -d
docker compose ps
```

`docker compose ps` should show the application services running. If a
service exits or remains unhealthy, inspect it before continuing:

```bash
docker compose logs --tail=100 php nginx db memcached
```

Open `http://localhost:5000`. A clean checkout redirects to the web installer.
Use these local database values:

| Installer field | Development value |
| --- | --- |
| Host | `db` |
| Port | `3306` |
| Database | `app` |
| Username | `user` |
| Password | `password` |
| Application environment | `dev` |
| Site URL | `http://localhost:5000` |

Choose a local administrator email and a unique password. The installer writes
`.env`, initializes the database, and creates the administrator account. The
example database and object-storage credentials in `docker-compose.yml` are
for local development only.

After installation, verify the HTTP endpoint and migration state:

```bash
curl --fail --show-error --head http://localhost:5000/
docker compose exec -T php composer migrate:status
```

The HTTP response may redirect to a login or another configured page, but the
request must reach Nginx and PHP without a connection or server error. Migration
status should report no pending migrations.

### Stop or reset the environment

Stop containers without deleting development data:

```bash
docker compose down
```

The database, MinIO data, and container-managed `node_modules` remain in named
volumes.

> [!CAUTION]
> The following command permanently deletes the Compose database, MinIO data,
> and Node dependency volume. It does not remove repository files, but the
> local site must be installed again.

```bash
docker compose down --volumes
```

## Host-based setup

Use this path only when the host already meets the same application
requirements as the development containers. Install dependencies first:

```bash
composer install
npm ci
composer check-platform-reqs
```

Prepare an empty MariaDB database and a dedicated account, start Memcached,
and configure a web server whose document root is the repository's `public/`
directory. Do not serve the repository root. Then open the site and complete
the web installer with `APP_ENV=dev`, or run the interactive CLI installer:

```bash
composer cms:install
```

The installer creates `.env`; copying `.env.example` is not required. If PHP,
MariaDB, Memcached, or web-server setup fails, compare the host with the
[application requirements](https://github.com/bfhp/stream-engine/wiki/Administrator-Requirements)
before diagnosing application code.

## Frontend development

Browser source code lives under `assets-src/`. Vite writes the files used by
the application to `public/assets/`:

```bash
npm run build
```

For repeated local edits, rebuild when sources change:

```bash
npm run build -- --watch
```

The `npm run dev` command starts Vite's development server, but the application
currently references compiled `/assets/` files directly. It does not
automatically switch the running site to Vite hot-module replacement. Use the
build or watch command when verifying frontend changes through Stream Engine.

With the Docker dependency volume, run the same commands in the `node`
service:

```bash
docker compose exec -T node npm run build
docker compose exec node npm run build -- --watch
```

The second command is intentionally interactive; stop it with `Ctrl+C`.

## Run the baseline checks

Run all checks before changing code. A green baseline distinguishes an
existing environment problem from a regression introduced by the change.

On a host with the dependencies installed:

```bash
php bin/check-wiki-docs.php
composer lint
composer test
composer audit:csrf
npm test
npm run typecheck
npm run build
```

With the Compose environment running:

```bash
docker compose exec -T php php bin/check-wiki-docs.php
docker compose exec -T php composer lint
docker compose exec -T \
  -e 'INSTALL_TEST_DB_DSN=mysql:host=db;port=3306' \
  -e INSTALL_TEST_DB_USERNAME=root \
  -e INSTALL_TEST_DB_PASSWORD=root \
  php composer test
docker compose exec -T php composer audit:csrf
docker compose exec -T node npm test
docker compose exec -T node npm run typecheck
docker compose exec -T node npm run build
```

These are the main continuous-integration checks. The three temporary
environment values let the destructive installation-schema test create and
remove its own isolated databases in the local Compose MariaDB service. Never
point `INSTALL_TEST_DB_DSN` at a database server where that account has access
to valuable databases. Coverage commands require the Xdebug-enabled PHP
container:

```bash
composer test:coverage
composer test:coverage-html
```

The HTML report is written to `coverage/` and is ignored by Git.

## Know the repository layout

| Path | Responsibility |
| --- | --- |
| `src/Core/` | Bootstrap-facing infrastructure and shared runtime contracts. |
| `src/Domain/` | Domain data objects. |
| `src/Repository/` | Persistence and query boundaries shared across features. |
| `src/Service/` | Cross-feature application services. |
| `src/Modules/` | Built-in feature modules and their owned controllers or templates. |
| `assets-src/` | TypeScript, React, CSS, and generated-build inputs. |
| `public/` | Web document root and compiled public assets. |
| `views/` | Shared email and theme templates. |
| `migrations/` | Ordered database migrations. |
| `resources/install/` | Fresh-install schema snapshot and manifest. |
| `tests/` | PHPUnit suites and Vitest tests under `tests/js/`. |
| `bin/` | Installation, migration, scheduler, and documentation commands. |
| `docs/wiki/` | Canonical source for the published GitHub Wiki. |

The architecture chapter will describe ownership and request flow in detail.
Until then, do not move responsibilities between these directories without
reviewing the existing contracts linked from the repository README.

## First-change checklist

- The checkout was clean before the change.
- The local site installs and responds over HTTP.
- Migration status contains no unexpected pending migration.
- Backend and frontend baseline checks pass.
- The change has a focused branch and does not include `.env` or generated
  local artifacts.
- User-visible behavior or an extension-contract change includes matching
  documentation.

[Back: Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide) · [Documentation home](https://github.com/bfhp/stream-engine/wiki/Home)
