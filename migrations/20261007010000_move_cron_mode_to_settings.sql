INSERT INTO `settings` (`setting_key`, `setting_value`, `updated_at`)
VALUES ('cron.mode', 'os', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
