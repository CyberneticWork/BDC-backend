-- Live loans table: columns the current save API writes.
-- Ignore Duplicate column (#1060) if a field already exists.

ALTER TABLE `loans` ADD COLUMN `request_date` DATE NULL AFTER `installment_amount`;

ALTER TABLE `loans`
  ADD COLUMN `deduct_basic_amount` DECIMAL(12,2) NULL DEFAULT NULL AFTER `deduct_from`;

ALTER TABLE `loans`
  ADD COLUMN `deduct_bonus_amount` DECIMAL(12,2) NULL DEFAULT NULL AFTER `deduct_basic_amount`;

ALTER TABLE `loans`
  ADD COLUMN `installment_deduct_from` VARCHAR(20) NULL DEFAULT NULL AFTER `deduct_from`;

ALTER TABLE `loans`
  ADD COLUMN `interest_deduct_from` VARCHAR(20) NULL DEFAULT NULL AFTER `installment_deduct_from`;
