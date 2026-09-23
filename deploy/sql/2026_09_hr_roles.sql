-- Custom HR roles + assign to existing users.
-- Run in phpMyAdmin if Access Control → Create role fails.
-- Safe to re-run.

ALTER TABLE `users`
  MODIFY `role` VARCHAR(40) NOT NULL DEFAULT 'user';

CREATE TABLE IF NOT EXISTS `hr_roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `role_key` varchar(40) NOT NULL,
  `name` varchar(80) NOT NULL,
  `based_on` varchar(40) NOT NULL DEFAULT 'user',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_roles_role_key_unique` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `hr_roles` (`role_key`, `name`, `based_on`, `is_system`, `created_at`, `updated_at`) VALUES
  ('admin', 'Administrator', 'admin', 1, NOW(), NOW()),
  ('hr', 'HR', 'hr', 1, NOW(), NOW()),
  ('supervisor', 'Supervisor', 'supervisor', 1, NOW(), NOW()),
  ('user', 'User', 'user', 1, NOW(), NOW()),
  ('employee', 'Employee', 'employee', 1, NOW(), NOW());
