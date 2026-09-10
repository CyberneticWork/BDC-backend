-- Capital installment and interest can be deducted independently from basic or bonus.
-- Ignore duplicate column error if already applied.

ALTER TABLE `loans`
  ADD COLUMN `installment_deduct_from` VARCHAR(20) NULL DEFAULT NULL AFTER `deduct_from`;

ALTER TABLE `loans`
  ADD COLUMN `interest_deduct_from` VARCHAR(20) NULL DEFAULT NULL AFTER `installment_deduct_from`;
