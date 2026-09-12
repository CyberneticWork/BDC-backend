-- Covering-person leave workflow. Ignore Duplicate column (#1060).

ALTER TABLE `leave_masters`
  ADD COLUMN `covering_employee_id` BIGINT UNSIGNED NULL AFTER `employee_id`;

ALTER TABLE `leave_masters`
  ADD COLUMN `covering_status` VARCHAR(30) NULL AFTER `covering_employee_id`;
