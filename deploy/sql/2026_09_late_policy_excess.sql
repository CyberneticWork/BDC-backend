-- Opt-in late attendance policy per company + >30m leave/reject decisions.
-- Ignore duplicate-column / table errors.

-- Safe to re-run: skip columns that already exist (MariaDB / MySQL 8.0.12+).
ALTER TABLE `companies`
  ADD COLUMN IF NOT EXISTS `nopay_working_days` TINYINT UNSIGNED NOT NULL DEFAULT 30;

ALTER TABLE `companies`
  ADD COLUMN IF NOT EXISTS `late_attendance_policy_enabled` TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `excess_late_decisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` BIGINT UNSIGNED NOT NULL,
  `late_date` DATE NOT NULL,
  `late_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
  `shift_minutes` INT UNSIGNED NOT NULL DEFAULT 480,
  `action` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `leave_type` VARCHAR(50) NULL,
  `leave_id` BIGINT UNSIGNED NULL,
  `nopay_id` BIGINT UNSIGNED NULL,
  `deduct_from` VARCHAR(20) NULL,
  `nopay_days` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `nopay_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `decided_by` BIGINT UNSIGNED NULL,
  `decided_at` TIMESTAMP NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `excess_late_emp_date_unique` (`employee_id`, `late_date`),
  KEY `excess_late_date_action` (`late_date`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
