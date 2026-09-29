-- Education section fields are optional (employees may have no or partial qualification details).
-- Run once on databases where the education tables already exist. The API also applies this
-- automatically the first time an employee with the Education step is saved.

ALTER TABLE `employee_qualifications`
  MODIFY `qualification_type` varchar(80) NULL DEFAULT NULL,
  MODIFY `institute_name` varchar(191) NULL DEFAULT NULL;

ALTER TABLE `employee_following_qualifications`
  MODIFY `qualification_name` varchar(191) NULL DEFAULT NULL,
  MODIFY `institute_name` varchar(191) NULL DEFAULT NULL,
  MODIFY `start_year` smallint unsigned NULL DEFAULT NULL,
  MODIFY `start_month` tinyint unsigned NULL DEFAULT NULL,
  MODIFY `lecture_type` varchar(10) NULL DEFAULT NULL;
