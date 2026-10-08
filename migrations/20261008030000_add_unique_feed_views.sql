CREATE TABLE `feed_views` (
  `feed_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `viewed_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`feed_id`,`user_id`),
  KEY `feed_views_user_id_viewed_at_index` (`user_id`,`viewed_at`),
  CONSTRAINT `feed_views_feed_fk` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feed_views_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
