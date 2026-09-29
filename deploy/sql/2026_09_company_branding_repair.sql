-- Company branding / Cybernetic Admin settings repair.
-- Safe to run more than once: only adds missing columns, then fixes data.
-- Works in phpMyAdmin (no stored procedures needed). MySQL 5.7+ / MariaDB 10.2+.

-- 1) Add any missing columns on `companies` -------------------------------

SET @db = DATABASE();

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='default_sports_fund_percentage')=0,
  'ALTER TABLE `companies` ADD COLUMN `default_sports_fund_percentage` DECIMAL(5,2) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='nopay_working_days')=0,
  'ALTER TABLE `companies` ADD COLUMN `nopay_working_days` TINYINT UNSIGNED NOT NULL DEFAULT 30', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='slug')=0,
  'ALTER TABLE `companies` ADD COLUMN `slug` VARCHAR(80) NULL, ADD UNIQUE KEY `companies_slug_unique` (`slug`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='frontend_host')=0,
  'ALTER TABLE `companies` ADD COLUMN `frontend_host` VARCHAR(191) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='org_group')=0,
  'ALTER TABLE `companies` ADD COLUMN `org_group` VARCHAR(80) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='logo_url')=0,
  'ALTER TABLE `companies` ADD COLUMN `logo_url` VARCHAR(2048) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='theme_primary')=0,
  'ALTER TABLE `companies` ADD COLUMN `theme_primary` VARCHAR(20) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='theme_secondary')=0,
  'ALTER TABLE `companies` ADD COLUMN `theme_secondary` VARCHAR(20) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='theme_accent')=0,
  'ALTER TABLE `companies` ADD COLUMN `theme_accent` VARCHAR(20) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='theme_json')=0,
  'ALTER TABLE `companies` ADD COLUMN `theme_json` JSON NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='late_attendance_policy_enabled')=0,
  'ALTER TABLE `companies` ADD COLUMN `late_attendance_policy_enabled` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='portal_active')=0,
  'ALTER TABLE `companies` ADD COLUMN `portal_active` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='attendance_process')=0,
  'ALTER TABLE `companies` ADD COLUMN `attendance_process` VARCHAR(40) NOT NULL DEFAULT ''spm_standard''', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='companies' AND COLUMN_NAME='process_config')=0,
  'ALTER TABLE `companies` ADD COLUMN `process_config` JSON NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='is_cybernetic_admin')=0,
  'ALTER TABLE `users` ADD COLUMN `is_cybernetic_admin` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) Firebase / Drive logo links are long: old VARCHAR(500) truncates or rejects them.
ALTER TABLE `companies` MODIFY `logo_url` VARCHAR(2048) NULL;

-- 3) Data clean-up ----------------------------------------------------------

-- "https://bdchr.cyberneticde.site/" -> "bdchr.cyberneticde.site"
UPDATE `companies`
SET `frontend_host` = LOWER(TRIM(TRAILING '/' FROM
      REPLACE(REPLACE(REPLACE(TRIM(`frontend_host`), 'https://', ''), 'http://', ''), 'www.', '')))
WHERE `frontend_host` IS NOT NULL;

UPDATE `companies` SET `frontend_host` = NULL WHERE `frontend_host` = '';
UPDATE `companies` SET `logo_url` = NULL WHERE TRIM(`logo_url`) = '';

-- Theme / add-on settings must be JSON objects; reset anything broken.
UPDATE `companies` SET `theme_json` = NULL
WHERE `theme_json` IS NOT NULL
  AND (CASE WHEN JSON_VALID(`theme_json`) THEN JSON_TYPE(`theme_json`) <> 'OBJECT' ELSE 1 END);

UPDATE `companies` SET `process_config` = NULL
WHERE `process_config` IS NOT NULL
  AND (CASE WHEN JSON_VALID(`process_config`) THEN JSON_TYPE(`process_config`) <> 'OBJECT' ELSE 1 END);

-- 4) Point the BDC portal at its live URL (set the right id first, then uncomment).
-- UPDATE `companies` SET `portal_active` = 0;
-- UPDATE `companies` SET `frontend_host` = 'bdchr.cyberneticde.site', `portal_active` = 1 WHERE `id` = 1;

INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES ('2026_09_company_branding_repair', 1);

-- 5) Check the result.
SELECT `id`, `company_code`, `name`, `frontend_host`, `portal_active`,
       `theme_primary`, LEFT(`logo_url`, 90) AS `logo_url_start`, CHAR_LENGTH(`logo_url`) AS `logo_len`
FROM `companies`
WHERE `deleted_at` IS NULL
ORDER BY `id`;
