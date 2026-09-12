-- Weekly off, medical claims, pending payments. Ignore Duplicate table (#1050).

CREATE TABLE IF NOT EXISTS `weekly_off_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned DEFAULT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `off_date` date NOT NULL,
  `days` decimal(8,4) NOT NULL DEFAULT 1.0000,
  `source` varchar(20) NOT NULL DEFAULT 'portal',
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `reason` varchar(1000) DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `weekly_off_employee_date` (`employee_id`,`off_date`),
  KEY `weekly_off_company_status` (`company_id`,`status`)
);

CREATE TABLE IF NOT EXISTS `employee_medical_quotas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `allocated_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_medical_quotas_employee_year` (`employee_id`,`year`)
);

CREATE TABLE IF NOT EXISTS `medical_claims` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `bill_path` varchar(500) DEFAULT NULL,
  `bill_name` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `review_note` varchar(500) DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `medical_claims_employee_status` (`employee_id`,`status`)
);

CREATE TABLE IF NOT EXISTS `pending_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned DEFAULT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `source_type` varchar(40) NOT NULL,
  `source_id` bigint unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `paid_by` bigint unsigned DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pending_payments_source` (`source_type`,`source_id`),
  KEY `pending_payments_status_type` (`status`,`source_type`)
);
