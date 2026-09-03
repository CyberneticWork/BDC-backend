-- Monthly late deduction tables (Sep 2026)
-- Safe: creates new empty tables only. Does not modify existing data.

CREATE TABLE IF NOT EXISTS `monthly_late_deduction_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `month` tinyint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `company_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'preview',
  `applied_by` bigint unsigned DEFAULT NULL,
  `applied_at` timestamp NULL DEFAULT NULL,
  `employee_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `monthly_late_deduction_runs_year_month_company_id_index` (`year`,`month`,`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monthly_late_deduction_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint unsigned DEFAULT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `month` tinyint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `total_late_minutes` int unsigned NOT NULL DEFAULT '0',
  `late_day_count` int unsigned NOT NULL DEFAULT '0',
  `free_minutes` int unsigned NOT NULL DEFAULT '90',
  `chargeable_minutes` int unsigned NOT NULL DEFAULT '0',
  `excess_minutes` int unsigned NOT NULL DEFAULT '0',
  `short_leave_count` tinyint unsigned NOT NULL DEFAULT '0',
  `short_leave_days` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `annual_leave_days` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `casual_leave_days` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `nopay_days` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `nopay_minutes` int unsigned NOT NULL DEFAULT '0',
  `annual_balance_before` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `casual_balance_before` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `annual_balance_after` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `casual_balance_after` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `band` varchar(40) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `breakdown` json DEFAULT NULL,
  `late_days` json DEFAULT NULL,
  `created_leave_ids` json DEFAULT NULL,
  `created_nopay_ids` json DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `monthly_late_emp_unique` (`employee_id`,`year`,`month`),
  KEY `monthly_late_deduction_items_year_month_status_index` (`year`,`month`,`status`),
  CONSTRAINT `monthly_late_deduction_items_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_09_03_100000_create_monthly_late_deduction_tables', 999);
