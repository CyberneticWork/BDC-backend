-- Following qualifications and previous employment add-ons (enable per company in Cybernetic Admin).
-- Safe to re-run. The API also creates these tables on first save if they are missing.

CREATE TABLE IF NOT EXISTS `employee_following_qualifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint unsigned NOT NULL,
  `qualification_name` varchar(191) DEFAULT NULL,
  `institute_name` varchar(191) DEFAULT NULL,
  `start_year` smallint unsigned DEFAULT NULL,
  `start_month` tinyint unsigned DEFAULT NULL,
  `end_year` smallint unsigned DEFAULT NULL,
  `end_month` tinyint unsigned DEFAULT NULL,
  `lecture_type` varchar(10) DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_following_qualifications_employee_id_index` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employee_previous_employments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint unsigned NOT NULL,
  `organization_name` varchar(191) NOT NULL,
  `last_designation` varchar(191) DEFAULT NULL,
  `join_date` date DEFAULT NULL,
  `last_date` date DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_previous_employments_employee_id_index` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
