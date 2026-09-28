-- O/L results and A/L details add-ons (enable per company in Cybernetic Admin).
-- Safe to re-run. The API also creates this table on first save if it is missing.

CREATE TABLE IF NOT EXISTS `employee_school_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint unsigned NOT NULL,
  `ol_english_grade` varchar(5) DEFAULT NULL,
  `ol_maths_grade` varchar(5) DEFAULT NULL,
  `ol_year` smallint unsigned DEFAULT NULL,
  `al_syllabus` varchar(20) DEFAULT NULL,
  `al_stream` varchar(100) DEFAULT NULL,
  `al_year` smallint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_school_results_employee_id_unique` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
