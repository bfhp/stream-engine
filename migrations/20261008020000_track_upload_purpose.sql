ALTER TABLE `uploads`
  ADD COLUMN `purpose` varchar(40) DEFAULT NULL AFTER `original_name`,
  ADD KEY `uploads_purpose_created_at_index` (`purpose`, `created_at`);
