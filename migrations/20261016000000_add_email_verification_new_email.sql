-- A row of email_verifications now also stands for a pending change of a
-- member's email address: `new_email` holds the address to switch to once
-- its owner follows the link sent to it (see UserService::updateCredentials()
-- and confirmEmailChange()). Rows that confirm a registration keep it NULL.
ALTER TABLE `email_verifications`
  ADD COLUMN `new_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `user_id`;
