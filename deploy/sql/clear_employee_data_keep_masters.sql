-- Wipe employee / payroll / leave / attendance data.
-- KEEP master files: companies, departments, sub_departments, designations,
-- employment_types, shifts, shift_overtime_rates, allowances, deductions,
-- bonuses, holidays, leave_settings*, emergency_contact_relationship_types,
-- HR users (users.employee_id IS NULL), accounting/inventory masters.
--
-- Run on the target database, e.g.:
--   mysql -u USER -p DATABASE_NAME < clear_employee_data_keep_masters.sql

SET FOREIGN_KEY_CHECKS = 0;
SET @db := DATABASE();

DROP PROCEDURE IF EXISTS wipe_hr_table;
DELIMITER //
CREATE PROCEDURE wipe_hr_table(IN t VARCHAR(64))
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = t AND TABLE_TYPE = 'BASE TABLE'
  ) THEN
    SET @sql := CONCAT('TRUNCATE TABLE `', t, '`');
    PREPARE s FROM @sql;
    EXECUTE s;
    DEALLOCATE PREPARE s;
  END IF;
END //
DELIMITER ;

-- Attendance / OT / roster (employee rows)
CALL wipe_hr_table('time_card_audits');
CALL wipe_hr_table('over_times');
CALL wipe_hr_table('time_cards');
CALL wipe_hr_table('no_pay_records');
CALL wipe_hr_table('rosters');
CALL wipe_hr_table('absences');
CALL wipe_hr_table('excess_late_decisions');
CALL wipe_hr_table('monthly_late_deduction_items');
CALL wipe_hr_table('monthly_late_deduction_runs');
CALL wipe_hr_table('dinner_allowances');
CALL wipe_hr_table('hikvision_event_logs');

-- Leaves
CALL wipe_hr_table('leave_masters');
CALL wipe_hr_table('employee_leave_balances');
CALL wipe_hr_table('leave_calendars');

-- Loans / advances / salary process
CALL wipe_hr_table('loans');
CALL wipe_hr_table('completed_loans');
CALL wipe_hr_table('salary_advance_requests');
CALL wipe_hr_table('pending_payments');
CALL wipe_hr_table('salary_process_audits');
CALL wipe_hr_table('salary_processes');
CALL wipe_hr_table('pay_deductions');
CALL wipe_hr_table('payments');

-- Assigned allowances / deductions / bonuses (NOT the master catalogs)
CALL wipe_hr_table('employee_allowances');
CALL wipe_hr_table('employee_deductions');
CALL wipe_hr_table('employee_bonuses');
CALL wipe_hr_table('employee_wise_allowances');
CALL wipe_hr_table('employee_wise_deductions');
CALL wipe_hr_table('employee_wise_bonuses');

-- Resignations / notices
CALL wipe_hr_table('resignation_documents');
CALL wipe_hr_table('resignations');
CALL wipe_hr_table('notifications');

-- PMS / LMS tied to people
CALL wipe_hr_table('task_progress_submissions');
CALL wipe_hr_table('kpi_task_assignments');
CALL wipe_hr_table('performance_appraisals');
CALL wipe_hr_table('performance_evaluations');
CALL wipe_hr_table('performance_reviews');
CALL wipe_hr_table('exam_results');
CALL wipe_hr_table('enrollments');
CALL wipe_hr_table('progress');
CALL wipe_hr_table('practical_feedbacks');

-- Employee profile
CALL wipe_hr_table('certificates');
CALL wipe_hr_table('documents');
CALL wipe_hr_table('attachments');
CALL wipe_hr_table('contact_details');
CALL wipe_hr_table('compensation');
CALL wipe_hr_table('childrens');
CALL wipe_hr_table('spouses');

-- Employee logins only (keep HR / admin users)
DELETE FROM personal_access_tokens
WHERE tokenable_type LIKE '%User%'
  AND tokenable_id IN (SELECT id FROM users WHERE employee_id IS NOT NULL);

DELETE FROM users WHERE employee_id IS NOT NULL;

CALL wipe_hr_table('employees');
CALL wipe_hr_table('organization_assignments');

DROP PROCEDURE IF EXISTS wipe_hr_table;
SET FOREIGN_KEY_CHECKS = 1;
