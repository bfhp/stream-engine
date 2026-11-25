# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project intends to follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.8.5] - 2026-11-25

Feed listings and sitemaps use indexed queries that avoid loading skipped
feed bodies. Existing visibility rules and the Twig security update are kept.

### Changed

- Feed sitemaps no longer use the row-level ACL.
  `FeedRepository::findPublicByTypePage()` takes a slice of every feed of the
  type by `feeds_type_created_at_id_index` alone and keeps the guest-visible
  rows (public, in a public container or none), with PRIMARY lookups only.
  The sitemap index sizes files from the index-only
  `FeedRepository::countAllByType()`, so a file can list fewer than 30,000
  URLs. Gallery photos keep the ACL-filtered query. The ACL queries this
  replaces read the whole `feeds` table, content included (`docs/TODO.md`).
- The row-level feed ACL is rebuilt from per-type rules, each lookup a
  scalar subquery on a primary key, with no `EXISTS`. Queries pinned to one
  feed type apply only that type's rules: `countByType('lib-page')` for a
  member is 24 lines of SQL instead of 238, comments 53, gallery photos 48;
  untyped queries choose the rules by each row's type (127 lines). A comment
  joins its publication once instead of repeating every rule through
  `CASE`. The optimizer executed the same `EXISTS` as a unique lookup, a full
  index scan or a table-wide materialization depending on statistics. The
  rules are unchanged: `tests/Repository/FeedAclEquivalenceTest.php` compares
  every ACL-filtered read with the previous predicate
  (`tests/Support/LegacyFeedAcl.php`) for every user and type of a fixture
  covering all of them.
- `feeds_type_created_at_id_index` also holds the columns the ACL reads
  (`visibility`, `container_id`, `owner_id`, `parent_id`), so typed counts and
  the ids of typed pages come from the index alone. `findByType()` and
  `findByTypePage()` select the ids of a page first and then only that page's
  rows (deferred join); `findByTypePage()` counts its total separately instead
  of `COUNT(*) OVER ()`, and not at all when the first page is not full.
- Local Docker development and the accessibility workflow use MariaDB 11.8.
  The PHP CI matrix now covers MariaDB 11.8, MariaDB 12, and MySQL 8.4.

### Removed

- The Memcached sitemap cache and the `sitemap:warm` scheduler task. Sitemap
  XML is generated on request; the PHP `zlib` extension is no longer required.
- `FeedService::eachFeedUrlBatchByType()` and the optional `$withContent`
  argument of `FeedRepository::findByTypeAfter()`. Extensions using these
  APIs must update their calls. `SitemapController` no longer takes a
  `CacheInterface` constructor argument.

### Kept from 0.8.2-0.8.4

- Twig 3.31.1 (security fixes).
- Sitemap chunks numbered 10 and up are served (0.8.3).
- `robots.txt` answers 304 only for an `If-Modified-Since` at or after its
  last change (0.8.2).
- URL generation (`FeedRepository::hydrateTree()`) does not select content
  (0.8.4); a 30,000-URL sitemap otherwise loads every page text.

### Upgrade notes

- Deploy the release with its bundled browser assets, run
  `composer install --no-dev`, then `composer migrate`. Migration
  `20261125000000_extend_feeds_type_index_with_acl_columns` rebuilds one
  index of `feeds` online (`ALGORITHM=INPLACE, LOCK=NONE`): the table stays
  readable and writable. Build duration depends on table size and database
  load; the migration changes no feed data.
- The installation snapshot includes all 24 migrations. Updated
  `schema.sql`, `manifest.json`, and SHA-256 checksums are attached to the
  GitHub Release. Existing sites must use the migration, not import the
  installation snapshot.
- Remove any external jobs invoking `sitemap:warm`; the normal once-per-minute
  scheduler should continue running for the remaining tasks.

## [0.8.4] - 2026-11-24

### Fixed

- Large feed sitemaps (`sitemap-lib-page-11.xml`) killed the PHP worker at a
  128 MB memory limit, which Nginx reported as 502, and the `sitemap:warm`
  task failed the same way. URL generation (`FeedRepository::hydrateTree()`)
  selected every feed's content although only the tree is needed, so a
  30 000-URL sitemap loaded the text of 30 000 library pages. It now selects
  no content, which also lightens URL generation for every feed list.
- Feed sitemaps are built in batches of 1 000 through the new
  `FeedService::eachFeedUrlBatchByType()`: the first batch seeks to the
  chunk's offset, later ones continue from the last row by
  `(created_at, id)`, and only canonical URLs are resolved. This limits the
  number of fully hydrated feed objects held at once while retaining the
  existing URL order and 30,000-URL chunk size.
  `FeedRepository::findByTypeAfter()` takes an optional `$withContent` argument,
  which defaults to including content for existing callers.

### Upgrade notes

- From 0.8.3, deploy the release and install PHP dependencies from the lockfile.
  No dependency updates or configuration changes are required. Earlier
  versions must also follow the intervening release notes.
- The hourly `sitemap:warm` task uses the same lower-memory generation path.
  To regenerate sitemap files immediately after deployment, run
  `php bin/cron.php --task=sitemap:warm` as the scheduler account or use
  **Administration → Scheduler → sitemap:warm → Run now**.
- No new database migrations or schema changes are required. The installation
  snapshot was regenerated from all 23 migrations: `schema.sql` is unchanged
  from 0.8.3, and its matching manifest identifies 0.8.4. Both files are
  included in the release source and attached to the GitHub Release with
  checksums. Existing sites must not import `schema.sql`.

## [0.8.3] - 2026-11-24

### Fixed

- Sitemap chunks 10-19, 100 and every other number starting with 1
  (`sitemap-lib-page-10.xml`, `sitemap-term-author-11.xml`) answered 404 although
  the index listed them: the chunk suffix only accepted a first digit of 2-9.
  Any chunk from 2 up is now served; `-1` and leading zeros still answer 404.

### Upgrade notes

- From 0.8.2, deploy the release and install PHP dependencies from the lockfile.
  No dependency updates or configuration changes are required. Earlier
  versions must also follow the intervening release notes.
- The hourly `sitemap:warm` task now processes the previously rejected chunk
  numbers. To refresh immediately after deployment, run
  `php bin/cron.php --task=sitemap:warm` as the scheduler account or use
  **Administration → Scheduler → sitemap:warm → Run now**.
- No new database migrations or schema changes are required. The installation
  snapshot was regenerated from all 23 migrations: `schema.sql` is unchanged
  from 0.8.2, and its matching manifest identifies 0.8.3. Both files are
  included in the release source and attached to the GitHub Release with
  checksums. Existing sites must not import `schema.sql`.

## [0.8.2] - 2026-11-24

### Changed

- Type-filtered feed queries now evaluate only the access-control branches
  that can match that type, avoiding unrelated comment and gallery checks
  and expensive MariaDB materialization. Guest queries omit owner and
  membership checks while retaining public visibility, container access,
  and gallery publication rules.
- Sitemap XML is cached gzip-compressed through `CacheInterface`. The new
  hourly `sitemap:warm` task regenerates the index and every listed file, so
  cached crawler requests avoid repeated feed counts and large URL queries.
  Sitemap responses use `Cache-Control: public, max-age=3600`; server cache
  entries expire after six hours if the scheduler stops.
- Feed sitemaps fetch URLs and dates without loading full content bodies,
  reducing memory use for files containing up to 30,000 URLs.

### Fixed

- Type-filtered feed queries retain comment inheritance and gallery publication
  checks for case-, accent-, or trailing-space variants of type names under
  case-insensitive database collations. Noncanonical type filters use the full
  access predicate; canonical type names retain the optimized query.
- `robots.txt` answered 304 to any `If-Modified-Since` header, however old;
  it now does so only when the date is at or after the file's last change.

### Security

- Updated Twig from 3.30.0 to 3.31.1 to include upstream escaping and sandbox
  security fixes. See the [Twig release notes](https://github.com/twigphp/Twig/releases/tag/v3.31.1).

### Upgrade notes

- From 0.8.1, deploy the release with its bundled browser assets and run
  `composer install --no-dev` from the updated lockfile to install the Twig
  security update. Earlier versions must also follow the intervening release
  notes. The PHP `zlib` extension is now required for sitemap compression;
  Composer and installer preflight check it. The bundled PHP image includes it.
- Keep Memcached reachable and the once-per-minute scheduler running so the
  new hourly `sitemap:warm` task can refresh cached sitemap content. Changes
  appear after refresh; a stopped scheduler can leave cached XML in place for
  up to six hours. Cache misses regenerate on request. For an immediate refresh,
  run `php bin/cron.php --task=sitemap:warm` as the scheduler account or use
  **Administration → Scheduler → sitemap:warm → Run now**.
- No new database migrations or schema changes are required. The installation
  snapshot was regenerated from all 23 migrations: `schema.sql` is unchanged
  from 0.8.1, and the matching manifest identifies 0.8.2. Both files are
  included in the release source and attached to the GitHub Release with
  checksums. Existing sites must not import `schema.sql`.

## [0.8.1] - 2026-11-24

### Added

- Administrators can delete a feed from its editor after confirming the
  deletion, through the CSRF-protected `DELETE /api/v1/feeds/{id}` endpoint.
  Comments, metadata, ratings, and child feeds are removed with it. Deletion
  is refused with HTTP 409 when the feed is bound to a page or contains
  gallery photographs, preserving page references and protected photo files.

- An HTML page requested without its trailing slash (`/forums`) is redirected
  with a 301 to the slashed form (`/forums/`) that `UrlGenerator` and
  `rel="canonical"` already use, so every page has one address. GET and HEAD
  only, after the access check (a hidden page still answers 404), query string
  kept; API routes and file names with a dot (`robots.txt`) are left alone.
  On by default; **Settings → Redirect to the trailing-slash URL**
  (`seo.trailing_slash_redirect`) turns it off.

### Changed

- `<meta name="description">` and `og:description` are cut to 255 characters
  on a word boundary, with "…" marking the cut, by the new `meta_description`
  Twig filter (tags and entities stripped). A blog or community post without
  its own description used to put its whole text there.

- Cron tasks run in parallel, each in its own process. A scheduler tick
  (`bin/cron.php`) now only starts `bin/cron.php --task=<name> --scheduled` in
  the background for every due task and exits, so a long task no longer holds
  back the others until it finishes. The worker re-checks the interval and takes
  the task's database lock, so duplicate dispatches are refused.
  `CronRunner::runScheduledTask()` and `StreamEngine::runScheduledCronTask()`
  are the worker entry points; `CronTrigger::spawnScheduledTask()` starts one.

### Fixed

- Pages without `rel="canonical"` got one, so their query-string variants are
  no longer indexed as separate copies: a gallery photo (canonical without
  `?discussion=1` / `?comment_parent` / `?comment_cursor`), an album and the
  album list (keeping `?offset`), the photo showcase (keeping `?sort=best` and
  `?offset`), registration and password recovery (without `?success=1`), and a
  feedback page without a bound feed. Token-only pages - registration
  verification, password reset, email confirmation and unsubscribe - send
  `noindex` instead. The developer guide (Pages and routing) now states that
  every public HTML page must pass `canonical`.

- A forum's title and description were HTML-escaped twice - by the controller
  and again by Twig - so `&` or quotes showed as `&amp;` / `&quot;` in the
  forum header, its breadcrumb and the description meta tags.

- The admin system health check counted every engine migration as pending when
  the engine is installed as a Composer dependency: it read
  `storage/migrations.json` and the application's `migrations/` from the engine
  package instead of the application root that `bin/migrate.php` uses.

- The users list keeps its height while the first page loads
  (`aria-busy="true"` on `[data-users-list]`), so the footer no longer jumps
  down when the cards appear.

### Upgrade notes

- From 0.8.0, deploy the release with the complete bundled `public/assets/`
  directory and install PHP dependencies from the lockfile. Earlier versions
  must also follow the intervening release notes. No dependency updates are
  required.
- **systemd schedulers:** add `KillMode=process` to the `Type=oneshot` service
  that runs the scheduler tick and reload its unit configuration. Otherwise
  systemd terminates background task workers when the tick exits. Traditional
  cron and Supercronic need no configuration change. Docker Compose users
  should recreate the scheduler service to apply its new `init: true` setting.
  See [Scheduler](https://github.com/bfhp/stream-engine/wiki/Administrator-Scheduler).
- Scheduled tasks now overlap across different task names; each task retains
  its database lock and interval checks. The scheduler account must be able
  to launch PHP CLI background workers. Custom tasks must not depend on the
  previous serial execution order.
- Trailing-slash redirects are enabled on existing sites by default. They can
  be disabled in **Administration → Settings**. Browsers may retain a cached
  301 after the setting is disabled; both address forms continue to resolve.
- Custom themes overriding description metadata should apply the
  `meta_description` filter to receive the 255-character plain-text limit.
  Custom public page controllers should supply `canonical`; token-only pages
  should send `noindex`, as described in the updated pages and routing guide.
- No new database migrations or schema changes are required. The installation
  snapshot was regenerated from all 23 migrations: `schema.sql` is unchanged
  from 0.8.0, and its matching manifest identifies 0.8.1. Both files are
  included in the release source and attached to the GitHub Release with
  checksums. Existing sites must not import `schema.sql`.

## [0.8.0] - 2026-11-23

### Changed

- Built browser assets are named by their content (`js/site-<hash>.js`,
  `css/site-<hash>.css`, …) and can be cached permanently. The build writes
  `public/assets/.vite/manifest.php`, and the new `Core\Assets` with the Twig
  helpers `asset_tags()`, `asset_styles()` and `asset_import_map()` renders
  each Vite entry's stylesheets, module preloads and script from it. Pages
  name entries instead of files: controllers pass `'entries' => ['forums']`
  instead of `<script>`/`<link>` strings in `head_ext`, and stylesheets an
  entry imports (Trix's, for example) are linked automatically.
- Theme manifests list Vite entries: `assets.entries` replaces `/assets/…`
  paths in `assets.styles`/`assets.scripts`, and `assets.rtl` maps entry names
  (`"bootstrap": "bootstrap-rtl"`). `assets.styles` is now only for a theme's
  own static stylesheets below `/themes/<id>/`; `assets.scripts` is removed.
- Browser translations are served per locale through an import map. The
  build writes one content-hashed module per locale and scope
  (`js/i18n/<locale>-<hash>.js`, `js/i18n/<locale>-admin-<hash>.js`) with the
  English fallback merged in; the layouts map `stream-engine-translations`
  (and, in the admin panel, `stream-engine-translations/admin`) to the page
  locale's files. A page downloads one locale instead of every locale bundled
  into `i18n.js`, and public pages no longer receive administration strings.
  Administration code imports `trans()` from `admin/i18n.ts`.
- Default avatar and community images are copied unhashed from
  `assets-src/public/` (they are referred to by fixed paths).
- The translation build plugin accepts multiple language directories, with
  later directories overriding earlier ones, so projects can combine engine
  catalogs with their own translations.

### Fixed

- The cookie notice appears with the page through a small inline script,
  without waiting for the site bundle and its translations. This avoids a
  delayed notice becoming the Largest Contentful Paint element on phones;
  dismissal and keyboard behavior remain handled by the site bundle.
- Both bundled themes reserve the header logo's space using its known image
  dimensions, avoiding layout shifts when the image loads.
- Forum signatures request `http://` image sources over HTTPS. Image-only
  links with empty alternative text receive an accessible name from the
  destination host; existing alternative text and link labels are preserved.
  Stored signatures are unchanged.
- Pages without a description fall back to their title in description and
  Open Graph metadata. Forum topics without an explicit description use a
  plain-text excerpt of the opening post, with Unicode-safe truncation.

### Upgrade notes

- This release changes the browser asset, theme, and translation contracts.
  Custom themes, site modules, and Vite configurations need the adaptations
  below. Version 0.7.7 was not released; upgrade directly from 0.7.6 to 0.8.0.
- From 0.7.6, deploy the release and install PHP dependencies from the
  lockfile. Deploy the complete bundled `public/assets/` directory together
  with the matching PHP and Twig files.
  Earlier versions must also follow the intervening release notes. No
  dependency updates are required.
- Projects with their own Vite configuration must adopt the engine's:
  content-hashed output names, `publicDir: assets-src/public`, and the
  `translationsPlugin` and `assetManifestPlugin` from the engine's
  `vite-build-data.ts` (drop any copy of that file). Rebuild browser assets;
  pages fail with "Browser assets are not built" until
  `public/assets/.vite/manifest.php` exists.
- Controllers and templates of site modules: replace asset tags in
  `head_ext` with `'entries' => ['<entry>']`, and links to built files with
  `asset_tags()`/`asset_styles()`. Custom themes: move `/assets/…` paths from
  `assets.styles`/`assets.scripts` to entry names in `assets.entries`.
- Layout overrides must preserve `head_assets`, which replaces `head_css`
  and `head_js`, and render `asset_import_map()` before module scripts and
  preloads. The administration layout uses `asset_import_map(true)` to make
  both public and administration translations available.
- Site code that translates `js.admin.*` keys must import from
  `@stream-engine/admin/i18n` instead of `@stream-engine/shared/i18n`.
- Web server: serve `/assets/` with a long-lived `Cache-Control` (built files
  change name on every change), except the unhashed `/assets/img/`, and deny
  `/assets/.vite/`. See the updated Nginx example in the installation guide.
- No new database migrations or schema changes are required. The installation
  snapshot was regenerated from all 23 migrations: `schema.sql` is unchanged
  from 0.7.6, and its matching manifest identifies 0.8.0. Both files are
  included in the release source and attached to the GitHub Release with
  checksums. Existing sites must not import `schema.sql`.
- Custom themes should preserve the updated cookie-notice partial and logo
  dimension attributes to receive the rendering improvements. External
  signature image hosts must support HTTPS; this change is applied when
  rendering and does not rewrite saved signatures.

## [0.7.6] - 2026-11-22

### Fixed

- Hidden widget placements now carry through to module-created pages beneath
  their nearest administration-managed page, including profile and community
  posts, photos, editors, and forum topics. A profile's hidden sidebar, for
  example, stays hidden on its posts. The page editor explains this behavior
  in all supported languages.
- Administration-managed child pages retain their own widget visibility
  choices. A module-created page can explicitly override inherited visibility,
  including choosing to show every placement.
- The administration dashboard reads recent users and content through new
  indexes on `(created_at, id)`, avoiding whole-table sorts. Pending and failed
  notification counts use the existing delivery queue index instead of
  scanning the complete delivery history.

### Upgrade notes

- From 0.7.5, deploy the release with its bundled browser assets, install PHP
  dependencies from the lockfile, and run `php bin/migrate.php migrate`.
  Confirm `php bin/migrate.php status` reports `Pending: 0`. Earlier versions
  must also follow the intervening release notes. No dependency updates are
  required.
- Migration `20261122000000_add_recent_activity_indexes` adds
  `feeds_created_at_id_index` and `users_created_at_id_index`. It changes
  indexes only and preserves existing content and accounts. Allow time and
  disk space for index creation on large tables.
- The fresh-install snapshot was regenerated from all 23 migrations for
  0.7.6. The updated `schema.sql` and matching `manifest.json` are included in
  the release source and attached to the GitHub Release. Use them for fresh
  installations; existing sites must run the migration instead of importing
  `schema.sql`.
- Previously hidden widget placements now also affect the module-created
  pages beneath them. Review profile, community, and forum page settings if
  those child pages should continue displaying a placement.

## [0.7.5] - 2026-11-21

### Added

- A dismissible cookie notice in both the default and Bootstrap themes,
  enabled by default and configurable in **Administration → Settings**.
  Dismissal is remembered in the visitor's browser for one year. The notice
  supports keyboard navigation, adapts page spacing to its height, and includes
  translations in all supported languages.
- Per-page widget visibility in the page editor. Administrators can hide
  individual placements, including “Inside <head>”, sidebars, and footer
  widgets. Existing pages continue to show every placement by default.

### Changed

- Faster forum index, topic lists, and unread markers: newest replies are
  resolved through existing indexes instead of ranking every reply. Topic
  reply counts are calculated only for the requested page of results.
- Feed listing methods in `FeedService` and `FeedRepository` accept an optional
  `withContent: false` argument to omit full post bodies from card-style lists.
  Content remains included by default; resume-link lookups for unfinished
  feeds now use the lighter query automatically.

### Upgrade notes

- From 0.7.4, deploy the release with its bundled browser assets and install
  PHP dependencies from the lockfile. Earlier versions must also follow the
  intervening release notes. No dependency updates are required.
- No new database migrations or schema changes are required. The installation
  snapshot was regenerated from all 22 migrations: `schema.sql` is unchanged
  from 0.7.4, and the matching manifest now identifies 0.7.5. Both files are
  included in the release source. Existing sites must not import `schema.sql`.
- The cookie notice appears on existing sites after upgrading unless disabled
  in **Administration → Settings**. Custom themes can override
  `partials/cookie-notice.twig`; themes replacing the base layout should
  preserve its `layout_cookie_notice` block to display the notice.
- Widget visibility is stored in the existing page settings, without a data
  migration. Custom themes should render the supplied `widgets` placements
  to respect each page's visibility choices.

## [0.7.4] - 2026-11-19

### Added

- A new “Inside <head>” widget placement in the administration interface.
  Administrators can add meta tags, styles, and scripts immediately before
  `</head>`, without a wrapping element, in both the default and Bootstrap
  themes. Labels and help text are available in all supported languages.

### Fixed

- Guests can use “Mark all as read” across the forum or within a section and
  its direct subforums in both themes. Bulk read timestamps are stored in the
  visitor's browser cookies independently of the per-topic history, so marking
  a large forum read does not overflow the 100-topic history limit. New topics
  and replies after the mark appear unread again; registered users retain
  their existing account-based read tracking.
- Introductory content on article-list and section-list pages now aligns with
  the theme's content container, including Bootstrap layouts.

### Upgrade notes

- From 0.7.3, deploy the release with its bundled browser assets and install
  PHP dependencies from the lockfile. Earlier versions must also follow the
  intervening release notes. No dependency updates or configuration changes
  are required.
- No new database migrations or schema changes are required. The installation
  snapshot was regenerated from all 22 migrations: `schema.sql` is unchanged
  from 0.7.3, and the matching manifest now identifies 0.7.4. Both files are
  included in the release source. Existing sites must not import `schema.sql`.
- Guest bulk read state is specific to the browser and is cleared with its
  cookies. The cookie lasts up to one year and retains the global timestamp
  plus up to 100 recent section timestamps; older section-specific marks may
  roll off. It does not transfer into an account on sign-in.
- Custom themes that replace the base head layout should preserve the new
  `head_widgets` block or render `widgets.head` without a wrapping element.

## [0.7.3] - 2026-11-18

### Fixed

- Authors can no longer use publication ownership to bypass a closed forum
  section or category. Without access to the effective container, their own
  topics and replies are excluded from direct reads, search, listings, and
  counts, and cannot be edited, deleted, or replied to.
- Comment authorization now rechecks the inherited publication ACL, including
  when a caller holds a previously loaded comment object. Closing a container
  or revoking membership takes effect on subsequent authorization checks.

### Security

- Container access is checked before publication ownership in both SQL reads
  and service authorization. This is a shared ACL correction for all feed
  containers, including communities; a publication's public visibility or
  authorship does not override a closed container. Administrators and global
  content moderators retain their override. Container owners and authorized
  local moderators retain access under the existing container policy.
- The known build/test dependency advisory
  [GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf)
  remains unresolved. npm audit still reports six high-severity affected
  packages through picomatch and its dependents. This release does not update
  dependencies or suppress the failing dependency audit.

### Upgrade notes

- From 0.7.2, deploy the release with its bundled browser assets and install
  PHP dependencies from the lockfile. Earlier versions must also follow the
  intervening release notes.
- No database migrations, schema changes, or dependency updates are required.
  The installation snapshot was regenerated from all 22 migrations:
  `schema.sql` is unchanged from 0.7.2, and its matching manifest identifies
  0.7.3. Existing sites must not import `schema.sql`.
- The ACL correction applies immediately to existing content. Review membership
  of closed sections, categories, and other containers: authors must have
  container access to continue working with their own publications. Adding
  an accepted member back restores access according to container visibility;
  moderator-only containers still require a local moderator role or higher.
  Authorship and content are preserved. Reverting to 0.7.2 restores the previous
  ownership bypass.
- Authors retain their usual ownership permissions inside containers they can
  access. Direct file URLs retain their existing access behavior.

## [0.7.2] - 2026-11-18

### Added

- Closed forum categories: their sections, topics, and replies inherit the
  category's visibility and shared membership through the existing ACL.
- Category administrators and moderators can manage inherited sections from
  the forum management pages. A section shows its inherited participants and
  links to the category where membership is edited.

### Changed

- Moving a section into a closed category applies that category's access and
  participants. A section's separate membership cannot bypass category access.
- Each section retains its own visibility setting. Reopening the category or
  moving a section out restores the section's own visibility and membership,
  including sections that were already private.
- Category access and membership inheritance is implemented entirely in the
  Forums module using the existing feed metadata and ACL contracts.

### Security

- The known build/test dependency advisory
  [GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf)
  remains unresolved. The current npm audit reports six high-severity affected
  packages through picomatch and its dependents; this is the same advisory
  documented in 0.7.1. The dependency audit remains failing and is not
  suppressed. PHP dependencies have no reported advisories.

### Upgrade notes

- From 0.7.1, deploy the release with its bundled browser assets and install PHP
  dependencies from the lockfile. Earlier versions must also follow the 0.7.1
  upgrade notes.
- There are no new database migrations or dependency updates. The installation
  snapshot was regenerated from all 22 migrations: `schema.sql` is unchanged
  from 0.7.1, and the matching manifest now identifies 0.7.2. Existing sites
  must not import `schema.sql`.
- Closing a category updates access for its existing sections and topics.
  Review the category participants before changing visibility; access to
  descendants follows the category. Existing owner and global moderation
  permissions continue to apply. Direct file URLs retain their existing access
  behavior.

## [0.7.1] - 2026-11-18

### Added

- Forum management on the public site for administrators and moderators,
  including settings, category moves, visibility, and local membership roles.

- Forum section access through the existing container membership ACL, with
  atomic propagation to topics and inherited access for new discussions.

- Site extensions: a site can change selected platform behavior with classes
  extending `StreamEngine\Extension\Extension`, discovered from the Composer
  classmap, with the shared services available as properties
  (`$this->db`, `$this->accessService`, …). First
  extension point: `verifyPasswordFallback`, which lets a site accept a
  password the engine's hash rejected. Run `composer dump-autoload -o` after
  adding, renaming, or removing an extension class.

- `UserRepository::updatePasswordHash()` for storing a new password hash.

- The administration **System status** page lists active site extensions and
  the extension points each one handles.

- Administration system health checks for PHP components, database access,
  pending migrations, cache, SMTP, writable storage and temporary directories,
  Twig cache, and free disk space. Results distinguish scheduled and manual
  checks, show stale results, and notify administrators of confirmed failures
  and recovery through the notification system.

### Changed

- `ControllerFactory` delegates constructor resolution to the new
  `ServiceResolver`, which also builds site extensions. Controller
  construction behaves as before.

- Updated Vite, the React Vite plugin, and Rolldown within their existing
  supported version ranges, and rebuilt production browser assets.

- npm dependency auditing now runs in a separate workflow on pushes, pull
  requests, weekly, and on demand, with its own README status badge. High and
  critical findings still fail the audit; frontend tests and builds can run
  independently. CI installation skips the duplicate implicit npm audit.

### Fixed

- Admin Feed list filters, sorting and limit now persist in the URL when
  returning from the editor or reloading the page.

- Article sections and their article lists respect the configured position
  order.

### Security

- Documented the unresolved picomatch ReDoS advisory
  [GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf)
  in the build/test dependency chain. No path from application HTTP requests
  to its glob-pattern inputs was found in the reviewed code. No patched
  upstream release was available at release preparation; the dependency
  audit remains failing, and this release does not fix or suppress the issue.

### Upgrade notes

- From 0.7.0, deploy the release and bundled browser assets, install PHP
  dependencies from the lockfile, and refresh Composer's optimized autoloader
  (`composer dump-autoload -o`) so site extensions can be discovered. Rebuild
  frontend assets only when deploying from sources without bundled output.
- There are no new database migrations. The fresh-install snapshot was regenerated: `schema.sql` is unchanged from
  0.7.0, and its matching manifest now identifies 0.7.1. Existing sites must
  not import `schema.sql`.
  Sites upgrading from an older release must also follow the 0.7.0 upgrade notes.
- Ensure the existing scheduler runs `bin/cron.php` once a minute. It discovers
  the new `system:health` task, which runs every five minutes. Health incidents
  can generate administrator notifications; check SMTP and delivery settings.
- Site extensions execute trusted PHP code. The password fallback is optional
  and only an explicit `true` from an extension accepts a password rejected by
  the engine's hash check. Sites without extensions retain normal authentication.

## [0.7.0] - 2026-11-13

### Added

- Photo albums for personal blogs and communities, with bulk uploads, editing,
  comments, ratings and a configurable showcase with rankings. Includes
  accessibility tests for both themes, light/dark modes and RTL.
- Album covers, drag-and-drop ordering, moving photos between albums in the
  same context, bulk deletion, and keyboard-accessible photo viewing.
- Protected original and preview images (JPEG, PNG and WebP), configurable
  upload limits, retry-safe publication and cleanup of orphaned photo files.

### Changed

- Admin dashboard layouts now use cookies instead of the `admin_dashboard_layouts`
  table; existing layouts are reset. Dashboard services now live in the Admin module.
- Existing Feed types can no longer be changed through the editor or API.
  Integrations must choose the type when creating content.

### Security

- Messenger attachments now use private storage and check conversation access
  before delivery, with shared file delivery.
- Photo pages, comments, rankings and file delivery enforce access to the
  photograph, album and parent blog or community. Photos cannot be self-rated.

### Upgrade notes

- From 0.6.2, back up the database and persistent files, deploy the release
  and bundled browser assets, install dependencies from the lockfiles, and run
  `composer migrate`. The new migration
  `20261113000000_drop_admin_dashboard_layouts` removes the obsolete dashboard
  table; dashboard arrangements must be configured again.
- The fresh-install `schema.sql` and `manifest.json` are regenerated for 0.7.0.
  Galleries reuse the existing Feed, metadata and upload tables. Use migrations
  for existing sites; do not import the snapshot over an existing database.
- Deploy the Nginx `internal` `/protected-uploads/` location from
  `docker/nginx/default.conf`. Its alias must match `UPLOADS_DIR` plus
  `-private` (by default `storage/uploads-private/`). Make this directory
  writable by PHP and include it in backups; private file delivery requires
  Nginx support for `X-Accel-Redirect`.
- Existing messenger attachments are not automatically moved from public
  storage or assigned the new `message-attachment` purpose. Their old public
  URLs remain accessible, while the new conversation attachment endpoint
  rejects them. Plan a separate file/data migration before upgrading a site
  that needs to preserve attachment access; this release does not retroactively
  protect previously uploaded files.
- Database rollback requires the matching backup; down migrations are not
  provided. The reviewed low-severity Chonky/deepmerge advisory remains
  documented in `SECURITY.md`.

## [0.6.2] - 2026-11-12

### Changed

- Updated frontend dependencies, including React, React Router, Vite,
  Playwright, axe-core, Chonky and icon libraries. Rebuilt production assets
  and refreshed the third-party license report.
- Updated PHP dependencies: phpdotenv, crawler-detect, PHPUnit and Symfony
  polyfills.
- Updated the GitHub Actions SARIF uploader used by Semgrep.

### Fixed

- Theme selection cards and the file browser's new-folder button now expose
  accessible names that include their visible labels.

### Upgrade notes

- From 0.6.1, install dependencies from the updated Composer lockfile and deploy
  the bundled browser assets. No new database migrations or schema changes
  are required. The installation manifest is refreshed for release 0.6.2.
- The existing Chonky/deepmerge low-severity advisory remains covered by the
  reviewed mitigation in `SECURITY.md`; this release does not claim an upstream
  fix.

## [0.6.1] - 2026-11-12

### Added

- Added `articles.list-id`, `articles.list-slug`, `articles.index` and `articles.by-term` page actions; list page size is configurable and related articles are opt-in.
- Audio uploads store title and artist tags; the player and its accessible label
  use them, falling back to the post title and filename.

### Fixed

- Module discovery no longer loads global vendor classes that can terminate PHP.

### Security

- Rate-limited login and password recovery; password resets are atomic and
  invalidate existing sessions.
- Enforced parent-content access for comments, disabled caching of private
  responses, and restricted CSS in user-authored content.
- Hardened upload response headers and documented secret-URL access for private
  attachments; verified isolation of file-browser data from vulnerable theme merges.

### Upgrade notes

- Back up the database and persistent files, install dependencies from the
  lockfiles, and run `composer migrate`. This release includes
  `20261101000000_rename_articles_list_action`,
  `20261102000000_add_auth_rate_limits`, and
  `20261108000000_add_upload_audio_tags`.
- Stored `articles.list` actions are migrated to `articles.list-slug`; update
  module definitions that still use the old action name.
- The fresh-install schema and manifest are regenerated for 0.6.1. Use
  migrations for existing installations; do not import `schema.sql` over them.
- Deploy the bundled rebuilt browser assets and updated Nginx configuration
  for hardened upload response headers. Private attachments use secret URLs;
  see `SECURITY.md` for the access model and its limits.
- Dependency audit: the Chonky/deepmerge chain retains three low-severity
  advisory entries with no upstream fix. File-browser data is isolated from
  vulnerable theme merges; the reviewed mitigation is documented in `SECURITY.md`.
- Database rollback requires restoring the matching backup; down migrations
  are not provided.

## [0.6.0] - 2026-10-30

### Added

- MySQL 8.4 LTS or newer is supported alongside MariaDB 10.10 or newer; the
  installer and migrations adapt the schema to the server. Moving a site
  between the two is not supported.
- Feed editor: a Metadata section for name/value pairs. Page editor: a feed
  picker with search and in-place feed creation.
- "Show password" buttons on password fields; password reset asks for the new
  password once.
- Comment author names link to the author's profile.
- An "Accessibility problem" issue form linked from the accessibility
  statement.

### Changed

- The administration API checks the complete page tree the next boot will
  load (persisted pages, module runtime pages, `api`/`admin` paths) before
  saving a page, and rejects duplicate patterns, ambiguous dynamic siblings and
  duplicate singleton actions instead of letting the site fail to start.
- WCAG 2.2 AA conformance: screen-reader names and announcements,
  keyboard access (including the Trix toolbar and administration selects),
  focus handling, contrast, target sizes and 320 px layouts across both themes
  and the administration panel. See `docs/ACCESSIBILITY_AUDIT.md`.
- Forms state their rules up front, and error messages in every locale name
  the limit or the fix. Site notifications stay longer and pause on hover or
  focus; errors stay until closed.
- Global moderators can read, edit and delete all content regardless of
  visibility, like administrators, but still have no access to
  Administration, uploads or private messages.
- A feed can be bound to only one page; existing duplicates stay saveable
  until their feed is changed.
- Plurals follow CLDR via ICU on server and browser; catalog plurals are keyed
  by category. `Formatter::plural()` is deprecated in favour of
  `transChoice()`.
- The rich-text heading button writes `<h2>` instead of `<h1>`.

### Removed

- Unused profile "avatars" tab templates in both themes.

### Fixed

- Profile → Email and password saved nothing; it now works through
  `POST /api/v1/my/security` with email confirmation (migration
  `20261016000000_add_email_verification_new_email`).
- The navigation burger is no longer shown when the top menu is empty.
- Translation gaps (Scheduler screen, Russian errors) and plural forms in
  English, Arabic and Russian.
- Smaller fixes: default avatars on comments, profile save without a picture,
  restored `trix.css` and `custom-content.css` build outputs, forum post
  anchors and layout glitches.

### Security

- All responses send `X-Content-Type-Options: nosniff`. Existing Nginx
  installations should add `add_header X-Content-Type-Options nosniff always;`
  to their `/uploads/` and `/` locations.

## [0.5.1] - 2026-10-12

### Fixed

- Fixed application bootstrap rejecting multiple pages that intentionally use
  the same non-singleton action, such as fixed articles with distinct Feed IDs.

## [0.5.0] - 2026-10-09

### Changed

- Forum, personal-blog, and community internal routes are now module-owned
  runtime pages registered beneath their public mount pages. The page editor
  and installer persist only configurable public pages, moving a mount moves
  its entire internal URL tree, and the upgrade migration removes the legacy
  internal page rows.
- Runtime page definitions now declare access, indexing, feed behavior, and
  localized static breadcrumb names in module code and are validated for
  hierarchy and route conflicts during application boot.
- TypeScript checking now runs in strict mode and covers the browser sources,
  JavaScript tests, and Vite/Vitest configuration files together.
- Feed view counters now count each member or guest at most once per feed in a
  24-hour window, ignore known crawlers, and remain independent from read/unread
  state.
- Replaced offset-based loading in the growing profile, community, and
  tag-filtered post feeds with stable `(created_at, id)` cursor pagination.

## [0.4.6] - 2026-10-08

### Added

- Added a cross-platform Docker Compose scheduler that uses Supercronic and the
  shared application image to run `bin/cron.php` once per minute.
- Added rich-text forum quick replies with an inline preview, safe HTML
  purification, rich-text editing, and safely escaped quote insertion into
  the editor.
- Added explicit edit and confirmed-delete controls for a member's own forum
  replies, while keeping topic deletion outside the reply endpoint.
- Added per-post forum ratings with each topic message and reply retaining its
  own vote state and aggregate score.
- Replaced forum topic placeholders with real post and distinct-participant
  totals, participant avatars with overflow counts, and per-author post totals.
- Added hourly garbage collection for forum attachment-card uploads left
  unattached for more than 24 hours, with reservation and race-safe rechecks.
- Added self-service topic deletion for authors during the first 24 hours,
  limited to topics without replies and protected against concurrent replies.
- Added the live member, guest, and bot presence summary to individual forum
  section statistics, using the same capped member list as the forum index.

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

[Unreleased]: https://github.com/bfhp/stream-engine/compare/v0.8.5...HEAD
[0.8.5]: https://github.com/bfhp/stream-engine/compare/v0.8.4...v0.8.5
[0.8.4]: https://github.com/bfhp/stream-engine/compare/v0.8.3...v0.8.4
[0.8.3]: https://github.com/bfhp/stream-engine/compare/v0.8.2...v0.8.3
[0.8.2]: https://github.com/bfhp/stream-engine/compare/v0.8.1...v0.8.2
[0.8.1]: https://github.com/bfhp/stream-engine/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/bfhp/stream-engine/compare/v0.7.6...v0.8.0
[0.7.6]: https://github.com/bfhp/stream-engine/compare/v0.7.5...v0.7.6
[0.7.5]: https://github.com/bfhp/stream-engine/compare/v0.7.4...v0.7.5
[0.7.4]: https://github.com/bfhp/stream-engine/compare/v0.7.3...v0.7.4
[0.7.3]: https://github.com/bfhp/stream-engine/compare/v0.7.2...v0.7.3
[0.7.2]: https://github.com/bfhp/stream-engine/compare/v0.7.1...v0.7.2
[0.7.1]: https://github.com/bfhp/stream-engine/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/bfhp/stream-engine/compare/v0.6.2...v0.7.0
