UPDATE feeds AS duplicate
JOIN feeds AS original
  ON original.id < duplicate.id
  AND original.parent_id <=> duplicate.parent_id
  AND original.type = duplicate.type
  AND original.slug = duplicate.slug
SET duplicate.slug = CONCAT(
  LEFT(duplicate.slug, 150 - CHAR_LENGTH(CAST(duplicate.id AS char)) - 7),
  '--feed-',
  duplicate.id
)
WHERE duplicate.slug IS NOT NULL;

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE feeds
  ADD COLUMN slug_parent_key int(10) unsigned
    GENERATED ALWAYS AS (coalesce(parent_id, 0)) STORED AFTER slug,
  ADD UNIQUE KEY feeds_parent_type_slug_unique (slug_parent_key, type, slug);

SET FOREIGN_KEY_CHECKS = 1;
