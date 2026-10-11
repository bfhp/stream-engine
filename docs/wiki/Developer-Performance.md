# Performance

Performance work in Stream Engine begins with predictable access paths,
bounded work, and measurements from a representative environment. This
chapter is the canonical performance contract for engine, module, theme, and
site code. It covers the rules that reviewers can enforce today and identifies
limits that are not yet automated.

Performance does not override correctness, authorization, accessibility, or
security. Optimize only after preserving those contracts and recording the
behavior that the change is meant to improve.

## Current performance model

Each HTTP request creates a new application composition root, connects to its
configured services, resolves the page, executes the controller, and renders a
response. MariaDB is the durable source of truth. Memcached accelerates selected
lookups, with a process-local fallback when the extension or service is not
available. Twig uses a compiled-template cache.

There is no general full-page response cache. A response may carry
`Last-Modified` metadata, but that alone does not make conditional response
caching complete. Do not design a feature on the assumption that PHP,
authorization, routing, or rendering will be skipped.

In `APP_ENV=dev`, application construction flushes the configured Memcached
service. Development requests therefore do not reproduce warm-cache production
behavior. Measure both cold and warm paths explicitly when cache behavior is
relevant.

## Document every repository access path

Every method in a `*Repository.php` class under `src/` that filters, joins,
groups, or sorts rows with `WHERE`, `JOIN`, `GROUP BY`, or `ORDER BY` must state
in its PHPDoc which MariaDB index supports the query. If no useful index
applies, document that fact and the reason.

Put the annotation at the start of the method docblock:

```php
/**
 * Uses index: feeds_parent_id_position_index (parent_id, position)
 *
 * @return list<Feed>
 */
public function findByParent(int $parentId, User $user, int $limit = 20): array
```

For a join, name the index used on each participating table:

```php
/**
 * Uses index: PRIMARY(id) on conversations.
 * Uses index: PRIMARY(conversation_id, user_id) on conversation_participants.
 */
```

An intentionally unindexed query must be equally visible:

```php
/**
 * Warning: no index; intentional full-table load for the small settings cache.
 */
```

Document known degradation rather than disguising it:

```php
/**
 * Warning: no index; ACL-filtered offset pagination can scan broadly.
 * TODO: replace with cursor pagination backed by an ordered index.
 */
```

This convention is mandatory for repositories and recommended for other code
that calls `PdoDatabase` directly. It is currently enforced by code review,
not by an automated checker. An annotation also does not prove that the
optimizer selects the index; verify important plans against MariaDB.

The annotation makes schema changes reviewable. Before renaming or removing an
index, search for its consumers:

```bash
rg "Uses index: .*index_name" src
```

Update the query, migration, install snapshot, annotation, and tests together.

## Design queries from access paths

Start with the rows and order the caller needs, then create an index whose
leading columns match equality predicates and whose remaining columns support
range predicates and ordering. Avoid adding speculative indexes: every index
also consumes storage and increases write cost.

For performance-sensitive queries:

1. Use the smallest result shape the caller needs.
2. Bind parameters and keep predicates sargable; avoid wrapping indexed columns
   in functions when an equivalent range predicate is possible.
3. Make ordering deterministic by including a stable tie-breaker such as the
   primary key.
4. Add the index through a forward migration and update the fresh-install
   schema snapshot as described in
   [Database and migrations](https://github.com/bfhp/stream-engine/wiki/Developer-Database-and-Migrations).
5. Run the query with representative row counts and value distributions.
6. Inspect `EXPLAIN` and, where supported, `ANALYZE` on the deployed MariaDB
   version.

Record the query, bound-value shape, relevant schema, row counts, selected
index, examined rows, ordering or temporary-table behavior, and runtime. A plan
from an empty test database is not evidence of production behavior.

## Prevent repeated database work

Repository calls hidden inside loops can turn one logical operation into an
N+1 query pattern. Prefer a bounded bulk lookup, an intentional join, or a
preloaded map when the complete set of identifiers is known.

`PdoDatabase` records SQL, parameters, execution time, and errors in its
per-request query log. Use that log in local diagnosis to compare query counts
and find repeated statements. Do not expose it in public responses or logs
that could disclose credentials, tokens, or personal data.

When changing a list or detail page, compare at least:

- total query count;
- repeated query shapes;
- slowest individual queries;
- total database time; and
- the same measurements at the largest supported page size.

Unit tests built on mocked database calls can verify orchestration, but they do
not prove SQL validity, optimizer behavior, constraints, or transaction
semantics. Use disposable-MariaDB integration tests for those claims.

## Keep lists and work bounded

Every user-controlled or data-dependent collection must have an explicit
upper bound. Validate page sizes, upload counts, batch sizes, recursion depth,
and retry counts on the server even if the browser also limits them.

For growing, ordered feeds, prefer keyset pagination with an opaque cursor. The
cursor must contain the complete deterministic sort tuple, and the query should
request `limit + 1` rows to determine whether another page exists. Preserve
authorization predicates in the paginated query; never fetch a broad result
and filter access in PHP.

Offset pagination remains acceptable for small, bounded administration lists
or compatibility paths when its scan cost is understood and documented. It is
not a default for unbounded public feeds.

Bulk maintenance must process finite batches and stop after an explicit number
of batches or other safe boundary. Session cleanup is one example of this
model. A task must remain resumable after partial progress.

## Use cache as an optimization

`Cache` uses Memcached when the PHP extension and configured service are
available. Otherwise it falls back to an in-process array for the lifetime of
the current request. Code must therefore remain correct with an empty cache,
with cache failures, and without persistence between requests.

The current cache contract has several important details:

- the configured prefix is concatenated with cache keys;
- the default TTL is 3,600 seconds unless a caller supplies another value;
- `get()` returns `null` for a miss or failure;
- `getOrSet()` treats `null` as a miss, so a meaningful `null` value cannot be
  cached through that API;
- there is no built-in stampede protection or atomic recomputation lock; and
- `flush()` clears the entire configured Memcached service, not only keys with
  the application prefix.

Cache keys must encode every input that changes the result, including locale,
permissions, site, theme, and relevant configuration where applicable. Cache
only data that can be reconstructed from an authoritative source. Define
invalidation before adding the cached read and invalidate after a successful
database commit.

Use `flush()` only when clearing the whole Memcached service is intended and
safe. Shared Memcached deployments should use targeted key deletion or versioned
key namespaces instead.

Because no stampede protection exists, expensive cache fills should stay
bounded and idempotent. If concurrent recomputation would overload a dependency,
add an explicit lock with a timeout and failure path rather than assuming
`getOrSet()` serializes callers.

## Protect the request and rendering hot path

The composition root runs on every request. Avoid filesystem scans, remote
calls, schema inspection, or work proportional to all database rows during
bootstrap. Extension discovery and configuration validation must be predictable
and should not conceal network I/O.

Controllers should perform authorization before expensive data loading and
should pass already prepared view data to Twig. Templates must not query the
database or call remote services. Avoid repeatedly sorting, translating, or
serializing the same collection in nested template loops.

Twig's compiled cache avoids recompiling unchanged templates; it does not cache
rendered HTML. Theme and module overrides must therefore be measured as part of
the real render path.

The footer runtime is useful as a local signal, but a single wall-clock number
is not a benchmark. It includes whichever database, cache, filesystem, and
rendering work happened in that request and is affected by warm-up and host
load.

## Bound external I/O and uploads

Remote services and object storage add latency outside PHP and MariaDB. Keep
connection and operation timeouts finite, validate sizes before expensive
processing, stream large payloads where the current API supports it, and avoid
holding a database transaction open during network I/O.

Upload workflows must bound file size, count, image dimensions, decoding work,
and generated variants. Store durable metadata only after the object operation
has reached the state required by the workflow, and define cleanup or retry
behavior for partial failure. Do not retry indefinitely inside an HTTP request.

When an external operation is too slow or unreliable for the request path,
move it to an explicit scheduled task only if the product can represent queued,
running, succeeded, and failed states safely.

## Design background work for predictable load

The scheduler is one-shot: the operating system starts `CronRunner`, which
selects due tasks and exits. The runner uses a process-level scheduler lock and
database-backed per-task locks; manual runs skip the schedule interval and
global scheduler lock but still use the task lock.

A scheduled task should:

- process a bounded batch;
- be idempotent or safely resumable;
- hold locks only as long as necessary;
- use finite timeouts for external dependencies;
- record useful counts and duration without secrets; and
- leave enough information to diagnose and retry a partial failure.

Do not turn a cron invocation into an unbounded queue consumer. If backlog can
grow faster than one safe batch can drain it, expose that condition through
task history or operational monitoring and revisit the workload design.

## Control frontend and response cost

Vite creates separate public and administration entry points plus module and
theme contributions. Preserve lazy loading for administration routes and avoid
placing page-specific code in shared entries. Review generated asset changes;
the build reports chunk sizes, but the repository currently has no automated
bundle-size budget.

For browser work, measure transferred compressed bytes, parsed JavaScript,
rendering work, and request count on a production build. Test slow devices and
networks when the change affects initial interaction. Optimize images and avoid
shipping data or markup that the current view does not use.

Keep JSON and HTML collections paginated. Compression, browser caching, and a
CDN can reduce transfer cost, but they do not make an unbounded response safe.
Preserve semantic markup and accessible interaction when reducing or deferring
frontend work.

## Measure a change reproducibly

Define the question before choosing a tool. A useful comparison records:

- the exact revision and configuration;
- PHP, MariaDB, Memcached, Node.js, and browser versions where relevant;
- workload, representative data volume, and data distribution;
- cache condition: disabled, cold, or warm;
- concurrency and sample count;
- latency distribution rather than only an average;
- query count and database time for server work;
- response bytes and bundle or chunk sizes for frontend work; and
- CPU, memory, error, and timeout observations when relevant.

Warm up deliberately, keep the environment stable, and compare the same
workload before and after the change. Report median and tail latency such as
p95, not only the best run. Keep raw commands or scripts with the pull request
when they are necessary to reproduce the conclusion.

Use production-like data without copying secrets or personal data into a
developer environment. Generated data should preserve the cardinality and
skew that influence the access path.

## Known limits

The current repository does not provide:

- an automated repository-index annotation check;
- a general HTTP response cache;
- cache stampede protection;
- per-prefix Memcached flush semantics;
- an application performance monitoring integration;
- continuous load or benchmark jobs;
- enforced query-count, latency, memory, or bundle-size budgets; or
- proof of MariaDB query plans from mocked repository tests.

Do not describe these capabilities as present. A contribution that introduces
one should document its operating model, failure modes, cost, privacy impact,
and verification path.

## Pull-request checklist

Before merging a performance-sensitive change, confirm that:

- correctness, authorization, security, localization, and accessibility remain
  intact;
- repository queries name their supporting indexes or explain why none applies;
- important MariaDB plans were checked with representative data;
- query count and repeated-query behavior were compared;
- list sizes, batches, retries, recursion, uploads, and external calls are
  bounded;
- cache keys, TTL, invalidation, failure behavior, and shared-service impact are
  documented;
- scheduled work is locked, bounded, observable, and resumable;
- generated frontend assets and response sizes were reviewed;
- benchmark conditions and before/after results are reproducible; and
- the normal project gate passes:

```bash
php bin/check-wiki-docs.php
composer lint
composer test
composer audit:csrf
npm test
npm run typecheck
npm run build
```

Performance assertions that depend on shared CI timing are usually unstable.
Test bounded behavior and query structure in the normal suite; keep controlled
benchmarks separate until the project establishes dedicated infrastructure and
budgets.

[Back: Localization and accessibility](https://github.com/bfhp/stream-engine/wiki/Developer-Localization-and-Accessibility) · [Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide) · [Next: Releases](https://github.com/bfhp/stream-engine/wiki/Developer-Releases)
