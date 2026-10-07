CREATE TABLE `mentions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `feed_id` int(10) unsigned DEFAULT NULL,
  `message_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `username_snapshot` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `first_mentioned_at` int(10) unsigned NOT NULL DEFAULT unix_timestamp(),
  `updated_at` int(10) unsigned NOT NULL DEFAULT unix_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `mentions_feed_user_unique` (`feed_id`,`user_id`),
  UNIQUE KEY `mentions_message_user_unique` (`message_id`,`user_id`),
  KEY `mentions_user_active_index` (`user_id`,`active`),
  CONSTRAINT `mentions_exactly_one_target` CHECK ((`feed_id` IS NULL) <> (`message_id` IS NULL)),
  CONSTRAINT `mentions_feed_fk` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mentions_message_fk` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mentions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
