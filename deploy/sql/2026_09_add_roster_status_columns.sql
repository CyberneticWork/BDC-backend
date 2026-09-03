-- Add missing roster status columns on live (safe for existing roster data)
-- Run in phpMyAdmin on spmhr_db if artisan migrate is not available.
-- Skip any ALTER that errors with "Duplicate column".

ALTER TABLE `rosters`
  ADD COLUMN `status` varchar(20) NOT NULL DEFAULT 'Active' AFTER `date_to`;

ALTER TABLE `rosters`
  ADD COLUMN `cancel_reason` varchar(255) DEFAULT NULL AFTER `status`;

ALTER TABLE `rosters`
  ADD COLUMN `cancelled_at` timestamp NULL DEFAULT NULL AFTER `cancel_reason`;

INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_08_27_100000_add_status_to_rosters_table', 999);
