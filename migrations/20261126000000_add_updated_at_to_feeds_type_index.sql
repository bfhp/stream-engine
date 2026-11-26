-- Feed sitemap files read their page of feeds by PRIMARY, one row at a time:
-- the rows hold the content, and library pages' rows are large (11.8 KB on
-- average, 7.3 GB for 330,000 feeds), so on a server whose buffer pool
-- holds a small part of the table each of the 30,000 lookups of a file may
-- be a read from disk - 2.6 s of the 3.3 s of sitemap-lib-page-11.xml's
-- query on production, against 0.12-0.34 s for the index part.
--
-- With updated_at (the file's <lastmod>) in the index the page is
-- index-only (FeedRepository::findPublicByTypePage()). The index keeps its
-- name and its column order with updated_at last, so every query and
-- annotation that uses it is unchanged. Built online: the table stays
-- readable and writable.
ALTER TABLE `feeds`
  DROP INDEX `feeds_type_created_at_id_index`,
  ADD KEY `feeds_type_created_at_id_index` (`type`, `created_at` DESC, `id` DESC, `visibility`, `container_id`, `owner_id`, `parent_id`, `updated_at`),
  ALGORITHM=INPLACE, LOCK=NONE;
