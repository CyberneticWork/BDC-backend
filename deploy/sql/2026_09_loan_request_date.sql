-- Loan request date (separate from deduction start month). Ignore duplicate-column errors.

ALTER TABLE `loans` ADD COLUMN `request_date` DATE NULL AFTER `installment_amount`;
