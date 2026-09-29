<?php

/*
 * Column layout shared by make_employee_template.php and employee_excel_to_sql.php.
 * Mirrors Employee Master validation (EmployeeController::store).
 *
 * Column: [key, header, required, type, option]
 *   text: option = max length        list: option = [list name, strict]
 *   yesno: option = default 'Yes'|'No'
 */

return [
    'lists' => [
        'Titles' => ['Mr', 'Mrs', 'Miss', 'Ms', 'Dr'],
        'Genders' => ['Male', 'Female', 'Other'],
        'Marital' => ['Single', 'Married', 'Divorced', 'Widowed'],
        'EmploymentTypes' => ['Permanent', 'Probation', 'Training', 'Contract', 'Daily Wages Salary'],
        'SpouseTypes' => ['husband', 'wife', 'relation', 'non-relation', 'friend'],
        'Provinces' => [
            'Western', 'Central', 'Southern', 'Northern', 'Eastern',
            'North Western', 'North Central', 'Uva', 'Sabaragamuwa',
        ],
        'Categories' => ['Executive', 'Non-Executive'],
        'DayOff' => ['none', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        'YesNo' => ['Yes', 'No'],
        'QualificationTypes' => [
            'Certificate', 'Diploma', 'Higher Diploma', 'Higher National Diploma', 'Bachelors',
            'Bachelors Honours', 'Postgraduate Certificate', 'Post Graduate Diploma',
            'Masters by Course Work', 'Masters with Course Work and a Research Component',
            'Master of Philosophy', 'Doctorate',
        ],
        'LectureTypes' => ['Weekday', 'Weekend'],
    ],

    'sheets' => [
        'Employees' => [
            'sections' => [
                'Personal' => ['color' => 'DBEAFE', 'columns' => [
                    ['attendance_no', 'Attendance No', true, 'text', 50],
                    ['epf_no', 'EPF No', true, 'text', 50],
                    ['title', 'Title', true, 'list', ['Titles', true]],
                    ['name_with_initials', 'Name With Initials', true, 'text', 100],
                    ['full_name', 'Full Name', true, 'text', 100],
                    ['display_name', 'Display Name', true, 'text', 100],
                    ['nic', 'NIC', true, 'nic', null],
                    ['dob', 'Date of Birth', true, 'date', null],
                    ['gender', 'Gender', true, 'list', ['Genders', true]],
                    ['marital_status', 'Marital Status', true, 'list', ['Marital', true]],
                    ['religion', 'Religion', false, 'text', 50],
                    ['country_of_birth', 'Country of Birth', false, 'text', 100],
                    ['employment_type', 'Employment Type', true, 'list', ['EmploymentTypes', false]],
                    ['spouse_type', 'Spouse Relationship', false, 'list', ['SpouseTypes', true]],
                    ['spouse_title', 'Spouse Title', false, 'list', ['Titles', true]],
                    ['spouse_name', 'Spouse Name', false, 'text', 100],
                    ['spouse_nic', 'Spouse NIC', false, 'nic', null],
                    ['spouse_dob', 'Spouse Date of Birth', false, 'date', null],
                ]],
                'Contact' => ['color' => 'DCFCE7', 'columns' => [
                    ['permanent_address', 'Permanent Address', true, 'text', 255],
                    ['temporary_address', 'Temporary Address', false, 'text', 255],
                    ['email', 'Email', true, 'email', null],
                    ['mobile', 'Mobile', true, 'text', 20],
                    ['land_line', 'Land Line', false, 'text', 20],
                    ['gn_division', 'GN Division', false, 'text', 100],
                    ['police_station', 'Police Station', false, 'text', 100],
                    ['district', 'District', true, 'text', 100],
                    ['province', 'Province', true, 'list', ['Provinces', false]],
                    ['electoral_division', 'Electoral Division', false, 'text', 100],
                    ['emg_relationship', 'Emergency Relationship', true, 'text', 50],
                    ['emg_name', 'Emergency Contact Name', true, 'text', 100],
                    ['emg_address', 'Emergency Contact Address', false, 'text', 255],
                    ['emg_tel', 'Emergency Contact Tel', true, 'text', 20],
                ]],
                'Organization' => ['color' => 'FFEDD5', 'columns' => [
                    ['company_code', 'Company Code', true, 'text', 50],
                    ['department', 'Department', true, 'text', 191],
                    ['sub_department', 'Sub Department', false, 'text', 191],
                    ['location', 'Location', true, 'text', 191],
                    ['designation', 'Designation', true, 'text', 191],
                    ['date_joined', 'Date Joined', true, 'date', null],
                    ['employee_category', 'Employee Category', true, 'list', ['Categories', true]],
                    ['current_supervisor', 'Current Supervisor', false, 'text', 100],
                    ['day_off', 'Day Off', false, 'list', ['DayOff', true]],
                    ['probation', 'Probation Period', false, 'yesno', 'No'],
                    ['probation_from', 'Probation From', false, 'date', null],
                    ['probation_to', 'Probation To', false, 'date', null],
                    ['training', 'Training Period', false, 'yesno', 'No'],
                    ['training_from', 'Training From', false, 'date', null],
                    ['training_to', 'Training To', false, 'date', null],
                    ['contract', 'Contract Period', false, 'yesno', 'No'],
                    ['contract_from', 'Contract From', false, 'date', null],
                    ['contract_to', 'Contract To', false, 'date', null],
                    ['confirmation_date', 'Confirmation Date', false, 'date', null],
                    ['active', 'Active', false, 'yesno', 'Yes'],
                ]],
                'Compensation' => ['color' => 'EDE9FE', 'columns' => [
                    ['basic_salary', 'Basic Salary', true, 'decimal', null],
                    ['monthly_bonus', 'Monthly Bonus', false, 'decimal', null],
                    ['increment_value', 'Increment Value', false, 'decimal', null],
                    ['increment_effective_from', 'Increment Effective From', false, 'date', null],
                    ['bank_name', 'Bank Name', false, 'text', 100],
                    ['branch_name', 'Branch Name', false, 'text', 100],
                    ['bank_code', 'Bank Code', false, 'text', 50],
                    ['branch_code', 'Branch Code', false, 'text', 50],
                    ['bank_account_no', 'Bank Account No', false, 'text', 50],
                    ['account_holder_name', 'Account Holder Name', false, 'text', 150],
                    ['enable_epf_etf', 'EPF/ETF', false, 'yesno', 'Yes'],
                    ['ot_active', 'OT Active', false, 'yesno', 'No'],
                    ['ot_morning', 'Morning OT', false, 'yesno', 'No'],
                    ['ot_evening', 'Evening OT', false, 'yesno', 'No'],
                    ['ot_morning_rate', 'OT Morning Rate', false, 'decimal', null],
                    ['ot_night_rate', 'OT Night Rate', false, 'decimal', null],
                    ['early_deduction', 'Early Deduction', false, 'yesno', 'No'],
                    ['nopay_active', 'NoPay Active', false, 'yesno', 'No'],
                    ['increment_active', 'Increment Active', false, 'yesno', 'No'],
                    ['br1', 'Budgetary Relief 2015', false, 'yesno', 'No'],
                    ['br2', 'Budgetary Relief 2016', false, 'yesno', 'No'],
                    ['stamp', 'Stamp Duty', false, 'yesno', 'No'],
                    ['secondary_emp', 'Secondary Employment', false, 'yesno', 'No'],
                    ['primary_emp_basic', 'Primary Employment Basic', false, 'yesno', 'No'],
                    ['sports_fund_percentage', 'Sports Fund %', false, 'decimal', null],
                    ['staff_fund_amount', 'Staff Fund Amount', false, 'decimal', null],
                    ['comments', 'Comments', false, 'text', 255],
                ]],
            ],
        ],
        'Children' => [
            'sections' => [
                'Children' => ['color' => 'FEF3C7', 'columns' => [
                    ['attendance_no', 'Attendance No', true, 'text', 50],
                    ['name', 'Child Name', true, 'text', 100],
                    ['dob', 'Child Date of Birth', true, 'date', null],
                    ['nic', 'Child NIC', false, 'text', 20],
                ]],
            ],
        ],
        'Qualifications' => [
            'sections' => [
                'Completed qualifications (optional)' => ['color' => 'E0F2FE', 'columns' => [
                    ['attendance_no', 'Attendance No', true, 'text', 50],
                    ['qualification_type', 'Qualification Type', false, 'list', ['QualificationTypes', true]],
                    ['course_name', 'Course Name', false, 'text', 191],
                    ['institute_name', 'Institute', false, 'text', 191],
                    ['completion_year', 'Completion Year', false, 'year', null],
                ]],
            ],
        ],
        'Following Qualifications' => [
            'sections' => [
                'Currently following (optional)' => ['color' => 'F1F5F9', 'columns' => [
                    ['attendance_no', 'Attendance No', true, 'text', 50],
                    ['qualification_name', 'Qualification', false, 'text', 191],
                    ['institute_name', 'Institute', false, 'text', 191],
                    ['start_year', 'Start Year', false, 'year', null],
                    ['start_month', 'Start Month (1-12)', false, 'month', null],
                    ['end_year', 'End Year', false, 'year', null],
                    ['end_month', 'End Month (1-12)', false, 'month', null],
                    ['lecture_type', 'Lecture Type', false, 'list', ['LectureTypes', true]],
                ]],
            ],
        ],
    ],
];
