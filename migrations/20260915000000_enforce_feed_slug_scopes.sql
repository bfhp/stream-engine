ALTER TABLE feeds
  ADD COLUMN slug_parent_key int(10) unsigned
    GENERATED ALWAYS AS (coalesce(parent_id, 0)) STORED AFTER slug,
  ADD UNIQUE KEY feeds_parent_type_slug_unique (slug_parent_key, type, slug);
