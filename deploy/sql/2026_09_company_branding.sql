-- Company branding (separate URL, logo, theme). Ignore duplicate-column errors.

ALTER TABLE `companies` ADD COLUMN `slug` VARCHAR(80) NULL UNIQUE AFTER `company_code`;
ALTER TABLE `companies` ADD COLUMN `frontend_host` VARCHAR(191) NULL AFTER `slug`;
ALTER TABLE `companies` ADD COLUMN `logo_url` VARCHAR(500) NULL AFTER `frontend_host`;
ALTER TABLE `companies` ADD COLUMN `theme_primary` VARCHAR(20) NULL AFTER `logo_url`;
ALTER TABLE `companies` ADD COLUMN `theme_secondary` VARCHAR(20) NULL AFTER `theme_primary`;
ALTER TABLE `companies` ADD COLUMN `theme_accent` VARCHAR(20) NULL AFTER `theme_secondary`;
ALTER TABLE `companies` ADD COLUMN `theme_json` JSON NULL AFTER `theme_accent`;

ALTER TABLE `users` ADD COLUMN `is_cybernetic_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`;
