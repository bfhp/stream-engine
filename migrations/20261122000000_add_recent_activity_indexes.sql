-- The admin dashboard's "Recent activity" card reads the newest rows of
-- feeds and users ordered by (created_at DESC, id DESC). Neither table had an
-- index leading with created_at, so each request sorted the whole table -
-- feeds holds every comment and forum reply as well as posts.
ALTER TABLE `feeds`
  ADD KEY `feeds_created_at_id_index` (`created_at`,`id`);

ALTER TABLE `users`
  ADD KEY `users_created_at_id_index` (`created_at`,`id`);
