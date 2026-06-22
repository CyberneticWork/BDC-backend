<?php

namespace App\Http\Controllers;

use App\Models\employee;
use App\Models\deduction;
use App\Models\over_time;
use App\Models\allowances;
use Illuminate\Http\Request;
use App\Models\salary_process;
use Illuminate\Support\Facades\DB;
use App\Models\employee_allowances;
use App\Models\employee_deductions;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\EmployeeAllowancesImport;
use App\Imports\EmployeeDeductionsImport;
use App\Models\loans;
use App\Models\leave_master;
use App\Models\time_card;
use App\Models\Roster;
use Carbon\Carbon;
use App\Models\EmployeeBonus;

class SalaryProcessController extends Controller
{
    private function getLateMinutes(?string $inTime, ?string $shiftStartTime): int
    {
        if (!$inTime || !$shiftStartTime) {
            return 0;
        }

        $in = strtotime($inTime);
        $shiftStart = strtotime($shiftStartTime);

        if ($in === false || $shiftStart === false) {
            return 0;
        }

        if ($in <= $shiftStart) {
            return 0;
        }

        return (int) floor(($in - $shiftStart) / 60);
    }

    private function getApprovedLeaveInfoForDate(int $employeeId, string $date): array
    {
        $leave = leave_master::where('employee_id', $employeeId)
            ->whereIn('status', ['Approved', 'HR_Approved'])
            ->where(function ($query) use ($date) {
                $query->whereDate('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->whereDate('leave_from', '<=', $date)
                            ->whereDate('leave_to', '>=', $date);
                    });
            })
            ->orderByRaw("
                CASE
                    WHEN status = 'Approved' THEN 1
                    WHEN status = 'HR_Approved' THEN 2
                    ELSE 3
                END
            ")
            ->first();

        if (!$leave) {
            return [
                'has_approved_leave' => false,
                'is_half_day_leave' => false,
                'leave_type' => null,
                'leave_status' => null,
                'leave_period' => null,
            ];
        }

        return [
            'has_approved_leave' => true,
            'is_half_day_leave' => (bool) ($leave->is_half_day ?? false),
            'leave_type' => $leave->leave_type,
            'leave_status' => $leave->status,
            'leave_period' => $leave->period,
        ];
    }

    private function getRosterShiftStartTime(int $employeeId, string $date): ?string
    {
        $roster = roster::with('shift')
            ->where('employee_id', $employeeId)
            ->where(function ($q) use ($date) {
                $q->whereDate('date_from', '<=', $date)
                    ->whereDate('date_to', '>=', $date);
            })
            ->orderBy('date_from', 'desc')
            ->first();

        if (!$roster || !$roster->shift || !$roster->shift->start_time) {
            return null;
        }

        return $roster->shift->start_time;
    }

    private function getRosterShiftWorkHours(int $employeeId, string $date, float $defaultHours = 8): float
    {
        $roster = roster::with('shift')
            ->where('employee_id', $employeeId)
            ->where(function ($q) use ($date) {
                $q->whereDate('date_from', '<=', $date)
                    ->whereDate('date_to', '>=', $date);
            })
            ->orderBy('date_from', 'desc')
            ->first();

        if (!$roster || !$roster->shift || !$roster->shift->start_time || !$roster->shift->end_time) {
            return $defaultHours;
        }

        $start = strtotime($roster->shift->start_time);
        $end = strtotime($roster->shift->end_time);

        if ($end <= $start) {
            $end = strtotime('+1 day', $end);
        }

        $hours = ($end - $start) / 3600;
        return $hours > 0 ? round($hours, 2) : $defaultHours;
    }

    private function calculateLateDeductionData(int $employeeId, string $startDate, string $endDate, float $perDaySalary): array
    {
        $cardsByDate = time_card::where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNull('deleted_at')
            ->orderBy('time', 'asc')
            ->get()
            ->groupBy('date');

        $minorLateCounter = 0;

        $approvedLeaveLateCount = 0;
        $noDeductionLateCount = 0;
        $shortLeaveCount = 0;
        $halfDayCount = 0;
        $deductibleLateCount = 0;

        $shortLeaveDeductionAmount = 0;
        $halfDayDeductionAmount = 0;

        foreach ($cardsByDate as $date => $dayCards) {
            $inCard = $dayCards->first(function ($card) {
                $st = strtolower(trim($card->status));
                return $card->entry == 1 || in_array($st, ['in', 'late coming', 'late_coming']);
            });

            if (!$inCard) {
                continue;
            }

            $shiftStartTime = $this->getRosterShiftStartTime($employeeId, $date);

            if (!$shiftStartTime) {
                continue;
            }

            $lateMinutes = $this->getLateMinutes($inCard->time, $shiftStartTime);

            if ($lateMinutes > 0 && $lateMinutes <= 30) {

                $leaveInfo = $this->getApprovedLeaveInfoForDate($employeeId, $date);

                if ($leaveInfo['has_approved_leave']) {
                    $approvedLeaveLateCount++;
                    continue;
                }

                $minorLateCounter++;
                $shiftHours = $this->getRosterShiftWorkHours($employeeId, $date, 8);
                $hourlyRate = $shiftHours > 0 ? ($perDaySalary / $shiftHours) : 0;

                if ($minorLateCounter <= 3) {
                    $noDeductionLateCount++;
                } elseif ($minorLateCounter <= 5) {
                    $shortLeaveCount++;
                    $deductibleLateCount++;
                    $shortLeaveDeductionAmount += ($hourlyRate * 2); // පැය 2ක දඩය (Short Leave)
                } else {
                    $halfDayCount++;
                    $deductibleLateCount++;
                    $halfDayDeductionAmount += ($hourlyRate * 4); // පැය 4ක දඩය (Half Day)
                }
            }
        }

        return [
            'approved_leave_late_count' => $approvedLeaveLateCount,
            'no_deduction_late_count' => $noDeductionLateCount,
            'short_leave_count' => $shortLeaveCount,
            'half_day_count' => $halfDayCount,
            'deductible_late_count' => $deductibleLateCount,
            'short_leave_deduction' => round($shortLeaveDeductionAmount, 2),
            'half_day_deduction' => round($halfDayDeductionAmount, 2),
        ];
    }

    public function getProcessedSalaries(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');

        $processedSalaries = salary_process::whereIn('status', ['processed', 'issued'])
            ->when($month, function ($query) use ($month) {
                $query->where('month', $month);
            })
            ->when($year, function ($query) use ($year) {
                $query->where('year', $year);
            })
            ->with([
                'employee' => function ($query) {
                    $query->select('id', 'full_name', 'attendance_employee_no')
                        ->with([
                            'compensation' => function ($q) {
                                $q->select('employee_id', 'basic_salary', 'enable_epf_etf', 'bank_name', 'bank_account_no', 'branch_name');
                            }
                        ]);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $response = $processedSalaries->map(function ($salary) {
            return [
                'id' => $salary->id,
                'employee_id' => $salary->employee_id,
                'employee_no' => $salary->employee_no,
                'full_name' => $salary->full_name,
                'company_name' => $salary->company_name,
                'department_name' => $salary->department_name,
                'stamp' => $salary->stamp,
                'basic_salary' => $salary->basic_salary,
                'ot_morning' => $salary->ot_morning,
                'ot_evening' => $salary->ot_evening,
                'month' => $salary->month,
                'year' => $salary->year,
                'status' => $salary->status,
                'compensation' => $salary->employee->compensation ?? null,
                'bank_details' => $salary->employee->bankDetails ?? null,
                'salary_breakdown' => is_string($salary->salary_breakdown) ? json_decode($salary->salary_breakdown, true) : $salary->salary_breakdown,
                'allowances' => is_string($salary->allowances) ? json_decode($salary->allowances, true) : $salary->allowances,
                'deductions' => is_string($salary->deductions) ? json_decode($salary->deductions, true) : $salary->deductions,
                'bonuses' => is_string($salary->bonuses) ? json_decode($salary->bonuses, true) : $salary->bonuses,
            ];
        });

        return response()->json($response);
    }

    public function updateEmployeesAllowances(Request $request)
    {
        $employeeIDs = $request->selectedEmployees;
        $type = $request->bulkActionType;
        $amount = $request->bulkActionAmount;
        $typeId = $request->bulkActionId;
        $month = $request->month;
        $year = $request->year;

        if (!is_array($employeeIDs) || empty($employeeIDs)) {
            return response()->json(['error' => 'No employees selected'], 400);
        }

        if (!$month || !$year) {
            return response()->json(['error' => 'Month and Year are required for bulk actions'], 400);
        }

        if (!$type || !$typeId) {
            return response()->json(['error' => 'Action type and item are required'], 400);
        }

        try {
            DB::beginTransaction();

            foreach ($employeeIDs as $employeeId) {
                if ($type === 'allowance') {
                    employee_allowances::updateOrCreate(
                        [
                            'employee_id' => $employeeId,
                            'allowance_id' => $typeId,
                            'month' => $month,
                            'year' => $year,
                        ],
                        [
                            'custom_amount' => $amount,
                            'is_active' => 1,
                        ]
                    );
                } elseif ($type === 'deduction') {
                    employee_deductions::updateOrCreate(
                        [
                            'employee_id' => $employeeId,
                            'deduction_id' => $typeId,
                            'month' => $month,
                            'year' => $year,
                        ],
                        [
                            'custom_amount' => $amount,
                            'is_active' => 1,
                        ]
                    );
                } else {
                    EmployeeBonus::updateOrCreate(
                        [
                            'employee_id' => $employeeId,
                            'bonus_id' => $typeId,
                            'month' => $month,
                            'year' => $year,
                        ],
                        [
                            'custom_amount' => $amount,
                            'is_active' => 1,
                        ]
                    );
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Bulk update successful for the selected month.',
                'type' => $type,
                'affected_employees' => count($employeeIDs),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error performing bulk update: ' . $e->getMessage(),
            ], 500);
        }
    }

    // public function updateEmployeesAllowances(Request $request)
    // {
    //     $employeeIDs = $request->selectedEmployees;
    //     $type = $request->bulkActionType;
    //     $amount = $request->bulkActionAmount;
    //     $typeId = $request->bulkActionId;
    //     $month = $request->month;
    //     $year = $request->year;

    //     if (!is_array($employeeIDs) || empty($employeeIDs)) {
    //         return response()->json(['error' => 'No employees selected'], 400);
    //     }

    //     if (!$month || !$year) {
    //         return response()->json(['error' => 'Month and Year are required for bulk actions'], 400);
    //     }

    //     $rows = [];

    //     foreach ($employeeIDs as $employeeId) {
    //         if ($type === 'allowance') {
    //             $rows[] = [
    //                 'employee_id' => $employeeId,
    //                 'allowance_id' => $typeId,
    //                 'custom_amount' => $amount,
    //                 'is_active' => 1,
    //                 'month' => $month,
    //                 'year' => $year,
    //                 'created_at' => now(),
    //                 'updated_at' => now(),
    //             ];
    //         } elseif ($type === 'deduction') {
    //             $rows[] = [
    //                 'employee_id' => $employeeId,
    //                 'deduction_id' => $typeId,
    //                 'custom_amount' => $amount,
    //                 'is_active' => 1,
    //                 'month' => $month,
    //                 'year' => $year,
    //                 'created_at' => now(),
    //                 'updated_at' => now(),
    //             ];
    //         } else {
    //             $rows[] = [
    //                 'employee_id' => $employeeId,
    //                 'bonus_id' => $typeId,
    //                 'custom_amount' => $amount,
    //                 'is_active' => 1,
    //                 'month' => $month,
    //                 'year' => $year,
    //                 'created_at' => now(),
    //                 'updated_at' => now(),
    //             ];
    //         }
    //     }

    //     // Delete existing for this month and insert to avoid duplicates
    //     if ($type === 'allowance') {
    //         employee_allowances::whereIn('employee_id', $employeeIDs)->where('allowance_id', $typeId)->where('month', $month)->where('year', $year)->delete();
    //         employee_allowances::insert($rows);
    //     } elseif ($type === 'deduction') {
    //         employee_deductions::whereIn('employee_id', $employeeIDs)->where('deduction_id', $typeId)->where('month', $month)->where('year', $year)->delete();
    //         employee_deductions::insert($rows);
    //     } else {
    //         EmployeeBonus::whereIn('employee_id', $employeeIDs)->where('bonus_id', $typeId)->where('month', $month)->where('year', $year)->delete();
    //         EmployeeBonus::insert($rows);
    //     }

    //     return response()->json([
    //         'message' => 'Bulk update successful for the selected month.',
    //         'type' => $type,
    //         'affected_employees' => count($employeeIDs)
    //     ]);
    // }

    /*


public function getEmployeesByMonthAndCompany(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $company_id = $request->query('company_id');
        $department_id = $request->query('department_id');
        $search = $request->query('search');

        $kpiTypeRaw = strtolower((string)$request->query('kpi_type', ''));
        $kpiType = in_array($kpiTypeRaw, ['monthly', '6month', 'six_month']) ? $kpiTypeRaw : '';

        $startDate = "{$year}-{$month}-01";
        $lastDay = date('t', strtotime($startDate));
        $endDate = "{$year}-{$month}-{$lastDay}";
        $selectedMonthYear = date('Y-m', strtotime($startDate));

        $totalDaysInMonth = (int)$lastDay;

        $query = "
            SELECT
                e.id,
                e.attendance_employee_no AS emp_no,
                e.full_name,
                e.nic,
                c.name AS company_name,
                d.name AS department_name,
                sd.name AS sub_department_name,
                comp.basic_salary,
                oa.probationary_period,
                oa.date_of_joining,
                e.epf,
                cd.permanent_address AS address,
                cd.mobile_line,
                cd.emg_name,
                cd.emg_relationship,
                cd.emg_tel,
                comp.increment_active,
                CASE WHEN comp.increment_active = 1 THEN comp.increment_value ELSE NULL END AS increment_value,
                CASE WHEN comp.increment_active = 1 THEN comp.increment_effected_date ELSE NULL END AS increment_effected_date,
                comp.ot_morning,
                comp.ot_evening,
                comp.enable_epf_etf,
                comp.br1,
                comp.br2,
                comp.stamp,
                comp.bank_name,
                comp.branch_name,
                comp.bank_account_no,
                COALESCE(SUM(lo.loan_amount), 0) AS total_loan_amount,
                MAX(lo.installment_count) AS installment_count,
                MAX(lo.installment_amount) AS installment_amount,
                MAX(lo.status) AS loan_status,
                MAX(lo.schedule) AS loan_schedule,
                MAX(lo.deduct_from) AS loan_deduct_from,
                MAX(lo.with_interest) AS with_interest,
                MAX(lo.interest_rate_per_annum) AS interest_rate_per_annum,

                -- No Pay Types Split (Approved records only)
                -- සෙනසුරාදා දවස් වෙන් කර ගැනීම (DAYNAME = 'Saturday')
                COALESCE(SUM(CASE WHEN npr.type IN ('FULL_DAY', 'PARTIAL_ABSENT') AND DAYNAME(npr.date) != 'Saturday' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS weekday_nopays,
                COALESCE(SUM(CASE WHEN npr.type IN ('FULL_DAY', 'PARTIAL_ABSENT') AND DAYNAME(npr.date) = 'Saturday' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS saturday_nopays,
                COALESCE(SUM(CASE WHEN npr.type = 'EARLY_OUT' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS early_out_nopays,
                COALESCE(SUM(CASE WHEN npr.type = 'LATE_IN' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS major_late_nopays,

                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', a.id, ',\"name\":\"', REPLACE(IFNULL(a.allowance_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ea.custom_amount, a.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(a.allowance_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(a.category, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_allowances ea JOIN allowances a ON a.id = ea.allowance_id
                    WHERE ea.employee_id = e.id AND ea.is_active = 1 AND a.status = 'active' AND (ea.month = ? AND ea.year = ?)
                ) AS allowances,

                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', dd.id, ',\"name\":\"', REPLACE(IFNULL(dd.deduction_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ed.custom_amount, dd.amount, 0), ',\"is_custom\":', CASE WHEN ed.id IS NOT NULL THEN 1 ELSE 0 END, ',\"code\":\"', REPLACE(IFNULL(dd.deduction_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(dd.category, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM deductions dd LEFT JOIN employee_deductions ed ON dd.id = ed.deduction_id AND ed.employee_id = e.id AND ed.is_active = 1 AND (ed.month = ? AND ed.year = ?)
                    WHERE dd.company_id = c.id AND (dd.department_id IS NULL OR dd.department_id = oa.department_id) AND dd.status = 'active'
                ) AS deductions,

                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', b.id, ',\"name\":\"', REPLACE(IFNULL(b.bonus_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(eb.custom_amount, b.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(b.bonus_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(b.bonus_type, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_bonuses eb JOIN bonuses b ON b.id = eb.bonus_id
                    WHERE eb.employee_id = e.id AND eb.is_active = 1 AND b.status = 'active' AND (eb.month = ? AND eb.year = ?)
                ) AS bonuses

            FROM employees e
            JOIN organization_assignments oa ON e.organization_assignment_id = oa.id
            JOIN companies c ON oa.company_id = c.id
            LEFT JOIN departments d ON oa.department_id = d.id
            LEFT JOIN sub_departments sd ON oa.sub_department_id = sd.id
            LEFT JOIN compensation comp ON e.id = comp.employee_id
            LEFT JOIN contact_details cd ON e.id = cd.employee_id
            LEFT JOIN loans lo ON e.id = lo.employee_id AND lo.status = 'active'
            LEFT JOIN no_pay_records npr ON e.id = npr.employee_id AND LOWER(npr.status) = 'approved' AND npr.date BETWEEN ? AND ?

            WHERE e.is_active = '1'
        ";

        $params = [$month, $year, $month, $year, $month, $year, $startDate, $endDate];

        if ($company_id) { $query .= " AND oa.company_id = ? "; $params[] = $company_id; }
        if ($department_id) { $query .= " AND oa.department_id = ? "; $params[] = $department_id; }
        if ($search) { $query .= " AND (e.attendance_employee_no LIKE ? OR e.full_name LIKE ?) "; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }

        $query .= " GROUP BY e.id, e.attendance_employee_no, e.full_name, e.nic, c.name, d.name, sd.name, comp.basic_salary, comp.br1, comp.br2, comp.increment_active, comp.increment_value, comp.increment_effected_date, comp.ot_morning, comp.ot_evening, comp.enable_epf_etf, comp.stamp, comp.bank_name, comp.branch_name, comp.bank_account_no, c.id, oa.department_id, oa.probationary_period, oa.date_of_joining, e.epf, cd.permanent_address, cd.mobile_line, cd.emg_name, cd.emg_relationship, cd.emg_tel";

        $results = DB::select($query, $params);
        $data = [];

        foreach ($results as $result) {
            $employeeData = (array)$result;

            $employeeData['compensation'] = [
                'bank_name' => $result->bank_name ?? null,
                'branch_name' => $result->branch_name ?? null,
                'bank_account_no' => $result->bank_account_no ?? null,
            ];

            $allowancesArr = json_decode($result->allowances ?? '[]', true) ?: [];
            $deductionsArr = json_decode($result->deductions ?? '[]', true) ?: [];
            $bonusesArr = json_decode($result->bonuses ?? '[]', true) ?: [];

            $stampValue = ((int)($result->stamp ?? 0) === 1) ? 25 : 0;
            $employeeData['stamp'] = $stampValue;

            $basicSalary = (float)($employeeData['basic_salary'] ?? 0);
            $brAllowance = 0;

            if ((int)$result->br1 === 1 && (int)$result->br2 === 1) { $brAllowance = 3500; }
            elseif ((int)$result->br1 === 1) { $brAllowance = 1000; }
            elseif ((int)$result->br2 === 1) { $brAllowance = 2500; }

            $basicSalary += $brAllowance;

            if (!empty($employeeData['increment_active']) && !empty($employeeData['increment_effected_date']) && strtotime($employeeData['increment_effected_date']) <= strtotime($endDate)) {
                $basicSalary += (float)($employeeData['increment_value'] ?? 0);
            }

            // LOAN CALCULATION
            $installmentAmount = 0.0;
            $loanInterest = 0.0;
            $loanPrincipal = 0.0;
            $loanDeductFrom = $employeeData['loan_deduct_from'] ?? 'bonus';
            $loanStatus = strtolower((string)($employeeData['loan_status'] ?? ''));

            if ($loanStatus === 'active') {
                $schedule = $employeeData['loan_schedule'] ?? null;
                if (is_string($schedule)) { $schedule = is_array(json_decode($schedule, true)) ? json_decode($schedule, true) : null; }

                if (is_array($schedule) && count($schedule) > 0) {
                    foreach ($schedule as $r) {
                        $due = $r['due_date'] ?? $r['dueDate'] ?? null;
                        if ($due && date('Y-m', strtotime($due)) === $selectedMonthYear) {
                            $installmentAmount = (float)($r['installment_amount'] ?? $r['installmentAmount'] ?? 0);
                            break;
                        }
                    }
                } else {
                    $installmentAmount = ((int)($employeeData['installment_count'] ?? 0) > 0) ? (float)($employeeData['installment_amount'] ?? 0) : 0.0;
                }

                if ($employeeData['with_interest'] && $employeeData['interest_rate_per_annum'] > 0) {
                    $loanAmountTotal = (float)($employeeData['total_loan_amount'] ?? 0);
                    $loanInterest = ($loanAmountTotal * ((float)$employeeData['interest_rate_per_annum'] / 100)) / 12;
                    if ($loanInterest > $installmentAmount) { $loanInterest = $installmentAmount; }
                }
                $loanPrincipal = $installmentAmount - $loanInterest;
            }

            // Working Days
            $companyLeavesCount = DB::table('leave_calendars')->where('company_id', $company_id ?? 0)
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('start_date', [$startDate, $endDate])->orWhereBetween('end_date', [$startDate, $endDate]);
                })->count();

            $workingDaysInMonth = max(1, $totalDaysInMonth - $companyLeavesCount);
            $perDaySalary = $basicSalary / $workingDaysInMonth;

            // සෙනසුරාදා සහ අනෙකුත් දවස් වල No Pay ගණනය කිරීම
            $weekdayNoPays = (float)($employeeData['weekday_nopays'] ?? 0);
            $saturdayNoPays = (float)($employeeData['saturday_nopays'] ?? 0);
            $earlyOutNoPays = (float)($employeeData['early_out_nopays'] ?? 0);
            $majorLateNoPays = (float)($employeeData['major_late_nopays'] ?? 0);

            $fullDayNoPayDeduction = round($weekdayNoPays * $perDaySalary, 2);
            $saturdayNoPayBonusDeduction = round($saturdayNoPays * $perDaySalary, 2); // මෙය Bonus එකෙන් කැපෙන කොටස

            $earlyOutNoPayDeduction = round($earlyOutNoPays * $perDaySalary, 2);
            $majorLateDeduction = round($majorLateNoPays * $perDaySalary, 2);

            // Probation Deduction
            $probationDeduction = 0.0;
            if ($employeeData['probationary_period']) {
                $probationLeaves = leave_master::where('employee_id', $employeeData['id'])
                    ->whereRaw('LOWER(status) = ?', ['approved'])
                    ->whereBetween('leave_date', [$startDate, $endDate])
                    ->get();

                $totalProbationOverLimit = 0;
                foreach ($probationLeaves as $pl) {
                    $totalProbationOverLimit += (float)($pl->over_limit ?? 0);
                }

                $probationDeduction = round($totalProbationOverLimit * $perDaySalary, 2);
            }

            // Minor Late Deductions (<= 30 mins)
            $minorLateData = $this->calculateLateDeductionData((int)$employeeData['id'], $startDate, $endDate, $perDaySalary);
            $shortLeaveDeduction = $minorLateData['short_leave_deduction'] ?? 0;
            $halfDayDeduction = $minorLateData['half_day_deduction'] ?? 0;

            // KPI
            $kpiAllowance = 0.0;
            $kpiBonusAllowance = 0.0;
            if ($kpiType === 'monthly') {
                $percentage = DB::table('performance_evaluations')->where('employee_id', $employeeData['id'])->whereBetween('start_date', [$startDate, $endDate])->avg('percentage');
                if (!is_null($percentage)) {
                    $kpiAllowance = round(($basicSalary * ((float)$percentage)) / 100.0, 2);
                    $allowancesArr[] = ['name' => 'KPI Allowance', 'amount' => $kpiAllowance, 'category' => 'kpi'];
                }
            } elseif ($kpiType === '6month') {
                $sixMonthStart = Carbon::parse($startDate)->subMonths(5)->startOfMonth()->toDateString();
                $percentage = DB::table('performance_appraisals')->where('employee_id', $employeeData['id'])->where('start_date', '>=', $sixMonthStart)->where('end_date', '<=', $endDate)->avg('percentage');
                if (!is_null($percentage)) {
                    $kpiBonusAllowance = round(($basicSalary * ((float)$percentage)) / 100.0, 2);
                    $bonusesArr[] = ['name' => 'KPI Bonus (6M)', 'amount' => $kpiBonusAllowance, 'category' => 'kpi_bonus'];
                }
            }

            $employeeData['allowances'] = $allowancesArr;
            $employeeData['deductions'] = $deductionsArr;
            $employeeData['bonuses'] = $bonusesArr;

            $totalAllowances = array_reduce($allowancesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);
            $totalBonuses = array_reduce($bonusesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

            $totalFixedDeductions = array_reduce($deductionsArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

            $epfEligibleAllowances = array_reduce($allowancesArr, function ($carry, $item) {
                return (strtolower($item['category'] ?? '') === 'kpi_bonus') ? $carry : $carry + (float)($item['amount'] ?? 0);
            }, 0);
            $epfEtfBase = $basicSalary + $epfEligibleAllowances;
            $epfEmployeeDeduction = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.08) : 0;

            // Overtime
            $otRows = over_time::with('timeCard:id,date,time,actual_date')->where('employee_id', $employeeData['id'])->whereRaw('LOWER(status) = ?', ['approved'])->whereHas('timeCard', function ($q) use ($startDate, $endDate) { $q->whereBetween('date', [$startDate, $endDate]); })->get();
            $morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1) ? ((float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount')) : 0.0;
            $night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1) ? ((float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount')) : 0.0;
            $holiday_ot_fees = (float)$otRows->sum('holiday_ot_amount');

            // --- DEDUCTION SPLIT ---
            $basicGross = $basicSalary + $totalAllowances;
            $bonusGross = $totalBonuses;

            // 1. Basic Deductions (සතියේ දිනවල NoPay, EPF, Probation)
            $basicDeductionsTotal = $epfEmployeeDeduction + $fullDayNoPayDeduction + $probationDeduction;

            // 2. Bonus Deductions (සෙනසුරාදා NoPay, Early Out, Short Leave, Half Day, Major Late, Custom Deductions)
            $bonusDeductionsTotal = $saturdayNoPayBonusDeduction + $earlyOutNoPayDeduction + $shortLeaveDeduction + $halfDayDeduction + $majorLateDeduction + $totalFixedDeductions;

            if ($loanDeductFrom === 'basic') {
                $basicDeductionsTotal += $loanPrincipal;
            } else {
                $bonusDeductionsTotal += $loanPrincipal;
            }
            $bonusDeductionsTotal += $loanInterest;

            // Totals
            $grossSalary = $basicGross + $bonusGross + $morning_ot_fees + $night_ot_fees + $holiday_ot_fees;
            $totalDeductions = $basicDeductionsTotal + $bonusDeductionsTotal + $stampValue;
            $netSalary = $grossSalary - $totalDeductions;

            $employeeData['salary_breakdown'] = [
                'basic_salary' => round($basicSalary, 2),
                'per_day_salary' => round($perDaySalary, 3),
                'ot_morning_fees' => round($morning_ot_fees, 2),
                'ot_night_fees' => round($night_ot_fees, 2),
                'holiday_ot_fees' => round($holiday_ot_fees, 2),
                'ot_morning_hours' => round((float)$otRows->sum('morning_ot') + (float)$otRows->sum('morning_ot_special'), 2),
                'ot_night_hours' => round((float)$otRows->sum('afternoon_ot') + (float)$otRows->sum('evening_ot_special'), 2),
                'holiday_ot_hours' => round((float)$otRows->sum('holiday_ot_hours'), 2),

                // Deductions mapping
                'full_day_nopay_deduction' => round($fullDayNoPayDeduction, 2),
                'saturday_nopay_deduction' => round($saturdayNoPayBonusDeduction, 2), // අලුත් එකතු කිරීම
                'early_out_nopay_deduction' => round($earlyOutNoPayDeduction, 2),
                'short_leave_deduction' => round($shortLeaveDeduction, 2),
                'half_day_deduction' => round($halfDayDeduction, 2),
                'major_late_deduction' => round($majorLateDeduction, 2),
                'epf_employee_deduction' => round($epfEmployeeDeduction, 2),
                'probation_deduction' => round($probationDeduction, 2),
                'stamp_duty' => $stampValue,

                // Loan mappings
                'loan_principal' => round($loanPrincipal, 2),
                'loan_interest' => round($loanInterest, 2),
                'loan_deduct_from' => $loanDeductFrom,

                'total_fixed_deductions' => round($totalFixedDeductions, 2),

                'net_salary' => round($netSalary, 2),
                'gross_salary' => round($grossSalary, 2),
                'total_deductions' => round($totalDeductions, 2),
            ];

            $data[] = $employeeData;
        }

        return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }
*/


    // public function getEmployeesByMonthAndCompany(Request $request)
    // {
    //     $month = $request->query('month');
    //     $year = $request->query('year');
    //     $company_id = $request->query('company_id');
    //     $department_id = $request->query('department_id');
    //     $search = $request->query('search');

    //     $kpiTypeRaw = strtolower((string)$request->query('kpi_type', ''));
    //     $kpiType = in_array($kpiTypeRaw, ['monthly', '6month', 'six_month']) ? $kpiTypeRaw : '';

    //     $startDate = "{$year}-{$month}-01";
    //     $lastDay = date('t', strtotime($startDate));
    //     $endDate = "{$year}-{$month}-{$lastDay}";
    //     $selectedMonthYear = date('Y-m', strtotime($startDate));

    //     $totalDaysInMonth = (int)$lastDay;

    //     $query = "
    //         SELECT
    //             e.id,
    //             e.attendance_employee_no AS emp_no,
    //             e.full_name,
    //             e.nic,
    //             c.name AS company_name,
    //             d.name AS department_name,
    //             sd.name AS sub_department_name,
    //             comp.basic_salary,
    //             oa.probationary_period,
    //             oa.date_of_joining,
    //             e.epf,
    //             cd.permanent_address AS address,
    //             cd.mobile_line,
    //             cd.emg_name,
    //             cd.emg_relationship,
    //             cd.emg_tel,
    //             comp.increment_active,
    //             CASE WHEN comp.increment_active = 1 THEN comp.increment_value ELSE NULL END AS increment_value,
    //             CASE WHEN comp.increment_active = 1 THEN comp.increment_effected_date ELSE NULL END AS increment_effected_date,
    //             comp.ot_morning,
    //             comp.ot_evening,
    //             comp.enable_epf_etf,
    //             comp.br1,
    //             comp.br2,
    //             comp.stamp,
    //             comp.bank_name,
    //             comp.branch_name,
    //             comp.bank_account_no,
    //             COALESCE(SUM(lo.loan_amount), 0) AS total_loan_amount,
    //             MAX(lo.installment_count) AS installment_count,
    //             MAX(lo.installment_amount) AS installment_amount,
    //             MAX(lo.status) AS loan_status,
    //             MAX(lo.schedule) AS loan_schedule,
    //             MAX(lo.deduct_from) AS loan_deduct_from,
    //             MAX(lo.with_interest) AS with_interest,
    //             MAX(lo.interest_rate_per_annum) AS interest_rate_per_annum,

    //             -- No Pay Types Split (Approved records only)
    //             COALESCE(SUM(CASE WHEN npr.type IN ('FULL_DAY', 'PARTIAL_ABSENT') AND DAYNAME(npr.date) != 'Saturday' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS weekday_nopays,
    //             COALESCE(SUM(CASE WHEN npr.type IN ('FULL_DAY', 'PARTIAL_ABSENT') AND DAYNAME(npr.date) = 'Saturday' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS saturday_nopays,
    //             COALESCE(SUM(CASE WHEN npr.type = 'EARLY_OUT' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS early_out_nopays,
    //             COALESCE(SUM(CASE WHEN npr.type = 'LATE_IN' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS major_late_nopays,

    //             (
    //                 SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
    //                     CONCAT('{\"id\":', a.id, ',\"name\":\"', REPLACE(IFNULL(a.allowance_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ea.custom_amount, a.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(a.allowance_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(a.category, ''), '\"', '\\\\\"'), '\"}')
    //                 SEPARATOR ','), ']'), '[]')
    //                 FROM employee_allowances ea JOIN allowances a ON a.id = ea.allowance_id
    //                 WHERE ea.employee_id = e.id AND ea.is_active = 1 AND a.status = 'active' AND (ea.month = ? AND ea.year = ?)
    //             ) AS allowances,

    //             (
    //                 SELECT COALESCE(SUM(da.amount), 0)
    //                 FROM dinner_allowances da
    //                 WHERE da.employee_id = e.id AND da.status = 'Approved' AND MONTH(da.date) = ? AND YEAR(da.date) = ?
    //             ) AS total_dinner_allowance,

    //             -- මෙතන තමයි කලින් අවුල තිබ්බේ (LEFT JOIN එකක් තිබුණා, ඒක JOIN කරලා හැදුවා)
    //             (
    //                 SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
    //                     CONCAT('{\"id\":', dd.id, ',\"name\":\"', REPLACE(IFNULL(dd.deduction_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ed.custom_amount, dd.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(dd.deduction_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(dd.category, ''), '\"', '\\\\\"'), '\"}')
    //                 SEPARATOR ','), ']'), '[]')
    //                 FROM employee_deductions ed JOIN deductions dd ON dd.id = ed.deduction_id
    //                 WHERE ed.employee_id = e.id AND ed.is_active = 1 AND dd.status = 'active' AND (ed.month = ? AND ed.year = ?)
    //             ) AS deductions,

    //             (
    //                 SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
    //                     CONCAT('{\"id\":', b.id, ',\"name\":\"', REPLACE(IFNULL(b.bonus_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(eb.custom_amount, b.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(b.bonus_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(b.bonus_type, ''), '\"', '\\\\\"'), '\"}')
    //                 SEPARATOR ','), ']'), '[]')
    //                 FROM employee_bonuses eb JOIN bonuses b ON b.id = eb.bonus_id
    //                 WHERE eb.employee_id = e.id AND eb.is_active = 1 AND b.status = 'active' AND (eb.month = ? AND eb.year = ?)
    //             ) AS bonuses

    //         FROM employees e
    //         JOIN organization_assignments oa ON e.organization_assignment_id = oa.id
    //         JOIN companies c ON oa.company_id = c.id
    //         LEFT JOIN departments d ON oa.department_id = d.id
    //         LEFT JOIN sub_departments sd ON oa.sub_department_id = sd.id
    //         LEFT JOIN compensation comp ON e.id = comp.employee_id
    //         LEFT JOIN contact_details cd ON e.id = cd.employee_id
    //         LEFT JOIN loans lo ON e.id = lo.employee_id AND lo.status = 'active'
    //         LEFT JOIN no_pay_records npr ON e.id = npr.employee_id AND LOWER(npr.status) = 'approved' AND npr.date BETWEEN ? AND ?

    //         WHERE e.is_active = '1'
    //     ";

    //     $params = [$month, $year, $month, $year, $month, $year, $month, $year, $startDate, $endDate];

    //     if ($company_id) {
    //         $query .= " AND oa.company_id = ? ";
    //         $params[] = $company_id;
    //     }
    //     if ($department_id) {
    //         $query .= " AND oa.department_id = ? ";
    //         $params[] = $department_id;
    //     }
    //     if ($search) {
    //         $query .= " AND (e.attendance_employee_no LIKE ? OR e.full_name LIKE ?) ";
    //         $params[] = "%{$search}%";
    //         $params[] = "%{$search}%";
    //     }

    //     $query .= " GROUP BY e.id, e.attendance_employee_no, e.full_name, e.nic, c.name, d.name, sd.name, comp.basic_salary, comp.br1, comp.br2, comp.increment_active, comp.increment_value, comp.increment_effected_date, comp.ot_morning, comp.ot_evening, comp.enable_epf_etf, comp.stamp, comp.bank_name, comp.branch_name, comp.bank_account_no, c.id, oa.department_id, oa.probationary_period, oa.date_of_joining, e.epf, cd.permanent_address, cd.mobile_line, cd.emg_name, cd.emg_relationship, cd.emg_tel";

    //     $results = DB::select($query, $params);
    //     $data = [];

    //     foreach ($results as $result) {
    //         $employeeData = (array)$result;

    //         $employeeData['compensation'] = [
    //             'bank_name' => $result->bank_name ?? null,
    //             'branch_name' => $result->branch_name ?? null,
    //             'bank_account_no' => $result->bank_account_no ?? null,
    //         ];

    //         $allowancesArr = json_decode($result->allowances ?? '[]', true) ?: [];
    //         $deductionsArr = json_decode($result->deductions ?? '[]', true) ?: [];
    //         $bonusesArr = json_decode($result->bonuses ?? '[]', true) ?: [];

    //         $dinnerAllowanceValue = (float)($employeeData['total_dinner_allowance'] ?? 0);
    //         if ($dinnerAllowanceValue > 0) {
    //             $allowancesArr[] = [
    //                 'id' => 'dinner_allowance',
    //                 'name' => 'Dinner Allowance',
    //                 'amount' => $dinnerAllowanceValue,
    //                 'is_custom' => 1,
    //                 'code' => 'DINNER_ALW',
    //                 'category' => 'dinner_allowance'
    //             ];
    //         }

    //         $stampValue = ((int)($result->stamp ?? 0) === 1) ? 25 : 0;
    //         $employeeData['stamp'] = $stampValue;

    //         $basicSalary = (float)($employeeData['basic_salary'] ?? 0);
    //         $brAllowance = 0;

    //         if ((int)$result->br1 === 1 && (int)$result->br2 === 1) {
    //             $brAllowance = 3500;
    //         } elseif ((int)$result->br1 === 1) {
    //             $brAllowance = 1000;
    //         } elseif ((int)$result->br2 === 1) {
    //             $brAllowance = 2500;
    //         }

    //         $basicSalary += $brAllowance;

    //         if (!empty($employeeData['increment_active']) && !empty($employeeData['increment_effected_date']) && strtotime($employeeData['increment_effected_date']) <= strtotime($endDate)) {
    //             $basicSalary += (float)($employeeData['increment_value'] ?? 0);
    //         }

    //         // LOAN CALCULATION
    //         $installmentAmount = 0.0;
    //         $loanInterest = 0.0;
    //         $loanPrincipal = 0.0;
    //         $loanDeductFrom = $employeeData['loan_deduct_from'] ?? 'bonus';
    //         $loanStatus = strtolower((string)($employeeData['loan_status'] ?? ''));

    //         if ($loanStatus === 'active') {
    //             $schedule = $employeeData['loan_schedule'] ?? null;
    //             if (is_string($schedule)) {
    //                 $schedule = is_array(json_decode($schedule, true)) ? json_decode($schedule, true) : null;
    //             }

    //             if (is_array($schedule) && count($schedule) > 0) {
    //                 foreach ($schedule as $r) {
    //                     $due = $r['due_date'] ?? $r['dueDate'] ?? null;
    //                     if ($due && date('Y-m', strtotime($due)) === $selectedMonthYear) {
    //                         $installmentAmount = (float)($r['installment_amount'] ?? $r['installmentAmount'] ?? 0);
    //                         break;
    //                     }
    //                 }
    //             } else {
    //                 $installmentAmount = ((int)($employeeData['installment_count'] ?? 0) > 0) ? (float)($employeeData['installment_amount'] ?? 0) : 0.0;
    //             }

    //             if ($employeeData['with_interest'] && $employeeData['interest_rate_per_annum'] > 0) {
    //                 $loanAmountTotal = (float)($employeeData['total_loan_amount'] ?? 0);
    //                 $loanInterest = ($loanAmountTotal * ((float)$employeeData['interest_rate_per_annum'] / 100)) / 12;
    //                 if ($loanInterest > $installmentAmount) {
    //                     $loanInterest = $installmentAmount;
    //                 }
    //             }
    //             $loanPrincipal = $installmentAmount - $loanInterest;
    //         }

    //         // Working Days & No Pay Deductions
    //         $companyLeavesCount = DB::table('leave_calendars')->where('company_id', $company_id ?? 0)
    //             ->where(function ($q) use ($startDate, $endDate) {
    //                 $q->whereBetween('start_date', [$startDate, $endDate])->orWhereBetween('end_date', [$startDate, $endDate]);
    //             })->count();

    //         $workingDaysInMonth = max(1, $totalDaysInMonth - $companyLeavesCount);
    //         $perDaySalary = $basicSalary / $workingDaysInMonth;

    //         // Full Day, Partial Absent (Half day & Short leaves No pay), Early Out, Major Late Deductions
    //         $weekdayNoPays = (float)($employeeData['weekday_nopays'] ?? 0);
    //         $saturdayNoPays = (float)($employeeData['saturday_nopays'] ?? 0);
    //         $earlyOutNoPays = (float)($employeeData['early_out_nopays'] ?? 0);
    //         $majorLateNoPays = (float)($employeeData['major_late_nopays'] ?? 0);

    //         $fullDayNoPayDeduction = round($weekdayNoPays * $perDaySalary, 2);
    //         $saturdayNoPayBonusDeduction = round($saturdayNoPays * $perDaySalary, 2);

    //         $earlyOutNoPayDeduction = round($earlyOutNoPays * $perDaySalary, 2);
    //         $majorLateDeduction = round($majorLateNoPays * $perDaySalary, 2);

    //         // Probation Deduction
    //         $probationDeduction = 0.0;
    //         if ($employeeData['probationary_period']) {
    //             $probationLeaves = leave_master::where('employee_id', $employeeData['id'])
    //                 ->whereRaw('LOWER(status) = ?', ['approved'])
    //                 ->whereBetween('leave_date', [$startDate, $endDate])
    //                 ->get();

    //             $totalProbationOverLimit = 0;
    //             foreach ($probationLeaves as $pl) {
    //                 $totalProbationOverLimit += (float)($pl->over_limit ?? 0);
    //             }

    //             $probationDeduction = round($totalProbationOverLimit * $perDaySalary, 2);
    //         }

    //         // Minor Late Deductions (<= 30 mins)
    //         $minorLateData = $this->calculateLateDeductionData((int)$employeeData['id'], $startDate, $endDate, $perDaySalary);
    //         $shortLeaveDeduction = $minorLateData['short_leave_deduction'] ?? 0;
    //         $halfDayDeduction = $minorLateData['half_day_deduction'] ?? 0;

    //         // KPI
    //         $kpiAllowance = 0.0;
    //         $kpiBonusAllowance = 0.0;
    //         if ($kpiType === 'monthly') {
    //             $percentage = DB::table('performance_evaluations')->where('employee_id', $employeeData['id'])->whereBetween('start_date', [$startDate, $endDate])->avg('percentage');
    //             if (!is_null($percentage)) {
    //                 $kpiAllowance = round(($basicSalary * ((float)$percentage)) / 100.0, 2);
    //                 $allowancesArr[] = ['name' => 'KPI Allowance', 'amount' => $kpiAllowance, 'category' => 'kpi'];
    //             }
    //         } elseif ($kpiType === '6month') {
    //             $sixMonthStart = Carbon::parse($startDate)->subMonths(5)->startOfMonth()->toDateString();
    //             $percentage = DB::table('performance_appraisals')->where('employee_id', $employeeData['id'])->where('start_date', '>=', $sixMonthStart)->where('end_date', '<=', $endDate)->avg('percentage');
    //             if (!is_null($percentage)) {
    //                 $kpiBonusAllowance = round(($basicSalary * ((float)$percentage)) / 100.0, 2);
    //                 $bonusesArr[] = ['name' => 'KPI Bonus (6M)', 'amount' => $kpiBonusAllowance, 'category' => 'kpi_bonus'];
    //             }
    //         }

    //         $employeeData['allowances'] = $allowancesArr;
    //         $employeeData['deductions'] = $deductionsArr;
    //         $employeeData['bonuses'] = $bonusesArr;

    //         $totalAllowances = array_reduce($allowancesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);
    //         $totalBonuses = array_reduce($bonusesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

    //         $totalFixedDeductions = array_reduce($deductionsArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

    //         $epfEligibleAllowances = array_reduce($allowancesArr, function ($carry, $item) {
    //             $cat = strtolower($item['category'] ?? '');
    //             return in_array($cat, ['kpi_bonus', 'dinner_allowance']) ? $carry : $carry + (float)($item['amount'] ?? 0);
    //         }, 0);
    //         $epfEtfBase = $basicSalary + $epfEligibleAllowances;
    //         $epfEmployeeDeduction = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.08) : 0;

    //         // Overtime
    //         $otRows = over_time::with('timeCard:id,date,time,actual_date')->where('employee_id', $employeeData['id'])->whereRaw('LOWER(status) = ?', ['approved'])->whereHas('timeCard', function ($q) use ($startDate, $endDate) {
    //             $q->whereBetween('date', [$startDate, $endDate]);
    //         })->get();
    //         $morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1) ? ((float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount')) : 0.0;
    //         $night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1) ? ((float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount')) : 0.0;
    //         $holiday_ot_fees = (float)$otRows->sum('holiday_ot_amount');

    //         // --- DEDUCTION SPLIT ---
    //         $basicGross = $basicSalary + $totalAllowances;
    //         $bonusGross = $totalBonuses;

    //         $basicDeductionsTotal = $epfEmployeeDeduction + $fullDayNoPayDeduction + $probationDeduction;
    //         $bonusDeductionsTotal = $saturdayNoPayBonusDeduction + $earlyOutNoPayDeduction + $shortLeaveDeduction + $halfDayDeduction + $majorLateDeduction + $totalFixedDeductions;

    //         if ($loanDeductFrom === 'basic') {
    //             $basicDeductionsTotal += $loanPrincipal;
    //         } else {
    //             $bonusDeductionsTotal += $loanPrincipal;
    //         }

    //         $bonusDeductionsTotal += $loanInterest;

    //         // Totals
    //         $grossSalary = $basicGross + $bonusGross + $morning_ot_fees + $night_ot_fees + $holiday_ot_fees;
    //         $totalDeductions = $basicDeductionsTotal + $bonusDeductionsTotal + $stampValue;
    //         $netSalary = $grossSalary - $totalDeductions;

    //         $employeeData['salary_breakdown'] = [
    //             'basic_salary' => round($basicSalary, 2),
    //             'per_day_salary' => round($perDaySalary, 3),
    //             'ot_morning_fees' => round($morning_ot_fees, 2),
    //             'ot_night_fees' => round($night_ot_fees, 2),
    //             'holiday_ot_fees' => round($holiday_ot_fees, 2),
    //             'ot_morning_hours' => round((float)$otRows->sum('morning_ot') + (float)$otRows->sum('morning_ot_special'), 2),
    //             'ot_night_hours' => round((float)$otRows->sum('afternoon_ot') + (float)$otRows->sum('evening_ot_special'), 2),
    //             'holiday_ot_hours' => round((float)$otRows->sum('holiday_ot_hours'), 2),

    //             'full_day_nopay_deduction' => round($fullDayNoPayDeduction, 2),
    //             'saturday_nopay_deduction' => round($saturdayNoPayBonusDeduction, 2),
    //             'early_out_nopay_deduction' => round($earlyOutNoPayDeduction, 2),
    //             'short_leave_deduction' => round($shortLeaveDeduction, 2),
    //             'half_day_deduction' => round($halfDayDeduction, 2),
    //             'major_late_deduction' => round($majorLateDeduction, 2),
    //             'epf_employee_deduction' => round($epfEmployeeDeduction, 2),
    //             'probation_deduction' => round($probationDeduction, 2),
    //             'stamp_duty' => $stampValue,

    //             'loan_principal' => round($loanPrincipal, 2),
    //             'loan_interest' => round($loanInterest, 2),
    //             'loan_deduct_from' => $loanDeductFrom,

    //             'total_fixed_deductions' => round($totalFixedDeductions, 2),

    //             'net_salary' => round($netSalary, 2),
    //             'gross_salary' => round($grossSalary, 2),
    //             'total_deductions' => round($totalDeductions, 2),
    //         ];

    //         $data[] = $employeeData;
    //     }

    //     return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    // }


    public function getEmployeesByMonthAndCompany(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $company_id = $request->query('company_id');
        $department_id = $request->query('department_id');
        $search = $request->query('search');

        $kpiTypeRaw = strtolower((string)$request->query('kpi_type', ''));
        $kpiType = in_array($kpiTypeRaw, ['monthly', '6month', 'six_month']) ? $kpiTypeRaw : '';

        $startDate = "{$year}-{$month}-01";
        $lastDay = date('t', strtotime($startDate));
        $endDate = "{$year}-{$month}-{$lastDay}";
        $selectedMonthYear = date('Y-m', strtotime($startDate));

        $totalDaysInMonth = (int)$lastDay;

        $query = "
            SELECT
                e.id,
                e.attendance_employee_no AS emp_no,
                e.full_name,
                e.nic,
                c.name AS company_name,
                d.name AS department_name,
                sd.name AS sub_department_name,
                comp.basic_salary,
                comp.monthly_bonus,
                comp.sports_fund_percentage,
                comp.staff_fund_amount,
                c.default_sports_fund_percentage,
                oa.probationary_period,
                oa.date_of_joining,
                e.epf,
                cd.permanent_address AS address,
                cd.mobile_line,
                cd.emg_name,
                cd.emg_relationship,
                cd.emg_tel,
                comp.increment_active,
                CASE WHEN comp.increment_active = 1 THEN comp.increment_value ELSE NULL END AS increment_value,
                CASE WHEN comp.increment_active = 1 THEN comp.increment_effected_date ELSE NULL END AS increment_effected_date,
                comp.ot_morning,
                comp.ot_evening,
                comp.enable_epf_etf,
                comp.br1,
                comp.br2,
                comp.stamp,
                comp.bank_name,
                comp.branch_name,
                comp.bank_account_no,
                COALESCE(SUM(lo.loan_amount), 0) AS total_loan_amount,
                MAX(lo.installment_count) AS installment_count,
                MAX(lo.installment_amount) AS installment_amount,
                MAX(lo.status) AS loan_status,
                MAX(lo.schedule) AS loan_schedule,
                MAX(lo.deduct_from) AS loan_deduct_from,
                MAX(lo.with_interest) AS with_interest,
                MAX(lo.interest_rate_per_annum) AS interest_rate_per_annum,

                -- No Pay Types Split (Approved records only)
                COALESCE(SUM(CASE WHEN npr.type IN ('FULL_DAY', 'PARTIAL_ABSENT') AND DAYNAME(npr.date) != 'Saturday' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS weekday_nopays,
                COALESCE(SUM(CASE WHEN npr.type IN ('FULL_DAY', 'PARTIAL_ABSENT') AND DAYNAME(npr.date) = 'Saturday' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS saturday_nopays,
                COALESCE(SUM(CASE WHEN npr.type = 'EARLY_OUT' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS early_out_nopays,
                COALESCE(SUM(CASE WHEN npr.type = 'LATE_IN' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS major_late_nopays,

                -- Employee-wise allowances (company/month assignments)
                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', a.id, ',\"name\":\"', REPLACE(IFNULL(a.allowance_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ea.custom_amount, a.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(a.allowance_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(a.category, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_allowances ea JOIN allowances a ON a.id = ea.allowance_id
                    WHERE ea.employee_id = e.id AND ea.is_active = 1 AND a.status = 'active'
                      AND LOWER(IFNULL(a.category, '')) NOT IN ('bonus', 'monthly_bonus')
                      AND (ea.month = ? AND ea.year = ?)
                ) AS allowances,

                -- Employee-wise allowance records (dated entries)
                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', ewa.id, ',\"name\":\"', REPLACE(IFNULL(ewa.allowance_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ewa.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(ewa.allowance_code, ''), '\"', '\\\\\"'), '\",\"category\":\"other\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_wise_allowances ewa
                    WHERE ewa.employee_id = e.id AND ewa.status = 'active'
                      AND MONTH(ewa.date) = ? AND YEAR(ewa.date) = ?
                ) AS employee_wise_allowances,

                (
                    SELECT COALESCE(SUM(da.amount), 0)
                    FROM dinner_allowances da
                    WHERE da.employee_id = e.id AND da.status = 'Approved' AND MONTH(da.date) = ? AND YEAR(da.date) = ?
                ) AS total_dinner_allowance,

                -- Employee-wise deductions (assigned per employee per month)
                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', dd.id, ',\"name\":\"', REPLACE(IFNULL(dd.deduction_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ed.custom_amount, dd.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(dd.deduction_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(dd.category, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_deductions ed JOIN deductions dd ON dd.id = ed.deduction_id
                    WHERE ed.employee_id = e.id AND ed.is_active = 1 AND dd.status = 'active'
                      AND (ed.month = ? AND ed.year = ?)
                ) AS deductions,

                -- Employee-wise deduction records (dated entries)
                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', ewd.id, ',\"name\":\"', REPLACE(IFNULL(ewd.deduction_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ewd.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(ewd.deduction_code, ''), '\"', '\\\\\"'), '\",\"category\":\"other\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_wise_deductions ewd
                    WHERE ewd.employee_id = e.id AND ewd.status = 'active'
                      AND MONTH(ewd.date) = ? AND YEAR(ewd.date) = ?
                ) AS employee_wise_deductions,

                -- Employee-wise bonuses (company master + month assignments)
                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', b.id, ',\"name\":\"', REPLACE(IFNULL(b.bonus_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(eb.custom_amount, b.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(b.bonus_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(b.bonus_type, ''), '\"', '\\\\\"'), '\",\"is_annual\":', COALESCE(b.is_annual, 0), ',\"payment_months\":', COALESCE(b.payment_months, '[]'), '}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_bonuses eb JOIN bonuses b ON b.id = eb.bonus_id
                    WHERE eb.employee_id = e.id AND eb.is_active = 1 AND b.status = 'active'
                      AND (
                          b.is_annual = 1
                          OR (COALESCE(b.is_annual, 0) = 0 AND eb.month = ? AND eb.year = ?)
                      )
                ) AS bonuses,

                -- Employee-wise bonus records (dated entries)
                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', ewb.id, ',\"name\":\"', REPLACE(IFNULL(ewb.bonus_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ewb.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(ewb.bonus_code, ''), '\"', '\\\\\"'), '\",\"category\":\"other\",\"is_annual\":', COALESCE(ewb.is_annual, 0), ',\"payment_months\":', COALESCE(ewb.payment_months, '[]'), '}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_wise_bonuses ewb
                    WHERE ewb.employee_id = e.id AND ewb.status = 'active'
                      AND (
                          ewb.is_annual = 1
                          OR (COALESCE(ewb.is_annual, 0) = 0 AND MONTH(ewb.date) = ? AND YEAR(ewb.date) = ?)
                      )
                ) AS employee_wise_bonuses

            FROM employees e
            JOIN organization_assignments oa ON e.organization_assignment_id = oa.id
            JOIN companies c ON oa.company_id = c.id
            LEFT JOIN departments d ON oa.department_id = d.id
            LEFT JOIN sub_departments sd ON oa.sub_department_id = sd.id
            LEFT JOIN compensation comp ON e.id = comp.employee_id
            LEFT JOIN contact_details cd ON e.id = cd.employee_id
            LEFT JOIN loans lo ON e.id = lo.employee_id AND lo.status = 'active'
            LEFT JOIN no_pay_records npr ON e.id = npr.employee_id AND LOWER(npr.status) = 'approved' AND npr.date BETWEEN ? AND ?

            WHERE e.is_active = '1'
        ";

        $params = [
            $month,
            $year, // employee_allowances
            $month,
            $year, // employee_wise_allowances
            $month,
            $year, // Dinner Allowance
            $month,
            $year, // employee_deductions
            $month,
            $year, // employee_wise_deductions
            $month,
            $year, // Non-annual Bonuses (master)
            $month,
            $year, // employee_wise_bonuses (non-annual date match)
            $startDate,
            $endDate, // No Pay Records
        ];

        if ($company_id) {
            $query .= " AND oa.company_id = ? ";
            $params[] = $company_id;
        }
        if ($department_id) {
            $query .= " AND oa.department_id = ? ";
            $params[] = $department_id;
        }
        if ($search) {
            $query .= " AND (e.attendance_employee_no LIKE ? OR e.full_name LIKE ?) ";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $query .= " GROUP BY e.id, e.attendance_employee_no, e.full_name, e.nic, c.name, d.name, sd.name, comp.basic_salary, comp.monthly_bonus, comp.sports_fund_percentage, comp.staff_fund_amount, c.default_sports_fund_percentage, comp.br1, comp.br2, comp.increment_active, comp.increment_value, comp.increment_effected_date, comp.ot_morning, comp.ot_evening, comp.enable_epf_etf, comp.stamp, comp.bank_name, comp.branch_name, comp.bank_account_no, c.id, oa.department_id, oa.probationary_period, oa.date_of_joining, e.epf, cd.permanent_address, cd.mobile_line, cd.emg_name, cd.emg_relationship, cd.emg_tel";

        $results = DB::select($query, $params);
        $data = [];
        $currentMonthInt = (int) $month;

        foreach ($results as $result) {
            $employeeData = (array)$result;

            $employeeData['compensation'] = [
                'bank_name' => $result->bank_name ?? null,
                'branch_name' => $result->branch_name ?? null,
                'bank_account_no' => $result->bank_account_no ?? null,
                'enable_epf_etf' => $result->enable_epf_etf ?? false,
                'monthly_bonus' => (float) ($result->monthly_bonus ?? 0),
                'sports_fund_percentage' => $result->sports_fund_percentage,
                'staff_fund_amount' => (float) ($result->staff_fund_amount ?? 0),
            ];

            $allowancesArr = json_decode($result->allowances ?? '[]', true) ?: [];
            $employeeWiseAllowances = json_decode($result->employee_wise_allowances ?? '[]', true) ?: [];
            $deductionsArr = json_decode($result->deductions ?? '[]', true) ?: [];
            $employeeWiseDeductions = json_decode($result->employee_wise_deductions ?? '[]', true) ?: [];
            $bonusesArr = json_decode($result->bonuses ?? '[]', true) ?: [];
            $employeeWiseBonuses = json_decode($result->employee_wise_bonuses ?? '[]', true) ?: [];

            $allowancesArr = array_merge($allowancesArr, $employeeWiseAllowances);
            $deductionsArr = array_merge($deductionsArr, $employeeWiseDeductions);
            $bonusesArr = array_merge($bonusesArr, $employeeWiseBonuses);

            // Annual bonuses: only include when current month is a payment month
            $bonusesArr = array_values(array_filter($bonusesArr, function ($bonus) use ($currentMonthInt) {
                if (empty($bonus['is_annual'])) {
                    return true;
                }
                $paymentMonths = $bonus['payment_months'] ?? [];
                if (is_string($paymentMonths)) {
                    $paymentMonths = json_decode($paymentMonths, true) ?: [];
                }
                return in_array($currentMonthInt, array_map('intval', $paymentMonths), true);
            }));

            $dinnerAllowanceValue = (float)($employeeData['total_dinner_allowance'] ?? 0);
            if ($dinnerAllowanceValue > 0) {
                $allowancesArr[] = [
                    'id' => 'dinner_allowance',
                    'name' => 'Dinner Allowance',
                    'amount' => $dinnerAllowanceValue,
                    'is_custom' => 1,
                    'code' => 'DINNER_ALW',
                    'category' => 'dinner_allowance'
                ];
            }

            $stampValue = ((int)($result->stamp ?? 0) === 1) ? 25 : 0;
            $employeeData['stamp'] = $stampValue;

            $basicSalary = (float)($employeeData['basic_salary'] ?? 0);
            $brAllowance = 0;

            if ((int)$result->br1 === 1 && (int)$result->br2 === 1) {
                $brAllowance = 3500;
            } elseif ((int)$result->br1 === 1) {
                $brAllowance = 1000;
            } elseif ((int)$result->br2 === 1) {
                $brAllowance = 2500;
            }

            $basicSalary += $brAllowance;

            if (!empty($employeeData['increment_active']) && !empty($employeeData['increment_effected_date']) && strtotime($employeeData['increment_effected_date']) <= strtotime($endDate)) {
                $basicSalary += (float)($employeeData['increment_value'] ?? 0);
            }

            // LOAN CALCULATION
            $installmentAmount = 0.0;
            $loanInterest = 0.0;
            $loanPrincipal = 0.0;
            $loanDeductFrom = $employeeData['loan_deduct_from'] ?? 'bonus';
            $loanStatus = strtolower((string)($employeeData['loan_status'] ?? ''));

            if ($loanStatus === 'active') {
                $schedule = $employeeData['loan_schedule'] ?? null;
                if (is_string($schedule)) {
                    $schedule = is_array(json_decode($schedule, true)) ? json_decode($schedule, true) : null;
                }

                if (is_array($schedule) && count($schedule) > 0) {
                    foreach ($schedule as $r) {
                        $due = $r['due_date'] ?? $r['dueDate'] ?? null;
                        if ($due && date('Y-m', strtotime($due)) === $selectedMonthYear) {
                            $installmentAmount = (float)($r['installment_amount'] ?? $r['installmentAmount'] ?? 0);
                            break;
                        }
                    }
                } else {
                    $installmentAmount = ((int)($employeeData['installment_count'] ?? 0) > 0) ? (float)($employeeData['installment_amount'] ?? 0) : 0.0;
                }

                if ($employeeData['with_interest'] && $employeeData['interest_rate_per_annum'] > 0) {
                    $loanAmountTotal = (float)($employeeData['total_loan_amount'] ?? 0);
                    $loanInterest = ($loanAmountTotal * ((float)$employeeData['interest_rate_per_annum'] / 100)) / 12;
                    if ($loanInterest > $installmentAmount) {
                        $loanInterest = $installmentAmount;
                    }
                }
                $loanPrincipal = $installmentAmount - $loanInterest;
            }

            // Working Days & No Pay Deductions
            $companyLeavesCount = DB::table('leave_calendars')->where('company_id', $company_id ?? 0)
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('start_date', [$startDate, $endDate])->orWhereBetween('end_date', [$startDate, $endDate]);
                })->count();

            $workingDaysInMonth = max(1, $totalDaysInMonth - $companyLeavesCount);
            $perDaySalary = $basicSalary / $workingDaysInMonth;

            // Full Day, Partial Absent, Early Out, Major Late Deductions
            $weekdayNoPays = (float)($employeeData['weekday_nopays'] ?? 0);
            $saturdayNoPays = (float)($employeeData['saturday_nopays'] ?? 0);
            $earlyOutNoPays = (float)($employeeData['early_out_nopays'] ?? 0);
            $majorLateNoPays = (float)($employeeData['major_late_nopays'] ?? 0);

            $fullDayNoPayDeduction = round($weekdayNoPays * $perDaySalary, 2);
            $saturdayNoPayBonusDeduction = round($saturdayNoPays * $perDaySalary, 2);

            $earlyOutNoPayDeduction = round($earlyOutNoPays * $perDaySalary, 2);
            $majorLateDeduction = round($majorLateNoPays * $perDaySalary, 2);

            // Probation Deduction
            $probationDeduction = 0.0;
            if ($employeeData['probationary_period']) {
                $probationLeaves = \App\Models\leave_master::where('employee_id', $employeeData['id'])
                    ->whereRaw('LOWER(status) = ?', ['approved'])
                    ->whereBetween('leave_date', [$startDate, $endDate])
                    ->get();

                $totalProbationOverLimit = 0;
                foreach ($probationLeaves as $pl) {
                    $totalProbationOverLimit += (float)($pl->over_limit ?? 0);
                }

                $probationDeduction = round($totalProbationOverLimit * $perDaySalary, 2);
            }

            // Minor Late Deductions (<= 30 mins)
            $minorLateData = $this->calculateLateDeductionData((int)$employeeData['id'], $startDate, $endDate, $perDaySalary);
            $shortLeaveDeduction = $minorLateData['short_leave_deduction'] ?? 0;
            $halfDayDeduction = $minorLateData['half_day_deduction'] ?? 0;

            // KPI
            $kpiAllowance = 0.0;
            $kpiBonusAllowance = 0.0;
            if ($kpiType === 'monthly') {
                $percentage = DB::table('performance_evaluations')->where('employee_id', $employeeData['id'])->whereBetween('start_date', [$startDate, $endDate])->avg('percentage');
                if (!is_null($percentage)) {
                    $kpiAllowance = round(($basicSalary * ((float)$percentage)) / 100.0, 2);
                    $allowancesArr[] = ['name' => 'KPI Allowance', 'amount' => $kpiAllowance, 'category' => 'kpi'];
                }
            } elseif ($kpiType === '6month') {
                $sixMonthStart = \Carbon\Carbon::parse($startDate)->subMonths(5)->startOfMonth()->toDateString();
                $percentage = DB::table('performance_appraisals')->where('employee_id', $employeeData['id'])->where('start_date', '>=', $sixMonthStart)->where('end_date', '<=', $endDate)->avg('percentage');
                if (!is_null($percentage)) {
                    $kpiBonusAllowance = round(($basicSalary * ((float)$percentage)) / 100.0, 2);
                    $bonusesArr[] = ['name' => 'KPI Bonus (6M)', 'amount' => $kpiBonusAllowance, 'category' => 'kpi_bonus'];
                }
            }

            // Monthly bonus is part of salary split — from compensation only (set at employee creation)
            $compMonthlyBonus = (float) ($employeeData['monthly_bonus'] ?? 0);
            $monthlyBonusTotal = $compMonthlyBonus;

            if ($compMonthlyBonus > 0) {
                $bonusesArr[] = [
                    'id' => 'comp_monthly_bonus',
                    'name' => 'Monthly Bonus',
                    'amount' => $compMonthlyBonus,
                    'category' => 'monthly_bonus',
                ];
            }

            $employeeData['allowances'] = $allowancesArr;
            $employeeData['deductions'] = $deductionsArr;
            $employeeData['bonuses'] = $bonusesArr;

            $totalAllowances = array_reduce($allowancesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);
            $totalBonuses = array_reduce($bonusesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

            $epfEtfDeductions = 0.0;
            $bonusFixedDeductions = 0.0;
            foreach ($deductionsArr as $deduction) {
                $cat = strtoupper($deduction['category'] ?? '');
                $amount = (float) ($deduction['amount'] ?? 0);
                if (in_array($cat, ['EPF', 'ETF'], true)) {
                    $epfEtfDeductions += $amount;
                } else {
                    $bonusFixedDeductions += $amount;
                }
            }

            // EPF/ETF calculated from basic salary only
            $epfEtfBase = $basicSalary;
            $epfEmployeeDeduction = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.08) : 0;

            // Sports fund: percentage of (basic + monthly bonus)
            $sportsFundPct = $employeeData['sports_fund_percentage'] ?? $employeeData['default_sports_fund_percentage'] ?? 0;
            $sportsFundDeduction = round(($basicSalary + $monthlyBonusTotal) * ((float) $sportsFundPct / 100), 2);

            // Staff fund: fixed amount from monthly bonus
            $staffFundDeduction = round((float) ($employeeData['staff_fund_amount'] ?? 0), 2);

            // Overtime
            $otRows = \App\Models\over_time::with('timeCard:id,date,time,actual_date')->where('employee_id', $employeeData['id'])->whereRaw('LOWER(status) = ?', ['approved'])->whereHas('timeCard', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('date', [$startDate, $endDate]);
            })->get();
            $morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1) ? ((float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount')) : 0.0;
            $night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1) ? ((float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount')) : 0.0;
            $holiday_ot_fees = (float)$otRows->sum('holiday_ot_amount');

            // --- DEDUCTION SPLIT ---
            $basicGross = $basicSalary + $totalAllowances;
            $bonusGross = $totalBonuses;

            $basicDeductionsTotal = $epfEmployeeDeduction + $epfEtfDeductions + $fullDayNoPayDeduction + $probationDeduction;
            $bonusDeductionsTotal = $saturdayNoPayBonusDeduction + $earlyOutNoPayDeduction + $shortLeaveDeduction + $halfDayDeduction + $majorLateDeduction + $bonusFixedDeductions + $sportsFundDeduction + $staffFundDeduction;

            if ($loanDeductFrom === 'basic') {
                $basicDeductionsTotal += $loanPrincipal;
            } else {
                $bonusDeductionsTotal += $loanPrincipal;
            }

            $bonusDeductionsTotal += $loanInterest;

            // Totals
            $grossSalary = $basicGross + $bonusGross + $morning_ot_fees + $night_ot_fees + $holiday_ot_fees;
            $totalDeductions = $basicDeductionsTotal + $bonusDeductionsTotal + $stampValue;
            $netSalary = $grossSalary - $totalDeductions;

            $employeeData['salary_breakdown'] = [
                'basic_salary' => round($basicSalary, 2),
                'monthly_bonus' => round($monthlyBonusTotal, 2),
                'per_day_salary' => round($perDaySalary, 3),
                'ot_morning_fees' => round($morning_ot_fees, 2),
                'ot_night_fees' => round($night_ot_fees, 2),
                'holiday_ot_fees' => round($holiday_ot_fees, 2),
                'ot_morning_hours' => round((float)$otRows->sum('morning_ot') + (float)$otRows->sum('morning_ot_special'), 2),
                'ot_night_hours' => round((float)$otRows->sum('afternoon_ot') + (float)$otRows->sum('evening_ot_special'), 2),
                'holiday_ot_hours' => round((float)$otRows->sum('holiday_ot_hours'), 2),

                'full_day_nopay_deduction' => round($fullDayNoPayDeduction, 2),
                'saturday_nopay_deduction' => round($saturdayNoPayBonusDeduction, 2),
                'early_out_nopay_deduction' => round($earlyOutNoPayDeduction, 2),
                'short_leave_deduction' => round($shortLeaveDeduction, 2),
                'half_day_deduction' => round($halfDayDeduction, 2),
                'major_late_deduction' => round($majorLateDeduction, 2),
                'epf_employee_deduction' => round($epfEmployeeDeduction, 2),
                'epf_etf_fixed_deductions' => round($epfEtfDeductions, 2),
                'sports_fund_deduction' => $sportsFundDeduction,
                'staff_fund_deduction' => $staffFundDeduction,
                'probation_deduction' => round($probationDeduction, 2),
                'stamp_duty' => $stampValue,

                'loan_principal' => round($loanPrincipal, 2),
                'loan_interest' => round($loanInterest, 2),
                'loan_deduct_from' => $loanDeductFrom,

                'total_fixed_deductions' => round($bonusFixedDeductions + $epfEtfDeductions, 2),

                'net_salary' => round($netSalary, 2),
                'gross_salary' => round($grossSalary, 2),
                'total_deductions' => round($totalDeductions, 2),
            ];

            // Payload cleanup: Remove intermediate database layer attributes
            unset(
                $employeeData['bank_name'],
                $employeeData['branch_name'],
                $employeeData['bank_account_no'],
                $employeeData['total_dinner_allowance'],
                $employeeData['br1'],
                $employeeData['br2'],
                $employeeData['increment_active'],
                $employeeData['increment_value'],
                $employeeData['increment_effected_date'],
                $employeeData['ot_morning'],
                $employeeData['ot_evening'],
                $employeeData['enable_epf_etf'],
                $employeeData['total_loan_amount'],
                $employeeData['installment_count'],
                $employeeData['installment_amount'],
                $employeeData['loan_status'],
                $employeeData['loan_schedule'],
                $employeeData['loan_deduct_from'],
                $employeeData['with_interest'],
                $employeeData['interest_rate_per_annum'],
                $employeeData['weekday_nopays'],
                $employeeData['saturday_nopays'],
                $employeeData['early_out_nopays'],
                $employeeData['major_late_nopays']
            );

            $data[] = $employeeData;
        }

        return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }

    //


    public function storeSalaryData(Request $request)
    {
        $validated = $request->validate([
            'data' => 'required|array',
            'data.*.emp_no' => 'required|string',
            'data.*.full_name' => 'required|string',
            'month' => 'sometimes|integer|between:1,12',
            'year' => 'sometimes|integer',
        ]);

        try {
            $month = $request->month ?? ($request->data[0]['month'] ?? date('m'));
            $year = $request->year ?? date('Y');
            $duplicateEntries = [];

            foreach ($request->data as $employeeData) {
                $existingRecord = salary_process::where('employee_no', $employeeData['emp_no'])
                    ->where('month', $month)
                    ->where('year', $year)
                    ->first();

                if ($existingRecord) {
                    $duplicateEntries[] = $employeeData['emp_no'];
                    continue;
                }

                salary_process::create([
                    'employee_id' => $employeeData['id'],
                    'employee_no' => $employeeData['emp_no'],
                    'full_name' => $employeeData['full_name'],
                    'company_name' => $employeeData['company_name'],
                    'department_name' => $employeeData['department_name'],
                    'sub_department_name' => $employeeData['sub_department_name'] ?? null,
                    'basic_salary' => $employeeData['basic_salary'],
                    'increment_active' => $employeeData['increment_active'] ?? false,
                    'increment_value' => $employeeData['increment_value'] ?? null,
                    'increment_effected_date' => $employeeData['increment_effected_date'] ?? null,
                    'ot_morning' => $employeeData['ot_morning'] ?? 0,
                    'ot_evening' => $employeeData['ot_evening'] ?? 0,
                    'enable_epf_etf' => $employeeData['enable_epf_etf'] ?? false,
                    'br1' => $employeeData['br1'] ?? false,
                    'br2' => $employeeData['br2'] ?? false,
                    'br_status' => $employeeData['br_status'] ?? '',
                    'total_loan_amount' => $employeeData['total_loan_amount'] ?? 0,
                    'installment_count' => $employeeData['installment_count'] ?? null,
                    'installment_amount' => $employeeData['installment_amount'] ?? null,
                    'approved_no_pay_days' => $employeeData['approved_no_pay_days'] ?? 0,
                    'allowances' => json_encode($employeeData['allowances'] ?? []),
                    'deductions' => json_encode($employeeData['deductions'] ?? []),
                    'bonuses' => json_encode($employeeData['bonuses'] ?? []),
                    'salary_breakdown' => json_encode($employeeData['salary_breakdown'] ?? []),
                    'month' => $month,
                    'year' => $year,
                    'status' => 'processed',
                ]);
            }

            return response()->json(['message' => 'Salary data saved successfully'], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error saving salary data: ' . $e->getMessage()], 500);
        }
    }

    public function downloadSalaryCSV(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');

        $salaries = salary_process::where('month', $month)
            ->where('year', $year)
            ->get();

        $filename = "salary_process_{$month}_{$year}.csv";

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($salaries) {
            $file = fopen('php://output', 'w');

            fputcsv($file, ['Employee No', 'Full Name', 'Company', 'Department', 'Basic Salary', 'Net Salary', 'Status', 'Month', 'Year']);

            foreach ($salaries as $salary) {
                fputcsv($file, [
                    $salary->employee_no,
                    $salary->full_name,
                    $salary->company_name,
                    $salary->department_name,
                    $salary->basic_salary,
                    data_get(json_decode($salary->salary_breakdown, true), 'net_salary', 0),
                    $salary->status,
                    $salary->month,
                    $salary->year,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }


    public function updateSlaryStatus(Request $request)
    {
        DB::beginTransaction();

        try {
            // 1. Processed තත්ත්වයට පත් කිරීම
            if ($request->has('status') && $request->status == 'processed') {
                $salaryData = salary_process::where('status', 'pending')
                    ->orWhere('status', 'Unprocessed')
                    ->update([
                        'status' => 'processed',
                    ]);
                DB::commit();
                return response()->json(['message' => 'Salary status updated to processed', 'data' => $salaryData], 200);
            }

            // 2. Issued තත්ත්වයට පත් කිරීම සහ Loan Installments අඩු කිරීම
            if ($request->has('status') && $request->status == 'issued') {
                $salaryProcesses = salary_process::where('status', 'processed')->get();

                foreach ($salaryProcesses as $process) {
                    $installmentCount = $process->installment_count;

                    if ($installmentCount !== null) {
                        $loan = loans::where('employee_id', $process->employee_id)
                            ->where('status', 'active')
                            ->first();

                        if ($loan) {
                            $prevCount = (int)($loan->installment_count ?? 0);
                            $newInstallmentCount = max(0, $prevCount - 1);

                            $loan->installment_count = $newInstallmentCount;
                            $loan->status = $newInstallmentCount == 0 ? 'completed' : 'active';
                            $loan->save();

                            // if the loan end put the Loan Completed Loans
                            if ($newInstallmentCount == 0) {
                                DB::table('completed_loans')->insert([
                                    'employee_id' => $loan->employee_id,
                                    'loan_id' => $loan->id,
                                    'loan_amount' => $loan->loan_amount,
                                    'interest_rate_per_annum' => $loan->interest_rate_per_annum,
                                    'with_interest' => $loan->with_interest,
                                    'installment_count' => $prevCount,
                                    'end_date' => now()->toDateString(),
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }
                }

                // all  Processed are Issued
                salary_process::where('status', 'processed')->update([
                    'status' => 'issued',
                ]);

                DB::commit();
                return response()->json(['message' => 'Salaries marked as issued and loan installments updated'], 200);
            }

            DB::rollBack();
            return response()->json(['message' => 'Invalid status'], 400);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating status: ' . $e->getMessage()], 500);
        }
    }
}
