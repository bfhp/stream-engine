RENAME TABLE `cron_runs` TO `cron_tasks`;

ALTER TABLE `cron_tasks`
  ADD COLUMN `active_run_id` bigint(20) unsigned DEFAULT NULL AFTER `manual_requested_at`;

CREATE TABLE `cron_run_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task` varchar(128) NOT NULL,
  `trigger` enum('scheduled','manual') NOT NULL,
  `status` enum('queued','running','success','failed','start_failed','timed_out') NOT NULL,
  `requested_at` int(10) unsigned NOT NULL,
  `started_at` int(10) unsigned DEFAULT NULL,
  `finished_at` int(10) unsigned DEFAULT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `error` varchar(2000) DEFAULT NULL,
  `requested_by_user_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cron_run_history_task_id` (`task`,`id`),
  KEY `idx_cron_run_history_status_started` (`status`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
