-- Paste this in phpMyAdmin on sunfohr_db (the SAME database as Laravel .env DB_DATABASE).
-- Ignore #1146 if a table does not exist. Do not stop; continue the rest.

SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM time_card_audits;
DELETE FROM over_times;
DELETE FROM time_cards;
DELETE FROM no_pay_records;
DELETE FROM rosters;
DELETE FROM absences;
DELETE FROM excess_late_decisions;
DELETE FROM monthly_late_deduction_items;
DELETE FROM monthly_late_deduction_runs;
DELETE FROM dinner_allowances;
DELETE FROM hikvision_event_logs;
DELETE FROM hikvision_punches;
DELETE FROM leave_masters;
DELETE FROM employee_leave_balances;
DELETE FROM leave_calendars;
DELETE FROM loans;
DELETE FROM completed_loans;
DELETE FROM salary_advance_requests;
DELETE FROM pending_payments;
DELETE FROM salary_process_audits;
DELETE FROM salary_processes;
DELETE FROM pay_deductions;
DELETE FROM payments;
DELETE FROM employee_allowances;
DELETE FROM employee_deductions;
DELETE FROM employee_bonuses;
DELETE FROM employee_wise_allowances;
DELETE FROM employee_wise_deductions;
DELETE FROM employee_wise_bonuses;
DELETE FROM resignation_documents;
DELETE FROM resignations;
DELETE FROM notifications;
DELETE FROM task_progress_submissions;
DELETE FROM kpi_task_assignments;
DELETE FROM performance_appraisals;
DELETE FROM performance_evaluations;
DELETE FROM performance_reviews;
DELETE FROM exam_results;
DELETE FROM enrollments;
DELETE FROM progress;
DELETE FROM practical_feedbacks;
DELETE FROM certificates;
DELETE FROM documents;
DELETE FROM attachments;
DELETE FROM contact_details;
DELETE FROM compensation;
DELETE FROM childrens;
DELETE FROM spouses;
DELETE FROM organization_assignments;
DELETE FROM personal_access_tokens;
DELETE FROM employees;

SET FOREIGN_KEY_CHECKS = 1;

SELECT COUNT(*) AS employees_left FROM employees;
SELECT id, name, email, role FROM users;
