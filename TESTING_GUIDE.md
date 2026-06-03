# HRM Backend Testing Guide

## Purpose
This guide helps QA and developers verify the backend implementation against the HRM requirements audited in this repository.

## Scope
Tests cover:
- Attendance and fingerprint punch recording
- In/Out punch pairing and overtime generation
- Holiday and special OT calculations
- Leave request workflow and probation rules
- Payroll processing, bank transfer, EPF/ETF, and monthly reports
- No-pay deduction and salary breakdown reporting

## Test Setup
1. Ensure backend is running and database seeded with employees, shifts, holidays, and compensation data.
2. Use an API client like Postman, Insomnia, or curl.
3. Authenticate if required by the API.
4. Prepare test employees with:
   - `employee_no`
   - a valid shift assignment
   - a compensation record containing bank and salary data
   - payroll status and leave balances as needed

## Test Data Reference
### Employee Data
```
Employee A (EMP001):
  - Name: John Doe
  - Shift: Standard (8:30 AM - 5:00 PM)
  - Basic Salary: LKR 50,000
  - Bank: Commercial Bank
  - Account: 1234567890
  - Status: Active

Employee B (EMP002):
  - Name: Jane Smith
  - Shift: Standard
  - Basic Salary: LKR 60,000
  - Status: On Probation (6 months)
  - Leave Balance: 3 days

Employee C (EMP003):
  - Name: Mike Johnson
  - Shift: Extended (8:00 AM - 6:00 PM)
  - Basic Salary: LKR 75,000
  - Department: Operations
  - Loan: LKR 100,000 (5,000/month)
```

### Shift Configuration
```
Standard Shift:
  - Start: 08:30, End: 17:00
  - Hours/day: 8.5
  - Working days/month: 22
  - OT Multiplier: 1.50
  - Holiday Multiplier: 1.75

Extended Shift:
  - Start: 08:00, End: 18:00
  - Hours/day: 10
  - Working days/month: 22
  - OT Multiplier: 1.50
  - Holiday Multiplier: 2.00
```

## 1. Attendance & Fingerprint Punch

### 1.1 Attendance Punch Record
**Frontend Flow:** Employee or Admin → Attendance Form → Submit

**Endpoint:** `POST /api/time-cards`

**Example Payload (In-Punch):**
```json
{
  "employee_id": 1,
  "clock_time": "2025-05-25 08:32:15",
  "fingerprint_clock": "FP20250525083215EMP001",
  "punch_type": "in"
}
```

**Expected Response (201 Created):**
```json
{
  "id": 1001,
  "employee_id": 1,
  "employee_no": "EMP001",
  "clock_time": "2025-05-25 08:32:15",
  "fingerprint_clock": "FP20250525083215EMP001",
  "punch_type": "in",
  "status": "recorded",
  "created_at": "2025-05-25T08:32:16Z"
}
```

**Verification in Frontend:**
- Form displays success toast: "✓ In-punch recorded at 08:32"
- Attendance status shows "Clocked In"
- Next action button changes to "Clock Out"

### 1.2 In/Out Pairing and Minimum Interval
**Test Scenario:** Valid out-punch after 8+ hours

**Step 1:** Create in-punch at 08:32
```json
{"employee_id": 1, "clock_time": "2025-05-25 08:32:00", "fingerprint_clock": "FP01", "punch_type": "in"}
```

**Step 2:** Create out-punch at 17:15 (same day, 8h 43m later)
```json
{"employee_id": 1, "clock_time": "2025-05-25 17:15:00", "fingerprint_clock": "FP02", "punch_type": "out"}
```

**Expected Response:**
```json
{
  "id": 1002,
  "employee_id": 1,
  "clock_time": "2025-05-25 17:15:00",
  "punch_type": "out",
  "paired_with_in_punch": 1001,
  "duration_hours": 8.717,
  "overtime_generated": true,
  "status": "recorded"
}
```

**Frontend Verification:**
- Show pairing confirmation: "Out-punch paired with In-punch at 08:32"
- Display daily summary: "8h 43m worked, 0h 13m OT"

**Error Case:** Out-punch within 5 minutes
```json
{"employee_id": 1, "clock_time": "2025-05-25 08:36:00", "fingerprint_clock": "FP03", "punch_type": "out"}
```

**Expected Error Response (422 Unprocessable Entity):**
```json
{
  "error": "Invalid punch",
  "message": "Out-punch must be at least 5 minutes after in-punch",
  "last_in_punch": "2025-05-25 08:32:00",
  "attempted_out_punch": "2025-05-25 08:36:00",
  "minimum_interval_minutes": 5
}
```

**Frontend Handling:**
- Display error toast: "❌ Cannot punch out within 5 minutes of punch in"
- Disable out-punch button for 5 minutes with countdown timer

### 1.3 Attendance Report
**Endpoint:** `GET /api/reports/time-cards/attendance?employee_id=1&start_date=2025-05-20&end_date=2025-05-25`

**Expected Response:**
```json
{
  "employee_no": "EMP001",
  "employee_name": "John Doe",
  "report_period": "2025-05-20 to 2025-05-25",
  "attendance_records": [
    {
      "date": "2025-05-20",
      "in_time": "08:30:45",
      "out_time": "17:05:30",
      "duration_hours": 8.583,
      "status": "present",
      "overtime_hours": 0.083
    },
    {
      "date": "2025-05-21",
      "in_time": null,
      "out_time": null,
      "status": "absent"
    },
    {
      "date": "2025-05-25",
      "in_time": "08:32:00",
      "out_time": "17:15:00",
      "duration_hours": 8.717,
      "status": "present",
      "overtime_hours": 0.217
    }
  ],
  "summary": {
    "total_present": 4,
    "total_absent": 1,
    "total_overtime_hours": 2.1
  }
}
```

**Frontend Display:**
- Table showing daily attendance with color-coding (green=present, red=absent, yellow=early-out)
- Summary stats at bottom

### 1.4 Absent & Single Entry Reports
**Endpoint (Absent):** `GET /api/reports/time-cards/absent?month=5&year=2025`

**Expected Response:**
```json
{
  "report_type": "absent",
  "month": 5,
  "year": 2025,
  "absent_records": [
    {
      "employee_no": "EMP001",
      "employee_name": "John Doe",
      "department": "Sales",
      "absent_dates": ["2025-05-21"],
      "total_absent_days": 1
    },
    {
      "employee_no": "EMP002",
      "employee_name": "Jane Smith",
      "department": "Operations",
      "absent_dates": ["2025-05-15", "2025-05-22"],
      "total_absent_days": 2
    }
  ]
}
```

**Endpoint (Single Entry):** `GET /api/reports/time-cards/single-entry?month=5&year=2025`

**Expected Response:**
```json
{
  "report_type": "single_entry",
  "month": 5,
  "year": 2025,
  "single_entry_records": [
    {
      "employee_no": "EMP003",
      "employee_name": "Mike Johnson",
      "date": "2025-05-18",
      "punch_type": "in",
      "time": "08:00:15",
      "note": "Only in-punch recorded, no out-punch"
    }
  ]
}
```

**Frontend Actions:**
- Allow supervisors to review and approve/reject absent records
- Flag single-entry days for follow-up

## 2. Overtime and Holiday OT

### 2.1 Regular OT Calculation
**Frontend Flow:** System automatically calculates when out-punch > shift hours

**Test Data:**
```
Employee: EMP001 (John Doe)
Shift: Standard (8.5h/day)
In-Punch: 2025-05-25 08:30:00
Out-Punch: 2025-05-25 19:00:00 (10.5h worked)
OT Hours: 2.0 hours
```

**Backend Calculation:**
```
Total hours worked: 10.5
Shift hours: 8.5
OT hours: 2.0
Hourly rate: 50,000 / (8.5 * 22) = LKR 265.46/hour
OT rate (1.5x): 265.46 * 1.5 = LKR 398.19/hour
OT fee: 2.0 * 398.19 = LKR 796.38
```

**Expected Response (after out-punch):**
```json
{
  "overtime_id": 5001,
  "employee_id": 1,
  "date": "2025-05-25",
  "ot_hours": 2.0,
  "ot_type": "regular",
  "ot_fee": 796.38,
  "status": "recorded"
}
```

**Frontend Display:**
- Show OT alert: "⚠ 2.0 OT hours recorded (LKR 796.38)"
- Include in daily summary

### 2.2 Holiday OT Calculation
**Test Scenario:** Attendance on a declared holiday

**Setup:** Add holiday to calendar
```json
POST /api/leave-calendar
{
  "date": "2025-06-15",
  "holiday_name": "Buddha Jayanti",
  "type": "company",
  "multiplier": 1.75
}
```

**Test Punch on Holiday:**
```
Date: 2025-06-15 (Holiday)
In-Punch: 08:30:00
Out-Punch: 17:00:00
Worked: 8.5 hours
```

**Backend Calculation:**
```
All hours on holiday are OT at 1.75x multiplier
OT fee: 8.5 * 265.46 * 1.75 = LKR 3,953.40
```

**Expected Response:**
```json
{
  "overtime_id": 5002,
  "date": "2025-06-15",
  "holiday_name": "Buddha Jayanti",
  "ot_hours": 8.5,
  "ot_type": "holiday",
  "holiday_multiplier": 1.75,
  "ot_fee": 3953.40,
  "status": "recorded"
}
```

**Frontend Alert:**
- Highlight in red: "🎉 Holiday OT: 8.5 hours @ 1.75x = LKR 3,953.40"

### 2.3 Shift OT Rate Verification
**Endpoint:** `GET /api/shifts/1/overtime-rates`

**Expected Response:**
```json
{
  "shift_id": 1,
  "shift_name": "Standard Shift",
  "shift_hours_per_day": 8.5,
  "working_days_per_month": 22,
  "ot_multiplier": 1.5,
  "holiday_multiplier": 1.75,
  "ignore_hours_threshold": 0.25,
  "hourly_rate_for_basic_50000": 265.46,
  "ot_rate_for_basic_50000": 398.19,
  "holiday_rate_for_basic_50000": 464.55
}
```

**Frontend Configuration Screen:**
- Display all multipliers and rates in read-only table
- Allow HR to edit multipliers and see live calculations

## 3. Leave Workflow & Probation

### 3.1 Create Leave Request (Frontend Form)
**Frontend Flow:** Employee selects dates → Enters reason → Submits → Confirmation

**Form Validation (Frontend):**
- Start date ≤ End date
- Leave type selected
- Reason min 10 characters
- No weekend-only selections (unless allowed)
- Check leave balance before submit

**Example Payload:**
```json
POST /api/leave-masters
{
  "employee_id": 1,
  "leave_type": "annual",
  "start_date": "2025-06-10",
  "end_date": "2025-06-12",
  "reason": "Medical appointment and rest",
  "days_requested": 3
}
```

**Expected Response (201 Created):**
```json
{
  "id": 101,
  "employee_id": 1,
  "employee_no": "EMP001",
  "employee_name": "John Doe",
  "leave_type": "annual",
  "start_date": "2025-06-10",
  "end_date": "2025-06-12",
  "days_requested": 3,
  "status": "pending",
  "submitted_at": "2025-05-25T14:30:00Z",
  "balance_before": 10,
  "balance_after": 7
}
```

**Frontend Confirmation:**
- Display toast: "✓ Leave request submitted for 3 days"
- Show status: "Pending supervisor approval"
- Display new balance: "7 days remaining"

### 3.2 Approval Workflow (Supervisor & HR Views)
**Supervisor Dashboard:**

**Endpoint:** `GET /api/leave-master/supervisor-leaves?status=pending`

**Expected Response:**
```json
{
  "pending_leaves": [
    {
      "id": 101,
      "employee_no": "EMP001",
      "employee_name": "John Doe",
      "department": "Sales",
      "leave_type": "annual",
      "dates": "2025-06-10 to 2025-06-12",
      "days": 3,
      "reason": "Medical appointment and rest",
      "submitted_at": "2025-05-25T14:30:00Z",
      "status": "pending",
      "action_required_from": "supervisor"
    }
  ]
}
```

**Supervisor Action:**
```json
PUT /api/leave-masters/101/status
{
  "status": "supervisor_approved",
  "comments": "Approved. Please ensure handover is done."
}
```

**HR Final Approval:**
```json
PUT /api/leave-masters/101/status
{
  "status": "hr_approved",
  "comments": "Approved for payroll processing"
}
```

**Frontend Status Timeline:**
```
Pending → Supervisor Approved (green) → HR Approved (blue)
[Accept] [Reject]        ✓ Approved          ✓ Approved
```

### 3.3 Probation Leave Validation
**Test Case: Employee on Probation with Limited Balance**

**Employee B Status:**
```
Name: Jane Smith (EMP002)
Status: On Probation (started 2025-05-01, ends 2025-10-30)
Probation Leave Balance: 3 days
Annual Leave Balance: 0 days (not eligible during probation)
```

**Request Exceeding Balance:**
```json
POST /api/leave-masters
{
  "employee_id": 2,
  "leave_type": "annual",
  "start_date": "2025-06-10",
  "end_date": "2025-06-15",
  "days_requested": 5,
  "reason": "Personal matter"
}
```

**Expected Error Response (422 Unprocessable Entity):**
```json
{
  "error": "Insufficient leave balance",
  "message": "Employee is on probation and cannot take more than 3 days leave",
  "employee_status": "probation",
  "probation_end_date": "2025-10-30",
  "available_balance": 3,
  "requested_days": 5,
  "leave_type_allowed_during_probation": "probation_leave"
}
```

**Frontend Error Display:**
- Show error: "❌ Only 3 probation leave days available. You requested 5 days."
- Suggest: "Please request probation_leave instead, or reduce dates."
- Disable submit button

### 3.4 Leave Counts & Eligibility
**Endpoint:** `GET /api/leave-masters/1/counts`

**Expected Response:**
```json
{
  "employee_id": 1,
  "employee_no": "EMP001",
  "leave_balances": [
    {
      "leave_type": "annual",
      "allocated": 14,
      "taken": 3,
      "pending_approval": 3,
      "available": 8,
      "carryover_allowed": 5,
      "carryover_used": 0
    },
    {
      "leave_type": "casual",
      "allocated": 5,
      "taken": 2,
      "pending_approval": 0,
      "available": 3
    },
    {
      "leave_type": "sick",
      "allocated": 14,
      "taken": 1,
      "pending_approval": 0,
      "available": 13
    }
  ],
  "probation_status": null,
  "can_apply_leave": true
}
```

**Frontend Leave Card Display:**
```
┌─ Annual Leave ────────────────────┐
│ Allocated: 14  |  Available: 8    │
│ Taken: 3       |  Pending: 3      │
│ ▓▓▓▓▓░░░░░░░░░│ 3/14 used       │
└───────────────────────────────────┘
```

## 4. Payroll Processing

### 4.1 Process Salary Data (Batch)
**Frontend Flow:** HR → Select month/year → Review breakdown → Process

**Example Payload (EMP001 - Basic 50,000):**
```json
POST /api/salary-process/store
{
  "month": 5,
  "year": 2025,
  "employee_id": 1,
  "basic_salary": 50000,
  "allowances": {"hra": 10000, "dearness": 5000},
  "deductions": {"no_pay_days": 1},
  "overtime_fees": 2066.99,
  "loan_installment": 5000,
  "enable_epf_etf": true
}
```

**Calculation Breakdown:**
```
Gross:          50,000 + 10,000 + 5,000 + 2,066.99 = 67,066.99
No-Pay (1d):    50,000/22 = -2,272.73
EPF 8%:         -4,000.00
ETF 3%:         -1,500.00
Loan:           -5,000.00
Net Salary:     49,294.26
```

**Expected Response:**
```json
{"id": 2001, "net_salary": 49294.26, "status": "processed"}
```

### 4.2 Fetch Processed Salaries
**Endpoint:** `GET /api/salary/processed?month=5&year=2025`

Returns processed salary list with net amounts and statuses.

### 4.3 Mark Salary Issued
**Endpoint:** `POST /api/salary/process/mark-issued`

Changes status to "issued" and locks records.

### 4.4 Import/Export Salary Data
**Export:** Downloads Excel paysheet
**Import:** Uploads updated salary corrections

## 5. Payroll and Bank / EPF / ETF Reports

### 5.1 Monthly Payroll Report
**Frontend:** HR Dashboard → Reports → Monthly Payroll

**Endpoint:** `GET /api/reports/monthly-data?month=5&year=2025`

**Example Response (EMP001):**
```json
{
  "emp_no": "EMP001",
  "name": "John Doe",
  "bank": "Commercial Bank",
  "account": "1234567890",
  "basic_salary": 50000,
  "gross_salary": 67066.99,
  "net_salary": 49294.26,
  "epf_8": 4000,
  "epf_12": 6000,
  "etf_3": 1500,
  "no_pay_amount": 2272.73,
  "bank_amount": 49294.26,
  "cash_amount": 0,
  "ot_morning_hours": 2.1,
  "ot_morning_fees": 836.47,
  "ot_night_hours": 3.1,
  "ot_night_fees": 1230.52
}
```

**Frontend Report Display:** Payroll summary table with all earnings and deductions.

### 5.2 Bank Transfer Report
**Endpoint:** `GET /api/reports/bank-transfer?month=5&year=2025`

**Expected:** Bank name, branch, account numbers, and net amounts for all employees

### 5.3 EPF / ETF Report
**Endpoint:** `GET /api/reports/epf-etf?month=5&year=2025`

**Expected:** Employee (8%) and employer (12% + 3%) contributions

## 6. Salary Breakdown & Deductions

### 6.1 No Pay and Half-Day Deductions
- Confirm salary breakdown fields:
  - `full_day_nopay_deduction`
  - `half_day_deduction`
  - `saturday_nopay_deduction`
  - `early_out_nopay_deduction`
- Create a salary with no-pay deductions and verify report sums them correctly

### 6.2 Late Deductions and Loan Installments
- Confirm deductions include:
  - `loan_installment`
  - `loan_interest`
  - `stamp_duty`
  - `late_deduction`
- Verify values are reflected in total deductions and net salary

### 6.3 Allowances and Bonuses
- Add allowances and bonuses to salary process
- Verify amounts appear in `allowances`, `bonuses`, and final net salary

## 7. Holiday Calendar and Department Holidays

### 7.1 Company Holiday
- Add a company-level holiday record in `leaveCalendar`
- Verify attendance on that date is flagged as holiday
- Verify holiday OT uses holiday multiplier

### 7.2 Department Holiday
- Add department-specific holiday record
- Verify the holiday applies for employees in that department and not for unrelated departments

## 8. Special Cases and Regression

### 8.1 Fingerprint Field Persistence
- Confirm attendance request stores the `fingerprint_clock` field and it persists in the attendance record

### 8.2 Probation Leave Rejection Path
- Force a probation leave violation
- Verify the API response contains a rejection or error message

### 8.3 Report Data Consistency
- Confirm `monthly-data` report values mathematically align:
  - `bank_amount = basic_salary + allowances - (epf8 + no_pay + loan + stamp_duty)`
  - `cash_amount = net_salary - bank_amount`

## 9. Notes & Gaps
- The backend currently supports OT and holiday multipliers and report generation, but no explicit `Poya day` special-case rule is visible.
- No direct biometric device integration API appears in the backend; test `fingerprint_clock` persistence only.
- Seasonal June–November special attendance rules are not found in the backend logic; verify if this is handled elsewhere.

## Quick Verification Matrix
| Requirement | Test Endpoint | Expected Result |
|---|---|---|
| Attendance punch | `POST /api/time-cards` | record stored with `fingerprint_clock` |
| In/Out pairing | same | in/out linked, >5 min rule enforced |
| OT creation | attendance out punch | overtime record generated |
| Holiday OT | attendance on holiday | holiday multiplier applied |
| Leave workflow | `POST /api/leave-masters` + `PUT /status` | pending → approved workflow |
| Probation leave | `POST /api/leave-masters` | rejected when out of balance |
| Salary process | `POST /api/salary-process/store` | salary breakdown saved |
| Monthly report | `GET /api/reports/monthly-data` | bank/cash/EPF/ETF fields present |
| Absent report | `GET /api/reports/time-cards/absent` | absent entries listed |
| Single entry | `GET /api/reports/time-cards/single-entry` | one-punch days listed |

## Recommended Test Data
- Employee A: normal employee, regular shift, existing bank details
- Employee B: in probation, limited leave
- Employee C: department with special holiday
- Employee D: holiday attendance with OT
- Employee E: salary with loan and allowances

## Final Checklist
1. Attendance record created and stored
2. `fingerprint_clock` persisted
3. In/out punches paired correctly
4. Overtime generated and calculated
5. Holiday OT uses multiplier
6. Leave requests flow through approvals
7. Probation leave rules enforced
8. Salary process stores breakdowns
9. Report endpoints return bank/cash/EPF/ETF totals
10. Absent/single-entry reports produce expected data

---

Use this guide to perform manual or automated API tests against the backend. Adjust employee IDs and dates for your seeded test data.
