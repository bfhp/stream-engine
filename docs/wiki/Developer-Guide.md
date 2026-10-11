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
   opening a pull request against `main`, following
   [Contributing](https://github.com/bfhp/stream-engine/wiki/Developer-Contributing).

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
4. [Modules](https://github.com/bfhp/stream-engine/wiki/Developer-Modules) — discover and compose modules, declare public
   and runtime contributions, integrate templates and browser code, preserve
   dependency boundaries, and remove an extension safely.
5. [Pages and routing](https://github.com/bfhp/stream-engine/wiki/Developer-Pages-and-Routing) — design static and
   dynamic page trees, bind content, generate canonical URLs, preserve access
   and method boundaries, and test route changes.
6. [Themes](https://github.com/bfhp/stream-engine/wiki/Developer-Themes) — create and discover themes, define manifests
   and settings, inherit templates and assets, override module presentation,
   preserve the browser UI contract, and test compatibility.
7. [Frontend development](https://github.com/bfhp/stream-engine/wiki/Developer-Frontend) — organize TypeScript and
   React code, define Vite entries, integrate public and administration
   features, localize and test browser behavior, and ship generated assets.
8. [API and security](https://github.com/bfhp/stream-engine/wiki/Developer-API-and-Security) — register versioned JSON
   routes, define success and error contracts, enforce authentication,
   authorization and CSRF, validate input, and protect output, uploads, and
   secrets.
9. [Testing](https://github.com/bfhp/stream-engine/wiki/Developer-Testing) — choose truthful unit, component, repository,
   integration, and browser test scopes; manage fixtures and external state;
   run coverage; write regressions; and reproduce continuous integration.
10. [Localization and accessibility](https://github.com/bfhp/stream-engine/wiki/Developer-Localization-and-Accessibility) — maintain server and browser
    catalogs, plural/date/time and RTL behavior, semantic and keyboard
    contracts, assistive-technology expectations, and honest verification.
11. [Performance](https://github.com/bfhp/stream-engine/wiki/Developer-Performance) — design indexed access paths,
    prevent repeated and unbounded work, use caches safely, measure request,
    background, and frontend cost, and document current platform limits.
12. [Releases](https://github.com/bfhp/stream-engine/wiki/Developer-Releases) — choose and synchronize a version,
    prepare release notes, snapshots and assets, test clean installation and
    upgrades, tag an immutable commit, and verify public distribution.
13. [Contributing](https://github.com/bfhp/stream-engine/wiki/Developer-Contributing) — scope an issue, work from a
    fork and focused branch, preserve project contracts, submit evidence with a
    pull request to `main`, respond to review, and meet the definition of done.

## Guide maintenance

The planned core chapter set is now published. Keep each chapter synchronized
with the implementation in the same pull request that changes its contract.
Add another page only when it has distinct ownership and lifecycle; otherwise
extend the existing canonical chapter so guidance cannot drift between pages.

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
