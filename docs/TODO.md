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

CI checks the results on MariaDB 12 and MySQL 8.4, not the plans. Done when
the same cases (`storage/diag/explain-feed-acl.php` in the HSG Magic
repository is one way to run them) show the same access paths on MariaDB 12
and MySQL 8.4 (`EXPLAIN ANALYZE` there), or the differences are fixed or
documented in the methods' `Uses index` annotations.

## P2

### Find where feed sitemap files spend their time

On production (0.8.5, 330,000 feeds) a feed sitemap file takes seconds
although its query is fast: the page query of `findPublicByTypePage()` takes
0.3-0.7 s on the same data on the stand. Measured with curl on 25.11.2026,
second and third requests:

| File | URLs | Time |
| --- | --- | --- |
| `sitemap-lib-page.xml` | 30,000 | 5.6 s (9.5 s cold) |
| `sitemap-lib-page-10.xml` | 30,000 | 2.1-2.2 s (4.0 s cold) |
| `sitemap-lib-page-11.xml` | about 8,700 | 2.2-2.3 s (4.5 s cold) |

The time does not follow the number of URLs (-11 has a third of -10's), and
the first file, the newest library pages, takes more than twice as long as
the others. Candidates, none measured yet: one Memcached round trip per URL
in `UrlGenerator::feeds()` (a get and a set each, on a shared 64 MB instance
with heavy eviction), `hydrateTree()` over the pages' parents, and per-request
setup. Done when the phases of one file (query, URL generation, XML) are
measured on production-sized data, and the slow one is fixed or documented.

## File maintenance rule

A new task must describe the observable problem, the desired outcome, and,
where important, how completion will be verified. Keep diagnostic notes and
reports about completed work in commits/PRs instead of turning TODO back into a
changelog.
