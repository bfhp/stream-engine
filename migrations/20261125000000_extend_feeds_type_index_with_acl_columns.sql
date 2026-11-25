-- Typed feed listings filter every row through the row-level ACL, which reads
-- visibility, container_id, owner_id and parent_id. None of them was in an
-- index, so for a type that is most of the table (library pages: 309,000 of
-- 330,000 feeds) MariaDB read every row, content included: a guest
-- countByType('lib-page') took 4 s and a page at OFFSET 300,000 18 s.
--
-- The index keeps its name and its leading (type, created_at, id) order, so
-- every query and annotation that uses it is unchanged; the ACL columns make
-- typed counts and the id step of typed pages index-only (55 ms and 64 ms on
-- the same data). Built online: the table stays readable and writable.
ALTER TABLE `feeds`
  DROP INDEX `feeds_type_created_at_id_index`,
  ADD KEY `feeds_type_created_at_id_index` (`type`, `created_at` DESC, `id` DESC, `visibility`, `container_id`, `owner_id`, `parent_id`),
  ALGORITHM=INPLACE, LOCK=NONE;
