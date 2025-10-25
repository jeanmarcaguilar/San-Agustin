-- Migration: add 2FA fields to login_db.users
CREATE DATABASE IF NOT EXISTS `login_db`;
USE `login_db`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `twofa_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role`,
  ADD COLUMN IF NOT EXISTS `twofa_code_hash` VARCHAR(255) NULL DEFAULT NULL AFTER `twofa_enabled`,
  ADD COLUMN IF NOT EXISTS `twofa_expires_at` DATETIME NULL DEFAULT NULL AFTER `twofa_code_hash`;

-- Optional helper index for expiry lookups
CREATE INDEX IF NOT EXISTS `idx_users_twofa_expires` ON `users` (`twofa_expires_at`);
