-- Company locations (Department Master > Locations) and employee location assignment.
-- The API also creates these on first use. The ALTER fails harmlessly if location_id already exists.

CREATE TABLE IF NOT EXISTS `company_locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(191) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_locations_company_id_index` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `organization_assignments`
  ADD COLUMN `location_id` bigint unsigned NULL,
  ADD KEY `organization_assignments_location_id_index` (`location_id`);
