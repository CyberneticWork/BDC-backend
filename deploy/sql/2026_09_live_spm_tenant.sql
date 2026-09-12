-- Run this on the LIVE SPM database (apis_spmhr_db).
-- Ignore "Duplicate column" (#1060) if a column already exists.

-- 1) Tenant / branding columns
ALTER TABLE `companies` ADD COLUMN `portal_active` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `companies` ADD COLUMN `org_group` VARCHAR(80) NULL;
ALTER TABLE `companies` ADD COLUMN `attendance_process` VARCHAR(40) NOT NULL DEFAULT 'spm_standard';
ALTER TABLE `companies` ADD COLUMN `process_config` JSON NULL;

-- 2) Group companies: SPM together, Blue Sky separate
UPDATE `companies`
SET `org_group` = 'spm'
WHERE `deleted_at` IS NULL
  AND (
    UPPER(`company_code`) IN ('SPM-C', 'SPM-S')
    OR LOWER(IFNULL(`frontend_host`, '')) LIKE 'spm-c.%'
    OR LOWER(IFNULL(`frontend_host`, '')) LIKE 'spm-s.%'
    OR LOWER(`name`) LIKE '%spm tax%'
  );

UPDATE `companies`
SET `org_group` = 'bsky'
WHERE `deleted_at` IS NULL
  AND (
    UPPER(`company_code`) IN ('B/SKY', 'B-SKY', 'BSKY')
    OR LOWER(IFNULL(`frontend_host`, '')) LIKE 'bsky.%'
    OR LOWER(`name`) LIKE '%blue sky%'
  );

-- 3) One login brand (SPM-C) so the main SPM site has a seed company
UPDATE `companies` SET `portal_active` = 0;
UPDATE `companies`
SET `portal_active` = 1
WHERE `deleted_at` IS NULL
  AND UPPER(`company_code`) = 'SPM-C'
LIMIT 1;

-- 4) Optional: longer logo URLs for Firebase
ALTER TABLE `companies` MODIFY `logo_url` VARCHAR(2048) NULL;
