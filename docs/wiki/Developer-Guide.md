# Stream Engine Developer Guide

This guide is for people who want to understand, change, extend, test, or
contribute to Stream Engine. It follows the path from a clean checkout to a
reviewed pull request and documents the contracts that keep the platform,
modules, themes, and site-specific code independent.

Stream Engine uses PHP 8.3 for the backend and TypeScript for browser code.
The main development stack also includes MariaDB, Memcached, Twig, React,
Vite, PHPUnit, and Vitest. You do not need to know every component before
starting, but you should be comfortable with Git and command-line tools.

> [!IMPORTANT]
> Stream Engine is under active development. Public extension contracts are
> documented explicitly; do not assume that an internal class or directory is
> a stable API merely because it is accessible.

## Start here

1. [Set up a development environment](https://github.com/bfhp/stream-engine/wiki/Developer-Getting-Started),
   install both dependency sets, create a local site, and run the same checks
   used by continuous integration.
2. Read [Architecture and request flow](https://github.com/bfhp/stream-engine/wiki/Developer-Architecture) before
   changing shared bootstrap, routing, rendering, caching, or background-task
   behavior.
3. Use the relevant extension chapter before adding a module, page action,
   theme, frontend entry point, or migration.
4. Complete the security, test, documentation, and compatibility checks before
   opening a pull request against `main`.

## Current chapters

1. [Getting started](https://github.com/bfhp/stream-engine/wiki/Developer-Getting-Started) — fork or clone the
   repository, start the Docker development stack or prepare a host stack,
   install the application, build frontend assets, and run the baseline checks.
2. [Architecture and request flow](https://github.com/bfhp/stream-engine/wiki/Developer-Architecture) — understand
   the composition root, layer ownership, request dispatch, rendering,
   frontend build, cache, and background-task boundaries.
3. [Database and migrations](https://github.com/bfhp/stream-engine/wiki/Developer-Database-and-Migrations) — create and
   test forward migrations, understand combined engine/site history and state,
   and maintain the fresh-install schema snapshot.

## Planned chapters

The guide will be expanded in this order as existing source documents and
implementation contracts are reconciled:

1. Modules
2. Pages and routing
3. Themes
4. Frontend development
5. API and security
6. Testing
7. Localization and accessibility
8. Performance
9. Releases
10. Contributing

Until a chapter is published, use the existing documents linked from the
[repository README](https://github.com/bfhp/stream-engine#architecture). They
remain authoritative until their material is migrated and replaced with a
short relocation notice.

## Documentation conventions

- Commands run from the repository root unless a section says otherwise.
- Docker commands use the development-only `docker-compose.yml` committed to
  the repository.
- Commands without a `docker compose` prefix run on the host.
- `main` is the pull-request target and the reference branch for current
  development.
- Paths are relative to the repository root.
- A documented extension contract is normative; an example is illustrative.

[Documentation home](https://github.com/bfhp/stream-engine/wiki/Home) · [Next: Getting started](https://github.com/bfhp/stream-engine/wiki/Developer-Getting-Started)
