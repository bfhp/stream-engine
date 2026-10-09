# Stream Engine Administrator Guide

This guide is for the person responsible for the complete lifecycle of a
Stream Engine site: preparing the host, installing the application, configuring
the site, keeping it healthy, recovering it, updating it, and removing it when
it is no longer required.

You do not need to understand the PHP implementation. You should be
comfortable using your hosting control panel or a command-line session,
managing a database, protecting credentials, and checking service logs. When a
task requires elevated operating-system or database privileges, the guide says
so explicitly.

> [!IMPORTANT]
> Stream Engine is under active development and is not yet recommended for
> production use. Test installation, backup, restore, and upgrade procedures
> in a non-production environment before relying on them for a public site.

## Current chapters

1. [Requirements](https://github.com/bfhp/stream-engine/wiki/Administrator-Requirements) — choose a deployment model
   and prepare PHP, MariaDB, Memcached, the web server, storage, and the task
   scheduler.
2. [Installation](https://github.com/bfhp/stream-engine/wiki/Administrator-Installation) — install a release with
   Composer, configure Nginx and PHP-FPM, run the protected web installer or
   CLI installer, and verify the result.
3. [Initial configuration](https://github.com/bfhp/stream-engine/wiki/Administrator-Configuration) — configure the
   deployment environment, email, cache, uploads, site identity, branding,
   registration, and CAPTCHA.
4. [Content, pages, and routing](https://github.com/bfhp/stream-engine/wiki/Administrator-Content-and-Routing) — create
   content, expose it through safe routes and page actions, control access,
   add navigation, and verify sitemap behavior.
5. [Users and permissions](https://github.com/bfhp/stream-engine/wiki/Administrator-Users-and-Permissions) — operate
   accounts, assign global roles, suspend access, protect administrator
   continuity, and distinguish page, content, ownership, and community rules.
6. [Appearance, navigation, and files](https://github.com/bfhp/stream-engine/wiki/Administrator-Appearance-and-Files) — select
   and preview themes, manage branding, menus and widgets, and operate the
   local uploads File Browser safely.
7. [Scheduler and background tasks](https://github.com/bfhp/stream-engine/wiki/Administrator-Scheduler) — configure a
   once-per-minute runner, control registered tasks, inspect execution history,
   and diagnose delayed or failed work.
8. [Routine operations](https://github.com/bfhp/stream-engine/wiki/Administrator-Routine-Operations) — monitor the
   application and its dependencies, review logs, queues, capacity, accounts,
   migrations, and updates, and follow daily, weekly, and monthly checklists.
9. [Backups and restore](https://github.com/bfhp/stream-engine/wiki/Administrator-Backups-and-Restore) — protect every
   persistent state component with external tooling, retain encrypted off-host
   copies, and practise isolated and production recovery.
10. [Upgrades and rollback](https://github.com/bfhp/stream-engine/wiki/Administrator-Upgrades-and-Rollback) — evaluate and
    rehearse a release, coordinate backups, writers, migrations, caches, and
    smoke tests, and restore matching code, database, and files when rollback
    is required.

## Planned lifecycle chapters

The following chapters will be added incrementally. A chapter is included in
the published navigation only after its instructions have been checked against
the current application.

11. Troubleshooting
12. Uninstallation

## Administrative boundaries

Stream Engine manages site content and application settings. The site operator
remains responsible for the surrounding platform, including:

- operating-system and container security updates;
- TLS certificates, DNS, firewall rules, and reverse proxies;
- MariaDB, Memcached, SMTP, and local upload-storage availability;
- scheduled execution of the Stream Engine task runner;
- monitoring, capacity, and log retention; and
- backup scheduling, off-host storage, and restore testing.

The engine does not implement its own backup scheduler. The backup chapter
provides operating-system and container examples for protecting all required
state with external tooling.

## Conventions used by this guide

- `/srv/stream-engine` is the example production application directory.
  Replace it with the absolute path used by your deployment.
- `www-data` is the example PHP-FPM service account. Replace it when your
  distribution or hosting platform uses another account.
- `example.com` and example credentials are placeholders. Never use them as
  production secrets.
- Commands are shown for a POSIX shell unless a section names another
  environment.
- A warning marked **destructive** identifies a command or operation that can
  permanently remove data.

[Documentation home](https://github.com/bfhp/stream-engine/wiki/Home) · [Next: Requirements](https://github.com/bfhp/stream-engine/wiki/Administrator-Requirements)
