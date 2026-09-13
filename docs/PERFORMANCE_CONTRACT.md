# Performance contract: query index documentation

This project has one concrete, enforced performance rule:

> Every Repository method that filters, joins or sorts rows (`WHERE`, `JOIN`,
> `ORDER BY`, `GROUP BY`) must state, in its PHPDoc block, which MariaDB
> index it relies on — or explicitly say that no index applies and why.

This is not a style preference. It exists so that the index a query needs is
visible in the code review diff, instead of depending on someone remembering
to run `EXPLAIN` before merging. If a migration ever drops or renames an
index, `grep -r "Uses index: <name>"` immediately tells you every call site
that breaks.

## Format

Put one line per index used, at the top of the method's docblock:

```php
/**
 * Uses index: feeds_parent_id_position_index (parent_id, position)
 */
public function findByParent(int $parentId, User $user, int $limit = 20): array
```

For queries spanning a join, document each table's index separately:

```php
/**
 * Uses index: PRIMARY(id) on conversations.
 * Uses index: PRIMARY(conversation_id, user_id) on conversation_participants.
 */
```

If a query is intentionally unindexed (e.g. a full settings-table load, or a
boot-time page-tree read), say so instead of leaving it silent:

```php
/**
 * Warning: no index; intentional full table load for small key-value settings cache.
 */
```

If a query can only degrade gracefully (e.g. offset pagination, a `LIKE`
without a usable prefix index), document the risk and, where relevant, the
fix that would remove it:

```php
/**
 * Warning: no index; offset pagination over the ACL-filtered feed list can scan broadly.
 * TODO: replace with cursor pagination backed by an ordered index.
 */
```

## Where this applies

Any class under `src/` matching `*Repository.php`.

The convention is applied on a best-effort basis to other classes that talk
to `PdoDatabase` directly (services, controllers), but Repository classes are
where it's mandatory and checked automatically.

## Enforcement

There's no automated check for this - it's enforced through code review.
When you add or change a Repository method that filters, joins or sorts,
the PR should include the "Uses index: ..." (or "Warning: no index; ...")
line, and reviewers should treat a missing one as a review comment, the
same way they would for a missing type hint.
