CREATE TABLE `admin_dashboard_layouts` (
  `user_id` int(10) unsigned NOT NULL,
  `layout_version` int(10) unsigned NOT NULL,
  `layout_json` longtext NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_admin_dashboard_layout_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
