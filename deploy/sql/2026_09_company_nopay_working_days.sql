-- Company NoPay day-rate divisor (e.g. 30, 25, 22). Default 30.
-- Ignore duplicate column error if already applied.

ALTER TABLE `companies`
  ADD COLUMN `nopay_working_days` tinyint unsigned NOT NULL DEFAULT 30 AFTER `established`;
