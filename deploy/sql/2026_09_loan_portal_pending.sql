-- Portal loan requests use status=pending. Ignore Duplicate column (#1060).

ALTER TABLE `loans` MODIFY `status` VARCHAR(30) NOT NULL DEFAULT 'active';

ALTER TABLE `loans` ADD COLUMN `reason` TEXT NULL;
ALTER TABLE `loans` ADD COLUMN `notes` TEXT NULL;
ALTER TABLE `loans` ADD COLUMN `submitted_via` VARCHAR(20) NULL DEFAULT 'hr';
ALTER TABLE `loans` ADD COLUMN `request_date` DATE NULL;
ALTER TABLE `loans` ADD COLUMN `schedule` JSON NULL;
