-- Time card pending approval + audit trail (live).
-- Ignore Duplicate column (#1060) / table exists errors if already applied.

ALTER TABLE `time_cards`
  ADD COLUMN `entry_source` VARCHAR(20) NULL AFTER `approval_status`;

ALTER TABLE `time_cards`
  ADD COLUMN `created_by` BIGINT UNSIGNED NULL AFTER `entry_source`;

CREATE TABLE IF NOT EXISTS `time_card_audits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `time_card_id` BIGINT UNSIGNED NULL,
  `employee_id` BIGINT UNSIGNED NULL,
  `entry_date` DATE NULL,
  `action` VARCHAR(30) NOT NULL,
  `source` VARCHAR(20) NULL,
  `reason` TEXT NULL,
  `old_values` JSON NULL,
  `new_values` JSON NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `time_card_audits_entry_date_action_index` (`entry_date`, `action`),
  KEY `time_card_audits_time_card_id_index` (`time_card_id`),
  KEY `time_card_audits_employee_id_index` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
