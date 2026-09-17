-- =============================================================================
-- Import employee master + HR data + time cards
-- FROM  sunfoerp_db  (Proline/Solar ERP UUID tables)
-- INTO  the database selected in phpMyAdmin  (live: sunfohr_db)
--
-- Both databases must be on the SAME MySQL/MariaDB server.
-- Select sunfohr_db first, then run this whole file.
-- Safe to re-run: skips employees that already match NIC / attendance no / EPF.
-- Does not copy ERP Firebase keys or ERP user logins.
-- =============================================================================

SET NAMES utf8mb4;
USE sunfohr_db;
SET FOREIGN_KEY_CHECKS = 0;

-- Target = sunfohr_db (must exist on this server, with sunfoerp_db)
SET @target_db := DATABASE();
SELECT @target_db AS running_on_database,
       (SELECT COUNT(*) FROM sunfoerp_db.hr_employee) AS erp_employees,
       (SELECT COUNT(*) FROM sunfoerp_db.hr_time_card) AS erp_time_cards;

-- ---------------------------------------------------------------------------
-- 1) Company (reuse Cybernetic Admin company if it already exists)
-- ---------------------------------------------------------------------------
INSERT INTO companies (company_code, name, location, created_at, updated_at)
SELECT 'SUNFO',
       IFNULL(NULLIF(TRIM(c.companyName), ''), 'SUNFO LANKA SOLAR'),
       NULLIF(TRIM(CONCAT_WS(', ', c.address1, c.address2)), ''),
       NOW(), NOW()
FROM sunfoerp_db.companydetails c
WHERE NOT EXISTS (
        SELECT 1 FROM companies
        WHERE deleted_at IS NULL
          AND (company_code IN ('SUNFO', 'SUNFOLANKA') OR name LIKE '%SUNFO%')
      )
LIMIT 1;

SET @company_id := (
  SELECT id FROM companies
  WHERE deleted_at IS NULL
    AND (company_code IN ('SUNFO', 'SUNFOLANKA') OR name LIKE '%SUNFO%')
  ORDER BY id
  LIMIT 1
);
SET @company_id := IFNULL(
  @company_id,
  (SELECT id FROM companies WHERE deleted_at IS NULL ORDER BY id LIMIT 1)
);

SELECT @company_id AS hr_company_id;

-- ---------------------------------------------------------------------------
-- 2) Employment types
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO employment_types (name, created_at, updated_at)
VALUES
  ('Permanent', NOW(), NOW()),
  ('Probation', NOW(), NOW()),
  ('Training', NOW(), NOW()),
  ('Contract', NOW(), NOW()),
  ('Daily Wages Salary', NOW(), NOW());

-- ---------------------------------------------------------------------------
-- 3) Departments / sub-departments / designations / shifts
-- ---------------------------------------------------------------------------
INSERT INTO departments (company_id, name, created_at, updated_at)
SELECT @company_id, d.name, NOW(), NOW()
FROM sunfoerp_db.hr_department d
WHERE d.deletedAt IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM departments x
    WHERE x.company_id = @company_id AND x.name = d.name AND x.deleted_at IS NULL
  );

INSERT INTO sub_departments (name, department_id, created_at, updated_at)
SELECT s.name, dept.id, NOW(), NOW()
FROM sunfoerp_db.hr_sub_department s
JOIN sunfoerp_db.hr_department d ON d.id = s.departmentId
JOIN departments dept ON dept.company_id = @company_id AND dept.name = d.name AND dept.deleted_at IS NULL
WHERE s.deletedAt IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM sub_departments x
    WHERE x.department_id = dept.id AND x.name = s.name AND x.deleted_at IS NULL
  );

INSERT INTO designations (name, description, created_at, updated_at)
SELECT DISTINCT TRIM(e.designationText), 'Imported from sunfoerp_db', NOW(), NOW()
FROM sunfoerp_db.hr_employee e
WHERE NULLIF(TRIM(e.designationText), '') IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM designations x WHERE x.name = TRIM(e.designationText) AND x.deleted_at IS NULL
  );

INSERT INTO designations (name, description, created_at, updated_at)
SELECT DISTINCT des.name, IFNULL(des.description, 'Imported from sunfoerp_db'), NOW(), NOW()
FROM sunfoerp_db.hr_designation des
WHERE des.deletedAt IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM designations x WHERE x.name = des.name AND x.deleted_at IS NULL
  );

INSERT INTO designations (name, description, created_at, updated_at)
SELECT 'Staff', 'Default for ERP employees without designation', NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM designations WHERE name = 'Staff' AND deleted_at IS NULL);

INSERT INTO shifts (shift_code, shift_description, start_time, end_time, created_at, updated_at)
SELECT
  IFNULL(NULLIF(TRIM(s.code), ''), CONCAT('SF-', LEFT(s.id, 8))),
  s.name,
  STR_TO_DATE(NULLIF(TRIM(s.startTime), ''), '%H:%i:%s'),
  STR_TO_DATE(NULLIF(TRIM(s.endTime), ''), '%H:%i:%s'),
  NOW(), NOW()
FROM sunfoerp_db.hr_shift s
WHERE s.deletedAt IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM shifts x
    WHERE x.shift_code = IFNULL(NULLIF(TRIM(s.code), ''), CONCAT('SF-', LEFT(s.id, 8)))
      AND x.deleted_at IS NULL
  );

-- ---------------------------------------------------------------------------
-- 4) Organization assignments (letter_path holds ERP employee UUID until mapped)
-- ---------------------------------------------------------------------------
INSERT INTO organization_assignments (
  company_id, date_of_joining, department_id, sub_department_id, designation_id,
  confirmation_date, probationary_period, is_active, letter_path, created_at, updated_at
)
SELECT
  @company_id,
  e.joinDate,
  dept.id,
  sub.id,
  desig.id,
  e.confirmationDate,
  IF(UPPER(IFNULL(e.employmentStatus, '')) = 'PROBATION', 1, 0),
  IF(e.isActive = 1 AND IFNULL(e.isDeleted, 0) = 0, 1, 0),
  CONCAT('__ERP__', e.id),
  NOW(), NOW()
FROM sunfoerp_db.hr_employee e
LEFT JOIN sunfoerp_db.hr_department d ON d.id = e.departmentId
LEFT JOIN departments dept
  ON dept.company_id = @company_id AND dept.name = d.name AND dept.deleted_at IS NULL
LEFT JOIN sunfoerp_db.hr_sub_department sd ON sd.id = e.subDepartmentId
LEFT JOIN sub_departments sub
  ON sub.department_id = dept.id AND sub.name = sd.name AND sub.deleted_at IS NULL
LEFT JOIN designations desig
  ON desig.deleted_at IS NULL
 AND desig.name = IFNULL(NULLIF(TRIM(e.designationText), ''), 'Staff')
WHERE NOT EXISTS (
  SELECT 1 FROM organization_assignments oa
  WHERE oa.letter_path = CONCAT('__ERP__', e.id)
)
AND NOT EXISTS (
  SELECT 1 FROM employees emp
  WHERE emp.deleted_at IS NULL
    AND (
      emp.nic = e.nic
      OR emp.attendance_employee_no = TRIM(e.attendanceNo)
      OR emp.epf = IFNULL(NULLIF(TRIM(e.epfNo), ''), CONCAT('NO-EPF-', TRIM(e.attendanceNo)))
    )
);

-- ---------------------------------------------------------------------------
-- 5) Employees
-- ---------------------------------------------------------------------------
INSERT INTO employees (
  title, attendance_employee_no, epf, nic, dob, gender,
  name_with_initials, full_name, display_name, is_active,
  employment_type_id, organization_assignment_id, marital_status,
  profile_photo_path, email, religion, country_of_birth,
  created_at, updated_at, deleted_at
)
SELECT
  IFNULL(NULLIF(TRIM(e.title), ''), 'Mr'),
  TRIM(e.attendanceNo),
  IFNULL(NULLIF(TRIM(e.epfNo), ''), CONCAT('NO-EPF-', TRIM(e.attendanceNo))),
  TRIM(e.nic),
  IFNULL(e.dob, '1990-01-01'),
  CASE LOWER(TRIM(IFNULL(e.gender, '')))
    WHEN 'f' THEN 'female'
    WHEN 'female' THEN 'female'
    WHEN 'm' THEN 'male'
    WHEN 'male' THEN 'male'
    ELSE 'other'
  END,
  IFNULL(NULLIF(TRIM(e.initials), ''), TRIM(e.fullName)),
  TRIM(e.fullName),
  NULLIF(TRIM(e.displayName), ''),
  IF(e.isActive = 1 AND IFNULL(e.isDeleted, 0) = 0, 1, 0),
  et.id,
  oa.id,
  CASE LOWER(TRIM(IFNULL(e.maritalStatus, 'single')))
    WHEN 'married' THEN 'married'
    WHEN 'divorced' THEN 'divorced'
    WHEN 'widowed' THEN 'widowed'
    ELSE 'single'
  END,
  NULLIF(TRIM(e.profilePhotoUrl), ''),
  NULLIF(TRIM(e.email), ''),
  NULLIF(TRIM(e.religion), ''),
  IFNULL(NULLIF(TRIM(e.city), ''), 'Sri Lanka'),
  NOW(), NOW(),
  IF(e.isDeleted = 1, IFNULL(e.deletedAt, NOW()), NULL)
FROM sunfoerp_db.hr_employee e
JOIN organization_assignments oa ON oa.letter_path = CONCAT('__ERP__', e.id)
JOIN employment_types et ON et.name = CASE UPPER(TRIM(IFNULL(e.employmentStatus, 'ACTIVE')))
    WHEN 'PROBATION' THEN 'Probation'
    WHEN 'TRAINING' THEN 'Training'
    WHEN 'CONTRACT' THEN 'Contract'
    WHEN 'DAILY' THEN 'Daily Wages Salary'
    WHEN 'DAILY_WAGES' THEN 'Daily Wages Salary'
    WHEN 'PERMANENT' THEN 'Permanent'
    WHEN 'ACTIVE' THEN 'Permanent'
    ELSE 'Permanent'
  END
WHERE NOT EXISTS (
  SELECT 1 FROM employees emp
  WHERE emp.attendance_employee_no = TRIM(e.attendanceNo)
     OR emp.nic = e.nic
     OR emp.epf = IFNULL(NULLIF(TRIM(e.epfNo), ''), CONCAT('NO-EPF-', TRIM(e.attendanceNo)))
);

-- Map ERP UUID -> HR bigint (new + already-imported)
DROP TABLE IF EXISTS _erp_emp_map;
CREATE TABLE _erp_emp_map (
  erp_id VARCHAR(36) PRIMARY KEY,
  hr_id BIGINT UNSIGNED NOT NULL,
  attendance_no VARCHAR(191) NULL,
  KEY (hr_id)
);

INSERT INTO _erp_emp_map (erp_id, hr_id, attendance_no)
SELECT e.id, emp.id, TRIM(e.attendanceNo)
FROM sunfoerp_db.hr_employee e
JOIN employees emp
  ON emp.attendance_employee_no = TRIM(e.attendanceNo)
  OR emp.nic = e.nic;

-- Clear ERP marker from org rows
UPDATE organization_assignments
SET letter_path = NULL
WHERE letter_path LIKE '__ERP__%';

-- ---------------------------------------------------------------------------
-- 6) Contact + compensation
-- Rebuild map first in case this block is run on its own in phpMyAdmin.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS _erp_emp_map (
  erp_id VARCHAR(36) PRIMARY KEY,
  hr_id BIGINT UNSIGNED NOT NULL,
  attendance_no VARCHAR(191) NULL,
  KEY (hr_id)
);
INSERT IGNORE INTO _erp_emp_map (erp_id, hr_id, attendance_no)
SELECT e.id, emp.id, TRIM(e.attendanceNo)
FROM sunfoerp_db.hr_employee e
JOIN employees emp
  ON emp.attendance_employee_no = TRIM(e.attendanceNo)
  OR emp.nic = e.nic;

INSERT INTO contact_details (
  employee_id, permanent_address, temporary_address, email,
  land_line, mobile_line, district, emg_relationship, emg_name, emg_address, emg_tel,
  created_at, updated_at
)
SELECT
  emp.id,
  LEFT(IFNULL(NULLIF(TRIM(e.permanentAddress), ''), '-'), 255),
  LEFT(NULLIF(TRIM(e.temporaryAddress), ''), 255),
  IFNULL(NULLIF(TRIM(e.email), ''), CONCAT(TRIM(e.attendanceNo), '.import@sunfo.local')),
  NULL,
  LEFT(CONCAT('SF', TRIM(e.attendanceNo)), 255),
  LEFT(NULLIF(TRIM(e.city), ''), 255),
  NULL,
  NULL,
  NULL,
  NULL,
  NOW(), NOW()
FROM sunfoerp_db.hr_employee e
JOIN employees emp ON emp.attendance_employee_no = TRIM(e.attendanceNo)
WHERE NOT EXISTS (
  SELECT 1 FROM contact_details c WHERE c.employee_id = emp.id
);

INSERT INTO compensation (
  employee_id, basic_salary, monthly_bonus,
  enable_epf_etf, ot_active, ot_active_special, early_deduction,
  increment_active, active_nopay, ot_morning, ot_evening, ot_morning_rate, ot_night_rate,
  ot_morning_special, ot_evening_special, ot_morning_rate_special, ot_night_rate_special,
  bank_name, branch_name, bank_account_no, account_holder_name,
  br1, br2, secondary_emp, primary_emp_basic, stamp,
  created_at, updated_at
)
SELECT
  emp.id,
  IFNULL(e.basicSalary, 0),
  IFNULL(e.monthlyBonus, 0),
  1, 0, 0, 0,
  0, 0, 0, 0, 0, 0,
  0, 0, 0, 0,
  NULLIF(TRIM(e.bankName), ''),
  NULLIF(TRIM(e.bankBranch), ''),
  NULLIF(TRIM(e.bankAccount), ''),
  IFNULL(NULLIF(TRIM(e.displayName), ''), TRIM(e.fullName)),
  0, 0, 0, 1, 0,
  NOW(), NOW()
FROM sunfoerp_db.hr_employee e
JOIN employees emp ON emp.attendance_employee_no = TRIM(e.attendanceNo)
WHERE NOT EXISTS (
  SELECT 1 FROM compensation c WHERE c.employee_id = emp.id
);

-- ---------------------------------------------------------------------------
-- 7) Allowance / deduction masters + employee assignments
-- ---------------------------------------------------------------------------
INSERT INTO allowances (
  allowance_code, allowance_name, company_id, status, category, allowance_type, amount, created_at, updated_at
)
SELECT
  IFNULL(NULLIF(TRIM(t.code), ''), CONCAT('SF-A-', t.id)),
  t.name,
  @company_id,
  IF(t.isActive = 1, 'active', 'inactive'),
  'other',
  IF(UPPER(t.kind) = 'VARIABLE', 'variable', 'fixed'),
  t.defaultAmount,
  NOW(), NOW()
FROM sunfoerp_db.hr_allowance_type t
WHERE t.deletedAt IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM allowances a
    WHERE a.allowance_code = IFNULL(NULLIF(TRIM(t.code), ''), CONCAT('SF-A-', t.id))
       OR (a.company_id = @company_id AND a.allowance_name = t.name AND a.deleted_at IS NULL)
  );

INSERT INTO deductions (
  deduction_code, deduction_name, company_id, amount, status, category, deduction_type, created_at, updated_at
)
SELECT
  IFNULL(NULLIF(TRIM(t.code), ''), CONCAT('SF-D-', t.id)),
  t.name,
  @company_id,
  t.defaultAmount,
  IF(t.isActive = 1, 'active', 'inactive'),
  IF(t.isStatutory = 1, 'EPF', 'other'),
  IF(UPPER(t.kind) = 'VARIABLE', 'variable', 'fixed'),
  NOW(), NOW()
FROM sunfoerp_db.hr_deduction_type t
WHERE t.deletedAt IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM deductions d
    WHERE d.deduction_code = IFNULL(NULLIF(TRIM(t.code), ''), CONCAT('SF-D-', t.id))
       OR (d.company_id = @company_id AND d.deduction_name = t.name AND d.deleted_at IS NULL)
  );

INSERT INTO employee_allowances (
  employee_id, attendance_employee_no, allowance_id, month, year, custom_amount, is_active, created_at, updated_at
)
SELECT
  emp.id,
  emp.attendance_employee_no,
  a.id,
  ea.periodMonth,
  ea.periodYear,
  ea.amount,
  ea.isActive,
  NOW(), NOW()
FROM sunfoerp_db.hr_employee_allowance ea
JOIN sunfoerp_db.hr_employee e ON e.id = ea.employeeId
JOIN employees emp ON emp.attendance_employee_no = TRIM(e.attendanceNo)
JOIN sunfoerp_db.hr_allowance_type t ON t.id = ea.allowanceTypeId
JOIN allowances a ON a.deleted_at IS NULL
  AND a.allowance_name = t.name
WHERE NOT EXISTS (
  SELECT 1 FROM employee_allowances x
  WHERE x.employee_id = emp.id AND x.allowance_id = a.id AND x.deleted_at IS NULL
);

INSERT INTO employee_deductions (
  employee_id, attendance_employee_no, deduction_id, month, year, custom_amount, is_active, created_at, updated_at
)
SELECT
  emp.id,
  emp.attendance_employee_no,
  d.id,
  ed.periodMonth,
  ed.periodYear,
  ed.amount,
  ed.isActive,
  NOW(), NOW()
FROM sunfoerp_db.hr_employee_deduction ed
JOIN sunfoerp_db.hr_employee e ON e.id = ed.employeeId
JOIN employees emp ON emp.attendance_employee_no = TRIM(e.attendanceNo)
JOIN sunfoerp_db.hr_deduction_type t ON t.id = ed.deductionTypeId
JOIN deductions d ON d.deleted_at IS NULL
  AND d.deduction_name = t.name
WHERE NOT EXISTS (
  SELECT 1 FROM employee_deductions x
  WHERE x.employee_id = emp.id AND x.deduction_id = d.id AND x.deleted_at IS NULL
);

-- ---------------------------------------------------------------------------
-- 8) Public / poya holidays -> leave_calendars
-- ---------------------------------------------------------------------------
INSERT INTO leave_calendars (
  company_id, leave_type, reason, start_date, end_date, created_at, updated_at
)
SELECT
  @company_id,
  h.kind,
  h.name,
  h.holidayDate,
  h.holidayDate,
  NOW(), NOW()
FROM sunfoerp_db.hr_holiday h
WHERE h.isActive = 1
  AND NOT EXISTS (
    SELECT 1 FROM leave_calendars lc
    WHERE lc.company_id = @company_id
      AND lc.start_date = h.holidayDate
      AND lc.reason = h.name
      AND lc.deleted_at IS NULL
  );

-- ---------------------------------------------------------------------------
-- 9) Time cards (IN=1, OUT=2). ERP fingerprintClock is attendance no — ignore it.
-- ---------------------------------------------------------------------------
INSERT INTO time_cards (
  employee_id, fingerprint_clock, time, date, working_hours, entry, status,
  approval_status, actual_date, reason, break_status, entry_source,
  created_at, updated_at, deleted_at
)
SELECT
  m.hr_id,
  TIMESTAMP(t.cardDate, CAST(t.clockTime AS TIME)),
  CAST(t.clockTime AS TIME),
  t.cardDate,
  IFNULL(CAST(t.workingHours AS CHAR), NULL),
  t.entryType,
  t.status,
  CASE UPPER(IFNULL(t.approvalStatus, 'PENDING'))
    WHEN 'ACTIVE' THEN 'Active'
    WHEN 'APPROVED' THEN 'Active'
    WHEN 'REJECTED' THEN 'Rejected'
    ELSE 'Pending'
  END,
  IFNULL(t.actualDate, t.cardDate),
  LEFT(t.reason, 255),
  CASE UPPER(IFNULL(t.breakStatus, 'PENDING'))
    WHEN 'APPROVED' THEN 'Approved'
    WHEN 'REJECTED' THEN 'Rejected'
    ELSE 'Pending'
  END,
  'erp_import',
  NOW(), NOW(),
  t.deletedAt
FROM sunfoerp_db.hr_time_card t
JOIN _erp_emp_map m ON m.erp_id = t.employeeId
WHERE t.clockTime IS NOT NULL
  AND CAST(t.clockTime AS TIME) IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM time_cards x
    WHERE x.employee_id = m.hr_id
      AND x.date = t.cardDate
      AND x.time = CAST(t.clockTime AS TIME)
      AND x.entry = t.entryType
      AND x.deleted_at IS NULL
  );

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- 10) Verify
-- ---------------------------------------------------------------------------
SELECT 'employees' AS item, COUNT(*) AS cnt FROM employees WHERE deleted_at IS NULL
UNION ALL SELECT 'mapped_from_erp', COUNT(*) FROM _erp_emp_map
UNION ALL SELECT 'contact_details', COUNT(*) FROM contact_details
UNION ALL SELECT 'compensation', COUNT(*) FROM compensation
UNION ALL SELECT 'time_cards', COUNT(*) FROM time_cards WHERE deleted_at IS NULL
UNION ALL SELECT 'employee_allowances', COUNT(*) FROM employee_allowances WHERE deleted_at IS NULL
UNION ALL SELECT 'employee_deductions', COUNT(*) FROM employee_deductions WHERE deleted_at IS NULL
UNION ALL SELECT 'leave_calendars', COUNT(*) FROM leave_calendars WHERE deleted_at IS NULL;

SELECT emp.id, emp.attendance_employee_no, emp.full_name, emp.nic,
       (SELECT COUNT(*) FROM time_cards tc WHERE tc.employee_id = emp.id AND tc.deleted_at IS NULL) AS punches
FROM employees emp
JOIN _erp_emp_map m ON m.hr_id = emp.id
ORDER BY emp.attendance_employee_no;
