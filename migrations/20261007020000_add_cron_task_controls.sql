ALTER TABLE `cron_runs`
  ADD COLUMN `is_enabled` tinyint(1) NOT NULL DEFAULT 1 AFTER `task`,
  ADD COLUMN `last_trigger` enum('scheduled','manual') DEFAULT NULL AFTER `last_status`;
