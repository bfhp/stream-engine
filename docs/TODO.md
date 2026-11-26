# TODO

The project's current backlog. Last reviewed against the code and tests on
**November 2, 2026**.

This file contains only unfinished work and decisions that have been made but
not yet implemented. Completed work is not recorded here; Git provides that
history. When a task is completed, it should be removed from this file together
with any related `TODO` comments in the code.

Priorities:

- **P0** — a clean installation does not work, the user receives a false
  success response, or data may be lost or returned incorrectly;
- **P1** — important reliability, security, or operational work;
- **P2** — product improvements, refactoring, and localized technical debt.

## P1

### Check the feed ACL plans on MariaDB 12 and MySQL 8.4

The row-level feed ACL was rebuilt from per-type rules (scalar subqueries on
primary keys) and `feeds_type_created_at_id_index` now holds the columns it
reads (migration `20261125000000_extend_feeds_type_index_with_acl_columns`),
so ACL-filtered counts and typed pages use the indexes their methods declare.
Measured with `ANALYZE` on MariaDB 11.8.9 with production data (330,000
feeds, 309,000 of them library pages): every subquery is `eq_ref PRIMARY`,
typed counts and page ids are index-only, and no plan reads the whole
table - a guest `countByType('lib-page')` 3.9 s -> 58 ms, `findByType` at
`OFFSET 300000` 7.3 s -> 64 ms, `findByTypePage` 50 s -> 1 ms.

The PHP job of CI checks the results on MariaDB 12 and MySQL 8.4, not the
plans. `bin/explain-feed-acl.php` runs the same cases on production-shaped
data (330,000 feeds, 309,000 library pages), checks each plan against the
methods' `Uses index` annotations and compares servers; the `Feed ACL plans`
workflow runs it on MariaDB 11.8, MariaDB 12 and MySQL 8.4. Done when that
workflow passes, or the differences it reports are fixed or documented in
the methods' `Uses index` annotations.

## P2

### Verify feed sitemap files after the batched URL cache and index-only page

Measured on production (0.8.6, 26.11.2026, with diagnostic scripts run on
the server): a file of 30,000 library pages took
4-6 s - the page query 2.5-4.2 s, of which 2.6 s were 30,000 row lookups by
PRIMARY reading from disk (7.3 GB table, 128 MB buffer pool), and 30,000
Memcached gets 1.3-1.7 s at about 50 us each; everything else under 0.2 s.
The page query is now index-only (migration `20261126000000`) and the URL
cache is read and written in batches (`CacheInterface::getMany()`/`setMany()`).

Done when, after the release, the feed sitemap files take well under a
second on production, measured with the same diagnostic scripts used for
the baseline.

## File maintenance rule

A new task must describe the observable problem, the desired outcome, and,
where important, how completion will be verified. Keep diagnostic notes and
reports about completed work in commits/PRs instead of turning TODO back into a
changelog.
