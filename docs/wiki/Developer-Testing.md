# Developer Guide: Testing

Stream Engine uses PHPUnit for PHP and Vitest for TypeScript. Tests protect
observable contracts: routing, authorization, persistence, rendered markup,
browser behavior, installation, and extension boundaries. A test is valuable
when it can fail for a user-visible or operational regression, not merely when
it executes another line.

This chapter explains test placement, scope, doubles, database integration,
frontend setup, coverage, regression practice, and parity with continuous
integration. Set up the toolchains first with
[Getting started](https://github.com/bfhp/stream-engine/wiki/Developer-Getting-Started),
and apply the negative cases from
[API and security](https://github.com/bfhp/stream-engine/wiki/Developer-API-and-Security)
to every changed endpoint.

## Test map

| Path | Primary responsibility |
| --- | --- |
| `tests/Core/` | Platform infrastructure, routing, configuration, installation primitives, caching, migrations, security, storage, and extension discovery. |
| `tests/Domain/` | Domain-object construction, normalization, state, and small invariants. |
| `tests/Repository/` | SQL text, bound parameters, row mapping, query scope, and repository return contracts, normally through a database test double. |
| `tests/Service/` | Application rules, authorization, orchestration, transactions, and side-effect decisions. |
| `tests/Modules/<Name>/` | Module registration, controller behavior, module-owned services, API branches, and cron handlers. |
| `tests/View/` | Twig and rendered HTML contracts that cross template or navigation boundaries. |
| `tests/Security/` | Cross-module security audits whose completeness matters more than one feature. |
| `tests/Integration/` | Behavior that intentionally crosses into real infrastructure, currently the disposable-database installation/schema suite, run against MariaDB and MySQL in CI. |
| `tests/Support/` | Narrow reusable fakes, builders, stream wrappers, and audit helpers. This directory is not a second application layer. |
| `tests/js/` | Browser utilities, DOM behavior, React/admin contracts, API error handling, localization, navigation, and uploads under Vitest/jsdom. |
| `tests/a11y/` | Page-level WCAG 2.2 A/AA scan of an installed site with Playwright and axe-core, and its baseline of known violations. |

Mirror the production owner where practical: `src/Core/Router.php` is covered
by `tests/Core/RouterTest.php`, while a module controller belongs below that
module's test directory. Split a very large suite by behavior, as the API and
Users module suites do, rather than creating an unrelated catch-all file.

PHP test classes use the `Tests\` namespace and PHPUnit attributes such as
`#[DataProvider(...)]`. Frontend tests import `describe`, `it`, `expect`, and
other helpers explicitly from `vitest`; globals are intentionally disabled.

## Choose the smallest truthful scope

Start at the narrowest boundary that can prove the contract, but do not replace
a required integration with a mock just to make the test fast.

### Unit and component tests

Use an isolated test when the behavior is a pure calculation or when a class's
collaborators have clear contracts. Good examples include:

- route matching and precedence with an in-memory `PageTree`;
- a service policy with repository collaborators returning controlled rows;
- request validation with a mocked application service;
- URL, formatting, translation, and cryptography vectors; and
- DOM behavior whose network or timer boundary can be injected or mocked.

Assert returned state, emitted output, persisted intent, or collaborator calls
that are themselves part of the contract. Avoid asserting every private step.
Tests that break during a harmless internal refactor but cannot catch a behavior
change are too tightly coupled.

### Repository tests

Most current repository tests use a mocked `PdoDatabase`. They can prove:

- the expected statement and bound values are sent;
- user or container scope is present;
- rows are mapped into the correct public shape; and
- empty, failure, and write-result branches are handled.

They do **not** prove that MariaDB or MySQL accepts the SQL, that an index or constraint
exists, that transaction isolation is correct, or that a query returns the
right rows against the real schema. Add or extend a disposable-database
integration test when the risk depends on any of those facts.

### Integration tests

Use real infrastructure for contracts that a test double would hide: schema
installation, migrations, constraints, database-specific SQL, storage SDK
behavior, cache expiry, or mail transport. Keep the dependency disposable,
give each test an isolated namespace/database/bucket, and clean it in a
`finally` block even after an assertion fails.

An integration test must not depend on the developer's working site, personal
credentials, or persistent data. If safe infrastructure is not configured,
skip with an actionable reason rather than silently replacing the integration
with a unit test.

## Write a behavior-focused PHPUnit test

A test normally follows arrange, act, assert without comments that simply
repeat those labels:

```php
<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\QueryParams;

final class QueryLimitTest extends TestCase
{
    #[DataProvider('limits')]
    public function testIntegerInputRejectsInvalidShapes(mixed $value, int $expected): void
    {
        $query = new QueryParams(['limit' => $value]);

        self::assertSame($expected, $query->int('limit', 20));
    }

    public static function limits(): iterable
    {
        yield 'integer string' => ['50', 50];
        yield 'fraction' => ['2.5', 20];
        yield 'array input' => [['50'], 20];
    }
}
```

Name a test after the behavior and condition, not the implementation method.
Use a data provider when the same contract has meaningful boundary cases; do
not compress unrelated scenarios into a loop that hides which case failed.

For an exception, assert its public type, status/code, or safe message where
that is the contract. Do not approve an unexpected warning or deprecation just
to make a suite green. Fix application-owned diagnostics and keep vendor noise
separate through PHPUnit's configured source boundary.

## Test doubles and fixtures

Use the lightest double that preserves the production collaborator's important
semantics:

- PHPUnit mocks are suitable for a narrow interface interaction or failure;
- `ArrayCache` preserves the real cache's `null`-means-miss behavior;
- `SecurityWithoutCookies` captures cookie writes while retaining token logic;
- `PhpInputStreamMock` controls `php://input` for controller JSON tests;
- `FakePdoDatabase` and `FakeSessionDatabase` compose selected real
  repositories/services over deliberately limited in-memory tables; and
- controller factory fixtures provide synthetic modules and route trees for
  discovery/ownership tests.

Read a support class before extending it. The database fakes recognize a
closed set of known query shapes; they are not SQL engines. Add only the
minimal production-compatible behavior needed by the scenario and document
any semantic shortcut. A fake that is more permissive or reliable than the
real dependency can conceal a defect.

Prefer small factory methods that return valid domain objects and override only
the field relevant to a test. Keep identifiers and timestamps explicit when
ordering, ownership, expiry, or deduplication depends on them. Fixtures must
not rely on execution order or state left by another test.

## Control global and external state

PHP controllers use superglobals, headers, output, cookies, files, and the
`php://input` stream. Tests that touch them must restore them:

- save or unset `$_SERVER`, `$_COOKIE`, `$_FILES`, and other changed globals in
  `tearDown()`;
- restore `PhpInputStreamMock` even when the call throws;
- capture output only around the operation that writes it;
- use a temporary directory with a unique random suffix and remove its files
  in `finally`;
- reset mutable static fakes before each case; and
- restore timezone, locale, environment variables, and error handlers changed
  by the test.

Use the locale, direction, semantic markup, keyboard, live-region, and manual
review matrix from
[Localization and accessibility](https://github.com/bfhp/stream-engine/wiki/Developer-Localization-and-Accessibility)
for user-visible changes.

The PHP suite currently runs sequentially, but isolation is still required:
CI order, a focused invocation, or a future runner may differ. Never make an
assertion depend on the wall clock when a bounded range or injected timestamp
can express the contract. Avoid network access in the normal unit suite.

For randomness, test shape, uniqueness, verification, and failure handling.
Do not assert a newly generated token's literal value.

## Database integration

`tests/Integration/InstallationSchemaTest.php` creates uniquely named temporary
databases, exercises release snapshots and migrations, and drops its data. It
runs only when `INSTALL_TEST_DB_DSN` is set. The configured account therefore
needs permission to create and drop databases.

Against the local Compose MariaDB service:

```bash
docker compose exec -T \
  -e 'INSTALL_TEST_DB_DSN=mysql:host=db;port=3306' \
  -e INSTALL_TEST_DB_USERNAME=root \
  -e INSTALL_TEST_DB_PASSWORD=root \
  php vendor/bin/phpunit tests/Integration/InstallationSchemaTest.php
```

On a host, point the variables at a dedicated disposable MariaDB or MySQL server before
running the same PHPUnit file. Never use a production server or an account
that can reach valuable databases. A generated database name is protection
against accidental overlap, not permission to use an unsafe server.

When adding a real-database test:

1. install the same snapshot/migration path used by the product;
2. insert the smallest rows that demonstrate the contract;
3. assert persisted rows, constraints, counts, ordering, or rollback—not only
   the absence of an exception;
4. cover the database-specific failure or boundary that required integration;
5. clean up independently of the test result; and
6. confirm the test runs in both CI PHP jobs (MariaDB and MySQL).

## Controller, API, and security tests

Controller tests should exercise the public controller method rather than call
a private handler through reflection. Provide a resolved `Page`, route
arguments, `RequestContext`, and controlled collaborators, then capture the
status/body or the typed exception that the engine will translate.

For each changed API operation, cover the positive result plus the applicable
negative matrix:

- guest, wrong role, owner, and a different authenticated user;
- missing and unauthorized resources without unintended disclosure;
- undeclared request method;
- missing, array-shaped, mismatched, and valid CSRF tokens for mutations;
- malformed JSON and incorrect scalar/array shapes;
- boundary identifiers, lengths, enums, pagination, and unknown fields; and
- a collaborator/database failure before and after any commit boundary.

The static CSRF suite discovers mutating registered routes and heuristically
checks that they reach `Security::verifyCsrf()`. Run it, but retain runtime
tests that prove an invalid token cannot reach the write:

```bash
composer audit:csrf
```

Security behavior often fails in what must **not** happen. Use expectations
such as `never()` to prove an unauthorized request did not call a mutation,
send mail, write a file, or disclose a row.

## Frontend tests with Vitest

`vitest.config.ts` is intentionally separate from the Vite production-build
configuration. It:

- runs `tests/**/*.test.ts` in `jsdom`;
- caps the worker pool at two for stable CI memory use;
- restores mocks between tests;
- exposes the `@stream-engine` source alias; and
- loads the translation and feed-type build-data plugins required by browser
  modules.

Import the production function or initializer directly. Prefer a pure exported
function for normalization/state transitions and a thin DOM initializer for
event wiring. Construct only the HTML required by the behavior, dispatch the
real browser event, and assert visible DOM/state or the outgoing request.

```ts
import { describe, expect, it } from "vitest";

import { getApiErrorMessage } from "@stream-engine/shared/api-errors";

describe("getApiErrorMessage()", () => {
    it("uses the standard API error before the fallback", () => {
        expect(getApiErrorMessage(
            { error: "Name is already in use", code: "bad_request" },
            "Request failed",
        )).toBe("Name is already in use");
    });
});
```

Do not reproduce production logic inside the test to calculate the expected
answer. Use literal expected output or an independent invariant. Clean timers,
listeners, DOM, storage, cookies, and mocked globals; an initializer that owns
an interval or global listener should expose a teardown handle.

Mock `fetch` at the transport boundary and test status, payload, and failure
behavior. Keep at least one contract test for shared API error parsing rather
than teaching every feature its own server-response shape. Use fake timers
only where elapsed time is the behavior, and always restore them.

Run a single file while iterating, then the complete frontend checks:

```bash
npm test -- --run tests/js/api-errors.test.ts
npm test
npm run typecheck
```

`npm run build` is not a substitute for tests or type checking. It is an
additional release/CI gate that proves the entry graph, build-data plugins,
aliases, and generated assets still compile.

## Accessibility checks

Accessibility is checked automatically at two levels. Both use
[axe-core](https://github.com/dequelabs/axe-core) with the WCAG 2.0–2.2 level A
and AA rule tags from `tests/a11y/format.ts`; best-practice rules are not part
of the gate. Neither replaces the manual review in
[Localization and accessibility](https://github.com/bfhp/stream-engine/wiki/Developer-Localization-and-Accessibility#accessibility-review-and-current-limits).

**Component checks** run in the normal Vitest suite. Render the component to
markup, mount it, and assert an empty report:

```ts
import { axeReport, mount } from "./support/axe";

const root = mount(renderToStaticMarkup(createElement(MantineProvider, null, element)));
expect(await axeReport(root)).toBe("");
```

jsdom has no layout or cascaded colours, so `axeReport()` disables the
contrast and `region` rules. These checks find missing names and labels,
invalid ARIA, and duplicate ids quickly; they cannot judge contrast, focus
behavior, or anything that depends on layout. `tests/js/a11y-admin.test.ts`
is the reference suite.

**Page scans** in `tests/a11y/` open an installed site in Chromium. They scan
the home, not-found, and sign-in dialog views, account pages the sign-in
dialog links to, the built-in administration screens and empty editors, and
the administrator's profile, each in the light and dark colour schemes. They
also open and scan interactive states: failed sign-in feedback, the profile
tabs, the user menu and sign-out confirmation, and the admin's dialogs,
drawers, menus and select dropdowns (`tests/a11y/states.ts`). These tests
never save: each state is opened, scanned and closed with Escape. The home
page, sign-in dialog and profile are also checked at 320 CSS px for
horizontal scrolling (WCAG 1.4.10), which axe does not test.

**Keyboard checks** (`tests/a11y/keyboard.ts`, `*-keyboard.spec.ts`) cover
what axe cannot. A Tab walk goes through each public page, the profile and
the administration screens until focus comes back to the start, and fails
on a keyboard trap (2.1.2), on a stop that looks the same focused and
unfocused (2.4.7), and on an outline or shadow focus indicator below 3:1
against its background (1.4.11). Visually hidden inputs are judged by their
sibling, parent and `<label>`, which carry their focus ring. Open-state tests
also check that Tab and Shift+Tab stay inside an open dialog and that Escape
returns focus to the control that opened it (2.4.3). Monaco editors are
excluded: they keep Tab for indentation by design (Ctrl+M releases it).
Direction
follows the site locale and markup follows the active theme. Without further
settings, `npm run test:a11y` scans the target site as it is configured. Set
`A11Y_THEMES` and/or `A11Y_LOCALES` to comma-separated lists to scan each
combination; CI uses both bundled themes in English and Arabic:

```bash
A11Y_THEMES=default,bootstrap A11Y_LOCALES=en,ar npm run test:a11y
```

`tests/a11y/run.mjs` then signs in, records the active theme, theme settings
and site locale, activates each theme with **Color mode** set to **System**
(public pages follow the light and dark projects only then; the default is
**Dark**), sets each locale, runs Playwright, and restores the recorded
settings at the end, also after a failure or Ctrl+C. Reports for each
combination go to `storage/a11y/runs/<theme>-<locale>/`. A run against a site
whose theme is not in **System** mode warns locally and fails in CI.

A scan fails only on axe rules that are not listed for that page in
`tests/a11y/baseline.json`. The baseline is keyed by page and rule, not by
element: a known problem does not hide a second instance of the same rule.
Fix the cause instead of adding to the baseline. When an existing problem
must be accepted for now, record it and review the diff:

```bash
A11Y_BASE_URL=http://localhost:5000 \
A11Y_ADMIN_EMAIL=admin@example.com A11Y_ADMIN_PASSWORD='…' \
A11Y_UPDATE_BASELINE=1 npm run test:a11y
```

Recording only adds entries, because each locale and colour scheme sees a
different subset. Remove an entry by hand once its rule is fixed everywhere;
the scan annotates baseline entries that it no longer observes. Without
`A11Y_UPDATE_BASELINE`, the same command runs the gate against the Docker
development site. Run `npx playwright install chromium` once first. Results,
traces, and the session file go to the ignored `storage/a11y/` directory.

When you add a public page type or an administration screen, add it to
`guest.spec.ts` or `admin.spec.ts`.

## Focused and complete runs

Run the nearest suite during development:

```bash
vendor/bin/phpunit tests/Core/RouterTest.php
vendor/bin/phpunit tests/Modules/API/APIControllerFeedsTest.php \
  --filter testCreateComment
npm test -- --run tests/js/navigation-url.test.ts
```

Before handing off a change, expand according to its impact. A repository
change normally needs its repository and service/controller consumers; a
shared router, security, translation, build, or module-discovery change needs
the complete relevant language suite.

The repository-wide local gate is:

```bash
php bin/check-wiki-docs.php
composer lint
composer test
composer audit:csrf
npm test
npm run typecheck
npm run build
```

The accessibility page scan needs a running site, so it is not part of this
local gate; run it as described in
[Accessibility checks](https://github.com/bfhp/stream-engine/wiki/Developer-Testing#accessibility-checks)
when a change affects rendered markup, and rely on CI otherwise.

The documentation validator checks the required page set, stable filenames,
guide navigation and reachability, Wiki page anchors, and canonical links to
files and Markdown headings in the repository. External network links remain a
review or retry-aware scheduled check so a transient service failure cannot
make every pull request fail.

Do not report “all tests pass” after only a focused file. State exactly which
checks ran and identify skipped tests. In particular, the database integration
suite is skipped when `INSTALL_TEST_DB_DSN` is absent; a green host run with
that skip is not equivalent to the CI PHP job.

## Coverage

PHPUnit measures project code under `src/`, excluding translation arrays in
`src/Lang/`. Uncovered files remain in the report at zero. Coverage is opt-in
because the normal suite runs without a coverage driver; the Compose PHP image
provides Xdebug:

```bash
composer test:coverage
composer test:coverage-html
```

The second command writes an ignored browsable report to `coverage/`. Use it
to find an untested decision, then write a test for its observable result. The
maintained priorities and last measured baseline live in
[`docs/TEST_COVERAGE_TODO.md`](https://github.com/bfhp/stream-engine/blob/main/docs/TEST_COVERAGE_TODO.md).

There is currently no enforced coverage percentage in CI, and TypeScript does
not currently publish a coverage report. Treat percentages as diagnostic
signals, not a quota. Authorization, data integrity, installation, recovery,
and irreversible side effects deserve coverage before low-risk getters. Never
add an assertion-free execution test solely to raise a number.

After a meaningful coverage batch, run the complete PHP suite and coverage
command before updating the recorded baseline. Remove completed backlog items;
do not preserve them as a changelog.

## Regression tests

A bug fix starts with the smallest test that fails for the reported behavior
and passes after the fix. The test should reproduce the relevant boundary—not
the accidental implementation that happened to contain the bug.

A useful regression test:

- names the input/state that triggered the defect;
- asserts the user-visible result and any important absence of side effects;
- covers the layer where the contract belongs;
- remains deterministic without production data or network access; and
- would fail if the same class of defect were reintroduced.

If the failure crossed layers, keep a narrow test for the corrected rule and
add one integration/contract test at the boundary that allowed the regression.
For example, a query-scope defect may need both a repository parameter test and
a real-database result/count test.

Do not delete or weaken a regression test because a refactor makes setup
awkward. Either preserve the contract through a better fixture or explain why
the behavior itself is intentionally changing.

## Continuous-integration parity

`.github/workflows/ci.yml` runs on pushes and pull requests with these jobs:

| Job | Environment and checks |
| --- | --- |
| Documentation | PHP 8.3; `php bin/check-wiki-docs.php`. |
| PHP | Two jobs: PHP 8.3 with MariaDB 12, and PHP 8.3 with MySQL 8.4; Composer install, Pint, PHPUnit with `INSTALL_TEST_DB_*`, and the CSRF audit. |
| Frontend | PHP 8.3 and Node 20.19; Composer install, `npm ci`, Vitest, TypeScript, and the production Vite build. |

`.github/workflows/accessibility.yml` runs the page scan separately, because
it is the slowest check: PHP 8.3, Node 20.19, MariaDB 12, and memcached;
production build, `bin/install.php` into a clean database, `bin/seed-demo.php`,
PHP's built-in server with `tests/a11y/router.php`, and the Playwright/axe scan
for the `default` and `bootstrap` themes. Pull requests scan English only;
pushes to `main`, the weekly run and manual runs add Arabic (right-to-left).
The reports are uploaded as a workflow artifact. It runs only when a pull request or a push to
`main` changes what the scan can see (`views/`, `assets-src/`, `public/assets/`,
`src/Lang/`, `tests/a11y/`, the install and seed scripts, or the npm
manifests), every Monday, and on demand: Actions → Accessibility → Run
workflow, for any branch. Because of the path filter, do not make it a
required status check, or pull requests that skip it will wait for it.

CI intentionally disables PHP coverage for speed. Reproduce its dependency
installation with the lock files (`composer install`, `npm ci`) when diagnosing
a CI-only failure. Do not fix a red job by skipping a legitimate case,
loosening an assertion, or changing generated assets without their source.

Differences to consider when a local run is green:

- CI uses PHP 8.3 even if the workstation runs a newer compatible version;
- CI supplies clean MariaDB 12 and MySQL 8.4 services and executes integration
  tests that a host run may skip;
- CI starts from a clean checkout without ignored local files;
- frontend dependencies come from `package-lock.json`; and
- jobs have a 15-minute limit, except the five-minute documentation job and
  the 25-minute accessibility job.

Inspect the first meaningful failure, reproduce its job locally, and keep the
fix scoped to the broken contract. A cancelled older workflow run is normal:
the workflow's concurrency setting retains only the latest run for a ref.

## Review checklist

- Each changed behavior has a test at the smallest scope that can truthfully
  prove it; integration-sensitive behavior is not hidden behind mocks.
- Tests live with the owning core, domain, repository, service, module, view,
  security, integration, or frontend area and use behavior-oriented names.
- Positive, boundary, invalid, unauthorized, empty, and dependency-failure
  cases are covered according to risk.
- Doubles preserve relevant production semantics and shared support fakes have
  not grown into incomplete alternate implementations.
- Superglobals, streams, cookies, output, timers, DOM, environment, temporary
  files, and mutable statics are restored after every case.
- Database integration uses only disposable resources and asserts real rows,
  constraints, ordering, transactions, or database-specific behavior.
- The bug test fails without the fix and asserts the observable regression,
  including absent side effects where relevant.
- Focused checks passed during iteration; complete PHP/frontend/security/docs
  gates passed before handoff; skips and unavailable infrastructure are stated.
- Coverage work targets meaningful missing behavior, and the maintained
  coverage backlog is updated only from a complete green measurement.

Use the reproducibility and measurement contract in
[Performance](https://github.com/bfhp/stream-engine/wiki/Developer-Performance) for query-plan checks, request
comparisons, frontend cost, and controlled benchmarks. Do not turn ordinary CI
timing into a brittle latency assertion.

The clean-checkout matrix, installation and upgrade rehearsals, dependency
audit, tag gate, and public-package verification are defined in
[Releases](https://github.com/bfhp/stream-engine/wiki/Developer-Releases).

[Back: API and security](https://github.com/bfhp/stream-engine/wiki/Developer-API-and-Security) · [Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide) · [Next: Localization and accessibility](https://github.com/bfhp/stream-engine/wiki/Developer-Localization-and-Accessibility)
