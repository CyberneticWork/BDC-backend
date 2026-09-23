-- =============================================================================
-- Create ONE test employee + user (role = employee) for portal login.
-- Run on the live/target HR database in phpMyAdmin.
-- Safe to re-run: it replaces the same TESTQA001 row.
--
-- Login (employee portal / main login):
--   Email : test.employee.qa@local.test
--   NIC   : 900000000V
--   Password : Test@1234
-- Attendance / fingerprint person no: TESTQA001
--
-- After testing, run:  remove_test_employee_portal_user.sql
-- =============================================================================

SET NAMES utf8mb4;

-- Reuse first company / department / employment type (create Permanent if none)
INSERT INTO employment_types (name, created_at, updated_at)
SELECT 'Permanent', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM employment_types WHERE name = 'Permanent' LIMIT 1);

SET @company_id := (SELECT id FROM companies WHERE deleted_at IS NULL ORDER BY id LIMIT 1);
SET @dept_id := (
  SELECT id FROM departments
  WHERE deleted_at IS NULL
    AND (@company_id IS NULL OR company_id = @company_id)
  ORDER BY id LIMIT 1
);
SET @emp_type_id := (
  SELECT id FROM employment_types
  WHERE name = 'Permanent' OR name LIKE '%Permanent%'
  ORDER BY id LIMIT 1
);
SET @emp_type_id := IFNULL(@emp_type_id, (SELECT id FROM employment_types ORDER BY id LIMIT 1));

-- Fresh org assignment for this test person
INSERT INTO organization_assignments (
  company_id, department_id, date_of_joining, is_active, created_at, updated_at
) VALUES (
  @company_id, @dept_id, CURDATE(), 1, NOW(), NOW()
);
SET @org_id := LAST_INSERT_ID();

-- Remove a previous TESTQA001 run (keep org we just created)
SET @old_emp := (SELECT id FROM employees WHERE attendance_employee_no = 'TESTQA001' LIMIT 1);
DELETE FROM users WHERE email = 'test.employee.qa@local.test' OR nic = '900000000V' OR employee_id = @old_emp;
DELETE FROM contact_details WHERE employee_id = @old_emp;
DELETE FROM employees WHERE id = @old_emp;

INSERT INTO employees (
  title, attendance_employee_no, epf, nic, dob, gender,
  name_with_initials, full_name, display_name,
  is_active, employment_type_id, organization_assignment_id,
  marital_status, email, created_at, updated_at
) VALUES (
  'Mr',
  'TESTQA001',
  'TESTQA001',
  '900000000V',
  '1990-01-15',
  'male',
  'T. Employee',
  'Test QA Employee',
  'Test QA Employee',
  1,
  @emp_type_id,
  @org_id,
  'single',
  'test.employee.qa@local.test',
  NOW(),
  NOW()
);
SET @emp_id := LAST_INSERT_ID();

INSERT INTO contact_details (
  employee_id, permanent_address, email, mobile_line, created_at, updated_at
) VALUES (
  @emp_id,
  'Test address, Colombo',
  'test.employee.qa@local.test',
  '0700000001',
  NOW(),
  NOW()
);

-- bcrypt of Test@1234 (Laravel $2y$10)
INSERT INTO users (
  employee_id, name, email, nic, password, role, is_first_login, created_at, updated_at
) VALUES (
  @emp_id,
  'Test QA Employee',
  'test.employee.qa@local.test',
  '900000000V',
  '$2y$10$2NwY0oz9a8QU5UJ5MJ3RY.Z1p8T3sfrpQ7pki0x7vKjcBQHEHAq.W',
  'employee',
  0,
  NOW(),
  NOW()
);

SELECT
  e.id AS employee_id,
  u.id AS user_id,
  u.role,
  u.email,
  e.nic,
  e.attendance_employee_no,
  'Test@1234' AS password_plain,
  e.full_name
FROM employees e
JOIN users u ON u.employee_id = e.id
WHERE e.attendance_employee_no = 'TESTQA001';
