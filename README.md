# Stream Engine

Stream Engine is a modular content management system built with PHP and
TypeScript. It combines traditional publishing features with forums,
communities, private messaging, user profiles, and a React-based administration
interface.

> [!IMPORTANT]
> Stream Engine is under active development and is not yet recommended for
> production use. Interfaces, migrations, and installation workflows may still
> change before the first stable release.

## An AI-assisted CMS built from scratch

This project is the author's deliberate attempt to build a CMS from scratch
rather than extend or fork an existing platform. The work is carried out with
the assistance of AI coding agents, primarily [OpenAI Codex](https://openai.com/codex/)
and [Anthropic Claude](https://www.anthropic.com/claude).

The agents help explore architecture, implement features, write tests, review
changes, and maintain documentation. The author directs the project, makes the
product and engineering decisions, and remains responsible for the resulting
code. The project therefore also serves as a practical experiment in how a
substantial, long-lived software system can be developed collaboratively with
AI agents.

“From scratch” refers to the CMS architecture and application code: Stream
Engine is not based on another CMS, but it makes pragmatic use of established
open-source libraries and infrastructure.

## Current capabilities

- Page-based routing and configurable content feeds
- Articles, personal blogs, communities, and forums
- Forum topics, replies, polls, attachments, and access control
- User registration, authentication, profiles, friendships, and memberships
- Private and group conversations, notifications, and email delivery
- Comments, ratings, favorites, tags, search, and sitemap generation
- A React administration interface for pages, feeds, users, widgets, and
  settings
- Modular backend controllers, module-owned templates, and discoverable module
  assets and admin pages
- Database migrations and shared CLI/web installation infrastructure
- Local uploads and S3-compatible object storage
- Twig themes with an explicit override and ownership model
- English and Russian localization infrastructure

The current backlog and known limitations are tracked in
[docs/TODO.md](docs/TODO.md).

## Technology

The backend targets PHP 8.3 and uses Twig, PDO, MariaDB, Memcached, PHPMailer,
and HTML Purifier. The frontend uses TypeScript, React, Mantine, Bootstrap,
Trix, and Vite. PHPUnit and Vitest provide the backend and frontend test suites.

The included Docker Compose environment runs Nginx, PHP-FPM, MariaDB,
Memcached, and MinIO.

## Quick start with Docker

You need Docker with the Compose plugin. From the repository root, build the
PHP image, install the dependencies, compile the frontend, and start the local
services:

```bash
docker compose build php
docker compose run --rm php composer install
docker compose run --rm node npm install
docker compose run --rm node npm run build
docker compose up -d
```

Open [http://localhost:5000](http://localhost:5000). An uninstalled instance
redirects to the web installer automatically.

Use these database values in the local installer:

| Setting | Value |
| --- | --- |
| Host | `db` |
| Port | `3306` |
| Database | `app` |
| Username | `user` |
| Password | `password` |

These credentials are intended only for local development. The installer
validates the environment, initializes the schema, creates the administrator,
and writes the generated application configuration to `.env`.

To stop the environment:

```bash
docker compose down
```

Database and object-storage data remain in named Docker volumes. Removing those
volumes with `docker compose down --volumes` permanently deletes the local
development data.

For CLI installation, unattended deployment, installation security, and schema
snapshot details, see [docs/INSTALLATION.md](docs/INSTALLATION.md).

## Development

For a host-based setup, install PHP 8.3 with the extensions required by
`composer.json`, plus Node.js and a compatible MariaDB server. Then install the
dependencies:

```bash
composer install
npm install
```

Common development commands:

```bash
# Frontend development server
npm run dev

# Production frontend build
npm run build

# Backend tests
composer test

# Frontend tests
npm test

# TypeScript checks
npm run typecheck

# PHP formatting check
composer lint

# CSRF route audit
composer audit:csrf

# Database migration status and execution
composer migrate:status
composer migrate
```

Coverage runs inside the PHP container because that image provides Xdebug:

```bash
composer test:coverage
composer test:coverage-html
```

## Architecture

Stream Engine separates the shared platform from feature modules. Platform
services and repositories provide cross-cutting capabilities; modules consume
those capabilities without depending on one another. Controllers are discovered
through Composer's optimized classmap, while module templates and frontend
entry points follow directory conventions.

Themes provide layouts, shared presentation, and optional overrides. Markup
specific to a feature stays with the module that owns it, allowing themes and
modules to evolve independently.

Key documentation:

- [Installation architecture](docs/INSTALLATION.md)
- [Building and loading modules](docs/MODULES.md)
- [Module dependency contract](docs/MODULE_CONTRACT.md)
- [Theme ownership and override contract](docs/THEME_CONTRACT.md)
- [Performance contract](docs/PERFORMANCE_CONTRACT.md)
- [Backlog and project status](docs/TODO.md)

## Contributing

The project is still taking shape. Before making a substantial change, review
the relevant architecture contracts and the backlog. New work should preserve
module boundaries, include tests appropriate to its risk, and keep PHP,
TypeScript, frontend builds, and CSRF checks green.

Bug reports and focused pull requests are welcome through the
[GitHub repository](https://github.com/bfhp/stream-engine).

## License

The PHP project is distributed under the
[GNU Lesser General Public License v3.0](LICENSE).
