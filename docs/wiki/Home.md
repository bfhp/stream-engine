# Stream Engine documentation

Stream Engine is a modular content management system built with PHP and
TypeScript. Choose the guide that matches your work.

## Administrator Guide

The [Administrator Guide](https://github.com/bfhp/stream-engine/wiki/Administrator-Guide) is for people responsible
for a Stream Engine site. It starts with server requirements and installation
and covers configuration, content and routing, users and permissions,
appearance and files, scheduled work, backups, upgrades, troubleshooting, and
removal.

Start here if you need to deploy or operate a site:

1. [Check the requirements](https://github.com/bfhp/stream-engine/wiki/Administrator-Requirements).
2. [Install Stream Engine](https://github.com/bfhp/stream-engine/wiki/Administrator-Installation).
3. [Complete the initial configuration](https://github.com/bfhp/stream-engine/wiki/Administrator-Configuration).
4. [Publish content and configure routing](https://github.com/bfhp/stream-engine/wiki/Administrator-Content-and-Routing).
5. [Manage users and permissions](https://github.com/bfhp/stream-engine/wiki/Administrator-Users-and-Permissions).
6. [Configure appearance, navigation, and files](https://github.com/bfhp/stream-engine/wiki/Administrator-Appearance-and-Files).
7. [Configure and verify scheduled tasks](https://github.com/bfhp/stream-engine/wiki/Administrator-Scheduler).
8. [Establish routine operational checks](https://github.com/bfhp/stream-engine/wiki/Administrator-Routine-Operations).
9. [Configure and test backup recovery](https://github.com/bfhp/stream-engine/wiki/Administrator-Backups-and-Restore).
10. [Plan upgrades and complete rollback drills](https://github.com/bfhp/stream-engine/wiki/Administrator-Upgrades-and-Rollback).
11. [Diagnose operational problems safely](https://github.com/bfhp/stream-engine/wiki/Administrator-Troubleshooting).
12. [Retire or uninstall a site](https://github.com/bfhp/stream-engine/wiki/Administrator-Uninstallation).

## Developer Guide

The [Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide) is for people who want to
understand the architecture, extend the engine, test changes, and contribute
them to the open-source project.

Start with [Getting started](https://github.com/bfhp/stream-engine/wiki/Developer-Getting-Started) to prepare a
checkout, install a local site, build the frontend, and run the baseline checks.
Continue with [Architecture and request flow](https://github.com/bfhp/stream-engine/wiki/Developer-Architecture) before
changing bootstrap, routing, rendering, caching, or background-task behavior.
Use [Database and migrations](https://github.com/bfhp/stream-engine/wiki/Developer-Database-and-Migrations) for schema
changes, data migrations, migration state, and fresh-install snapshots.
Use [Modules](https://github.com/bfhp/stream-engine/wiki/Developer-Modules) to add site features while preserving
autoloading, dependency, routing, template, frontend, API, cron, and removal
contracts. Use [Pages and routing](https://github.com/bfhp/stream-engine/wiki/Developer-Pages-and-Routing) when changing
page trees, dynamic patterns, content bindings, canonical URLs, or route
access. Use [Themes](https://github.com/bfhp/stream-engine/wiki/Developer-Themes) to create a public-site presentation
layer, declare its manifest and assets, override templates, and preserve the
shared browser UI contract. Use [Frontend development](https://github.com/bfhp/stream-engine/wiki/Developer-Frontend)
for TypeScript and React organization, Vite entries, shared browser utilities,
localization, tests, builds, and committed release assets. Further extension
chapters will follow as their existing source documents are reconciled.

> [!IMPORTANT]
> Stream Engine is under active development and is not yet recommended for
> production use. Interfaces, migrations, and installation workflows may
> change before the first stable release.

## Documentation source

The canonical documentation is maintained in `docs/wiki/` in the
[Stream Engine repository](https://github.com/bfhp/stream-engine). The GitHub
Wiki is a published copy. Documentation changes should be submitted through
the same pull-request workflow as code changes.
