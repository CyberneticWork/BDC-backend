-- Leave shortfall → NoPay on HR approve (Act accrual from 2026-12-31)
-- Run on spmhr_db. Ignore "Duplicate column" errors if already applied.

ALTER TABLE `leave_masters`
  ADD COLUMN `requested_days` decimal(8,4) NULL AFTER `leave_duration`;

ALTER TABLE `leave_masters`
  ADD COLUMN `leave_balance_days` decimal(8,4) NULL AFTER `requested_days`;

ALTER TABLE `leave_masters`
  ADD COLUMN `nopay_days` decimal(8,4) NOT NULL DEFAULT 0.0000 AFTER `leave_balance_days`;

ALTER TABLE `leave_masters`
  ADD COLUMN `nopay_applied` tinyint(1) NOT NULL DEFAULT 0 AFTER `nopay_days`;

ALTER TABLE `leave_masters`
  ADD COLUMN `nopay_record_id` bigint unsigned NULL AFTER `nopay_applied`;
