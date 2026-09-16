-- =============================================================================
-- New-company reset — keep HR admin login + Cybernetic Admin (env)
-- =============================================================================
-- KEEPS
--   • users.role IN ('admin','hr','superadmin')
--   • users.is_cybernetic_admin = 1 (if that column exists)
--   • user_acl_permissions for those users
--   • Laravel tables: migrations, jobs, failed_jobs, job_batches
--
-- Cybernetic Admin password is NOT in MySQL. Keep .env:
--   CYBERNETIC_ADMIN_PASSWORD=...
-- Then open /cybernetic-admin and create the new company.
--
-- CLEARS companies, employees, attendance, payroll, leave, loans, LMS/PMS,
-- departments, shifts, and other data so you can enter a fresh company.
--
-- Preview:
--   SELECT id, name, email, role FROM users;
--
-- Run:
--   mysql -u USER -p YOUR_DATABASE < clear_for_new_company_keep_admin.sql
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET @db := DATABASE();

SELECT id, name, email, role
FROM users
WHERE LOWER(IFNULL(role, '')) IN ('admin', 'hr', 'superadmin');

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
        'jobs',
        'job_batches',
        'failed_jobs'
      )
    ORDER BY TABLE_NAME;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  SET FOREIGN_KEY_CHECKS = 0;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO t;
    IF done = 1 THEN
      LEAVE read_loop;
    END IF;
    -- DELETE not TRUNCATE: MariaDB/MySQL rejects TRUNCATE when FKs exist (#1701)
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

DROP PROCEDURE IF EXISTS delete_non_admin_users;
DELIMITER //
CREATE PROCEDURE delete_non_admin_users()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_cybernetic_admin'
  ) THEN
    DELETE FROM users
    WHERE LOWER(IFNULL(role, '')) NOT IN ('admin', 'hr', 'superadmin')
      AND IFNULL(is_cybernetic_admin, 0) = 0;
  ELSE
    DELETE FROM users
    WHERE LOWER(IFNULL(role, '')) NOT IN ('admin', 'hr', 'superadmin');
  END IF;
END //
DELIMITER ;

CALL delete_non_admin_users();
DROP PROCEDURE IF EXISTS delete_non_admin_users;

DROP PROCEDURE IF EXISTS cleanup_user_acl;
DELIMITER //
CREATE PROCEDURE cleanup_user_acl()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_acl_permissions'
  ) THEN
    DELETE FROM user_acl_permissions
    WHERE user_id NOT IN (SELECT id FROM users);
  END IF;
END //
DELIMITER ;

CALL cleanup_user_acl();
DROP PROCEDURE IF EXISTS cleanup_user_acl;

SET FOREIGN_KEY_CHECKS = 1;

SELECT id, name, email, role AS remaining_logins FROM users;
SELECT 'Log into Cybernetic Admin with CYBERNETIC_ADMIN_PASSWORD from .env' AS next_step;
