-- Companies that share org_group appear together in HR (same organization).
-- Different groups never mix (e.g. Blue Sky stays off the SPM login).

ALTER TABLE `companies`
  ADD COLUMN `org_group` VARCHAR(80) NULL AFTER `frontend_host`;

UPDATE `companies`
SET `org_group` = 'spm'
WHERE `deleted_at` IS NULL
  AND (
    UPPER(`company_code`) IN ('SPM-C', 'SPM-S')
    OR LOWER(`frontend_host`) LIKE 'spm-c.%'
    OR LOWER(`frontend_host`) LIKE 'spm-s.%'
    OR LOWER(`name`) LIKE '%spm tax%'
  );

UPDATE `companies`
SET `org_group` = 'bsky'
WHERE `deleted_at` IS NULL
  AND (
    UPPER(`company_code`) IN ('B/SKY', 'B-SKY', 'BSKY')
    OR LOWER(`frontend_host`) LIKE 'bsky.%'
    OR LOWER(`name`) LIKE '%blue sky%'
  );
