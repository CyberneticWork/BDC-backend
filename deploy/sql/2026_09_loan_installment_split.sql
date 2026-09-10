-- Loan installment can be deducted from basic, bonus, or split across both.
-- Ignore duplicate column error if already applied.

ALTER TABLE `loans`
  ADD COLUMN `deduct_basic_amount` DECIMAL(12,2) NULL DEFAULT NULL AFTER `deduct_from`;

ALTER TABLE `loans`
  ADD COLUMN `deduct_bonus_amount` DECIMAL(12,2) NULL DEFAULT NULL AFTER `deduct_basic_amount`;
