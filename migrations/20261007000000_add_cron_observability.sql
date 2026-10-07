ALTER TABLE `cron_runs`
  ADD COLUMN `last_started_at` int(10) unsigned DEFAULT NULL AFTER `last_run`,
  ADD COLUMN `last_finished_at` int(10) unsigned DEFAULT NULL AFTER `last_started_at`,
  ADD COLUMN `last_status` enum('success','failed') DEFAULT NULL AFTER `last_finished_at`,
  ADD COLUMN `last_duration_ms` int(10) unsigned DEFAULT NULL AFTER `last_status`,
  ADD COLUMN `last_error` varchar(2000) DEFAULT NULL AFTER `last_duration_ms`,
  ADD COLUMN `consecutive_failures` int(10) unsigned NOT NULL DEFAULT 0 AFTER `last_error`;

CREATE TABLE `cron_scheduler_state` (
  `id` tinyint(3) unsigned NOT NULL,
  `last_started_at` int(10) unsigned DEFAULT NULL,
  `last_finished_at` int(10) unsigned DEFAULT NULL,
  `last_status` enum('success','failed') DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
