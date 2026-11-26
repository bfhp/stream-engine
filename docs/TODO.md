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

## P2

### Build uncached feed sitemap URLs without reading feed rows

A feed sitemap file of 30,000 library pages takes 0.4-0.6 s on production
(0.8.7) when its URLs are cached, but about 4.5 s when they are not: the
first request after Memcached empties or a URL's daily `CACHE_TTL` expires.
Measured on 26.11.2026 with the file's URLs deleted from the cache:
`hydrateTree()` 2.8 s (2.57 s of SQL: 33,000 full rows read by PRIMARY,
mostly from disk, for the `id`, `parent_id`, `type` and `slug` URLs need)
and `Cache::setMany()` 1.0 s for 30,000 URLs; the page query 0.3 s.

Done when a file with none of its URLs cached is built in about a second on
production-sized data, measured by phase.

## File maintenance rule

A new task must describe the observable problem, the desired outcome, and,
where important, how completion will be verified. Keep diagnostic notes and
reports about completed work in commits/PRs instead of turning TODO back into a
changelog.
