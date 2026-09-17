-- Live: salary-advance deduct-from on HR deduction assignments
ALTER TABLE employee_deductions
  ADD COLUMN IF NOT EXISTS deduct_from VARCHAR(20) NULL AFTER custom_amount;

ALTER TABLE deductions
  ADD COLUMN IF NOT EXISTS deduct_from VARCHAR(20) NULL AFTER category;
