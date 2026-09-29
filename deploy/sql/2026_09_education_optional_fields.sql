-- Education section fields are optional (employees may have no or partial qualification details).
-- Safe to run on any database, whether or not the education tables exist yet.
-- The API also applies this automatically the first time an employee with the Education step is saved.

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

-- Tables created by the earlier version had NOT NULL columns; relax them (no-op when already nullable).
ALTER TABLE `employee_qualifications`
  MODIFY `qualification_type` varchar(80) NULL DEFAULT NULL,
  MODIFY `institute_name` varchar(191) NULL DEFAULT NULL;

ALTER TABLE `employee_following_qualifications`
  MODIFY `qualification_name` varchar(191) NULL DEFAULT NULL,
  MODIFY `institute_name` varchar(191) NULL DEFAULT NULL,
  MODIFY `start_year` smallint unsigned NULL DEFAULT NULL,
  MODIFY `start_month` tinyint unsigned NULL DEFAULT NULL,
  MODIFY `lecture_type` varchar(10) NULL DEFAULT NULL;
