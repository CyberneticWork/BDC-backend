-- Employee qualifications add-on (enable per company in Cybernetic Admin).
-- Safe to re-run. The API also creates this table on first save if it is missing.

CREATE TABLE IF NOT EXISTS `employee_qualifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'completed',
  `qualification_type` varchar(80) DEFAULT NULL,
  `course_name` varchar(191) DEFAULT NULL,
  `institute_name` varchar(191) DEFAULT NULL,
  `completion_year` smallint unsigned DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_qualifications_employee_id_index` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
