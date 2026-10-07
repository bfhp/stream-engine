ALTER TABLE `cron_runs`
  ADD COLUMN `manual_requested_at` int(10) unsigned DEFAULT NULL AFTER `last_trigger`;
