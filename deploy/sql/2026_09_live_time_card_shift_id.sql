-- Optional: link time cards to the matched shift for multi-roster pairing.
-- Ignore Duplicate column (#1060).

ALTER TABLE `time_cards`
  ADD COLUMN `shift_id` BIGINT UNSIGNED NULL AFTER `employee_id`;
