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

Where the predicate comes from: one predicate serves every type and carries
every rule. For a guest or member `countByType('lib-page')` it is 238 lines
with 15 `EXISTS`: the owner/public/member rule (30 lines), the closed
container rule (0.7.3, 28), the gallery photo rule (0.7.0, 57), and the
comment rule (0.6.1, 35) that repeats the container (28) and gallery (60)
rules for the comment's publication.

Proposed approach A (measured on MariaDB 11.8.9 with production data, not
implemented yet):

- Build the predicate from the rules of the queried type only, and write the
  owner/public/member check as one comparison of the membership role level
  (`COALESCE((SELECT role_level ... WHERE container_id = ? AND user_id = ?),
  0) >= CASE visibility WHEN 'private' THEN 2 ELSE 1 END`). Every subquery is
  a scalar lookup on a primary key, so the optimizer has no other plan; the
  same `EXISTS` was executed as `unique_subquery`, a full index scan or
  `MATERIALIZED ... ALL` depending on statistics. A typed query becomes 10-25
  lines; comments join their publication once instead of repeating rules
  through `CASE`.
- Extend `feeds_type_created_at_id_index` to `(type, created_at DESC, id DESC,
  visibility, container_id, owner_id, parent_id)` (online DDL, same name):
  typed counts become index-only (`lib-page`: 4.3 s -> 55-68 ms, `lib`:
  350 ms -> 9 ms).
- Deferred join in `findByType()` and `findByTypePage()`: page ids by the ACL
  on that index, full rows for the page only (`OFFSET 300000`: 18.5 s ->
  64 ms); `findByTypePage()` counts its total separately instead of
  `COUNT(*) OVER ()` (30-50 s for `lib-page`).
- The current predicate stays in the tests as the oracle
  (`tests/Support/LegacyFeedAcl.php`, a verbatim copy):
  `tests/Repository/FeedAclEquivalenceTest.php` compares every ACL-filtered
  read with it for 8 users and every type of `tests/Support/FeedAclFixture.php`
  (type x visibility x kind of container x owner; comments, replies and
  replies to replies; photos in every state and context). It fails for a
  dropped container rule, an ignored photo state, replies checked against
  their direct parent, a wrong role level and a narrowed moderator override.
  Rewrite one type family at a time: ordinary feeds (done:
  `ordinaryAclCondition()`, 24 lines for a member's `countByType`), comments
  (done: `commentPublicationRule()`, 53 lines), gallery photos (done:
  `photoAclCondition()`), untyped queries (done: `untypedAclCondition()`,
  127 lines; the previous predicate is gone from `FeedRepository`). Left:
  the index and the deferred join, measured on the stand.

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
