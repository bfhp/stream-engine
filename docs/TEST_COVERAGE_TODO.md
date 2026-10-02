# Test coverage TODO

This backlog is kept separately from the product backlog in `docs/TODO.md`.
Remove completed items rather than retaining them as a changelog.

## Baseline

Last measured on **October 2, 2026** with `composer test:coverage`:

- 2,120 tests, 8,645 assertions, 7 skipped;
- classes: **37.61%** (44/117);
- methods: **67.24%** (821/1,221);
- lines: **83.71%** (11,224/13,408).

The percentage is a diagnostic signal, not a target by itself. Prioritize
authorization, data integrity, installation, and external side effects before
low-risk accessors or defensive branches. Before working on a class, generate
`composer test:coverage-html` and select concrete uncovered behavior from the
line report. Do not add tests that merely execute lines without asserting an
observable contract.

## P1: critical workflows and boundaries

### Installation and upgrades

The installation subsystem has good coverage in several value objects but
important orchestration gaps: `InstallerFactory` is at 7.14% lines,
`ConfiguredInstaller` at 53.57%, `InstallationPreflight` at 65.33%, and the
claim/state/token stores are between 75.00% and 79.59%.

- Extend the empty-database installation integration test into a post-install
  smoke test: register a regular user, deliver a system notification, and
  create/open a library page using the installed schema and configuration.
- Cover preflight failure, retry, expired/consumed claim and token, interrupted
  state, and configured-installer error paths. Assert persisted state and the
  response shown to the installer, not only the thrown exception.
- Verify release/schema snapshot command paths that are safe to run in a
  disposable database. `ReleaseVersion`, `SchemaSnapshotBuilder`, and
  `SqlScript` currently report partial method coverage despite their executed
  lines.
- Investigate why `StreamEngine` is absent from the report. Once instrumented,
  cover the request/`Response` branches that are reachable through the normal
  application bootstrap.

### External side effects

- Add a separate `S3ObjectStorage` integration suite using an isolated test
  bucket. Cover put/get/delete, missing objects, pagination, SDK failures, and
  cleanup. Do not replace this with SDK mocks: the current class is at 27.56%
  lines (35/127).
- Cover `MailService` transport success, rejection, malformed recipient data,
  localized templates, and failure propagation with a deterministic test
  transport. It is at 26.09% lines (12/46).
- Exercise `Cache` against the configured test backend for hit, miss,
  expiration, serialization, deletion, and backend failure. It is at 69.77%
  lines (30/43).

### Authorization and request handling

- Add a real-database ACL integration test proving that a non-admin sees only
  owned records, public records, and records granted through membership. Check
  both list totals and returned rows so count/data queries cannot diverge.
- Cover the uncovered `Security` branches for malformed/missing tokens and
  session state. It is at 61.54% lines (16/26).
- Bring `MessagesController` request-method, validation, authorization, and
  error-response paths under tests, including group-administrator membership
  changes. It is at 66.67% lines (160/240).
- Use the HTML line report to cover security-relevant gaps in
  `ProfileController` (80.28%), `APIController` (89.42%), `AdminController`
  (89.93%), and `UsersController` (93.15%). Prefer endpoint behavior over
  calling private handlers directly.

### Persistence failure paths

- Cover transaction rollback and database error translation in `PdoDatabase`
  (58.49% lines), then cover migration locking/resume failures that are not
  already exercised through `MigrationRunner`.
- Cover the missing write/error paths in `UploadRepository` (63.49%),
  `MenuRepository` (62.50%), and `FeedRatingRepository` (77.55%). Assert query
  scope and persisted results against a disposable database where mocks would
  hide SQL behavior.

## P2: application branches

### Feed and profile behavior

- Start with the 159 uncovered lines in `FeedService` (77.98%), then the 65 in
  `FeedRepository` (86.97%) and 56 in `ProfileController` (80.28%). Select
  cases from the HTML report around visibility, ownership, parent/container
  scope, deletion, and failed writes; do not duplicate already-covered happy
  paths.
- Cover the remaining behavior in `FeedMetadataRepository`,
  `FeedReadRepository`, and `PollService`, especially empty results,
  idempotency, invalid transitions, and database failures.

### Themes, menus, and operational helpers

- Cover `ThemeCatalog` discovery and validation failures (66.17% lines),
  including malformed metadata, missing assets, duplicates, and fallback
  selection.
- Cover the remaining menu tree mutation and ordering branches shared by
  `MenuRepository` and `MenuService`.
- Cover the untested trigger decision in `CronTrigger` and the relevant image
  format/error branches in `ImageProcessor`. Avoid platform-specific tests
  that cannot make stable assertions in CI.

## Frontend coverage

The PHP report does not measure TypeScript. Keep frontend priorities based on
uncovered user-visible behavior and regressions, and continue using
`npm test` as the baseline.

- Cover comment DOM contracts: cursor pagination, reply ordering,
  duplicate-submit protection, errors, and escaping.
- Cover favorites, ratings, “continue reading,” and `openDirectMessage()`.
- Cover profile dirty state and the friends tab.
- Cover friendship and membership button state machines in `users.ts`.
- Cover forum attachment/submit guards and the blog post tag editor.
- Refactor `initMessages()` before expanding its tests: extract pure
  computations, collect DOM references through `resolveRefs(root)`, and return
  `{ stop() }` to clear intervals and listeners. Then cover polling races,
  message deduplication, read receipts, attachment request IDs, group
  administrator controls, and hash navigation.

## Scope and maintenance

- WordPress import remains outside normal coverage because
  `bin/wp-import.php` is a one-off manual tool. Test it when import work resumes
  or a regression is found.
- After each meaningful batch, run the focused suite, `composer test`, and
  `composer test:coverage`; update the baseline only when the complete coverage
  run is green.
- A completed item must be removed. If the remaining percentage belongs only
  to unreachable, generated, or intentionally environment-specific code,
  document the exclusion in the coverage configuration instead of adding a
  hollow test.
