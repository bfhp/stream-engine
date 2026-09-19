# TODO

The project's current backlog. Last reviewed against the code and tests on
**September 12, 2026**.

This file contains only unfinished work and decisions that have been made but
not yet implemented. Completed work is not recorded here; Git provides that
history. When a task is completed, it should be removed from this file together
with any related `TODO` comments in the code.

Priorities:

- **P0** — a clean installation does not work, the user receives a false
  success response, or data may be lost or returned incorrectly;
- **P1** — important reliability, security, or operational work, or a major
  gap in test coverage;
- **P2** — product improvements, refactoring, and localized technical debt.

## Current check status

The following checks were green at the time of the review:

- `composer test` — 2,428 tests, 10,115 assertions, 5 skipped;
- `npm test` — 27 files, 496 tests;
- `npm run typecheck`;
- `npm run build`;
- `composer audit:csrf`.

There is no current coverage report: `composer test:coverage` requires a
running Docker daemon. Old coverage percentages were deliberately removed
because they became misleading after more tests were added.

## P1

### Authorization and roles

- Decide the rule for group conversations: currently, any participant can
  remove any other participant.

### Test coverage

First obtain a fresh `composer test:coverage` report; do not use numbers from
the old report as a prioritization criterion.

Backend:

- extend the existing empty-database installation test into a post-install
  smoke test covering regular registration, a system notification, and library
  page creation;
- cover the `StreamEngine` branches that become reachable after extracting
  `Response`;
- cover the remaining branches in `ProfileController`, `MessagesController`,
  `APIController`, and `FeedRepository`, chosen according to the fresh report;
- add an integration ACL test against a real database: a non-admin sees only
  records they own, public records, and records accessible through membership;
- test `S3ObjectStorage` in a separate integration suite with a test bucket
  instead of mocking the SDK in unit tests.

Frontend:

- comment DOM contracts: cursor pagination, reply ordering, duplicate-submit
  protection, errors, and escaping;
- favorites, ratings, “continue reading,” and `openDirectMessage()`;
- profile dirty state and the friends tab;
- friendship and membership button state machines in `users.ts`;
- forum attachment/submit guards and the blog post tag editor.

`initMessages()` must be refactored first: extract pure computations, collect
DOM references through `resolveRefs(root)`, and return `{ stop() }` to clear
intervals and listeners. Then test polling races, message deduplication, read
receipts, attachment request IDs, and hash navigation.

WordPress import remains outside normal coverage: it is a one-off manual tool
in `bin/wp-import.php`. Test it only when import work resumes or a regression is
found.

### Automated checks and CI

- The CSRF audit is included in PHPUnit (`tests/Security/CsrfCoverageTest.php`),
  runs with `composer test`, and can be run separately with
  `composer audit:csrf`.

## P2

### Admin: dashboard

`Dashboard.tsx` is currently only a placeholder and does not provide an
operational overview of the site.

- Define and display useful summary metrics, recent activity, system health,
  and shortcuts to common administration tasks.
- Make every dashboard card permission-aware and provide explicit loading,
  empty, and error states.
- Cover the dashboard API, access rules, and rendering of each state with
  tests.

### Admin: user management

`Users.tsx` is currently only a placeholder; administrators cannot manage
users through the admin interface.

- Add a paginated, searchable user list with relevant status and role filters.
- Define and implement the permitted account operations, including profile and
  role changes, activation/deactivation, and safe handling of privileged or
  self-targeted accounts.
- Enforce every operation on the server independently of the UI and cover
  authorization, validation, audit-sensitive actions, and list states with
  tests.

### Admin: interface localization

The admin interface is only partially translated: several pages and navigation
elements still contain English string literals, and formatting currently
follows the browser locale rather than the locale selected in CMS settings.

- Route all user-facing admin text, including module-provided navigation and
  validation/error messages, through the translation system.
- Make the selected CMS locale control admin labels and locale-sensitive date,
  time, and number formatting, with a documented fallback for missing keys.
- Ensure changing the locale updates the interface predictably and add tests
  that exercise every supported locale without relying on the browser locale.

### Admin: enabling modules and components

`ModuleRegistry` currently treats every controller in the Composer classmap as
active, while widgets have no separate enabled state: empty HTML effectively
acts as an implicit off switch.

- Establish consistent UI terminology: a module is a functional extension; a
  component/widget is a theme placement element.
- Store module state and do not register actions, APIs, cron jobs, views, or
  admin pages for a disabled module. Make the installation and admin system
  modules impossible to disable.
- Before disabling a module, show its dependencies and uses in pages, feeds,
  and menus; do not leave active routes whose handler has disappeared.
- Add an explicit enabled switch for widgets instead of the “empty HTML”
  convention.
- Cover bootstrap, cache clearing, re-enabling, and action/feed-type conflicts
  with integration tests.

### Admin: theme selection and settings

The active theme is currently specified only through the `THEME_DIR` path, and
the base theme contains a hard-coded `data-bs-theme="dark"`.

- Introduce a theme catalog with manifest/id/name and select themes in settings
  without accepting an arbitrary path from the admin API. Preserve the fallback
  to `views/themes/default/` defined by `docs/THEME_CONTRACT.md`.
- Define a theme settings schema and defaults; store values in a theme
  namespace, validate them on the server, and preserve them separately when
  switching themes.
- Move color mode and other base-theme parameters out of Twig literals and into
  these settings; provide a safe preview and a return to the default when a
  theme is missing or broken.
- Define theme asset delivery and cache invalidation, then test selection,
  fallback behavior, and settings persistence.

### Admin: page action configuration UI

The backend action catalog now exposes and enforces a normalized contract for
`feedId`, `feedType`, `listFeedType`, and `termVocabulary`. `PageEdit` does not
yet use that metadata, so the administrator sees the same four controls for
every action and learns about invalid combinations only after saving.

- Explain the selected action's contract beside the four fields, mark required
  and `oneOf` inputs, and make unsupported or ineffective values visible as
  errors instead of silently saving or clearing them.
- Restrict feed-type selectors to the contract's `values`, surface referenced
  feed type requirements, and preserve the current values of an unknown legacy
  action without presenting them as valid for a new action.
- Add frontend tests for action changes, required/unsupported fields,
  constrained options, compound requirements, and legacy preservation.

### Admin: menu editor

`MenuRepository` currently only reads the entire menu, and there is no separate
admin CRUD interface.

- Add a tree editor for creating, editing, deleting, and ordering items and
  groups.
- Support the existing `internal`, `external`, `dynamic`, `action`, and
  `divider` types, as well as page/url/action, parent, group, label, access rule,
  and a new enabled state.
- On the server, validate cycles and missing parent/page/action references and
  save ordering atomically; before deleting a parent, require an explicit
  decision about its child items.
- Add a preview for the current user and tests for the tree, ACL, and reordering.

### Registration

- Integrate a real CAPTCHA or remove the hidden, unfinished markup from the
  registration form.

### User mentions in comments, forums, and messages

- Define a single `@username` syntax, escaping rules, and username boundaries;
  parse mentions on the server rather than trusting client HTML.
- Add context- and visibility-aware autocomplete for participants in the
  discussion, forum, or group conversation without leaking private profiles.
- Render a mention as a safe profile link and create a notification with a
  canonical link to the specific comment/post/message.
- Do not notify authors when they mention themselves, deduplicate repeated
  mentions, and define behavior for text edits, user deletion, and username
  changes.
- Verify that the recipient may view the object before delivering a
  notification; cover the parser, XSS, ACL, edits, and notification
  deduplication with tests.

### Blog: friends-only posts via container_id/container_type

`FriendService` already stores relationships in the personal blog feed's
`memberships`. If the product needs “friends only” visibility, add it as an
explicit feed access policy and check both sides of the mutual membership. Do
not add a new visibility type merely because the relationship exists before a
product decision is made.

### Forums: topic-view interactive features

The topic and reply view MVP works. Deferred work:

- rich-text quick reply and preview;
- quoting into the reply form;
- editing/deleting one's own reply through explicit UI;
- ratings for individual posts;
- real participant/post counters instead of placeholders;
- garbage collection for orphaned uploads.

Deleting an entire topic must remain a separate owner/moderator action and must
not be reachable through the endpoint for deleting a single reply.

### Forums: who's online within a forum

`forums.list` already displays active members, guests, and bots based on
`user_sessions`. `forums.topic-list.twig` still contains a text placeholder:
reuse the same contract and name limit for an individual forum's card without
duplicating presence calculations in Twig.

### View counter

`FeedService::recordView()` intentionally counts every render, including repeat
views and bots. If unique-view metrics are needed, define a deduplication window
and storage for guests/users; do not mix this with the existing read/unread
watermark.

### TypeScript strictness

The current `tsc` check is green, but the project is not in strict mode.

First, expand the actual coverage of `npm run typecheck`: tests in
`tests/Site/js/`, as well as `vite-build-data.ts`, `vite.config.ts`, and
`vitest.config.ts`, are currently excluded. Include them in the main
`tsconfig.json` or check them with a separate configuration while keeping the
shared script green.

1. Enable `strictNullChecks` incrementally, starting with DOM-heavy modules.
2. Then address `noImplicitAny`; local declarations are needed for
   `@bfhp/astro-natal-chart` and the Bootstrap subpath in use.
3. Do not conceal the migration with large numbers of `@ts-expect-error`
   comments without individual explanations.

### Cursor pagination

Offset pagination in ACL-filtered lists can scan large parts of a table. Move
hot lists to stable cursor pagination over indexed `(sort_column, id)`. Choose
specific queries based on measurements and the rules in
`docs/PERFORMANCE_CONTRACT.md` rather than rewriting every paginator in advance.

## File maintenance rule

A new task must describe the observable problem, the desired outcome, and,
where important, how completion will be verified. Keep diagnostic notes and
reports about completed work in commits/PRs instead of turning TODO back into a
changelog.
