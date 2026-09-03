-- Pending live tables for SPM HR (Aug 2026 features)
-- Database: spmhr_db
-- Run manually in phpMyAdmin / MySQL if artisan migrate is unavailable.

CREATE TABLE IF NOT EXISTS `employee_leave_balances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `leave_type` varchar(100) NOT NULL,
  `entitled_days` decimal(8,2) NOT NULL DEFAULT '0.00',
  `notes` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `emp_leave_balance_unique` (`employee_id`,`year`,`leave_type`),
  KEY `employee_leave_balances_year_status_index` (`year`,`status`),
  KEY `employee_leave_balances_employee_id_foreign` (`employee_id`),
  CONSTRAINT `employee_leave_balances_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `salary_advance_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `needed_on` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `review_note` varchar(500) DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `salary_advance_requests_employee_id_status_index` (`employee_id`,`status`),
  CONSTRAINT `salary_advance_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rosters status columns (run each line only if that column is missing)
-- ALTER TABLE `rosters` ADD COLUMN `status` varchar(20) NOT NULL DEFAULT 'Active' AFTER `date_to`;
-- ALTER TABLE `rosters` ADD COLUMN `cancel_reason` varchar(255) DEFAULT NULL AFTER `status`;
-- ALTER TABLE `rosters` ADD COLUMN `cancelled_at` timestamp NULL DEFAULT NULL AFTER `cancel_reason`;

-- Record migrations so future artisan migrate does not re-run them
INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_08_27_100000_add_status_to_rosters_table', 999),
  ('2026_08_27_233000_create_employee_leave_balances_table', 999),
  ('2026_08_28_000001_create_salary_advance_requests_table', 999);

