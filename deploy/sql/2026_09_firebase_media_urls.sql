-- Longer URLs for Firebase Storage download links.

ALTER TABLE `documents`
  MODIFY `document_path` TEXT NULL;

ALTER TABLE `companies`
  MODIFY `logo_url` VARCHAR(2048) NULL;
