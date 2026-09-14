-- Generated installation schema. Do not edit by hand.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `conversations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `direct_key` varchar(50) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `created_at` int(10) unsigned NOT NULL DEFAULT unix_timestamp(),
  `last_message_id` bigint(20) unsigned DEFAULT NULL,
  `last_message_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_direct_key` (`direct_key`),
  KEY `idx_last_message_at` (`last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `conversation_participants` (
  `conversation_id` bigint(20) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `role` enum('member','admin') DEFAULT 'member',
  `last_read_message_id` bigint(20) unsigned DEFAULT NULL,
  `joined_at` int(10) unsigned NOT NULL DEFAULT unix_timestamp(),
  `last_typing_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`conversation_id`,`user_id`),
  KEY `idx_user` (`user_id`,`conversation_id`),
  KEY `conversation_participants_conversation_typing_index` (`conversation_id`,`last_typing_at`,`user_id`),
  CONSTRAINT `fk_cp_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `cron_runs` (
  `task` varchar(128) NOT NULL,
  `last_run` int(11) NOT NULL,
  `locked_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`task`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `email_verifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `email_verifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `feeds` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `container_id` int(10) unsigned DEFAULT NULL,
  `container_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `owner_id` int(10) unsigned NOT NULL,
  `slug` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `slug_parent_key` int(10) unsigned GENERATED ALWAYS AS (coalesce(`parent_id`,0)) STORED,
  `title` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `views` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` int(10) unsigned NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  `visibility` enum('public','members','private') NOT NULL DEFAULT 'public',
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `rating_sum` int(10) unsigned NOT NULL DEFAULT 0,
  `rating_count` int(10) unsigned NOT NULL DEFAULT 0,
  `rating_avg` decimal(3,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `feeds_parent_type_slug_unique` (`slug_parent_key`,`type`,`slug`),
  KEY `feeds_parent_id_position_index` (`parent_id`,`position`),
  KEY `feeds_parent_id_type_created_at_id_index` (`parent_id`,`type`,`created_at` DESC,`id` DESC),
  KEY `feeds_parent_id_type_position_index` (`parent_id`,`type`,`position`),
  KEY `feeds_slug_index` (`slug`),
  KEY `owner_id` (`owner_id`),
  KEY `parent_id` (`parent_id`),
  KEY `feeds_type_created_at_id_index` (`type`,`created_at` DESC,`id` DESC),
  KEY `feeds_parent_id_slug_type_index` (`parent_id`,`slug`,`type`),
  KEY `feeds_type_rating_avg_id_index` (`type`,`rating_avg` DESC,`id` DESC),
  FULLTEXT KEY `feeds_fulltext_title_content` (`title`,`content`),
  FULLTEXT KEY `feeds_fulltext_title` (`title`),
  CONSTRAINT `feeds_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feeds_ibfk_2` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `feed_favorites` (
  `feed_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`feed_id`,`user_id`),
  KEY `feed_favorites_user_id_created_at_index` (`user_id`,`created_at`),
  CONSTRAINT `feed_favorites_feed_fk` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feed_favorites_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `feed_metadata` (
  `feed_id` int(10) unsigned NOT NULL,
  `name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`feed_id`,`name`),
  KEY `feed_metadata_name_content_index` (`name`,`content`(191)),
  KEY `feed_metadata_name_index` (`name`),
  CONSTRAINT `feed_metadata_feed_fk` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `feed_polls` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `feed_id` int(10) unsigned NOT NULL,
  `question` varchar(255) NOT NULL,
  `max_choices` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `allow_revote` tinyint(1) NOT NULL DEFAULT 0,
  `results_visibility` enum('always','after_vote','after_close') NOT NULL DEFAULT 'always',
  `closes_at` int(10) unsigned DEFAULT NULL,
  `voters_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` int(10) unsigned NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `feed_polls_feed_id_unique` (`feed_id`),
  CONSTRAINT `feed_polls_feed_fk` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `feed_poll_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `poll_id` int(10) unsigned NOT NULL,
  `text` varchar(255) NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `votes_count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `feed_poll_options_poll_id_position_index` (`poll_id`,`position`),
  CONSTRAINT `feed_poll_options_poll_fk` FOREIGN KEY (`poll_id`) REFERENCES `feed_polls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `feed_poll_votes` (
  `poll_id` int(10) unsigned NOT NULL,
  `option_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`poll_id`,`option_id`,`user_id`),
  KEY `feed_poll_votes_option_fk` (`option_id`),
  KEY `feed_poll_votes_user_fk` (`user_id`),
  KEY `feed_poll_votes_poll_id_user_id_index` (`poll_id`,`user_id`),
  CONSTRAINT `feed_poll_votes_option_fk` FOREIGN KEY (`option_id`) REFERENCES `feed_poll_options` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feed_poll_votes_poll_fk` FOREIGN KEY (`poll_id`) REFERENCES `feed_polls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feed_poll_votes_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `feed_ratings` (
  `feed_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `value` tinyint(3) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`feed_id`,`user_id`),
  KEY `feed_ratings_user_fk` (`user_id`),
  CONSTRAINT `feed_ratings_feed_fk` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feed_ratings_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `feed_reads` (
  `parent_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `position` int(10) unsigned DEFAULT NULL,
  `read_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`parent_id`,`user_id`),
  KEY `feed_reads_user_id_read_at_index` (`user_id`,`read_at`),
  CONSTRAINT `feed_reads_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feed_reads_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `feed_terms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `parent_key` int(10) unsigned GENERATED ALWAYS AS (coalesce(`parent_id`,0)) STORED,
  `vocabulary` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `feed_terms_vocabulary_parent_name_unique` (`vocabulary`,`parent_key`,`name`),
  UNIQUE KEY `feed_terms_vocabulary_parent_slug_unique` (`vocabulary`,`parent_key`,`slug`),
  KEY `feed_terms_parent_id_index` (`parent_id`),
  KEY `feed_terms_vocabulary_slug_index` (`vocabulary`,`slug`),
  CONSTRAINT `feed_terms_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `feed_terms` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `feed_term_links` (
  `feed_id` int(10) unsigned NOT NULL,
  `term_id` int(10) unsigned NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`feed_id`,`term_id`),
  KEY `feed_term_links_term_position_index` (`term_id`,`position`),
  CONSTRAINT `feed_term_links_feed_fk` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feed_term_links_term_fk` FOREIGN KEY (`term_id`) REFERENCES `feed_terms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `memberships` (
  `container_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `joined_at` int(10) unsigned NOT NULL,
  `membership_role_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`container_id`,`user_id`),
  KEY `fk_membership_role` (`membership_role_id`),
  KEY `memberships_user_container` (`user_id`,`container_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_membership_role` FOREIGN KEY (`membership_role_id`) REFERENCES `membership_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `memberships_ibfk_1` FOREIGN KEY (`container_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `memberships_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `membership_roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `role_level` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `menu` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent` int(10) unsigned DEFAULT NULL,
  `menu_group` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `page_id` int(10) unsigned DEFAULT NULL,
  `type` enum('internal','external','action','divider','dynamic') NOT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `label` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sort_order` int(10) unsigned DEFAULT 0,
  `access_rule` varchar(20) NOT NULL DEFAULT 'public' CHECK (`access_rule` in ('public','authenticated','moderator','admin')),
  PRIMARY KEY (`id`),
  UNIQUE KEY `menu_group` (`menu_group`,`parent`,`sort_order`),
  KEY `menu_pages_id_fk` (`page_id`),
  KEY `menu_sort_order_id_index` (`sort_order`,`id`),
  CONSTRAINT `menu_pages_id_fk` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `reply_to_message_id` bigint(20) unsigned DEFAULT NULL,
  `attachment_upload_id` int(11) DEFAULT NULL,
  `text` text NOT NULL,
  `created_at` int(10) unsigned NOT NULL DEFAULT unix_timestamp(),
  `updated_at` int(10) unsigned DEFAULT NULL,
  `deleted_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_conv_id` (`conversation_id`,`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_messages_reply_to` (`reply_to_message_id`),
  KEY `idx_messages_attachment` (`attachment_upload_id`),
  KEY `idx_messages_conversation_deleted_updated` (`conversation_id`,`deleted_at`,`updated_at`),
  CONSTRAINT `fk_messages_attachment` FOREIGN KEY (`attachment_upload_id`) REFERENCES `uploads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_messages_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_reply_to` FOREIGN KEY (`reply_to_message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_messages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `notification_deliveries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recipient_user_id` int(10) unsigned NOT NULL,
  `notification_type` varchar(100) NOT NULL,
  `channel` varchar(32) NOT NULL,
  `delivery` varchar(16) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `message_text` text DEFAULT NULL,
  `deduplication_key` varchar(190) DEFAULT NULL,
  `scheduled_at` int(10) unsigned NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(1000) DEFAULT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `claim_token` char(32) DEFAULT NULL,
  `claimed_at` int(10) unsigned DEFAULT NULL,
  `created_at` int(10) unsigned NOT NULL DEFAULT unix_timestamp(),
  `sent_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notification_deliveries_deduplication_unique` (`recipient_user_id`,`notification_type`,`channel`,`deduplication_key`),
  KEY `notification_deliveries_queue_index` (`status`,`channel`,`delivery`,`scheduled_at`,`id`),
  KEY `notification_deliveries_recipient_created_index` (`recipient_user_id`,`created_at`,`id`),
  KEY `notification_deliveries_claim_token_index` (`claim_token`),
  CONSTRAINT `notification_deliveries_user_fk` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `notification_preferences` (
  `user_id` int(10) unsigned NOT NULL,
  `notification_type` varchar(100) NOT NULL,
  `channel` varchar(32) NOT NULL,
  `delivery` varchar(16) NOT NULL,
  PRIMARY KEY (`user_id`,`notification_type`,`channel`),
  CONSTRAINT `notification_preferences_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `notification_unsubscribe_tokens` (
  `user_id` int(10) unsigned NOT NULL,
  `token` char(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` int(10) unsigned NOT NULL DEFAULT unix_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `notification_unsubscribe_tokens_token_unique` (`token`),
  CONSTRAINT `notification_unsubscribe_tokens_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `pages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent` int(10) unsigned DEFAULT NULL,
  `pattern` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `action` varchar(200) NOT NULL,
  `page_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `settings` longtext DEFAULT NULL,
  `feed_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `list_feed_type` varchar(50) DEFAULT NULL,
  `term_vocabulary` varchar(50) DEFAULT NULL,
  `feed_id` int(10) unsigned DEFAULT NULL,
  `changefreq` enum('always','hourly','daily','weekly','monthly','yearly','never','noindex') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `updated` int(10) unsigned NOT NULL,
  `access_rule` varchar(20) NOT NULL DEFAULT 'public' CHECK (`access_rule` in ('public','authenticated','moderator','admin')),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `pr_created_at_index` (`created_at`),
  KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `uploads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `path` varchar(255) NOT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `size` int(11) DEFAULT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `created_at` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `uploads_user_id_size_index` (`user_id`,`size`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `nick` varchar(50) NOT NULL DEFAULT '',
  `username` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `signature` text DEFAULT NULL,
  `show_gender_publicly` tinyint(1) NOT NULL DEFAULT 0,
  `show_birth_date_publicly` tinyint(1) NOT NULL DEFAULT 0,
  `show_homepage_publicly` tinyint(1) NOT NULL DEFAULT 0,
  `hide_presence` tinyint(1) NOT NULL DEFAULT 0,
  `show_hidden_profile_to_friends` tinyint(1) NOT NULL DEFAULT 0,
  `homepage` varchar(255) NOT NULL DEFAULT '',
  `gender` varchar(16) NOT NULL DEFAULT '',
  `birth_date` date DEFAULT NULL,
  `avatar_url` varchar(255) NOT NULL DEFAULT '',
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `digest_delivery_time` time NOT NULL DEFAULT '09:00:00',
  `role` varchar(20) NOT NULL DEFAULT 'user' CHECK (`role` in ('user','moderator','admin')),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `users_username_unique` (`username`),
  KEY `users_active_created_id_index` (`is_active`,`created_at`,`id`),
  KEY `users_active_nick_id_index` (`is_active`,`nick`,`id`),
  KEY `users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `user_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `bot_name` varchar(64) DEFAULT NULL,
  `token_hash` char(64) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `expires_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  `last_used_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_expires_idx` (`user_id`,`expires_at`),
  KEY `user_id` (`user_id`),
  KEY `user_sessions_presence_index` (`last_used_at`,`user_id`,`bot_name`),
  KEY `user_sessions_expires_at_index` (`expires_at`),
  CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

SET FOREIGN_KEY_CHECKS = 1;
