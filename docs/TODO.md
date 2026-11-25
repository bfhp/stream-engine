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

### ACL-filtered feed queries do not use their declared indexes

`FeedRepository` methods that apply the row-level ACL (`applyAcl()`,
`buildAclCondition()`) declare `feeds_type_created_at_id_index` and PRIMARY
lookups, contrary to the
[performance contract](wiki/Developer-Performance.md). Measured on MariaDB
11.8 with 319,000 `lib-page` feeds (97% of `feeds`), a guest
`countByType('lib-page')` took 16.6 s against 0.3 s for the same count
without ACL:

- the outer query reads the whole table (`type: ALL`), page content included:
  the ACL needs `visibility` and `container_id`, which no index covers, and
  `type` alone selects almost every row;
- the container check (`containerAclCondition()`, added in 0.7.3 and
  annotated `Uses feeds PRIMARY(id)`) is an `EXISTS` that MariaDB rewrites to
  `IN` (`exists_to_in`) and materialises over the whole table
  (`MATERIALIZED acl_container ALL`). The comment branch, included in every
  query whatever its type, does the same (`MATERIALIZED acl_parent ALL`), and
  the gallery branch is an `EXISTS` of the same shape.

Sitemaps no longer use this path (`findPublicByTypePage()`); member and guest
listings, counts and deep `OFFSET` pages still do.

Done when each ACL check is a lookup on the index it declares - for example
correlated scalar subqueries on PRIMARY instead of `EXISTS`, and an index
covering the ACL columns where a type is not selective - and `ANALYZE` on
representative data (MariaDB 11.8 and 12, MySQL 8.4) shows no materialised
or full-table access for typed counts and pages, for guests and members.

## File maintenance rule

A new task must describe the observable problem, the desired outcome, and,
where important, how completion will be verified. Keep diagnostic notes and
reports about completed work in commits/PRs instead of turning TODO back into a
changelog.
