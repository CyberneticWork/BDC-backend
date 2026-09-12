-- Admin-allocated ACL for HR users. Ignore duplicate column/table errors.

ALTER TABLE `users`
  ADD COLUMN `acl_customized` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`;

CREATE TABLE IF NOT EXISTS `user_acl_permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `module_key` varchar(80) NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT 0,
  `can_add` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  `can_approve` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_acl_user_module_unique` (`user_id`,`module_key`),
  KEY `user_acl_permissions_module_key_index` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
