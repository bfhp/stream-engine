# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project intends to follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Added a cross-platform Docker Compose scheduler that uses Supercronic and the
  shared application image to run `bin/cron.php` once per minute.
- Added rich-text forum quick replies with an inline preview, safe HTML
  purification, rich-text editing, and quote insertion into the editor.

### Fixed

- Fixed `@mention` autocomplete in forum rich-text editors, mention
  synchronization when a topic is edited, and profile-link rendering in
  paginated replies.
- Constrained default-theme forum pages to the standard content width and
  restored visible Trix toolbar icons in light mode.

## [0.4.5] - 2026-10-08

### Added

- Added an administrator-configurable header logo with validated image upload,
  replacement and removal, responsive rendering in both bundled themes, and
  the site name kept visible alongside it.
- Added administrator-configurable site icons with automatic favicon and
  Apple Touch Icon generation, optional SVG support, and default restoration.
- Added registration controls for closed, email-confirmed, and immediately
  active accounts, a configurable honeypot, and optional Turnstile or hCaptcha
  verification.
- Added automatic removal of registrations left unconfirmed for more than 24
  hours.
- Added `@username` mentions to comments, forum posts, and private messages,
  with username autocomplete, safe profile links, escaping, and support for
  username changes.
- Added configurable mention notifications with deduplication, access checks
  before delivery, and deep links to the referenced comment, forum post, or
  message. Mention resolution uses one bounded batch lookup when content is
  saved.
- Added friends-only personal blog posts. One-way friend requests remain
  subscribers, mutual requests promote both existing memberships to members,
  and removing either direction revokes friends-only access without expensive
  reverse-relationship checks in feed queries.

## [0.4.4] - 2026-10-07

### Added

- Added an admin file browser for browsing, uploading, and organizing files.
- Added an admin Scheduler page with task health, intervals, next runs,
  durations, scheduler heartbeat status, and `os`, `web`, and `off` operating
  modes.
- Added per-task enablement, immediate manual execution, and a details drawer
  with scheduled and manual run history, errors, timings, and distinct queued,
  running, failed-to-start, timed-out, successful, and failed states. History
  is limited to the latest 25 attempts per task.
- Added a non-blocking `composer update` warning that lists pending database
  migrations and points operators to `composer migrate`.

### Changed

- The admin page list now displays pages as an indented hierarchy.
- Cron mode is now stored in application settings and managed from the admin
  interface instead of through `CRON_MODE`.
- Renamed the current task-state table from `cron_runs` to `cron_tasks` and
  moved individual attempts into the bounded `cron_run_history` table.
- Installation schema snapshots are now built by creating, migrating, and
  removing an isolated temporary database automatically instead of requiring
  a manually prepared empty database.

### Removed

- Removed the legacy `CRON_KEY` setting and public cron HTTP endpoint; web mode
  now starts the internal runner from ordinary application requests.

### Fixed

- Fixed manual cron execution under PHP-FPM by launching the CLI PHP binary,
  and made queued, running, success, failure, duration, and next-run state
  update reliably after manual runs.

## [0.4.3] - 2026-10-05

### Added

- Modules can declare validated action-specific select fields stored in page
  settings; the standard page editor renders them automatically.
- Themes can be discovered from the root application's `views/themes`
  directory in addition to the engine themes.

### Changed

- New menu items default to the `top` group, with an editable group selector
  offering `top`, `bottom`, and `user`.

### Fixed

- Menu item ordering is now assigned server-side when items are created or
  moved, avoiding duplicate or stale sort positions.
- The page editor now displays structured API validation errors correctly.

## [0.4.2] - 2026-10-04

### Added

- Added a `system` color-mode choice whenever a theme supports both `light`
  and `dark`. The public layout resolves the operating-system preference
  before styles load, follows preference changes while the page is open, and
  keeps the default and Bootstrap themes, browser chrome, and admin previews
  in sync.
- Added editable `visibility` and `position` fields to the feed administration
  form for both new and existing feeds. The API now persists both values,
  validates visibility against `public`, `members`, and `private`, and limits
  positions to unsigned integers.

### Changed

- Reworked the feed editor into a responsive grid: long text fields retain
  more space, compact fields share rows, and parent ID is now a numeric input;
  narrow screens continue to use a single-column layout.

### Fixed

- Required every stored page except the root page (`id = 1`) to have a parent.
  The Pages editor no longer allows clearing the parent, and server-side
  validation rejects creating or detaching non-root pages without one.

## [0.4.1] - 2026-10-04

### Added

- Added a localized page setting for `article.show-id` and
  `article.show-slug` that lets administrators render article content inside
  the active theme's container or at full width. Contained content is the
  default, including for the welcome page created during installation.

### Fixed

- Fixed light mode in the `default` and `bootstrap` themes by replacing
  unconditional dark backgrounds and text colors with active-theme tokens,
  removing forced light text from Bootstrap chrome, and applying light-aware
  colors to shared content, browser chrome, and the messenger.

## [0.4.0] - 2026-10-04

### Added

- Added global date and time format selectors to the administration settings,
  with locale-based, day-first, month-first, ISO, 24-hour, and 12-hour options.
  The selected formats are validated server-side and applied consistently to
  server-rendered dates, the admin interface, user lists, and the messenger;
  per-user timezone detection remains unchanged.
- Added theme inheritance: `parent` in `theme.json`, inherited templates,
  assets and settings, and `@<theme>` / `@<Module>` Twig namespaces.
- Added the `bootstrap` theme, built on `default`; it becomes the default
  choice for sites that never selected a theme.
- Added a UI adapter (`CMS.ui`) so scripts no longer depend on Bootstrap
  directly.

### Changed

- Bootstrap is no longer added implicitly: themes declare it, including its
  RTL build (`assets.rtl`), and its JS ships as `/assets/js/ui-bootstrap.js`.
  Themes with `"inheritAssets": false` must list these themselves.
- The `default` theme no longer uses Bootstrap (own `base.css`, native
  dialogs and toggles, framework-free module pages); scripts render API
  results from the theme's `<template>`s. **Sites that explicitly selected `default`
  should switch to `bootstrap` to keep their current look.**

## [0.3.0] - 2026-10-03

### Added

- Added the German (`de`), French (`fr`), Spanish (`es`), Brazilian Portuguese (`pt-br`), Arabic (`ar`), Italian (`it`), Japanese (`ja`), Korean (`ko`), Simplified Chinese (`zh-cn`), Dutch (`nl`), and Polish (`pl`) interface locales with a full catalog, admin locale
  option, and singular/plural handling for server-side units.
- Added a visual theme gallery with catalog-based selection, validated
  per-theme settings, safe previews, fallback, and versioned assets.
- Added right-to-left support. The locale decides the document direction
  (`TranslationManager::direction()`, Twig `dir`, `<html dir>` on site, admin,
  error, and email layouts), and Arabic-style plural rules are applied on the
  server and in the browser.
- Added a separate `bootstrap-rtl.css` build that the engine loads instead of
  `bootstrap.css` for right-to-left locales (`ThemeCatalog::forDirection()`),
  so only one Bootstrap build is ever loaded.
- Mirrored directional icons in right-to-left mode, and added `dir="auto"` and
  `<bdi>` isolation for user-generated names, titles, comments, posts, and
  messages.
- The administration interface now uses Mantine's `DirectionProvider` and
  direction-aware indent, outdent, back, and nesting markers.

### Changed

- Locale choices are now discovered from catalogs during installation and in
  administration, with complete-catalog checks and regional-code fallback.
- Bootstrap CSS is no longer bundled into `site.css`; it is its own
  `/assets/css/bootstrap.css` entry listed by the default theme. Themes that list
  core `site.css` without Bootstrap get it prepended automatically. Run
  `npm run build` after upgrading.
- Site and messenger styles use logical CSS properties (`inline-start`/`inline-end`,
  `inset-inline-*`, logical border radii) instead of left/right.
- Vitest workers disable Node's experimental `localStorage` to avoid a
  `--localstorage-file` warning on Node 25+.

## [0.2.3] - 2026-10-02

### Added

- Complete English and Russian localization for the administration interface,
  including built-in and module-provided navigation, dashboard cards, form
  labels, notifications, and server-side validation errors.
- Locale coverage checks that require every supported language to provide the
  complete admin catalog.
- Complete admin user management with server-side pagination, search, role and
  status filters, and editing of account identity, role, and activation state.
- A personalized admin dashboard with versioned per-administrator layouts,
  configurable card visibility and size, accessible drag-and-drop ordering,
  keyboard controls, and default-layout restoration.
- A validated dashboard-card extension contract for modules, including
  permission-aware discovery, isolated data providers, generic metric, link,
  and list renderers, and per-card refresh endpoints.
- Dashboard cards for user and content totals, recent registrations and
  content, pending community membership requests, cron and notification queue
  health, and common administration shortcuts.
- A complete menu editor with explicit enabled state, tree and group ordering,
  an ACL-aware preview for the current administrator, and deliberate child
  handling when deleting parent items.

### Changed

- Admin date, time, number, and byte formatting now follows the locale selected
  in CMS settings instead of the browser locale; saving a locale change reloads
  the admin shell so labels and formatting switch together.
- Module admin pages now declare a translation `labelKey`; missing translations
  fall back to English and then to the visible key.
- Deactivating an account from the admin interface now revokes all of that
  user's active sessions immediately.
- Dashboard provider failures are isolated to their own cards, which expose
  explicit loading, empty, unavailable, and error states without preventing
  the rest of the dashboard from rendering.
- Menu order is now persisted as a single atomic tree update instead of through
  manually entered numeric positions.

### Security

- Enforced admin user mutations and dashboard layout changes on the server
  with authorization, validation, and CSRF protection independent of the UI.
- Protected the reserved system account, prevented administrators from
  changing their own role or active status, and prevented demotion or
  deactivation of the last active administrator.
- Made unknown and unauthorized dashboard card IDs indistinguishable to API
  clients to avoid disclosing restricted module cards.
- Validated module-provided dashboard payloads by card kind and restricted
  dashboard links to internal admin routes before they reach the browser.
- Validated menu page targets and frontend action hooks in the application
  layer before saving them.

## [0.2.2] - 2026-09-19

### Added

- A read-only admin catalog of page actions exported by module controllers through `pageActions()`.
- A profile page is now created during installation.

### Changed

- Split dual-purpose article, community, and blog-post show page actions into explicit `-id` and `-slug` actions, including migration of existing pages.
- Added automatic dark-mode support to the admin interface.
- Made page update timestamps server-managed and removed them from the Pages editor.
- Replaced free-form action, feed type, list feed type, and parent ID fields in the Pages editor with searchable selectors while preserving unknown legacy values.
- Extended page actions with backend-enforced configuration contracts for feed IDs, feed types, list feed types, and term vocabularies, including constrained and compound requirements.
- Made the Pages editor explain and validate each action's supported fields, constrain feed-type choices, preserve legacy configuration, and warn about suspicious feed bindings and route patterns.
- Made the feedback page's hint feed optional; pages without a feed now use the configured page name and render without hint content or feed metadata.

### Fixed

- Allowed the admin feed editor to save feeds with an empty slug where the feed type permits it.
- Prevented page hierarchy cycles in the admin editor, enforced an empty pattern and no parent for the root page, and made runtime ancestor traversal fail fast on already-corrupt cyclic data.
- Prevented production startup failures when optimized Composer classmaps contain optional integration classes whose development-only dependencies are not installed.
- Preserved trailing slashes in generated sitemap URLs.

## [0.2.1] - 2026-09-17

### Added

- A complete favicon set for modern browsers, legacy clients, and Apple touch icons — at long last.

### Changed

- Made Composer and Packagist the canonical distribution channel, with npm kept only for frontend development tooling.

## [0.2.0] - 2026-09-17

### Added

- Explicit URL ownership for generated public pages, fixed versioned API endpoints, and resource paths.
- Query parameters and URI fragments for generated action, page, feed, term, and listing URLs, with RFC 3986 encoding and consistent `?query#fragment` ordering.
- Frontend redirects for registration, feedback, and direct messages now use same-origin-validated page URLs resolved by backend routing instead of hard-coded route paths.
- New `footer_legal` widget placement for legal information in the site footer.
- Admin menu management with nested items, menu groups, link types, access rules, ordering, and protected CRUD API endpoints.

### Changed

- Centralized request error handling with JSON responses for API routes, HTML error pages elsewhere, and safe logging for internal failures.
- Centralized application-environment reads in `Config`, including development-mode behavior in `StreamEngine` and `PdoDatabase`.
- Canonical links in account emails and user notifications now use the configured `SITE_URL` instead of request host data, with explicit handling when the canonical URL is missing.
- Redesigned the default user avatar and applied it to users without a custom avatar in the site header.
- Made article, forum, community, and blog-post routes resolve feeds within their declared type and parent scope instead of selecting an ambiguous global slug match.
- Made an external once-per-minute scheduler the production cron model, while reducing the request-driven fallback from 49 out of 50 requests to 1 out of 50.
- Bounded feed list and search page sizes, respected search limits, rejected structured comment content, and made unsupported API methods consistently return 405.
- Added controller-level authorization to mutating API endpoints and stopped message edits from exposing whether another user's message is outside the editing window.

## [0.1.0] - 2026-09-14

### Added

- Initial version.
