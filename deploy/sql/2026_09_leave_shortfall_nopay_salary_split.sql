-- Tag leave-form shortfall NoPay records (separate from late deduction LATE_MONTHLY).
-- Salary process: (basic + monthly_bonus)/30 × days, split to basic + bonus.

UPDATE `no_pay_records`
SET `type` = 'LEAVE_SHORTFALL'
WHERE `description` LIKE '%Auto NoPay from leave shortfall%'
  AND (`type` IS NULL OR `type` IN ('FULL_DAY', 'PARTIAL_ABSENT'));
