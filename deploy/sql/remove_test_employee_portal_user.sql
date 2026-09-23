-- =============================================================================
-- Remove the TESTQA001 employee + employee-role user created by
-- insert_test_employee_portal_user.sql
-- Run on the same HR database after you finish testing.
-- If a DELETE errors (table missing), comment that one line and continue.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @emp_id := (SELECT id FROM employees WHERE attendance_employee_no = 'TESTQA001' LIMIT 1);
SET @org_id := (SELECT organization_assignment_id FROM employees WHERE id = @emp_id LIMIT 1);

DELETE FROM contact_details WHERE employee_id = @emp_id;
DELETE FROM compensation WHERE employee_id = @emp_id;
DELETE FROM users
WHERE employee_id = @emp_id
   OR email = 'test.employee.qa@local.test'
   OR nic = '900000000V';
DELETE FROM employees WHERE id = @emp_id OR attendance_employee_no = 'TESTQA001';
DELETE FROM organization_assignments WHERE id = @org_id;

SET FOREIGN_KEY_CHECKS = 1;

SELECT
  (SELECT COUNT(*) FROM employees WHERE attendance_employee_no = 'TESTQA001') AS employees_left,
  (SELECT COUNT(*) FROM users WHERE email = 'test.employee.qa@local.test' OR nic = '900000000V') AS users_left;
