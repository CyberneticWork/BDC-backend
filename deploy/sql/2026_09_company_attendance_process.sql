-- Company attendance / payroll process (Cybernetic Admin)
-- Ignore duplicate-column errors if already applied.

ALTER TABLE `companies`
  ADD COLUMN `attendance_process` VARCHAR(40) NOT NULL DEFAULT 'spm_standard' AFTER `portal_active`;

ALTER TABLE `companies`
  ADD COLUMN `process_config` JSON NULL AFTER `attendance_process`;

INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_09_company_attendance_process', 1);
