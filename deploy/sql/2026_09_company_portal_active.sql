-- Login branding: only one company should be portal_active.
-- Skip if column already exists (#1060).

ALTER TABLE `companies`
  ADD COLUMN `portal_active` TINYINT(1) NOT NULL DEFAULT 0;
