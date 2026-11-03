CREATE TABLE `auth_rate_limits` (
  `scope` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `attempts` int(10) unsigned NOT NULL,
  `window_ends_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`scope`,`subject_hash`),
  KEY `auth_rate_limits_window_ends_index` (`window_ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
