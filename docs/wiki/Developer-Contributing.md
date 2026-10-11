# Contributing

Stream Engine is an open-source project and welcomes focused bug reports,
documentation improvements, tests, translations, fixes, and features. This
chapter is the canonical contribution guide: it follows a change from initial
scope through a reviewed pull request into `main`.

Contributors do not need commit access. Fork the repository, make the change in
a branch, and open a pull request against
[`bfhp/stream-engine:main`](https://github.com/bfhp/stream-engine).

The project is under active development. Existing behavior is not automatically
a stable extension contract, but documented contracts must be preserved or
changed deliberately with migration, compatibility, and documentation work.

## Before starting

Search the repository's
[issues](https://github.com/bfhp/stream-engine/issues), open pull requests,
[`docs/TODO.md`](https://github.com/bfhp/stream-engine/blob/main/docs/TODO.md),
and the relevant Developer Guide chapters. This avoids duplicate work and
reveals an existing design or compatibility constraint.

A small, well-understood bug fix or documentation correction can normally go
straight to a focused pull request. For a substantial feature, public contract
change, new dependency, schema redesign, large UI change, or breaking change,
open an issue first and describe:

- the observable problem or use case;
- the proposed outcome, not only an implementation;
- affected users, administrators, modules, themes, APIs, or deployments;
- compatibility, migration, security, privacy, and operational impact; and
- reasonable alternatives and unresolved decisions.

Discussion is not a promise that a particular implementation will be merged,
but it reduces the risk of investing in a direction that conflicts with the
project architecture or roadmap.

Keep sensitive vulnerability details out of public issues and pull requests.
The repository currently has no committed `SECURITY.md` with a dedicated
private contact. Check the repository's Security tab for an enabled private
reporting channel. If none is available, open a minimal issue asking the
maintainer for a private contact without including exploit details, secrets,
personal data, or an unpatched proof of concept.

## Choose a useful scope

One pull request should solve one coherent problem. Include all work necessary
to make that problem complete—tests, migrations, generated assets, translations,
documentation, and compatibility handling—but leave unrelated cleanup for a
separate change.

Good first contributions have a clear observable result and a small ownership
surface. Examples include a regression test and fix, a misleading error
message, a missing translation, an accessibility defect, or a documentation
command that no longer matches the repository.

Before implementation, identify the owning layer using
[Architecture and request flow](https://github.com/bfhp/stream-engine/wiki/Developer-Architecture).
Do not place feature behavior in the global composition root merely because it
is reachable there. Use the module, theme, page-action, API, frontend, and
database extension contracts instead of creating a parallel mechanism.

## Fork and create a branch

Create a fork through GitHub, then clone it and register the main repository as
`upstream`. Replace `YOUR-ACCOUNT` before running these commands:

```bash
git clone https://github.com/YOUR-ACCOUNT/stream-engine.git
cd stream-engine
git remote add upstream https://github.com/bfhp/stream-engine.git
git fetch upstream
git switch -c fix/short-description upstream/main
```

There is no enforced branch-name grammar. Use a short descriptive name such as
`fix/...`, `feature/...`, `docs/...`, or `test/...`; do not encode secrets,
customer names, or private incident details in a branch name.

Verify the remotes before pushing:

```bash
git remote -v
```

`origin` should be your fork and `upstream` should be the main repository. Push
the branch to your fork, not directly to upstream:

```bash
git push -u origin fix/short-description
```

If you already have an authorized clone, the remote names may differ. The
important invariant is that the pull request compares the contribution branch
with `bfhp/stream-engine:main`.

## Prepare the development environment

Follow [Getting started](https://github.com/bfhp/stream-engine/wiki/Developer-Getting-Started)
and establish a clean baseline before changing code:

```bash
composer install
npm ci
php bin/check-wiki-docs.php
composer lint
composer test
composer audit:csrf
npm test
npm run typecheck
npm run build
```

The local PHPUnit run skips disposable-MariaDB integration tests unless the
`INSTALL_TEST_DB_*` variables are supplied. Use the Docker command in
[Testing](https://github.com/bfhp/stream-engine/wiki/Developer-Testing) when the
change depends on MariaDB schema, SQL behavior, constraints, or installation.

If the baseline already fails, record the exact command and failure before
working. Do not hide an unrelated failure by weakening a test or broadening the
change without discussion.

## Implement within the project contracts

Use the chapter that owns the changed behavior:

| Change | Required guide |
| --- | --- |
| Composition, layer ownership, request flow, cache, or cron wiring | [Architecture](https://github.com/bfhp/stream-engine/wiki/Developer-Architecture) |
| Schema or persisted data | [Database and migrations](https://github.com/bfhp/stream-engine/wiki/Developer-Database-and-Migrations) |
| Reusable feature extension | [Modules](https://github.com/bfhp/stream-engine/wiki/Developer-Modules) |
| Page tree, action, URL, method, or access rule | [Pages and routing](https://github.com/bfhp/stream-engine/wiki/Developer-Pages-and-Routing) |
| Public presentation or inheritance | [Themes](https://github.com/bfhp/stream-engine/wiki/Developer-Themes) |
| TypeScript, React, styles, Vite, or generated assets | [Frontend development](https://github.com/bfhp/stream-engine/wiki/Developer-Frontend) |
| JSON endpoint, authentication, authorization, CSRF, validation, upload, or secret | [API and security](https://github.com/bfhp/stream-engine/wiki/Developer-API-and-Security) |
| Test scope, doubles, fixtures, integration, or coverage | [Testing](https://github.com/bfhp/stream-engine/wiki/Developer-Testing) |
| User-visible language, locale, RTL, semantics, keyboard, or assistive technology | [Localization and accessibility](https://github.com/bfhp/stream-engine/wiki/Developer-Localization-and-Accessibility) |
| Query access path, bounded work, cache cost, or measurement | [Performance](https://github.com/bfhp/stream-engine/wiki/Developer-Performance) |
| Version, changelog, snapshot, tag, or distribution | [Releases](https://github.com/bfhp/stream-engine/wiki/Developer-Releases) |

Prefer the smallest design that uses an existing contract. A contribution that
introduces a new extension point must document ownership, discovery, validation,
failure isolation, security boundaries, compatibility, and removal—not only a
successful example.

### PHP

PHP targets version 8.3. Keep `declare(strict_types=1);`, types, namespaces, and
dependency direction consistent with neighboring code. Formatting is enforced
by Laravel Pint through `composer lint`; use `composer lint:fix` for mechanical
formatting, then review its diff.

Repositories must document supporting indexes for filtered, joined, grouped,
or sorted queries. Expected request failures use the established validation,
not-found, and forbidden exceptions; unexpected exceptions must not leak
internal details to a client.

### TypeScript and browser code

TypeScript is strict. Reuse shared browser utilities and stable Twig/UI adapter
contracts. Server authorization cannot be replaced by hidden buttons or React
route guards. New visible strings belong in every supported catalog, and UI
work must preserve keyboard, focus, semantic, reflow, light/dark, and RTL
behavior.

`npm run build` replaces `public/assets/`. Never edit generated JavaScript or
CSS directly. Commit the reviewed generated output and
`THIRD_PARTY_LICENSES.md` with the source change that produced them.

### Database and persistent state

Never edit a migration that has shipped in a release. Add a forward migration,
consider partial execution and lock duration, test representative legacy data,
and update the release snapshot only when preparing the snapshot described by
the database and release guides.

Treat `storage/migrations.json`, installation state, uploads, object-storage
objects, and configuration as user state. Tests and changes must not assume
that they may be discarded or silently recreated.

### Dependencies

Explain why a new dependency is preferable to a small local implementation or
an existing library. Review maintenance status, license compatibility, runtime
and bundle cost, transitive dependencies, security exposure, and failure mode.
Commit the matching lockfile and generated license/build changes. Do not mix an
unrelated broad dependency refresh into a feature pull request.

## Add truthful tests

Every behavior change needs a regression at the smallest scope that can prove
the claim. A test should fail for the intended reason without the change and
pass with it.

Cover relevant success, boundary, empty, invalid, unauthorized, and dependency-
failure cases. Use a real disposable database when the claim depends on
MariaDB, and browser-oriented tests when it depends on DOM or interaction.
Mocks may prove orchestration but not SQL syntax, query plans, constraints,
transactions, filesystem permissions, cache expiry, object-storage semantics,
or a real mail transport.

Do not delete, skip, loosen, or rewrite an existing assertion merely to make a
new implementation pass. If the documented behavior is intentionally changing,
update the test, implementation, changelog, and relevant guide together and
explain the compatibility decision in the pull request.

## Update documentation and generated files

Documentation is part of the change, not follow-up work. Update the canonical
Wiki source under `docs/wiki/` when behavior affects users, operators,
extension authors, contributors, or a public contract. Do not edit the GitHub
Wiki repository directly; merging the source change to `main` triggers its
publication workflow.

Also update, as applicable:

- `CHANGELOG.md` under `Unreleased` for a notable change;
- migration and install-snapshot material according to the release boundary;
- every server and browser translation catalog for a visible string;
- generated `public/assets/` and third-party licenses after frontend changes;
- `.env.example` without real credentials for configuration changes; and
- README links or concise onboarding information when the entry path changes.

Examples and commands must work against the same revision. Record limitations
honestly instead of documenting planned behavior as available.

## Keep commits reviewable

The repository does not enforce Conventional Commits. Use a concise subject
that describes the outcome, keep logically related source, tests, docs, and
generated output together, and avoid temporary debugging or editor files.

Before committing, review both the summary and content:

```bash
git status --short
git diff --check
git diff
```

Stage deliberately; never assume every untracked file belongs to the change.
Do not commit `.env`, credentials, tokens, production data, database dumps,
private fixtures, personal information, `vendor/`, `node_modules/`, coverage
output, or IDE state.

History should help a reviewer understand the change. Fixup commits are useful
during review; maintainers may squash when merging. Do not rewrite a branch
that other contributors use without coordinating with them.

## Synchronize with `main`

Before opening the pull request—and again when requested during review—bring in
the current upstream branch and resolve conflicts in the contribution branch:

```bash
git fetch upstream
git rebase upstream/main
git push --force-with-lease origin fix/short-description
```

Use `--force-with-lease`, never an unconditional force push. Rebasing rewrites
branch history; if the branch is shared, coordinate first or merge
`upstream/main` instead. After resolving a conflict, rerun tests affected by
both sides and the complete gate before requesting review.

Do not resolve generated-asset, lockfile, schema snapshot, or migration
conflicts by choosing one side blindly. Recreate generated output from the
resolved source and verify the resulting state.

## Open the pull request

Open the pull request against `bfhp/stream-engine:main`. A draft pull request
is appropriate when design feedback or early CI is useful, but mark it ready
only when the change is complete enough to review.

The repository currently has no committed pull-request template, so include
this information in the description:

- the problem and user-visible outcome;
- the chosen approach and important alternatives;
- affected contracts and compatibility impact;
- migrations, configuration, dependency, generated-asset, or operational work;
- security, privacy, localization, accessibility, and performance impact;
- exact test and verification commands with results and skips;
- screenshots or a short recording for meaningful visual interaction changes;
- known limitations and follow-up work; and
- the issue closed or related, when one exists.

Screenshots supplement tests and semantic review; they do not prove keyboard,
screen-reader, RTL, responsive, or failure behavior. Remove personal data,
tokens, internal URLs, and production content from all attached evidence.

Keep the title outcome-oriented and the description current as the design
changes. Do not mark an unresolved limitation as future work when it is needed
for correctness, safety, migration, or compatibility of the current change.

## Understand continuous integration

Every push and pull request runs three GitHub Actions jobs:

| Job | Current checks |
| --- | --- |
| Documentation | `php bin/check-wiki-docs.php` |
| PHP | PHP 8.3, required extensions, MariaDB 12, locked Composer install, Pint, the complete PHPUnit suite with installation integration tests, and the CSRF audit |
| Frontend | PHP dependencies, Node.js 20.19, `npm ci`, Vitest, strict TypeScript, and the Vite production build |

CI cancels an older run for the same ref when a newer commit arrives. Wait for
the latest commit's jobs and inspect the first meaningful failure. A green run
on an earlier SHA does not validate later review changes.

The Wiki publication workflow is separate. It runs after relevant source files
land on `main`; a pull request validates the pages but does not publish its
branch to the public Wiki.

CI is a baseline, not proof of every contract. It does not perform browser
assistive-technology review, production deployment, load testing, public
package publication, or a site-specific upgrade rehearsal. Report the manual
verification appropriate to the change.

## Respond to review

Treat review comments as questions about the change, not as instructions to
silence mechanically. Reply with the resulting code or concrete evidence,
explain a different decision when necessary, and identify assumptions that
still need maintainer input.

Push focused updates to the same pull request. Add a regression before or with
a bug fix, update the description when scope or compatibility changes, and
rerun the affected checks. Resolve a conversation only after its concern is
addressed or an agreed decision is recorded.

Avoid mixing unrelated cleanup into review updates. If review exposes a larger
independent problem, open or reference a follow-up issue and keep the current
pull request coherent unless the issue blocks its safety or correctness.

Maintainers may request changes, close an out-of-scope proposal, or choose a
different architecture. Passing CI does not guarantee merge. Once merged,
avoid reusing the branch for another change; start the next contribution from
the current `upstream/main`.

## License, provenance, and generated assistance

The repository is licensed under `LGPL-3.0-only`. Submit only code, text,
translations, images, fonts, fixtures, and other material that you have the
right to contribute under the project's distribution terms. Preserve required
copyright and license notices and identify incorporated third-party material.

The repository currently has no automated Contributor License Agreement or
Developer Certificate of Origin sign-off check. Do not add `Signed-off-by`
lines as a substitute for verifying provenance unless the project adopts and
documents that policy.

AI-assisted contributions are welcome, consistent with the project's own
development model, but the human contributor remains responsible for every
submitted line. Review generated output for correctness, security, licensing,
invented APIs, stale assumptions, secrets, and unnecessary scope; run the same
tests and provide the same explanation required for hand-written work. Do not
submit output whose training-source or license provenance you know makes the
contribution unsafe.

## Definition of done

A contribution is ready for merge when:

- it solves the stated problem with no unrelated scope;
- it respects documented architecture and extension contracts;
- authorization, validation, CSRF, output safety, secrets, and failure paths
  were reviewed where relevant;
- localization, RTL, semantics, keyboard operation, focus, and assistive
  technology were preserved for user-visible work;
- database and persistent-state changes have safe forward migrations and
  representative integration tests;
- work, queries, responses, uploads, retries, and background batches remain
  bounded, with important indexes documented;
- source, tests, documentation, changelog, translations, lockfiles, snapshots,
  generated assets, and licenses are consistent;
- `git diff --check` and the complete local gate pass, with skips and manual
  verification reported honestly;
- the pull request targets `main`, explains impact and evidence, and contains
  no secrets or private data;
- the latest pull-request commit passes all required CI jobs; and
- review concerns are resolved through code, tests, documentation, or an
  explicit recorded decision.

[Back: Releases](https://github.com/bfhp/stream-engine/wiki/Developer-Releases) · [Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide)
