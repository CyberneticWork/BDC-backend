-- =============================================================================
-- Hilburn / HR live reset — empty DB for a NEW company
-- =============================================================================
-- KEEPS
--   • users with role = admin  (system Admin login)
--   • user_acl_permissions for those admins
--   • employment_types (needed to add employees later)
--   • emergency_contact_relationship_types (needed on employee form)
--   • Laravel: migrations, jobs, job_batches, failed_jobs
--
-- Cybernetic Admin is NOT a MySQL user. Password stays in live .env:
--   CYBERNETIC_ADMIN_PASSWORD=...
-- After this script: open /cybernetic-admin → create the new company.
--
-- DELETES
--   companies, employees, departments, attendance, payroll, leave, loans,
--   shifts, allowances/deductions, LMS/PMS, hikvision logs, tokens, sessions,
--   and every other data table. HR / supervisor / employee logins are removed.
--
-- BACK UP THE DATABASE FIRST.
--
-- phpMyAdmin: Import this file. If DELIMITER fails, set the delimiter box
-- at the bottom of the SQL tab to // then run the procedure blocks only.
--
-- CLI:
--   mysql -u USER -p YOUR_DATABASE < clear_for_new_company_keep_admin.sql
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET @db := DATABASE();

SELECT id, name, email, role AS will_keep_admins
FROM users
WHERE LOWER(IFNULL(role, '')) = 'admin';

DROP PROCEDURE IF EXISTS wipe_except_keep;
DELIMITER //
CREATE PROCEDURE wipe_except_keep()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE t VARCHAR(64);
  DECLARE cur CURSOR FOR
    SELECT TABLE_NAME
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db
      AND TABLE_TYPE = 'BASE TABLE'
      AND TABLE_NAME NOT IN (
        'migrations',
        'users',
        'user_acl_permissions',
        'employment_types',
        'emergency_contact_relationship_types',
        'jobs',
        'job_batches',
        'failed_jobs'
      )
    ORDER BY TABLE_NAME;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  SET FOREIGN_KEY_CHECKS = 0;

  UPDATE users
  SET employee_id = NULL
  WHERE LOWER(IFNULL(role, '')) = 'admin';

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO t;
    IF done = 1 THEN
      LEAVE read_loop;
    END IF;
    SET @sql := CONCAT('DELETE FROM `', t, '`');
    PREPARE s FROM @sql;
    EXECUTE s;
    DEALLOCATE PREPARE s;
    BEGIN
      DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
      SET @sql := CONCAT('ALTER TABLE `', t, '` AUTO_INCREMENT = 1');
      PREPARE s FROM @sql;
      EXECUTE s;
      DEALLOCATE PREPARE s;
    END;
  END LOOP;
  CLOSE cur;
END //
DELIMITER ;

CALL wipe_except_keep();
DROP PROCEDURE IF EXISTS wipe_except_keep;

DROP PROCEDURE IF EXISTS keep_only_system_admin;
DELIMITER //
CREATE PROCEDURE keep_only_system_admin()
BEGIN
  DELETE FROM users
  WHERE LOWER(IFNULL(role, '')) <> 'admin';

  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_acl_permissions'
  ) THEN
    DELETE FROM user_acl_permissions
    WHERE user_id NOT IN (SELECT id FROM users);
  END IF;
END //
DELIMITER ;

CALL keep_only_system_admin();
DROP PROCEDURE IF EXISTS keep_only_system_admin;

SET FOREIGN_KEY_CHECKS = 1;

SELECT id, name, email, role AS remaining_logins FROM users;
SELECT COUNT(*) AS companies_left FROM companies;
SELECT COUNT(*) AS employees_left FROM employees;
SELECT 'Next: log in /cybernetic-admin with CYBERNETIC_ADMIN_PASSWORD from .env and add the new company' AS next_step;
