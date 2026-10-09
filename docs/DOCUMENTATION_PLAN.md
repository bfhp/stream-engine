# Administrator and developer documentation plan

## Purpose

Stream Engine will maintain two complete documentation paths:

1. an **Administrator Guide** for people who install, configure, operate,
   update, recover, and eventually remove a site; and
2. a **Developer Guide** for people who study the architecture, extend the
   engine, test changes, and contribute them to the open-source project.

The guides will be written in clear international English. They should avoid
locale-specific idioms, unexplained abbreviations, and assumptions about the
reader's country or hosting provider. Commands, paths, UI labels, expected
results, warnings, and recovery steps must be explicit.

## Publishing model

The canonical source will live in `docs/wiki/` in the main repository. All
changes will therefore use the normal branch, pull-request, review, and CI
workflow and can be versioned with the code they describe.

After a change is merged into `main`, a GitHub Actions workflow will publish
the contents of `docs/wiki/` to the repository's GitHub Wiki. The Wiki is a
rendered publication target, not a second source of truth, and its pages must
not be edited directly.

The publishing workflow will:

- run after a push to `main` that changes `docs/wiki/**`, and support a manual
  `workflow_dispatch` run;
- validate the documentation before publishing;
- clone `bfhp/stream-engine.wiki.git` into a temporary directory;
- synchronize the complete managed page set, including deletions;
- create a commit only when the published content changed;
- include the source repository commit SHA in the Wiki commit message;
- serialize publication runs so an older run cannot overwrite a newer one;
- use the least-privileged available credential and never expose a write
  credential to pull-request workflows.

The Wiki repository must first be initialized with one page on GitHub. The
workflow should initially use the repository `GITHUB_TOKEN` with explicit
minimum permissions. If GitHub does not permit that token to push to the Wiki,
a repository-scoped GitHub App or stored publication token will be used and
its ownership and rotation procedure will be documented.

Wiki source filenames will be flat, stable, ASCII names because filenames
become Wiki page names. `Home.md` and `_Sidebar.md` will provide the main entry
points and navigation. Internal links must work both in the repository and in
the published Wiki.

## Information architecture

### Shared entry pages

| Source page | Purpose |
| --- | --- |
| `Home.md` | Introduce Stream Engine documentation and direct readers to one of the two guides. |
| `_Sidebar.md` | Keep both guides visible from every Wiki page, with short grouped navigation. |
| `Administrator-Guide.md` | Administrator landing page, lifecycle overview, and compact table of contents. |
| `Developer-Guide.md` | Developer landing page, learning path, contribution path, and compact table of contents. |

Pages should link to the next likely task as well as back to their guide's
landing page. Navigation must remain useful on narrow screens and must not
depend on a generated table of contents.

## Administrator Guide

The Administrator Guide covers the complete lifecycle of a working site. It
is task-oriented rather than an inventory of implementation classes or API
endpoints.

### Proposed pages

| Page | Required contents |
| --- | --- |
| `Administrator-Requirements.md` | Supported runtime, PHP extensions, database, cache, web server, storage, scheduler, mail, TLS, DNS, filesystem permissions, and capacity-planning basics. Compare Composer/manual, Docker Compose, and other supported deployment models without promising unsupported platforms. |
| `Administrator-Installation.md` | Release selection, file placement, web-server document root, environment preparation, web and CLI installers, unattended installation, installation token, initial administrator, production-safe secrets, and post-install verification. |
| `Administrator-Configuration.md` | Site identity and URL, locale and date/time formats, registration, CAPTCHA, SMTP, cache isolation, local and S3-compatible uploads, branding, and other global settings. Explain which values live in `.env` and which live in the database. |
| `Administrator-Content-and-Routing.md` | The relationship between feeds, pages, page actions, routes, and menus. Include one complete example that creates content, exposes it at a URL, controls access, and adds navigation. Cover sitemap-related settings and safe route changes. |
| `Administrator-Users-and-Permissions.md` | User, moderator, and administrator roles; account activation; registration policy; page access rules; ownership; protected system account; self-protection; and last-administrator safeguards. Clearly distinguish global roles from per-page and content access. |
| `Administrator-Appearance-and-Files.md` | Themes, theme-specific settings, template fallback at an administrator level, menus and nesting, widgets and placements, header logo, site icon, and File Browser operations. Include destructive-operation warnings and upload limits. |
| `Administrator-Scheduler.md` | OS scheduler, request-driven, and off modes; cron, systemd timer, and Docker scheduler setup; service account and writable paths; task enable/disable and manual runs; history; verification; and failure diagnosis. Add other operating-system instructions only when tested and supported. |
| `Administrator-Routine-Operations.md` | Dashboard health indicators, log review, storage and database capacity, pending/failed deliveries, scheduler health, account review, update checks, and a practical daily/weekly/monthly checklist. |
| `Administrator-Backups-and-Restore.md` | State explicitly that backup orchestration is outside the engine. Define the required backup set: database, `.env`, local uploads or external object storage, and any site-owned files. Provide tested OS/container examples, retention and off-host guidance, encryption considerations, consistent backup ordering, restore steps, and a mandatory restore test. |
| `Administrator-Upgrades-and-Rollback.md` | Supported release source, release notes, compatibility checks, pre-upgrade backup, service window, dependency and asset handling, migrations, cache considerations, scheduler coordination, smoke tests, and rollback of both files and database. Do not claim zero-downtime updates until the product supports them. |
| `Administrator-Troubleshooting.md` | A symptom-first diagnostic index covering installation, HTTP/PHP failures, database connectivity, pending migrations, permissions, cache, cron, email, uploads, object storage, themes/assets, and recovery from a failed update. Name log and state-file locations and redact secrets in support reports. |
| `Administrator-Uninstallation.md` | Final backup, scheduler removal, service/container shutdown, application files, database and database user, persistent volumes, local/object-storage uploads, secrets and external credentials, TLS/DNS cleanup, and explicit irreversible steps. |

Where operating systems differ, instructions should be grouped by supported
deployment environment rather than mixed into a single command sequence. A
reader must always be able to identify what is authoritative for a traditional
Linux host, Docker Compose, and any additional platform that the project later
declares supported.

## Developer Guide

The Developer Guide explains both how Stream Engine works and how to change it
without violating its extension contracts. It also defines the contribution
path from a first local checkout to a reviewed pull request into `main`.

### Proposed pages

| Page | Required contents |
| --- | --- |
| `Developer-Getting-Started.md` | Repository checkout, PHP/Node/database prerequisites, Docker and host-based setup, installation of dependencies, development database, frontend dev server, common commands, and a first successful test run. |
| `Developer-Architecture.md` | System boundaries, directory ownership, bootstrap, request flow, router, controllers, services, repositories, view models, Twig rendering, frontend entry points, caching, and background tasks. Use Mermaid only when it makes a multi-step flow materially clearer. |
| `Developer-Database-and-Migrations.md` | Migration naming and ordering, creating/applying/checking migrations, fresh-install snapshot and manifest, engine versus site migrations, data migrations, compatibility rules, and release-time verification. |
| `Developer-Modules.md` | Module discovery and isolation, controllers, services, repositories, templates, assets, translations, page actions, admin pages, cron tasks, dashboard cards, dependencies, a minimal worked module, and the module contract. |
| `Developer-Pages-and-Routing.md` | Static and dynamic page trees, action contracts, placeholders, feed bindings, precedence, access rules, request methods, URL generation, ambiguity prevention, and tests for new routes. |
| `Developer-Themes.md` | Creating a theme, metadata and settings, layouts, template ownership, inheritance and fallback, module overrides, slots/widgets, assets, RTL, compatibility, and the theme contract. |
| `Developer-Frontend.md` | TypeScript and React organization, Vite entry points, shared browser utilities, admin routes supplied by modules, styling, localization, builds, type checks, tests, and committed release assets. |
| `Developer-API-and-Security.md` | API response and error conventions, validation, authentication, authorization, ownership checks, CSRF, request-method declarations, output escaping/sanitization, uploads, secrets, and the required security audit. |
| `Developer-Testing.md` | PHPUnit and Vitest organization, unit versus integration scope, fixtures, database tests, frontend tests, coverage commands and expectations, regression tests, and CI parity. |
| `Developer-Localization-and-Accessibility.md` | Translation catalogs, message ownership, plural forms, date/time formatting, browser translations, RTL and bidi behavior, keyboard and screen-reader expectations, and tests for user-visible changes. |
| `Developer-Performance.md` | Query and index rules, pagination, bounded work, caching, hot paths, background processing, measurement expectations, and the performance contract. |
| `Developer-Releases.md` | Versioning, changelog/release notes, migration and install-snapshot checks, dependency and frontend builds, complete test matrix, packaging, tags, artifacts, upgrade verification, and post-release checks. |
| `Developer-Contributing.md` | Issues and scope, forks, branches, coding conventions, tests, documentation, commits, local checks, opening a pull request against `main`, CI, review, updating a PR, compatibility expectations, and the project's definition of done. |

## Existing-document migration

Existing documentation contains normative material and must be reconciled,
not copied into competing pages. During migration, each topic gets exactly one
canonical source page. Old paths may temporarily contain short relocation
notices when preserving external links is valuable, but must not retain an
independent copy that can drift.

| Existing document | Planned destination |
| --- | --- |
| `INSTALLATION.md` | Administrator installation and upgrade pages; developer-only snapshot and installer architecture material goes to database/migrations or release documentation. |
| `CRON.md` | `Administrator-Scheduler.md`, with implementation details linked from the developer architecture where necessary. |
| `MODULES.md` | `Developer-Modules.md`. |
| `ROUTING.md` | `Developer-Pages-and-Routing.md`; administrator concepts and examples are rewritten for `Administrator-Content-and-Routing.md`. |
| `MODULE_CONTRACT.md` | Normative sections within or directly adjacent to `Developer-Modules.md`. |
| `THEME_CONTRACT.md` | Normative sections within or directly adjacent to `Developer-Themes.md`. |
| `PERFORMANCE_CONTRACT.md` | `Developer-Performance.md`. |
| Root `README.md` setup and architecture sections | Keep only the project overview and short quick start, then link prominently to both published guides and their repository sources. |

Repository links, source comments, and other documentation must be updated in
the same change that moves a canonical page.

## Validation and maintenance rules

Pull-request CI will validate at least:

- every Markdown link between managed Wiki pages resolves;
- referenced repository files and headings exist;
- `Home.md`, `_Sidebar.md`, and both guide landing pages exist;
- every managed page is reachable from a guide or shared navigation;
- filenames are unique under case-insensitive comparison and follow the stable
  Wiki naming convention;
- the publishing source contains no generated files or secrets;
- command examples selected for automated verification still execute against
  the current tree.

External link checking should be scheduled or retry-aware so transient network
failures do not make normal pull requests unreliable.

A pull request that changes user-visible behavior, installation, operations,
an extension contract, or the contribution workflow must update the relevant
guide in the same pull request. Reviewers should check documentation as part of
the feature's completion criteria. Screenshots should be used sparingly because
they age quickly; durable task descriptions, exact labels, expected results,
and recovery instructions are preferred.

## Implementation phases

### Phase 1: documentation infrastructure

- Create `docs/wiki/`, `Home.md`, `_Sidebar.md`, and the two guide landing
  pages.
- Add repository and README links to the source guides and published Wiki.
- Add internal-link and navigation validation to CI.
- Initialize the GitHub Wiki and add the publication workflow.
- Complete a manual publication test and document credential recovery.

### Phase 2: Administrator Guide

- Write the administrator pages in lifecycle order.
- Reconcile installation and scheduler documentation first.
- Test every command in each declared deployment environment.
- Perform a clean-install walkthrough followed by configuration, backup,
  restore, upgrade, rollback, and removal drills.
- Record product limitations honestly instead of documenting unimplemented
  behavior.

### Phase 3: Developer Guide

- Migrate the existing architecture and extension contracts.
- Add complete module and theme walkthroughs.
- Document security, testing, localization, performance, and release rules.
- Validate the guide with a clean checkout and a small extension change.
- Add the fork-to-`main` open-source contribution workflow.

### Phase 4: enforcement and release readiness

- Add documentation checks to pull-request completion criteria.
- Check the entire Wiki navigation and publication result.
- Remove obsolete duplicate documentation and temporary relocation notices
  whose compatibility period has ended.
- Require guide review as part of release preparation.

## Completion criteria

The documentation initiative is complete when:

- a new administrator can install, configure, operate, back up, restore,
  update, troubleshoot, and remove a clean Stream Engine site using only the
  Administrator Guide;
- a new developer can set up the project, understand a request end to end,
  implement and test a module or theme change, and submit a conforming pull
  request to `main` using only the Developer Guide;
- the main repository is the single source of truth and GitHub Wiki publication
  is automatic, repeatable, and observable;
- all internal links and navigation paths pass CI;
- existing contract material has one maintained canonical location; and
- documentation changes are part of the definition of done for relevant code
  changes.
