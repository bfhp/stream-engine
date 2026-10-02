# TODO

The project's current backlog. Last reviewed against the code and tests on
**October 2, 2026**.

This file contains only unfinished work and decisions that have been made but
not yet implemented. Completed work is not recorded here; Git provides that
history. When a task is completed, it should be removed from this file together
with any related `TODO` comments in the code.

Priorities:

- **P0** — a clean installation does not work, the user receives a false
  success response, or data may be lost or returned incorrectly;
- **P1** — important reliability, security, or operational work;
- **P2** — product improvements, refactoring, and localized technical debt.

## P2

### Localization

- Make every added locale available during installation and in administration
  settings without maintaining a separate hard-coded locale list.
- Extend locale catalog tests so every catalog has the same required keys as
  English and locale selection/fallback works for both base and regional
  locale codes.

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

First, expand the actual scope of `npm run typecheck`: tests in
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
