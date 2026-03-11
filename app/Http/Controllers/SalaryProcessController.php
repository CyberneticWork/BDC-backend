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
use App\Models\roster;
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

        // Grace period nathiwa hariyatama late eka gannawa
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

        if (
            !$roster ||
            !$roster->shift ||
            !$roster->shift->start_time ||
            !$roster->shift->end_time
        ) {
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

    private function calculateLateDeductionData(
        int $employeeId,
        string $startDate,
        string $endDate,
        float $perDaySalary
    ): array {
        $records = time_card::select(
                'employee_id',
                'date',
                DB::raw('MIN(CASE WHEN status IN ("IN", "Late Coming") THEN time END) as in_time'),
                DB::raw('
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            CASE
                                WHEN status IN ("IN", "Late Coming")
                                THEN status
                                ELSE NULL
                            END
                            ORDER BY time ASC SEPARATOR ","
                        ),
                        ",",
                        1
                    ) as first_in_status
                ')
            )
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereIn('status', ['IN', 'Late Coming', 'OUT', 'Early OUT'])
            ->whereNull('deleted_at')
            ->groupBy('employee_id', 'date')
            ->orderBy('date', 'asc')
            ->get();

        $lateCounter = 0;
        $minorLateCounter = 0; // Winadi 30ta adu ewa ganinna
        $forgivenMinorLatesAmount = 0; // Mul dawas 3e amount eka ekathu karanna

        $approvedLeaveLateCount = 0;
        $noDeductionLateCount = 0;
        $shortLeaveCount = 0;
        $halfDayCount = 0;
        $deductibleLateCount = 0;
        $totalLateMinutes = 0;
        $totalLateDeduction = 0;
        $lateDates = [];

        foreach ($records as $record) {
            $isLate = ($record->first_in_status ?? null) === 'Late Coming';

            if (!$isLate) {
                continue;
            }

            $shiftStartTime = $this->getRosterShiftStartTime($employeeId, $record->date);

            if (!$shiftStartTime) {
                $lateDates[] = [
                    'date' => $record->date,
                    'in_time' => $record->in_time,
                    'shift_start_time' => null,
                    'late_minutes' => 0,
                    'late_hours' => 0,
                    'policy_action' => 'No Roster Shift Found',
                    'has_approved_leave' => false,
                    'is_half_day_leave' => false,
                    'leave_type' => null,
                    'leave_status' => null,
                    'leave_period' => null,
                    'hourly_rate' => 0,
                    'salary_deduction' => 0,
                ];
                continue;
            }

            $lateMinutes = $this->getLateMinutes($record->in_time, $shiftStartTime);

            if ($lateMinutes <= 0) {
                continue;
            }

            $leaveInfo = $this->getApprovedLeaveInfoForDate($employeeId, $record->date);

            if ($leaveInfo['has_approved_leave']) {
                $approvedLeaveLateCount++;

                $lateDates[] = [
                    'date' => $record->date,
                    'in_time' => $record->in_time,
                    'shift_start_time' => $shiftStartTime,
                    'late_minutes' => $lateMinutes,
                    'late_hours' => round($lateMinutes / 60, 2),
                    'policy_action' => 'Leave Approved - No Deduction',
                    'has_approved_leave' => true,
                    'is_half_day_leave' => $leaveInfo['is_half_day_leave'],
                    'leave_type' => $leaveInfo['leave_type'],
                    'leave_status' => $leaveInfo['leave_status'],
                    'leave_period' => $leaveInfo['leave_period'],
                    'hourly_rate' => 0,
                    'salary_deduction' => 0,
                ];

                continue;
            }

            $lateCounter++;
            $totalLateMinutes += $lateMinutes;

            $shiftHours = $this->getRosterShiftWorkHours($employeeId, $record->date, 8);
            $hourlyRate = $shiftHours > 0 ? ($perDaySalary / $shiftHours) : 0;
            $lateHours = $lateMinutes / 60;
            $actualLateDeduction = round($hourlyRate * $lateHours, 2);

            $policyAction = 'No Deduction';
            $salaryDeduction = 0;

            // Aluth Late Calculation Logic eka
            if ($lateMinutes <= 30) {
                $minorLateCounter++;

                if ($minorLateCounter <= 3) {
                    $policyAction = 'Minor Late (Free)';
                    $noDeductionLateCount++;
                    $salaryDeduction = 0;
                    $forgivenMinorLatesAmount += $actualLateDeduction;
                } elseif ($minorLateCounter <= 5) {
                    $policyAction = 'Minor Late (Deducted)';
                    $shortLeaveCount++;
                    $deductibleLateCount++;
                    $salaryDeduction = $actualLateDeduction;
                } elseif ($minorLateCounter == 6) {
                    $policyAction = '6th Minor Late (All 6 Deducted)';
                    $halfDayCount++;
                    $deductibleLateCount++;
                    $salaryDeduction = $actualLateDeduction + $forgivenMinorLatesAmount;
                } else {
                    $policyAction = 'Minor Late (Deducted)';
                    $halfDayCount++;
                    $deductibleLateCount++;
                    $salaryDeduction = $actualLateDeduction;
                }
            } else {
                // Winadi 30 ta wada wadi ewata (Direct Deduct)
                $policyAction = 'Major Late (Direct Deduction)';
                $deductibleLateCount++;
                $salaryDeduction = $actualLateDeduction;
            }

            $totalLateDeduction += $salaryDeduction;

            $lateDates[] = [
                'date' => $record->date,
                'in_time' => $record->in_time,
                'shift_start_time' => $shiftStartTime,
                'late_minutes' => $lateMinutes,
                'late_hours' => round($lateHours, 2),
                'policy_action' => $policyAction,
                'has_approved_leave' => false,
                'is_half_day_leave' => false,
                'leave_type' => null,
                'leave_status' => null,
                'leave_period' => null,
                'hourly_rate' => round($hourlyRate, 2),
                'salary_deduction' => round($salaryDeduction, 2),
            ];
        }

        return [
            'late_dates' => $lateDates,
            'late_count_for_policy' => $lateCounter,
            'approved_leave_late_count' => $approvedLeaveLateCount,
            'no_deduction_late_count' => $noDeductionLateCount,
            'short_leave_count' => $shortLeaveCount,
            'half_day_count' => $halfDayCount,
            'deductible_late_count' => $deductibleLateCount,
            'total_late_minutes' => $totalLateMinutes,
            'total_late_hours' => round($totalLateMinutes / 60, 2),
            'late_deduction_amount' => round($totalLateDeduction, 2),
        ];
    }

    public function index()
    {
        $salaryData = salary_process::with(['employee', 'noPayRecord', 'overTime', 'allowance', 'loan', 'deduction'])
            ->orderBy('process_date', 'desc')
            ->get();

        return response()->json($salaryData);
    }

    public function store(Request $request)
    {
        $salaryData = salary_process::create([
            'employee_id' => $request->employee_id,
            'process_date' => $request->process_date,
            'basic' => $request->basic,
            'basic_salary' => $request->basic_salary,
            'no_pay_records_id' => $request->no_pay_records_id,
            'over_times_id' => $request->over_times_id,
            'allowances_id' => $request->allowances_id,
            'loans_id' => $request->loans_id,
            'deductions_id' => $request->deductions_id,
            'gross_amount' => $request->gross_amount,
            'salary_advance' => $request->salary_advance,
            'net_salary' => $request->net_salary,
            'status' => 'Pending',
            'processed_by' => Auth::id(),
        ]);

        return response()->json($salaryData, 201);
    }

    public function updateSlaryStatus(Request $request)
    {
        DB::beginTransaction();

        try {
            if ($request->has('status') && $request->status == 'processed') {
                $salaryData = salary_process::where('status', 'pending')->update([
                    'status' => 'processed',
                ]);
                DB::commit();
                return response()->json($salaryData, 200);
            }

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

    public function show(string $id) {}
    public function update(Request $request, string $id) {}
    public function destroy(string $id) {}

    public function getEmployeesByMonthAndCompany(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $company_id = $request->query('company_id');
        $department_id = $request->query('department_id');

        $kpiTypeRaw = strtolower((string)$request->query('kpi_type', ''));
        $kpiType = in_array($kpiTypeRaw, ['monthly', '6month', 'six_month']) ? $kpiTypeRaw : '';

        $startDate = "{$year}-{$month}-01";
        $lastDay = date('t', strtotime($startDate));
        $endDate = "{$year}-{$month}-{$lastDay}";
        $selectedMonthYear = date('Y-m', strtotime($startDate));

        $totalDaysInMonth = (int)$lastDay;

        $companyLeaves = DB::table('leave_calendars')
            ->where('company_id', $company_id)
            ->where(function ($query) use ($startDate, $endDate) {
                $query->where(function ($q) use ($startDate, $endDate) {
                    $q->where('start_date', '<=', $endDate)
                        ->where('end_date', '>=', $startDate);
                })->orWhereBetween('start_date', [$startDate, $endDate]);
            })
            ->whereNull('deleted_at')
            ->get();

        $leaveDaysCount = 0;
        foreach ($companyLeaves as $leave) {
            $leaveStart = max($startDate, $leave->start_date);
            $leaveEnd = $leave->end_date ? min($endDate, $leave->end_date) : $leaveStart;
            $leaveDaysCount += date_diff(date_create($leaveStart), date_create($leaveEnd))->days + 1;
        }

        $workingDaysInMonth = max(1, $totalDaysInMonth - $leaveDaysCount);

        $query = "
            SELECT
                e.id,
                e.attendance_employee_no AS emp_no,
                e.full_name,
                c.name AS company_name,
                d.name AS department_name,
                sd.name AS sub_department_name,
                comp.basic_salary,
                oa.probationary_period,

                comp.increment_active,
                CASE WHEN comp.increment_active = 1 THEN comp.increment_value ELSE NULL END AS increment_value,
                CASE WHEN comp.increment_active = 1 THEN comp.increment_effected_date ELSE NULL END AS increment_effected_date,

                comp.ot_morning,
                comp.ot_evening,
                comp.enable_epf_etf,
                comp.br1,
                comp.br2,
                comp.ot_morning_rate,
                comp.ot_night_rate,
                comp.stamp,

                CASE
                    WHEN comp.br1 = 1 AND comp.br2 = 1 THEN 'Both BR1 and BR2'
                    WHEN comp.br1 = 1 THEN 'BR1 Only'
                    WHEN comp.br2 = 1 THEN 'BR2 Only'
                    ELSE 'None'
                END AS br_status,

                COALESCE(SUM(lo.loan_amount), 0) AS total_loan_amount,
                MAX(lo.installment_count) AS installment_count,
                MAX(lo.installment_amount) AS installment_amount,
                MAX(lo.status) AS loan_status,
                MAX(lo.schedule) AS loan_schedule,
                MAX(lo.start_from) AS loan_start_from,

                COALESCE(SUM(COALESCE(npr.no_pay_count, 0)), 0) AS approved_no_pay_days,

                (
                    SELECT CONCAT('[',
                        GROUP_CONCAT(
                            CONCAT(
                                '{\"id\":', a.id,
                                ',\"name\":\"', a.allowance_name,
                                '\",\"amount\":', COALESCE(ea.custom_amount, a.amount),
                                ',\"is_custom\":', CASE WHEN ea.id IS NOT NULL THEN 1 ELSE 0 END,
                                ',\"code\":\"', a.allowance_code,
                                '\",\"category\":\"', a.category, '\"}'
                            )
                        ),
                    ']')
                    FROM allowances a
                    LEFT JOIN employee_allowances ea ON a.id = ea.allowance_id AND ea.employee_id = e.id
                    WHERE a.company_id = c.id
                      AND (a.department_id IS NULL OR a.department_id = oa.department_id)
                      AND a.status = 'active'
                ) AS allowances,

                (
                    SELECT CONCAT('[',
                        GROUP_CONCAT(
                            CONCAT(
                                '{\"id\":', dd.id,
                                ',\"name\":\"', dd.deduction_name,
                                '\",\"amount\":', COALESCE(ed.custom_amount, dd.amount),
                                ',\"is_custom\":', CASE WHEN ed.id IS NOT NULL THEN 1 ELSE 0 END,
                                ',\"code\":\"', dd.deduction_code,
                                '\",\"category\":\"', dd.category, '\"}'
                            )
                        ),
                    ']')
                    FROM deductions dd
                    LEFT JOIN employee_deductions ed ON dd.id = ed.deduction_id AND ed.employee_id = e.id
                    WHERE dd.company_id = c.id
                      AND (dd.department_id IS NULL OR dd.department_id = oa.department_id)
                      AND dd.status = 'active'
                ) AS deductions,

                (
                    SELECT CONCAT('[',
                        GROUP_CONCAT(
                            CONCAT(
                                '{\"id\":', b.id,
                                ',\"name\":\"', b.bonus_name,
                                '\",\"amount\":', COALESCE(eb.custom_amount, b.amount),
                                ',\"is_custom\":', CASE WHEN eb.id IS NOT NULL THEN 1 ELSE 0 END,
                                ',\"code\":\"', COALESCE(b.bonus_code, ''), '\"}'
                            )
                        ),
                    ']')
                    FROM bonuses b
                    LEFT JOIN employee_bonuses eb ON b.id = eb.bonus_id AND eb.employee_id = e.id
                    WHERE b.company_id = c.id
                      AND (b.department_id IS NULL OR b.department_id = oa.department_id)
                      AND b.status = 'active'
                ) AS bonuses

            FROM employees e
            JOIN organization_assignments oa ON e.organization_assignment_id = oa.id
            JOIN companies c ON oa.company_id = c.id
            LEFT JOIN departments d ON oa.department_id = d.id
            LEFT JOIN sub_departments sd ON oa.sub_department_id = sd.id
            LEFT JOIN compensation comp ON e.id = comp.employee_id
            LEFT JOIN loans lo ON e.id = lo.employee_id AND lo.status = 'active'
            LEFT JOIN no_pay_records npr ON e.id = npr.employee_id
                AND LOWER(npr.status) = 'approved'
                AND npr.date BETWEEN ? AND ?

            WHERE oa.company_id = ?
        ";

        if ($department_id) {
            $query .= " AND oa.department_id = ? ";
        }

        $query .= "
            AND EXISTS (
                SELECT 1 FROM rosters r
                WHERE r.employee_id = e.id
                AND (
                    (r.date_from <= ? AND r.date_to >= ?) OR
                    (r.date_from BETWEEN ? AND ?) OR
                    (r.date_to BETWEEN ? AND ?) OR
                    (r.date_from IS NULL AND r.date_to IS NULL)
                )
            )
            GROUP BY
                e.id, e.attendance_employee_no, e.full_name,
                c.name, d.name, sd.name,
                comp.basic_salary, comp.br1, comp.br2,
                comp.increment_active, comp.increment_value,
                comp.increment_effected_date, comp.ot_morning,
                comp.ot_evening, comp.ot_morning_rate,
                comp.ot_night_rate, comp.enable_epf_etf,
                comp.stamp,
                c.id, oa.department_id,
                oa.probationary_period
        ";

        $params = [$startDate, $endDate, $company_id];
        if ($department_id) {
            $params[] = $department_id;
        }
        $params = array_merge($params, [$endDate, $startDate, $startDate, $endDate, $startDate, $endDate]);

        $results = DB::select($query, $params);

        $data = [];

        foreach ($results as $result) {
            $employeeData = (array)$result;

            $allowancesArr = json_decode($result->allowances ?? '[]', true) ?: [];
            $deductionsArr = json_decode($result->deductions ?? '[]', true) ?: [];
            $bonusesArr = json_decode($result->bonuses ?? '[]', true) ?: [];

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

            $approvedNoPayDays = (float)($employeeData['approved_no_pay_days'] ?? 0);

            $installmentAmount = 0.0;
            $loanStatus = strtolower((string)($employeeData['loan_status'] ?? ''));

            if ($loanStatus === 'active') {
                $schedule = $employeeData['loan_schedule'] ?? null;

                if (is_string($schedule)) {
                    $decoded = json_decode($schedule, true);
                    $schedule = is_array($decoded) ? $decoded : null;
                }

                if (is_array($schedule) && count($schedule) > 0) {
                    foreach ($schedule as $r) {
                        $due = $r['due_date'] ?? $r['dueDate'] ?? null;
                        if (!$due) {
                            continue;
                        }

                        if (date('Y-m', strtotime($due)) === $selectedMonthYear) {
                            $installmentAmount = (float)($r['installment_amount'] ?? $r['installmentAmount'] ?? 0);
                            break;
                        }
                    }

                    if ($installmentAmount <= 0) {
                        $installmentAmount = 0.0;
                    }
                } else {
                    $count = (int)($employeeData['installment_count'] ?? 0);
                    $installmentAmount = ($count > 0) ? (float)($employeeData['installment_amount'] ?? 0) : 0.0;
                }
            }

            $employeeData['installment_amount'] = $installmentAmount;

            if (
                !empty($employeeData['increment_active']) &&
                !empty($employeeData['increment_effected_date']) &&
                strtotime($employeeData['increment_effected_date']) <= strtotime($endDate)
            ) {
                $basicSalary += (float)($employeeData['increment_value'] ?? 0);
            }

            $employeeData['probationary_period'] = (bool)($result->probationary_period ?? false);
            $probationOverLimitDays = 0.0;

            if ($employeeData['probationary_period']) {
                $probationOverLimitDays = (float)(leave_master::where('employee_id', $employeeData['id'])
                    ->whereRaw('LOWER(status) = ?', ['approved'])
                    ->where(function ($q) use ($startDate, $endDate) {
                        $q->whereBetween('leave_date', [$startDate, $endDate])
                            ->orWhere(function ($q2) use ($startDate, $endDate) {
                                $q2->where('leave_from', '<=', $endDate)
                                   ->where('leave_to', '>=', $startDate);
                            });
                    })
                    ->sum('over_limit') ?? 0);
            }

            $perDaySalary = $basicSalary / $workingDaysInMonth;

            $noPayDeduction = round($approvedNoPayDays * $perDaySalary, 2);
            $probationDeduction = round($probationOverLimitDays * $perDaySalary, 2);

            $lateData = $this->calculateLateDeductionData(
                (int)$employeeData['id'],
                $startDate,
                $endDate,
                $perDaySalary
            );

            $lateDeductionAmount = round((float)($lateData['late_deduction_amount'] ?? 0), 2);

            $adjustedBasic = round($basicSalary - $noPayDeduction - $probationDeduction, 2);
            if ($adjustedBasic < 0) {
                $adjustedBasic = 0;
            }

            if ($noPayDeduction > 0) {
                $deductionsArr[] = [
                    'id' => null,
                    'name' => 'No Pay Deduction',
                    'amount' => $noPayDeduction,
                    'is_custom' => 1,
                    'code' => 'NOPAY',
                    'category' => 'nopay',
                ];
            }

            if ($probationDeduction > 0) {
                $deductionsArr[] = [
                    'id' => null,
                    'name' => 'Probation Over-limit Deduction',
                    'amount' => $probationDeduction,
                    'is_custom' => 1,
                    'code' => 'PROB',
                    'category' => 'probation',
                ];
            }

            if ($lateDeductionAmount > 0) {
                $deductionsArr[] = [
                    'id' => null,
                    'name' => 'Late Attendance Deduction',
                    'amount' => $lateDeductionAmount,
                    'is_custom' => 1,
                    'code' => 'LATE',
                    'category' => 'late_attendance',
                ];
            }

            $kpiAllowance = 0.0;
            $kpiBonusAllowance = 0.0;

            if ($kpiType === 'monthly') {
                $percentage = DB::table('performance_evaluations')
                    ->where('employee_id', $employeeData['id'])
                    ->where(function ($q) use ($startDate, $endDate) {
                        $q->where('start_date', '<=', $endDate)
                          ->where('end_date', '>=', $startDate);
                    })
                    ->avg('percentage');

                if (!is_null($percentage)) {
                    $kpiAllowance = ($adjustedBasic * ((float)$percentage)) / 100.0;
                    $allowancesArr[] = [
                        'id' => null,
                        'name' => 'KPI Allowance',
                        'amount' => round($kpiAllowance, 2),
                        'is_custom' => 1,
                        'code' => 'KPI',
                        'category' => 'kpi'
                    ];
                }
            } elseif ($kpiType === '6month' || $kpiType === 'six_month') {
                $sixMonthStart = Carbon::parse($startDate)->subMonths(5)->startOfMonth()->toDateString();

                $percentage = DB::table('performance_appraisals')
                    ->where('employee_id', $employeeData['id'])
                    ->where(function ($q) use ($sixMonthStart, $endDate) {
                        $q->where('start_date', '<=', $endDate)
                          ->where('end_date', '>=', $sixMonthStart);
                    })
                    ->avg('percentage');

                if (!is_null($percentage)) {
                    $kpiBonusAllowance = ($adjustedBasic * ((float)$percentage)) / 100.0;
                    $allowancesArr[] = [
                        'id' => null,
                        'name' => 'KPI Bonus (6M)',
                        'amount' => round($kpiBonusAllowance, 2),
                        'is_custom' => 1,
                        'code' => 'KPI6M',
                        'category' => 'kpi_bonus'
                    ];
                }
            }

            $employeeData['allowances'] = $allowancesArr;
            $employeeData['deductions'] = $deductionsArr;
            $employeeData['bonuses'] = $bonusesArr;

            $totalAllowances = array_reduce($allowancesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);
            $totalBonuses = array_reduce($bonusesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

            $epfEligibleAllowances = array_reduce($allowancesArr, function ($carry, $item) {
                $category = strtolower((string)($item['category'] ?? ''));
                if ($category === 'kpi_bonus') {
                    return $carry;
                }
                return $carry + (float)($item['amount'] ?? 0);
            }, 0);

            $epfEtfBase = $adjustedBasic + $epfEligibleAllowances;

            $epfEmployeeDeduction = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.08) : 0;
            $epfEmployerContribution = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.12) : 0;
            $etfEmployerContribution = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.03) : 0;

            $totalFixedDeductions = array_reduce($deductionsArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

            $empid = $employeeData['id'];

            $otRows = over_time::with('timeCard:id,date,time,actual_date')
                ->where('employee_id', $empid)
                ->whereRaw('LOWER(status) = ?', ['approved'])
                ->whereHas('timeCard', function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('date', [$startDate, $endDate])
                      ->orWhereBetween('actual_date', [$startDate, $endDate]);
                })
                ->get([
                    'ot_hours',
                    'total_ot_amount',
                    'holiday_ot_hours',
                    'holiday_ot_amount',
                    'morning_ot',
                    'afternoon_ot',
                    'morning_ot_special',
                    'evening_ot_special',
                    'morning_ot_amount',
                    'morning_ot_special_amount',
                    'evening_ot_amount',
                    'evening_ot_special_amount',
                    'time_cards_id'
                ]);

            $sumMorning = (float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount');
            $sumNight = (float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount');
            $sumHoliday = (float)$otRows->sum('holiday_ot_amount');

            $morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1) ? $sumMorning : 0.0;
            $night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1) ? $sumNight : 0.0;
            $holiday_ot_fees = $sumHoliday;

            $totalOt = (float)$otRows->sum('total_ot_amount');

            if ($totalOt > 0 && ($morning_ot_fees + $night_ot_fees + $holiday_ot_fees) <= 0) {
                $holiday_ot_fees = $totalOt;
            }

            $overtimeDetails = $otRows->map(function ($ot) {
                $tc = $ot->timeCard;
                $displayDate = ($tc && !empty($tc->actual_date)) ? $tc->actual_date : ($tc->date ?? null);

                return [
                    'date' => $displayDate,
                    'out_time' => $tc->time ?? null,
                    'ot_hours' => (float)($ot->ot_hours ?? 0),
                    'holiday_ot_hours' => (float)($ot->holiday_ot_hours ?? 0),
                    'holiday_ot_amount' => (float)($ot->holiday_ot_amount ?? 0),
                    'total_ot_amount' => (float)($ot->total_ot_amount ?? 0),
                ];
            })->values();

            $employeeData['overtime_details'] = $overtimeDetails;
            $employeeData['overtime_total_amount'] = round((float)$otRows->sum('total_ot_amount'), 2);
            $employeeData['overtime_total_hours'] = round((float)$otRows->sum('ot_hours'), 2);

            $grossSalary = $basicSalary
                + $totalAllowances
                + $totalBonuses
                + $morning_ot_fees
                + $night_ot_fees
                + $holiday_ot_fees
                + $kpiBonusAllowance;

            $totalDeductions = $totalFixedDeductions + $installmentAmount + $epfEmployeeDeduction;

            $netSalary = $grossSalary - $totalDeductions;
            if ($stampValue) {
                $netSalary -= $stampValue;
            }

            $employeeData['gross_salary'] = round($grossSalary, 2);
            $employeeData['total_deductions'] = round($totalDeductions + $stampValue, 2);
            $employeeData['net_salary'] = round($netSalary, 2);

            $employeeData['salary_breakdown'] = [
                'basic_salary' => round($basicSalary, 2),
                'br_allowance' => round($brAllowance, 2),
                'approved_no_pay_days' => round($approvedNoPayDays, 2),
                'ot_morning_fees' => round($morning_ot_fees, 2),
                'ot_night_fees' => round($night_ot_fees, 2),
                'adjusted_basic' => round($adjustedBasic, 2),
                'per_day_salary' => round($perDaySalary, 3),
                'holiday_ot_fees' => round($holiday_ot_fees, 2),
                'no_pay_deduction' => round($noPayDeduction, 2),
                'probation_over_limit_days' => round($probationOverLimitDays, 2),
                'probation_deduction' => round($probationDeduction, 2),

                'late_count_for_policy' => (int)($lateData['late_count_for_policy'] ?? 0),
                'approved_leave_late_count' => (int)($lateData['approved_leave_late_count'] ?? 0),
                'no_deduction_late_count' => (int)($lateData['no_deduction_late_count'] ?? 0),
                'short_leave_count' => (int)($lateData['short_leave_count'] ?? 0),
                'half_day_count' => (int)($lateData['half_day_count'] ?? 0),
                'deductible_late_count' => (int)($lateData['deductible_late_count'] ?? 0),
                'late_deduction_amount' => round($lateDeductionAmount, 2),
                'total_late_minutes' => (int)($lateData['total_late_minutes'] ?? 0),
                'total_late_hours' => round((float)($lateData['total_late_hours'] ?? 0), 2),
                'late_dates' => $lateData['late_dates'] ?? [],

                'kpi_allowance' => round($kpiAllowance, 2),
                'kpi_bonus_allowance' => round($kpiBonusAllowance, 2),
                'total_allowances' => round($totalAllowances, 2),
                'total_bonuses' => round($totalBonuses, 2),
                'epf_etf_base' => round($epfEtfBase, 2),
                'epf_employee_deduction' => round($epfEmployeeDeduction, 2),
                'epf_employer_contribution' => round($epfEmployerContribution, 2),
                'etf_employer_contribution' => round($etfEmployerContribution, 2),
                'total_fixed_deductions' => round($totalFixedDeductions, 2),
                'loan_installment' => round($installmentAmount, 2),
                'gross_salary' => round($grossSalary, 2),
                'total_deductions' => round($totalDeductions + $stampValue, 2),
                'stamp' => round($stampValue, 2),
                'net_salary' => round($netSalary, 2),
            ];

            $data[] = $employeeData;
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'month' => $month,
                'year' => $year,
                'company_id' => $company_id,
                'department_id' => $department_id,
                'count' => count($data),
                'total_days_in_month' => $totalDaysInMonth,
                'company_leave_days' => $leaveDaysCount,
                'working_days_in_month' => $workingDaysInMonth
            ]
        ]);
    }

    public function updateEmployeesAllowances(Request $request)
    {
        $employeeIDs = $request->selectedEmployees;
        $type = $request->bulkActionType;
        $amount = $request->bulkActionAmount;
        $typeId = $request->bulkActionId;

        if (!is_array($employeeIDs) || empty($employeeIDs)) {
            return response()->json(['error' => 'No employees selected'], 400);
        }

        if (!in_array($type, ['allowance', 'deduction', 'bonus'], true)) {
            return response()->json([
                'error' => 'Invalid bulkActionType',
                'received' => $type
            ], 422);
        }

        $rows = [];

        foreach ($employeeIDs as $employeeId) {
            if ($type === 'allowance') {
                $rows[] = [
                    'employee_id' => $employeeId,
                    'allowance_id' => $typeId,
                    'custom_amount' => $amount,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } elseif ($type === 'deduction') {
                $rows[] = [
                    'employee_id' => $employeeId,
                    'deduction_id' => $typeId,
                    'custom_amount' => $amount,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } else {
                $rows[] = [
                    'employee_id' => $employeeId,
                    'bonus_id' => $typeId,
                    'custom_amount' => $amount,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($type === 'allowance') {
            employee_allowances::upsert(
                $rows,
                ['employee_id', 'allowance_id'],
                ['custom_amount', 'is_active', 'updated_at']
            );
        } elseif ($type === 'deduction') {
            employee_deductions::upsert(
                $rows,
                ['employee_id', 'deduction_id'],
                ['custom_amount', 'is_active', 'updated_at']
            );
        } else {
            EmployeeBonus::upsert(
                $rows,
                ['employee_id', 'bonus_id'],
                ['custom_amount', 'is_active', 'updated_at']
            );
        }

        return response()->json([
            'message' => 'Bulk update successful',
            'type' => $type,
            'affected_employees' => count($employeeIDs)
        ]);
    }

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
            $month = $request->data[0]['month'];
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
                    'allowances' => $employeeData['allowances'] ?? null,
                    'deductions' => $employeeData['deductions'] ?? null,
                    'bonuses' => $employeeData['bonuses'] ?? null,
                    'salary_breakdown' => $employeeData['salary_breakdown'] ?? null,
                    'month' => $month,
                    'year' => $year,
                ]);
            }

            $response = ['message' => 'Salary data saved successfully'];

            if (!empty($duplicateEntries)) {
                $response['duplicates'] = [
                    'message' => 'Some entries were skipped as duplicates',
                    'employee_numbers' => $duplicateEntries,
                    'count' => count($duplicateEntries)
                ];
            }

            return response()->json($response, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error saving salary data: ' . $e->getMessage()], 500);
        }
    }


    /*
    public function getProcessedSalaries()
    {
        $processedSalaries = salary_process::where('status', 'processed')
            ->with([
                'employee' => function ($query) {
                    $query->select('id', 'full_name', 'attendance_employee_no')
                        ->with([
                            'compensation' => function ($q) {
                                $q->select('employee_id', 'basic_salary', 'enable_epf_etf');
                            }
                        ])
                        ->with([
                            'compensation' => function ($q) {
                                $q->select('employee_id', 'bank_name', 'bank_account_no', 'branch_name');
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
                'salary_breakdown' => $salary->salary_breakdown,
                'allowances' => $salary->allowances,
                'deductions' => $salary->deductions,
                'bonuses' => $salary->bonuses,
            ];
        });

        return response()->json($response);
    }
        */

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
                            $q->select(
                                'employee_id',
                                'basic_salary',
                                'enable_epf_etf',
                                'bank_name',
                                'bank_account_no',
                                'branch_name'
                            );
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
            'salary_breakdown' => $salary->salary_breakdown,
            'allowances' => $salary->allowances,
            'deductions' => $salary->deductions,
            'bonuses' => $salary->bonuses,
        ];
    });

    return response()->json($response);
}



    public function markAsIssued(Request $request)
    {
        $validated = $request->validate([
            'employee_ids' => 'required|array',
            'employee_ids.*' => 'exists:salary_processes,employee_id'
        ]);

        $salaryProcesses = salary_process::whereIn('employee_id', $validated['employee_ids'])
            ->where('status', 'processed')
            ->get();

        DB::beginTransaction();

        try {
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

                $process->update(['status' => 'issued']);
            }

            DB::commit();
            return response()->json(['message' => 'Payslips marked as issued and loan installments updated successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating payslips and loans: ' . $e->getMessage()], 500);
        }
    }

    public function fetchExcelData(Request $request)
    {
        $employees = employee::whereIn('id', $request->selectedEmployees)
            ->get([
                'id',
                'attendance_employee_no',
                'nic',
                'full_name',
            ]);

        $allowance = "";
        $deduction = "";
        $bonus = "";

        if ($request->bulkActionType == 'allowance') {
            $allowance = allowances::where('id', $request->bulkActionId)
                ->get(['id', 'allowance_name']);
        } elseif ($request->bulkActionType == 'deduction') {
            $deduction = deduction::where('id', $request->bulkActionId)
                ->get(['id', 'deduction_name']);
        } elseif ($request->bulkActionType == 'bonus') {
            $bonus = DB::table('bonuses')
                ->where('id', $request->bulkActionId)
                ->get(['id', 'bonus_name']);
        }

        return response()->json([$employees, $allowance, $deduction, $bonus], 200);
    }

    public function importExcelData(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
            'type' => 'required|in:allowances,deductions'
        ]);

        try {
            $import = null;
            $message = '';

            if ($request->type === 'allowances') {
                $import = new EmployeeAllowancesImport();
                $message = 'Employee allowances imported successfully';
            } else {
                $import = new EmployeeDeductionsImport();
                $message = 'Employee deductions imported successfully';
            }

            Excel::import($import, $request->file('file'));

            return response()->json([
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error importing file: ' . $e->getMessage()
            ], 422);
        }
    }

    public function updateStatus(Request $request)
    {
        DB::beginTransaction();
        try {
            if ($request->has('status') && $request->status == 'issued') {
                $salaryProcesses = salary_process::where('status', 'processed')->get();

                foreach ($salaryProcesses as $process) {
                    $loan = loans::where('employee_id', $process->employee_id)
                        ->where('status', 'active')
                        ->first();

                    if ($loan) {
                        $prevCount = (int)($loan->installment_count ?? 0);
                        $newInstallmentCount = max(0, $prevCount - 1);

                        $isLastInstallment = ($newInstallmentCount == 0);

                        $remainder = $loan->loan_amount % $loan->installment_amount;
                        $hasRemainder = ($remainder > 0);

                        if ($isLastInstallment && $hasRemainder && $loan->installment_count == 1) {
                            $process->installment_amount = $remainder;
                            $process->save();
                        }

                        $loan->installment_count = $newInstallmentCount;
                        $loan->status = $isLastInstallment ? 'completed' : 'active';
                        $loan->save();

                        if ($isLastInstallment) {
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

                    $process->update(['status' => 'issued']);
                }

                DB::commit();
                return response()->json(['message' => 'Payslips marked as issued and loan installments updated successfully']);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating payslips and loans: ' . $e->getMessage()], 500);
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

            fputcsv($file, [
                'Employee No',
                'Full Name',
                'Company',
                'Department',
                'Basic Salary',
                'Net Salary',
                'Status',
                'Month',
                'Year'
            ]);

            foreach ($salaries as $salary) {
                fputcsv($file, [
                    $salary->employee_no,
                    $salary->full_name,
                    $salary->company_name,
                    $salary->department_name,
                    $salary->basic_salary,
                    data_get($salary->salary_breakdown, 'net_salary', $salary->net_salary ?? 0),
                    $salary->status,
                    $salary->month,
                    $salary->year,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}


/*
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
use Carbon\Carbon;
use App\Models\EmployeeBonus;

class SalaryProcessController extends Controller
{
    private function getLatePolicyAction(int $lateIndex): string
    {
        if ($lateIndex <= 3) {
            return 'No Deduction';
        }

        if ($lateIndex <= 5) {
            return 'Short Leave';
        }

        return 'Half Day';
    }

    private function getLateMinutes(?string $inTime, string $officeStart = '09:00:00'): int
    {
        if (!$inTime) {
            return 0;
        }

        $in = strtotime($inTime);
        $start = strtotime($officeStart);

        if ($in <= $start) {
            return 0;
        }

        return (int) floor(($in - $start) / 60);
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

    private function calculateLateDeductionData(int $employeeId, string $startDate, string $endDate, float $perDaySalary): array
    {
        $records = time_card::select(
                'employee_id',
                'date',
                DB::raw('MIN(CASE WHEN status IN ("IN", "Late Coming") THEN time END) as in_time'),
                DB::raw('
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            CASE
                                WHEN status IN ("IN", "Late Coming")
                                THEN status
                                ELSE NULL
                            END
                            ORDER BY time ASC SEPARATOR ","
                        ),
                        ",",
                        1
                    ) as first_in_status
                ')
            )
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereIn('status', ['IN', 'Late Coming', 'OUT', 'Early OUT'])
            ->whereNull('deleted_at')
            ->groupBy('employee_id', 'date')
            ->orderBy('date', 'asc')
            ->get();

        $lateCounter = 0;
        $shortLeaveCount = 0;
        $halfDayCount = 0;
        $noDeductionLateCount = 0;
        $approvedLeaveLateCount = 0;
        $deductibleLateCount = 0;
        $lateDates = [];

        foreach ($records as $record) {
            $isLate = ($record->first_in_status ?? null) === 'Late Coming';

            if (!$isLate) {
                continue;
            }

            $lateMinutes = $this->getLateMinutes($record->in_time, '09:00:00');

            // Only apply this rule for late <= 30 minutes
            if ($lateMinutes <= 0 || $lateMinutes > 30) {
                continue;
            }

            $leaveInfo = $this->getApprovedLeaveInfoForDate($employeeId, $record->date);

            // Approved leave => no deduction
            if ($leaveInfo['has_approved_leave']) {
                $approvedLeaveLateCount++;

                $lateDates[] = [
                    'date' => $record->date,
                    'in_time' => $record->in_time,
                    'late_minutes' => $lateMinutes,
                    'policy_action' => 'Leave Approved - No Deduction',
                    'has_approved_leave' => true,
                    'is_half_day_leave' => $leaveInfo['is_half_day_leave'],
                    'leave_type' => $leaveInfo['leave_type'],
                    'leave_status' => $leaveInfo['leave_status'],
                    'leave_period' => $leaveInfo['leave_period'],
                    'salary_deduction' => 0,
                ];

                continue;
            }

            $lateCounter++;
            $policyAction = $this->getLatePolicyAction($lateCounter);
            $salaryDeduction = 0;

            if ($policyAction === 'No Deduction') {
                $noDeductionLateCount++;
            } elseif ($policyAction === 'Short Leave') {
                $shortLeaveCount++;
                $deductibleLateCount++;
                $salaryDeduction = round($perDaySalary / 2, 2);
            } elseif ($policyAction === 'Half Day') {
                $halfDayCount++;
                $deductibleLateCount++;
                $salaryDeduction = round($perDaySalary / 2, 2);
            }

            $lateDates[] = [
                'date' => $record->date,
                'in_time' => $record->in_time,
                'late_minutes' => $lateMinutes,
                'policy_action' => $policyAction,
                'has_approved_leave' => false,
                'is_half_day_leave' => false,
                'leave_type' => null,
                'leave_status' => null,
                'leave_period' => null,
                'salary_deduction' => $salaryDeduction,
            ];
        }

        $totalLateDeduction = round(
            ($shortLeaveCount * ($perDaySalary / 2)) + ($halfDayCount * ($perDaySalary / 2)),
            2
        );

        return [
            'late_dates' => $lateDates,
            'late_count_for_policy' => $lateCounter,
            'approved_leave_late_count' => $approvedLeaveLateCount,
            'no_deduction_late_count' => $noDeductionLateCount,
            'short_leave_count' => $shortLeaveCount,
            'half_day_count' => $halfDayCount,
            'deductible_late_count' => $deductibleLateCount,
            'late_deduction_amount' => $totalLateDeduction,
        ];
    }

    public function index()
    {
        $salaryData = salary_process::with(['employee', 'noPayRecord', 'overTime', 'allowance', 'loan', 'deduction'])
            ->orderBy('process_date', 'desc')
            ->get();

        return response()->json($salaryData);
    }

    public function store(Request $request)
    {
        $salaryData = salary_process::create([
            'employee_id' => $request->employee_id,
            'process_date' => $request->process_date,
            'basic' => $request->basic,
            'basic_salary' => $request->basic_salary,
            'no_pay_records_id' => $request->no_pay_records_id,
            'over_times_id' => $request->over_times_id,
            'allowances_id' => $request->allowances_id,
            'loans_id' => $request->loans_id,
            'deductions_id' => $request->deductions_id,
            'gross_amount' => $request->gross_amount,
            'salary_advance' => $request->salary_advance,
            'net_salary' => $request->net_salary,
            'status' => 'Pending',
            'processed_by' => Auth::id(),
        ]);

        return response()->json($salaryData, 201);
    }

    public function updateSlaryStatus(Request $request)
    {
        DB::beginTransaction();

        try {
            if ($request->has('status') && $request->status == 'processed') {
                $salaryData = salary_process::where('status', 'pending')->update([
                    'status' => 'processed',
                ]);
                DB::commit();
                return response()->json($salaryData, 200);
            }

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

    public function show(string $id) {}
    public function update(Request $request, string $id) {}
    public function destroy(string $id) {}

    public function getEmployeesByMonthAndCompany(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $company_id = $request->query('company_id');
        $department_id = $request->query('department_id');

        $kpiTypeRaw = strtolower((string)$request->query('kpi_type', ''));
        $kpiType = in_array($kpiTypeRaw, ['monthly', '6month', 'six_month']) ? $kpiTypeRaw : '';

        $startDate = "{$year}-{$month}-01";
        $lastDay = date('t', strtotime($startDate));
        $endDate = "{$year}-{$month}-{$lastDay}";
        $selectedMonthYear = date('Y-m', strtotime($startDate));

        $totalDaysInMonth = (int)$lastDay;

        $companyLeaves = DB::table('leave_calendars')
            ->where('company_id', $company_id)
            ->where(function ($query) use ($startDate, $endDate) {
                $query->where(function ($q) use ($startDate, $endDate) {
                    $q->where('start_date', '<=', $endDate)
                        ->where('end_date', '>=', $startDate);
                })->orWhereBetween('start_date', [$startDate, $endDate]);
            })
            ->whereNull('deleted_at')
            ->get();

        $leaveDaysCount = 0;
        foreach ($companyLeaves as $leave) {
            $leaveStart = max($startDate, $leave->start_date);
            $leaveEnd = $leave->end_date ? min($endDate, $leave->end_date) : $leaveStart;
            $leaveDaysCount += date_diff(date_create($leaveStart), date_create($leaveEnd))->days + 1;
        }

        $workingDaysInMonth = max(1, $totalDaysInMonth - $leaveDaysCount);

        $query = "
            SELECT
                e.id,
                e.attendance_employee_no AS emp_no,
                e.full_name,
                c.name AS company_name,
                d.name AS department_name,
                sd.name AS sub_department_name,
                comp.basic_salary,
                oa.probationary_period,

                comp.increment_active,
                CASE WHEN comp.increment_active = 1 THEN comp.increment_value ELSE NULL END AS increment_value,
                CASE WHEN comp.increment_active = 1 THEN comp.increment_effected_date ELSE NULL END AS increment_effected_date,

                comp.ot_morning,
                comp.ot_evening,
                comp.enable_epf_etf,
                comp.br1,
                comp.br2,
                comp.ot_morning_rate,
                comp.ot_night_rate,
                comp.stamp,

                CASE
                    WHEN comp.br1 = 1 AND comp.br2 = 1 THEN 'Both BR1 and BR2'
                    WHEN comp.br1 = 1 THEN 'BR1 Only'
                    WHEN comp.br2 = 1 THEN 'BR2 Only'
                    ELSE 'None'
                END AS br_status,

                COALESCE(SUM(lo.loan_amount), 0) AS total_loan_amount,
                MAX(lo.installment_count) AS installment_count,
                MAX(lo.installment_amount) AS installment_amount,
                MAX(lo.status) AS loan_status,
                MAX(lo.schedule) AS loan_schedule,
                MAX(lo.start_from) AS loan_start_from,

                COALESCE(SUM(COALESCE(npr.no_pay_count, 0)), 0) AS approved_no_pay_days,

                (
                    SELECT CONCAT('[',
                        GROUP_CONCAT(
                            CONCAT(
                                '{\"id\":', a.id,
                                ',\"name\":\"', a.allowance_name,
                                '\",\"amount\":', COALESCE(ea.custom_amount, a.amount),
                                ',\"is_custom\":', CASE WHEN ea.id IS NOT NULL THEN 1 ELSE 0 END,
                                ',\"code\":\"', a.allowance_code,
                                '\",\"category\":\"', a.category, '\"}'
                            )
                        ),
                    ']')
                    FROM allowances a
                    LEFT JOIN employee_allowances ea ON a.id = ea.allowance_id AND ea.employee_id = e.id
                    WHERE a.company_id = c.id
                      AND (a.department_id IS NULL OR a.department_id = oa.department_id)
                      AND a.status = 'active'
                ) AS allowances,

                (
                    SELECT CONCAT('[',
                        GROUP_CONCAT(
                            CONCAT(
                                '{\"id\":', dd.id,
                                ',\"name\":\"', dd.deduction_name,
                                '\",\"amount\":', COALESCE(ed.custom_amount, dd.amount),
                                ',\"is_custom\":', CASE WHEN ed.id IS NOT NULL THEN 1 ELSE 0 END,
                                ',\"code\":\"', dd.deduction_code,
                                '\",\"category\":\"', dd.category, '\"}'
                            )
                        ),
                    ']')
                    FROM deductions dd
                    LEFT JOIN employee_deductions ed ON dd.id = ed.deduction_id AND ed.employee_id = e.id
                    WHERE dd.company_id = c.id
                      AND (dd.department_id IS NULL OR dd.department_id = oa.department_id)
                      AND dd.status = 'active'
                ) AS deductions,

                (
                    SELECT CONCAT('[',
                        GROUP_CONCAT(
                            CONCAT(
                                '{\"id\":', b.id,
                                ',\"name\":\"', b.bonus_name,
                                '\",\"amount\":', COALESCE(eb.custom_amount, b.amount),
                                ',\"is_custom\":', CASE WHEN eb.id IS NOT NULL THEN 1 ELSE 0 END,
                                ',\"code\":\"', COALESCE(b.bonus_code, ''), '\"}'
                            )
                        ),
                    ']')
                    FROM bonuses b
                    LEFT JOIN employee_bonuses eb ON b.id = eb.bonus_id AND eb.employee_id = e.id
                    WHERE b.company_id = c.id
                      AND (b.department_id IS NULL OR b.department_id = oa.department_id)
                      AND b.status = 'active'
                ) AS bonuses

            FROM employees e
            JOIN organization_assignments oa ON e.organization_assignment_id = oa.id
            JOIN companies c ON oa.company_id = c.id
            LEFT JOIN departments d ON oa.department_id = d.id
            LEFT JOIN sub_departments sd ON oa.sub_department_id = sd.id
            LEFT JOIN compensation comp ON e.id = comp.employee_id
            LEFT JOIN loans lo ON e.id = lo.employee_id AND lo.status = 'active'
            LEFT JOIN no_pay_records npr ON e.id = npr.employee_id
                AND LOWER(npr.status) = 'approved'
                AND npr.date BETWEEN ? AND ?

            WHERE oa.company_id = ?
        ";

        if ($department_id) {
            $query .= " AND oa.department_id = ? ";
        }

        $query .= "
            AND EXISTS (
                SELECT 1 FROM rosters r
                WHERE r.employee_id = e.id
                AND (
                    (r.date_from <= ? AND r.date_to >= ?) OR
                    (r.date_from BETWEEN ? AND ?) OR
                    (r.date_to BETWEEN ? AND ?) OR
                    (r.date_from IS NULL AND r.date_to IS NULL)
                )
            )
            GROUP BY
                e.id, e.attendance_employee_no, e.full_name,
                c.name, d.name, sd.name,
                comp.basic_salary, comp.br1, comp.br2,
                comp.increment_active, comp.increment_value,
                comp.increment_effected_date, comp.ot_morning,
                comp.ot_evening, comp.ot_morning_rate,
                comp.ot_night_rate, comp.enable_epf_etf,
                comp.stamp,
                c.id, oa.department_id,
                oa.probationary_period
        ";

        $params = [$startDate, $endDate, $company_id];
        if ($department_id) $params[] = $department_id;
        $params = array_merge($params, [$endDate, $startDate, $startDate, $endDate, $startDate, $endDate]);

        $results = DB::select($query, $params);

        $data = [];

        foreach ($results as $result) {
            $employeeData = (array)$result;

            $allowancesArr = json_decode($result->allowances ?? '[]', true) ?: [];
            $deductionsArr = json_decode($result->deductions ?? '[]', true) ?: [];
            $bonusesArr    = json_decode($result->bonuses ?? '[]', true) ?: [];

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

            $approvedNoPayDays = (float)($employeeData['approved_no_pay_days'] ?? 0);

            $installmentAmount = 0.0;
            $loanStatus = strtolower((string)($employeeData['loan_status'] ?? ''));

            if ($loanStatus === 'active') {
                $schedule = $employeeData['loan_schedule'] ?? null;

                if (is_string($schedule)) {
                    $decoded = json_decode($schedule, true);
                    $schedule = is_array($decoded) ? $decoded : null;
                }

                if (is_array($schedule) && count($schedule) > 0) {
                    foreach ($schedule as $r) {
                        $due = $r['due_date'] ?? $r['dueDate'] ?? null;
                        if (!$due) continue;

                        if (date('Y-m', strtotime($due)) === $selectedMonthYear) {
                            $installmentAmount = (float)($r['installment_amount'] ?? $r['installmentAmount'] ?? 0);
                            break;
                        }
                    }

                    if ($installmentAmount <= 0) {
                        $installmentAmount = 0.0;
                    }
                } else {
                    $count = (int)($employeeData['installment_count'] ?? 0);
                    $installmentAmount = ($count > 0) ? (float)($employeeData['installment_amount'] ?? 0) : 0.0;
                }
            }

            $employeeData['installment_amount'] = $installmentAmount;

            if (
                !empty($employeeData['increment_active']) &&
                !empty($employeeData['increment_effected_date']) &&
                strtotime($employeeData['increment_effected_date']) <= strtotime($endDate)
            ) {
                $basicSalary += (float)($employeeData['increment_value'] ?? 0);
            }

            $employeeData['probationary_period'] = (bool)($result->probationary_period ?? false);
            $probationOverLimitDays = 0.0;

            if ($employeeData['probationary_period']) {
                $probationOverLimitDays = (float)(leave_master::where('employee_id', $employeeData['id'])
                    ->whereRaw('LOWER(status) = ?', ['approved'])
                    ->where(function ($q) use ($startDate, $endDate) {
                        $q->whereBetween('leave_date', [$startDate, $endDate])
                            ->orWhere(function ($q2) use ($startDate, $endDate) {
                                $q2->where('leave_from', '<=', $endDate)
                                    ->where('leave_to', '>=', $startDate);
                            });
                    })
                    ->sum('over_limit') ?? 0);
            }

            $perDaySalary = $basicSalary / $workingDaysInMonth;

            $noPayDeduction = round($approvedNoPayDays * $perDaySalary, 2);
            $probationDeduction = round($probationOverLimitDays * $perDaySalary, 2);

            $lateData = $this->calculateLateDeductionData(
                (int) $employeeData['id'],
                $startDate,
                $endDate,
                $perDaySalary
            );

            $lateDeductionAmount = round((float) ($lateData['late_deduction_amount'] ?? 0), 2);

            $adjustedBasic = round($basicSalary - $noPayDeduction - $probationDeduction, 2);
            if ($adjustedBasic < 0) $adjustedBasic = 0;

            if ($noPayDeduction > 0) {
                $deductionsArr[] = [
                    'id' => null,
                    'name' => 'No Pay Deduction',
                    'amount' => $noPayDeduction,
                    'is_custom' => 1,
                    'code' => 'NOPAY',
                    'category' => 'nopay',
                ];
            }

            if ($probationDeduction > 0) {
                $deductionsArr[] = [
                    'id' => null,
                    'name' => 'Probation Over-limit Deduction',
                    'amount' => $probationDeduction,
                    'is_custom' => 1,
                    'code' => 'PROB',
                    'category' => 'probation',
                ];
            }

            if ($lateDeductionAmount > 0) {
                $deductionsArr[] = [
                    'id' => null,
                    'name' => 'Late Attendance Deduction',
                    'amount' => $lateDeductionAmount,
                    'is_custom' => 1,
                    'code' => 'LATE',
                    'category' => 'late_attendance',
                ];
            }

            $kpiAllowance = 0.0;
            $kpiBonusAllowance = 0.0;

            if ($kpiType === 'monthly') {
                $percentage = DB::table('performance_evaluations')
                    ->where('employee_id', $employeeData['id'])
                    ->where(function ($q) use ($startDate, $endDate) {
                        $q->where('start_date', '<=', $endDate)
                            ->where('end_date', '>=', $startDate);
                    })
                    ->avg('percentage');

                if (!is_null($percentage)) {
                    $kpiAllowance = ($adjustedBasic * ((float)$percentage)) / 100.0;
                    $allowancesArr[] = [
                        'id' => null,
                        'name' => 'KPI Allowance',
                        'amount' => round($kpiAllowance, 2),
                        'is_custom' => 1,
                        'code' => 'KPI',
                        'category' => 'kpi'
                    ];
                }
            } elseif ($kpiType === '6month' || $kpiType === 'six_month') {
                $sixMonthStart = Carbon::parse($startDate)->subMonths(5)->startOfMonth()->toDateString();

                $percentage = DB::table('performance_appraisals')
                    ->where('employee_id', $employeeData['id'])
                    ->where(function ($q) use ($sixMonthStart, $endDate) {
                        $q->where('start_date', '<=', $endDate)
                            ->where('end_date', '>=', $sixMonthStart);
                    })
                    ->avg('percentage');

                if (!is_null($percentage)) {
                    $kpiBonusAllowance = ($adjustedBasic * ((float)$percentage)) / 100.0;
                    $allowancesArr[] = [
                        'id' => null,
                        'name' => 'KPI Bonus (6M)',
                        'amount' => round($kpiBonusAllowance, 2),
                        'is_custom' => 1,
                        'code' => 'KPI6M',
                        'category' => 'kpi_bonus'
                    ];
                }
            }

            $employeeData['allowances'] = $allowancesArr;
            $employeeData['deductions'] = $deductionsArr;
            $employeeData['bonuses'] = $bonusesArr;

            $totalAllowances = array_reduce($allowancesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);
            $totalBonuses    = array_reduce($bonusesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

            $epfEligibleAllowances = array_reduce($allowancesArr, function ($carry, $item) {
                $category = strtolower((string)($item['category'] ?? ''));
                if ($category === 'kpi_bonus') return $carry;
                return $carry + (float)($item['amount'] ?? 0);
            }, 0);

            $epfEtfBase = $adjustedBasic + $epfEligibleAllowances;

            $epfEmployeeDeduction = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.08) : 0;
            $epfEmployerContribution = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.12) : 0;
            $etfEmployerContribution = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.03) : 0;

            $totalFixedDeductions = array_reduce($deductionsArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

            $empid = $employeeData['id'];

            $otRows = over_time::with('timeCard:id,date,time,actual_date')
                ->where('employee_id', $empid)
                ->whereRaw('LOWER(status) = ?', ['approved'])
                ->whereHas('timeCard', function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('date', [$startDate, $endDate])
                      ->orWhereBetween('actual_date', [$startDate, $endDate]);
                })
                ->get([
                    'ot_hours',
                    'total_ot_amount',
                    'holiday_ot_hours',
                    'holiday_ot_amount',
                    'morning_ot',
                    'afternoon_ot',
                    'morning_ot_special',
                    'evening_ot_special',
                    'morning_ot_amount',
                    'morning_ot_special_amount',
                    'evening_ot_amount',
                    'evening_ot_special_amount',
                    'time_cards_id'
                ]);

            $sumMorning = (float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount');
            $sumNight = (float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount');
            $sumHoliday = (float)$otRows->sum('holiday_ot_amount');

            $morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1) ? $sumMorning : 0.0;
            $night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1) ? $sumNight : 0.0;

            $holiday_ot_fees = $sumHoliday;

            $totalOt = (float)$otRows->sum('total_ot_amount');

            if ($totalOt > 0 && ($morning_ot_fees + $night_ot_fees + $holiday_ot_fees) <= 0) {
                $holiday_ot_fees = $totalOt;
            }

            $overtimeDetails = $otRows->map(function ($ot) {
                $tc = $ot->timeCard;
                $displayDate = ($tc && !empty($tc->actual_date)) ? $tc->actual_date : ($tc->date ?? null);

                return [
                    'date' => $displayDate,
                    'out_time' => $tc->time ?? null,
                    'ot_hours' => (float)($ot->ot_hours ?? 0),
                    'holiday_ot_hours' => (float)($ot->holiday_ot_hours ?? 0),
                    'holiday_ot_amount' => (float)($ot->holiday_ot_amount ?? 0),
                    'total_ot_amount' => (float)($ot->total_ot_amount ?? 0),
                ];
            })->values();

            $employeeData['overtime_details'] = $overtimeDetails;
            $employeeData['overtime_total_amount'] = round((float)$otRows->sum('total_ot_amount'), 2);
            $employeeData['overtime_total_hours']  = round((float)$otRows->sum('ot_hours'), 2);

            $grossSalary = $basicSalary
                + $totalAllowances
                + $totalBonuses
                + $morning_ot_fees
                + $night_ot_fees
                + $holiday_ot_fees
                + $kpiBonusAllowance;

            $totalDeductions = $totalFixedDeductions + $installmentAmount + $epfEmployeeDeduction;

            $netSalary = $grossSalary - $totalDeductions;
            if ($stampValue) $netSalary -= $stampValue;

            $employeeData['gross_salary'] = round($grossSalary, 2);
            $employeeData['total_deductions'] = round($totalDeductions + $stampValue, 2);
            $employeeData['net_salary'] = round($netSalary, 2);

            $employeeData['salary_breakdown'] = [
                'basic_salary' => round($basicSalary, 2),
                'br_allowance' => round($brAllowance, 2),
                'approved_no_pay_days' => round($approvedNoPayDays, 2),
                'ot_morning_fees' => round($morning_ot_fees, 2),
                'ot_night_fees' => round($night_ot_fees, 2),
                'adjusted_basic' => round($adjustedBasic, 2),
                'per_day_salary' => round($perDaySalary, 3),
                'holiday_ot_fees' => round($holiday_ot_fees, 2),
                'no_pay_deduction' => round($noPayDeduction, 2),
                'probation_over_limit_days' => round($probationOverLimitDays, 2),
                'probation_deduction' => round($probationDeduction, 2),

                'late_count_for_policy' => (int) ($lateData['late_count_for_policy'] ?? 0),
                'approved_leave_late_count' => (int) ($lateData['approved_leave_late_count'] ?? 0),
                'no_deduction_late_count' => (int) ($lateData['no_deduction_late_count'] ?? 0),
                'short_leave_count' => (int) ($lateData['short_leave_count'] ?? 0),
                'half_day_count' => (int) ($lateData['half_day_count'] ?? 0),
                'deductible_late_count' => (int) ($lateData['deductible_late_count'] ?? 0),
                'late_deduction_amount' => round($lateDeductionAmount, 2),
                'late_dates' => $lateData['late_dates'] ?? [],

                'kpi_allowance' => round($kpiAllowance, 2),
                'kpi_bonus_allowance' => round($kpiBonusAllowance, 2),
                'total_allowances' => round($totalAllowances, 2),
                'total_bonuses' => round($totalBonuses, 2),
                'epf_etf_base' => round($epfEtfBase, 2),
                'epf_employee_deduction' => round($epfEmployeeDeduction, 2),
                'epf_employer_contribution' => round($epfEmployerContribution, 2),
                'etf_employer_contribution' => round($etfEmployerContribution, 2),
                'total_fixed_deductions' => round($totalFixedDeductions, 2),
                'loan_installment' => round($installmentAmount, 2),
                'gross_salary' => round($grossSalary, 2),
                'total_deductions' => round($totalDeductions + $stampValue, 2),
                'stamp' => round($stampValue, 2),
                'net_salary' => round($netSalary, 2),
            ];

            $data[] = $employeeData;
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'month' => $month,
                'year' => $year,
                'company_id' => $company_id,
                'department_id' => $department_id,
                'count' => count($data),
                'total_days_in_month' => $totalDaysInMonth,
                'company_leave_days' => $leaveDaysCount,
                'working_days_in_month' => $workingDaysInMonth
            ]
        ]);
    }

    public function updateEmployeesAllowances(Request $request)
    {
        $employeeIDs = $request->selectedEmployees;
        $type = $request->bulkActionType;
        $amount = $request->bulkActionAmount;
        $typeId = $request->bulkActionId;

        if (!is_array($employeeIDs) || empty($employeeIDs)) {
            return response()->json(['error' => 'No employees selected'], 400);
        }

        if (!in_array($type, ['allowance', 'deduction', 'bonus'], true)) {
            return response()->json([
                'error' => 'Invalid bulkActionType',
                'received' => $type
            ], 422);
        }

        $rows = [];

        foreach ($employeeIDs as $employeeId) {
            if ($type === 'allowance') {
                $rows[] = [
                    'employee_id' => $employeeId,
                    'allowance_id' => $typeId,
                    'custom_amount' => $amount,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } elseif ($type === 'deduction') {
                $rows[] = [
                    'employee_id' => $employeeId,
                    'deduction_id' => $typeId,
                    'custom_amount' => $amount,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } else {
                $rows[] = [
                    'employee_id' => $employeeId,
                    'bonus_id' => $typeId,
                    'custom_amount' => $amount,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($type === 'allowance') {
            employee_allowances::upsert(
                $rows,
                ['employee_id', 'allowance_id'],
                ['custom_amount', 'is_active', 'updated_at']
            );
        } elseif ($type === 'deduction') {
            employee_deductions::upsert(
                $rows,
                ['employee_id', 'deduction_id'],
                ['custom_amount', 'is_active', 'updated_at']
            );
        } else {
            EmployeeBonus::upsert(
                $rows,
                ['employee_id', 'bonus_id'],
                ['custom_amount', 'is_active', 'updated_at']
            );
        }

        return response()->json([
            'message' => 'Bulk update successful',
            'type' => $type,
            'affected_employees' => count($employeeIDs)
        ]);
    }

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
            $month = $request->data[0]['month'];
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
                    'allowances' => $employeeData['allowances'] ?? null,
                    'deductions' => $employeeData['deductions'] ?? null,
                    'bonuses' => $employeeData['bonuses'] ?? null,
                    'salary_breakdown' => $employeeData['salary_breakdown'] ?? null,
                    'month' => $month,
                    'year' => $year,
                ]);
            }

            $response = ['message' => 'Salary data saved successfully'];

            if (!empty($duplicateEntries)) {
                $response['duplicates'] = [
                    'message' => 'Some entries were skipped as duplicates',
                    'employee_numbers' => $duplicateEntries,
                    'count' => count($duplicateEntries)
                ];
            }

            return response()->json($response, 201);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Error saving salary data: ' . $e->getMessage()], 500);
        }
    }

    public function getProcessedSalaries()
    {
        $processedSalaries = salary_process::where('status', 'processed')
            ->with([
                'employee' => function ($query) {
                    $query->select('id', 'full_name', 'attendance_employee_no')
                        ->with([
                            'compensation' => function ($q) {
                                $q->select('employee_id', 'basic_salary', 'enable_epf_etf');
                            }
                        ])
                        ->with([
                            'compensation' => function ($q) {
                                $q->select('employee_id', 'bank_name', 'bank_account_no', 'branch_name');
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
                'salary_breakdown' => $salary->salary_breakdown,
                'allowances' => $salary->allowances,
                'deductions' => $salary->deductions,
                'bonuses' => $salary->bonuses,
            ];
        });

        return response()->json($response);
    }

    public function markAsIssued(Request $request)
    {
        $validated = $request->validate([
            'employee_ids' => 'required|array',
            'employee_ids.*' => 'exists:salary_processes,employee_id'
        ]);

        $salaryProcesses = salary_process::whereIn('employee_id', $validated['employee_ids'])
            ->where('status', 'processed')
            ->get();

        DB::beginTransaction();

        try {
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

                $process->update(['status' => 'issued']);
            }

            DB::commit();
            return response()->json(['message' => 'Payslips marked as issued and loan installments updated successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating payslips and loans: ' . $e->getMessage()], 500);
        }
    }

    public function fetchExcelData(Request $request)
    {
        $employees = employee::whereIn('id', $request->selectedEmployees)
            ->get([
                'id',
                'attendance_employee_no',
                'nic',
                'full_name',
            ]);

        $allowance = "";
        $deduction = "";
        $bonus = "";

        if ($request->bulkActionType == 'allowance') {
            $allowance = allowances::where('id', $request->bulkActionId)
                ->get(['id', 'allowance_name']);
        } elseif ($request->bulkActionType == 'deduction') {
            $deduction = deduction::where('id', $request->bulkActionId)
                ->get(['id', 'deduction_name']);
        } elseif ($request->bulkActionType == 'bonus') {
            $bonus = DB::table('bonuses')
                ->where('id', $request->bulkActionId)
                ->get(['id', 'bonus_name']);
        }

        return response()->json([$employees, $allowance, $deduction, $bonus], 200);
    }

    public function importExcelData(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
            'type' => 'required|in:allowances,deductions'
        ]);

        try {
            $import = null;
            $message = '';

            if ($request->type === 'allowances') {
                $import = new EmployeeAllowancesImport();
                $message = 'Employee allowances imported successfully';
            } else {
                $import = new EmployeeDeductionsImport();
                $message = 'Employee deductions imported successfully';
            }

            Excel::import($import, $request->file('file'));

            return response()->json([
                'message' => $message,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error importing file: ' . $e->getMessage()
            ], 422);
        }
    }

    public function updateStatus(Request $request)
    {
        DB::beginTransaction();
        try {
            if ($request->has('status') && $request->status == 'issued') {
                $salaryProcesses = salary_process::where('status', 'processed')->get();

                foreach ($salaryProcesses as $process) {
                    $loan = loans::where('employee_id', $process->employee_id)
                        ->where('status', 'active')
                        ->first();

                    if ($loan) {
                        $prevCount = (int)($loan->installment_count ?? 0);
                        $newInstallmentCount = max(0, $prevCount - 1);

                        $isLastInstallment = ($newInstallmentCount == 0);

                        $remainder = $loan->loan_amount % $loan->installment_amount;
                        $hasRemainder = ($remainder > 0);

                        if ($isLastInstallment && $hasRemainder && $loan->installment_count == 1) {
                            $process->installment_amount = $remainder;
                            $process->save();
                        }

                        $loan->installment_count = $newInstallmentCount;
                        $loan->status = $isLastInstallment ? 'completed' : 'active';
                        $loan->save();

                        if ($isLastInstallment) {
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

                    $process->update(['status' => 'issued']);
                }

                DB::commit();
                return response()->json(['message' => 'Payslips marked as issued and loan installments updated successfully']);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating payslips and loans: ' . $e->getMessage()], 500);
        }
    }
}

*/










//=============================================================================================
/*
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
use Carbon\Carbon;

// ✅ BONUS
use App\Models\EmployeeBonus;

class SalaryProcessController extends Controller
{
    public function index()
    {
        $salaryData = salary_process::with(['employee', 'noPayRecord', 'overTime', 'allowance', 'loan', 'deduction'])
            ->orderBy('process_date', 'desc')
            ->get();

        return response()->json($salaryData);
    }

    public function store(Request $request)
    {
        $salaryData = salary_process::create([
            'employee_id' => $request->employee_id,
            'process_date' => $request->process_date,
            'basic' => $request->basic,
            'basic_salary' => $request->basic_salary,
            'no_pay_records_id' => $request->no_pay_records_id,
            'over_times_id' => $request->over_times_id,
            'allowances_id' => $request->allowances_id,
            'loans_id' => $request->loans_id,
            'deductions_id' => $request->deductions_id,
            'gross_amount' => $request->gross_amount,
            'salary_advance' => $request->salary_advance,
            'net_salary' => $request->net_salary,
            'status' => 'Pending',
            'processed_by' => Auth::id(),
        ]);

        return response()->json($salaryData, 201);
    }

    public function updateSlaryStatus(Request $request)
    {
        DB::beginTransaction();

        try {
            if ($request->has('status') && $request->status == 'processed') {
                $salaryData = salary_process::where('status', 'pending')->update([
                    'status' => 'processed',
                ]);
                DB::commit();
                return response()->json($salaryData, 200);
            }

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

    public function show(string $id) {}
    public function update(Request $request, string $id) {}
    public function destroy(string $id) {}

    // ✅ MAIN
   public function getEmployeesByMonthAndCompany(Request $request)
{
    $month = $request->query('month');
    $year = $request->query('year');
    $company_id = $request->query('company_id');
    $department_id = $request->query('department_id');

    $kpiTypeRaw = strtolower((string)$request->query('kpi_type', ''));
    $kpiType = in_array($kpiTypeRaw, ['monthly', '6month', 'six_month']) ? $kpiTypeRaw : '';

    $startDate = "{$year}-{$month}-01";
    $lastDay = date('t', strtotime($startDate));
    $endDate = "{$year}-{$month}-{$lastDay}";
    $selectedMonthYear = date('Y-m', strtotime($startDate));

    // Working days calculation
    $totalDaysInMonth = (int)$lastDay;

    $companyLeaves = DB::table('leave_calendars')
        ->where('company_id', $company_id)
        ->where(function ($query) use ($startDate, $endDate) {
            $query->where(function ($q) use ($startDate, $endDate) {
                $q->where('start_date', '<=', $endDate)
                    ->where('end_date', '>=', $startDate);
            })->orWhereBetween('start_date', [$startDate, $endDate]);
        })
        ->whereNull('deleted_at')
        ->get();

    $leaveDaysCount = 0;
    foreach ($companyLeaves as $leave) {
        $leaveStart = max($startDate, $leave->start_date);
        $leaveEnd = $leave->end_date ? min($endDate, $leave->end_date) : $leaveStart;
        $leaveDaysCount += date_diff(date_create($leaveStart), date_create($leaveEnd))->days + 1;
    }

    $workingDaysInMonth = max(1, $totalDaysInMonth - $leaveDaysCount);

    $query = "
        SELECT
            e.id,
            e.attendance_employee_no AS emp_no,
            e.full_name,
            c.name AS company_name,
            d.name AS department_name,
            sd.name AS sub_department_name,
            comp.basic_salary,
            oa.probationary_period,

            comp.increment_active,
            CASE WHEN comp.increment_active = 1 THEN comp.increment_value ELSE NULL END AS increment_value,
            CASE WHEN comp.increment_active = 1 THEN comp.increment_effected_date ELSE NULL END AS increment_effected_date,

            comp.ot_morning,
            comp.ot_evening,
            comp.enable_epf_etf,
            comp.br1,
            comp.br2,
            comp.ot_morning_rate,
            comp.ot_night_rate,
            comp.stamp,

            CASE
                WHEN comp.br1 = 1 AND comp.br2 = 1 THEN 'Both BR1 and BR2'
                WHEN comp.br1 = 1 THEN 'BR1 Only'
                WHEN comp.br2 = 1 THEN 'BR2 Only'
                ELSE 'None'
            END AS br_status,

            COALESCE(SUM(lo.loan_amount), 0) AS total_loan_amount,
            MAX(lo.installment_count) AS installment_count,
            MAX(lo.installment_amount) AS installment_amount,
            MAX(lo.status) AS loan_status,
            MAX(lo.schedule) AS loan_schedule,
            MAX(lo.start_from) AS loan_start_from,

            COALESCE(SUM(COALESCE(npr.no_pay_count, 0)), 0) AS approved_no_pay_days,

            (
                SELECT CONCAT('[',
                    GROUP_CONCAT(
                        CONCAT(
                            '{\"id\":', a.id,
                            ',\"name\":\"', a.allowance_name,
                            '\",\"amount\":', COALESCE(ea.custom_amount, a.amount),
                            ',\"is_custom\":', CASE WHEN ea.id IS NOT NULL THEN 1 ELSE 0 END,
                            ',\"code\":\"', a.allowance_code,
                            '\",\"category\":\"', a.category, '\"}'
                        )
                    ),
                ']')
                FROM allowances a
                LEFT JOIN employee_allowances ea ON a.id = ea.allowance_id AND ea.employee_id = e.id
                WHERE a.company_id = c.id
                  AND (a.department_id IS NULL OR a.department_id = oa.department_id)
                  AND a.status = 'active'
            ) AS allowances,

            (
                SELECT CONCAT('[',
                    GROUP_CONCAT(
                        CONCAT(
                            '{\"id\":', dd.id,
                            ',\"name\":\"', dd.deduction_name,
                            '\",\"amount\":', COALESCE(ed.custom_amount, dd.amount),
                            ',\"is_custom\":', CASE WHEN ed.id IS NOT NULL THEN 1 ELSE 0 END,
                            ',\"code\":\"', dd.deduction_code,
                            '\",\"category\":\"', dd.category, '\"}'
                        )
                    ),
                ']')
                FROM deductions dd
                LEFT JOIN employee_deductions ed ON dd.id = ed.deduction_id AND ed.employee_id = e.id
                WHERE dd.company_id = c.id
                  AND (dd.department_id IS NULL OR dd.department_id = oa.department_id)
                  AND dd.status = 'active'
            ) AS deductions,

            (
                SELECT CONCAT('[',
                    GROUP_CONCAT(
                        CONCAT(
                            '{\"id\":', b.id,
                            ',\"name\":\"', b.bonus_name,
                            '\",\"amount\":', COALESCE(eb.custom_amount, b.amount),
                            ',\"is_custom\":', CASE WHEN eb.id IS NOT NULL THEN 1 ELSE 0 END,
                            ',\"code\":\"', COALESCE(b.bonus_code, ''), '\"}'
                        )
                    ),
                ']')
                FROM bonuses b
                LEFT JOIN employee_bonuses eb ON b.id = eb.bonus_id AND eb.employee_id = e.id
                WHERE b.company_id = c.id
                  AND (b.department_id IS NULL OR b.department_id = oa.department_id)
                  AND b.status = 'active'
            ) AS bonuses

        FROM employees e
        JOIN organization_assignments oa ON e.organization_assignment_id = oa.id
        JOIN companies c ON oa.company_id = c.id
        LEFT JOIN departments d ON oa.department_id = d.id
        LEFT JOIN sub_departments sd ON oa.sub_department_id = sd.id
        LEFT JOIN compensation comp ON e.id = comp.employee_id
        LEFT JOIN loans lo ON e.id = lo.employee_id AND lo.status = 'active'
        LEFT JOIN no_pay_records npr ON e.id = npr.employee_id
            AND LOWER(npr.status) = 'approved'
            AND npr.date BETWEEN ? AND ?

        WHERE oa.company_id = ?
    ";

    if ($department_id) {
        $query .= " AND oa.department_id = ? ";
    }

    $query .= "
        AND EXISTS (
            SELECT 1 FROM rosters r
            WHERE r.employee_id = e.id
            AND (
                (r.date_from <= ? AND r.date_to >= ?) OR
                (r.date_from BETWEEN ? AND ?) OR
                (r.date_to BETWEEN ? AND ?) OR
                (r.date_from IS NULL AND r.date_to IS NULL)
            )
        )
        GROUP BY
            e.id, e.attendance_employee_no, e.full_name,
            c.name, d.name, sd.name,
            comp.basic_salary, comp.br1, comp.br2,
            comp.increment_active, comp.increment_value,
            comp.increment_effected_date, comp.ot_morning,
            comp.ot_evening, comp.ot_morning_rate,
            comp.ot_night_rate, comp.enable_epf_etf,
            comp.stamp,
            c.id, oa.department_id,
            oa.probationary_period
    ";

    $params = [$startDate, $endDate, $company_id];
    if ($department_id) $params[] = $department_id;
    $params = array_merge($params, [$endDate, $startDate, $startDate, $endDate, $startDate, $endDate]);

    $results = DB::select($query, $params);

    $data = [];

    foreach ($results as $result) {
        $employeeData = (array)$result;

        $allowancesArr = json_decode($result->allowances ?? '[]', true) ?: [];
        $deductionsArr = json_decode($result->deductions ?? '[]', true) ?: [];
        $bonusesArr    = json_decode($result->bonuses ?? '[]', true) ?: [];

        // Stamp
        $stampValue = ((int)($result->stamp ?? 0) === 1) ? 25 : 0;
        $employeeData['stamp'] = $stampValue;

        // Base + BR
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

        // ✅ FIXED: float use කරන්න
        $approvedNoPayDays = (float)($employeeData['approved_no_pay_days'] ?? 0);

        // LOAN (schedule-based)
        $installmentAmount = 0.0;
        $loanStatus = strtolower((string)($employeeData['loan_status'] ?? ''));

        if ($loanStatus === 'active') {
            $schedule = $employeeData['loan_schedule'] ?? null;

            if (is_string($schedule)) {
                $decoded = json_decode($schedule, true);
                $schedule = is_array($decoded) ? $decoded : null;
            }

            if (is_array($schedule) && count($schedule) > 0) {
                foreach ($schedule as $r) {
                    $due = $r['due_date'] ?? $r['dueDate'] ?? null;
                    if (!$due) continue;

                    if (date('Y-m', strtotime($due)) === $selectedMonthYear) {
                        $installmentAmount = (float)($r['installment_amount'] ?? $r['installmentAmount'] ?? 0);
                        break;
                    }
                }

                if ($installmentAmount <= 0) {
                    $installmentAmount = 0.0;
                }
            } else {
                $count = (int)($employeeData['installment_count'] ?? 0);
                $installmentAmount = ($count > 0) ? (float)($employeeData['installment_amount'] ?? 0) : 0.0;
            }
        }

        $employeeData['installment_amount'] = $installmentAmount;

        // Increment
        if (
            !empty($employeeData['increment_active']) &&
            !empty($employeeData['increment_effected_date']) &&
            strtotime($employeeData['increment_effected_date']) <= strtotime($endDate)
        ) {
            $basicSalary += (float)($employeeData['increment_value'] ?? 0);
        }

        // Probation over-limit
        $employeeData['probationary_period'] = (bool)($result->probationary_period ?? false);
        $probationOverLimitDays = 0.0;

        if ($employeeData['probationary_period']) {
            $probationOverLimitDays = (float)(leave_master::where('employee_id', $employeeData['id'])
                ->whereRaw('LOWER(status) = ?', ['approved'])
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('leave_date', [$startDate, $endDate])
                        ->orWhere(function ($q2) use ($startDate, $endDate) {
                            $q2->where('leave_from', '<=', $endDate)
                                ->where('leave_to', '>=', $startDate);
                        });
                })
                ->sum('over_limit') ?? 0);
        }

        // ✅ NoPay + Probation as DEDUCTIONS
        $perDaySalary = $basicSalary / $workingDaysInMonth;

        $noPayDeduction = round($approvedNoPayDays * $perDaySalary, 2);
        $probationDeduction = round($probationOverLimitDays * $perDaySalary, 2);

        $adjustedBasic = round($basicSalary - $noPayDeduction - $probationDeduction, 2);
        if ($adjustedBasic < 0) $adjustedBasic = 0;

        if ($noPayDeduction > 0) {
            $deductionsArr[] = [
                'id' => null,
                'name' => 'No Pay Deduction',
                'amount' => $noPayDeduction,
                'is_custom' => 1,
                'code' => 'NOPAY',
                'category' => 'nopay',
            ];
        }

        if ($probationDeduction > 0) {
            $deductionsArr[] = [
                'id' => null,
                'name' => 'Probation Over-limit Deduction',
                'amount' => $probationDeduction,
                'is_custom' => 1,
                'code' => 'PROB',
                'category' => 'probation',
            ];
        }

        // KPI
        $kpiAllowance = 0.0;
        $kpiBonusAllowance = 0.0;

        if ($kpiType === 'monthly') {
            $percentage = DB::table('performance_evaluations')
                ->where('employee_id', $employeeData['id'])
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->where('start_date', '<=', $endDate)
                        ->where('end_date', '>=', $startDate);
                })
                ->avg('percentage');

            if (!is_null($percentage)) {
                $kpiAllowance = ($adjustedBasic * ((float)$percentage)) / 100.0;
                $allowancesArr[] = [
                    'id' => null,
                    'name' => 'KPI Allowance',
                    'amount' => round($kpiAllowance, 2),
                    'is_custom' => 1,
                    'code' => 'KPI',
                    'category' => 'kpi'
                ];
            }
        } elseif ($kpiType === '6month' || $kpiType === 'six_month') {
            $sixMonthStart = Carbon::parse($startDate)->subMonths(5)->startOfMonth()->toDateString();

            $percentage = DB::table('performance_appraisals')
                ->where('employee_id', $employeeData['id'])
                ->where(function ($q) use ($sixMonthStart, $endDate) {
                    $q->where('start_date', '<=', $endDate)
                        ->where('end_date', '>=', $sixMonthStart);
                })
                ->avg('percentage');

            if (!is_null($percentage)) {
                $kpiBonusAllowance = ($adjustedBasic * ((float)$percentage)) / 100.0;
                $allowancesArr[] = [
                    'id' => null,
                    'name' => 'KPI Bonus (6M)',
                    'amount' => round($kpiBonusAllowance, 2),
                    'is_custom' => 1,
                    'code' => 'KPI6M',
                    'category' => 'kpi_bonus'
                ];
            }
        }

        $employeeData['allowances'] = $allowancesArr;
        $employeeData['deductions'] = $deductionsArr;
        $employeeData['bonuses'] = $bonusesArr;

        $totalAllowances = array_reduce($allowancesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);
        $totalBonuses    = array_reduce($bonusesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

        $epfEligibleAllowances = array_reduce($allowancesArr, function ($carry, $item) {
            $category = strtolower((string)($item['category'] ?? ''));
            if ($category === 'kpi_bonus') return $carry;
            return $carry + (float)($item['amount'] ?? 0);
        }, 0);

        // EPF/ETF base uses adjusted basic
        $epfEtfBase = $adjustedBasic + $epfEligibleAllowances;

        $epfEmployeeDeduction = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.08) : 0;
        $epfEmployerContribution = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.12) : 0;
        $etfEmployerContribution = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.03) : 0;

        $totalFixedDeductions = array_reduce($deductionsArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

        // OT
        $empid = $employeeData['id'];

        $otRows = over_time::with('timeCard:id,date,time,actual_date')
            ->where('employee_id', $empid)
            ->whereRaw('LOWER(status) = ?', ['approved'])
            ->whereHas('timeCard', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('date', [$startDate, $endDate])
                  ->orWhereBetween('actual_date', [$startDate, $endDate]);
            })
            ->get([
                'ot_hours',
                'total_ot_amount',
                'holiday_ot_hours',
                'holiday_ot_amount',
                'morning_ot',
                'afternoon_ot',
                'morning_ot_special',
                'evening_ot_special',
                'morning_ot_amount',
                'morning_ot_special_amount',
                'evening_ot_amount',
                'evening_ot_special_amount',
                'time_cards_id'
            ]);

        $sumMorning = (float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount');
        $sumNight = (float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount');
        $sumHoliday = (float)$otRows->sum('holiday_ot_amount');

        $morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1) ? $sumMorning : 0.0;
        $night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1) ? $sumNight : 0.0;

        $holiday_ot_fees = $sumHoliday;

        $totalOt = (float)$otRows->sum('total_ot_amount');

        if ($totalOt > 0 && ($morning_ot_fees + $night_ot_fees + $holiday_ot_fees) <= 0) {
            $holiday_ot_fees = $totalOt;
        }

        $overtimeDetails = $otRows->map(function ($ot) {
            $tc = $ot->timeCard;
            $displayDate = ($tc && !empty($tc->actual_date)) ? $tc->actual_date : ($tc->date ?? null);

            return [
                'date' => $displayDate,
                'out_time' => $tc->time ?? null,
                'ot_hours' => (float)($ot->ot_hours ?? 0),
                'holiday_ot_hours' => (float)($ot->holiday_ot_hours ?? 0),
                'holiday_ot_amount' => (float)($ot->holiday_ot_amount ?? 0),
                'total_ot_amount' => (float)($ot->total_ot_amount ?? 0),
            ];
        })->values();

        $employeeData['overtime_details'] = $overtimeDetails;
        $employeeData['overtime_total_amount'] = round((float)$otRows->sum('total_ot_amount'), 2);
        $employeeData['overtime_total_hours']  = round((float)$otRows->sum('ot_hours'), 2);

        $grossSalary = $basicSalary
            + $totalAllowances
            + $totalBonuses
            + $morning_ot_fees
            + $night_ot_fees
            + $holiday_ot_fees
            + $kpiBonusAllowance;

        $totalDeductions = $totalFixedDeductions + $installmentAmount + $epfEmployeeDeduction;

        $netSalary = $grossSalary - $totalDeductions;
        if ($stampValue) $netSalary -= $stampValue;

        $employeeData['gross_salary'] = round($grossSalary, 2);
        $employeeData['total_deductions'] = round($totalDeductions + $stampValue, 2);
        $employeeData['net_salary'] = round($netSalary, 2);

        $employeeData['salary_breakdown'] = [
            'basic_salary' => round($basicSalary, 2),
            'br_allowance' => round($brAllowance, 2),
            'approved_no_pay_days' => round($approvedNoPayDays, 2),
            'ot_morning_fees' => round($morning_ot_fees, 2),
            'ot_night_fees' => round($night_ot_fees, 2),
            'adjusted_basic' => round($adjustedBasic, 2),
            'per_day_salary' => round($perDaySalary, 3),
            'holiday_ot_fees' => round($holiday_ot_fees, 2),
            'no_pay_deduction' => round($noPayDeduction, 2),
            'probation_over_limit_days' => round($probationOverLimitDays, 2),
            'probation_deduction' => round($probationDeduction, 2),
            'kpi_allowance' => round($kpiAllowance, 2),
            'kpi_bonus_allowance' => round($kpiBonusAllowance, 2),
            'total_allowances' => round($totalAllowances, 2),
            'total_bonuses' => round($totalBonuses, 2),
            'epf_etf_base' => round($epfEtfBase, 2),
            'epf_employee_deduction' => round($epfEmployeeDeduction, 2),
            'epf_employer_contribution' => round($epfEmployerContribution, 2),
            'etf_employer_contribution' => round($etfEmployerContribution, 2),
            'total_fixed_deductions' => round($totalFixedDeductions, 2),
            'loan_installment' => round($installmentAmount, 2),
            'gross_salary' => round($grossSalary, 2),
            'total_deductions' => round($totalDeductions + $stampValue, 2),
            'stamp' => round($stampValue, 2),
            'net_salary' => round($netSalary, 2),
        ];

        $data[] = $employeeData;
    }

    return response()->json([
        'data' => $data,
        'meta' => [
            'month' => $month,
            'year' => $year,
            'company_id' => $company_id,
            'department_id' => $department_id,
            'count' => count($data),
            'total_days_in_month' => $totalDaysInMonth,
            'company_leave_days' => $leaveDaysCount,
            'working_days_in_month' => $workingDaysInMonth
        ]
    ]);
}

    public function updateEmployeesAllowances(Request $request)
    {
        $employeeIDs = $request->selectedEmployees;
        $type = $request->bulkActionType; // allowance | deduction | bonus
        $amount = $request->bulkActionAmount;
        $typeId = $request->bulkActionId;

        if (!is_array($employeeIDs) || empty($employeeIDs)) {
            return response()->json(['error' => 'No employees selected'], 400);
        }

        if (!in_array($type, ['allowance', 'deduction', 'bonus'], true)) {
            return response()->json([
                'error' => 'Invalid bulkActionType',
                'received' => $type
            ], 422);
        }

        $rows = [];

        foreach ($employeeIDs as $employeeId) {
            if ($type === 'allowance') {
                $rows[] = [
                    'employee_id' => $employeeId,
                    'allowance_id' => $typeId,
                    'custom_amount' => $amount,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } elseif ($type === 'deduction') {
                $rows[] = [
                    'employee_id' => $employeeId,
                    'deduction_id' => $typeId,
                    'custom_amount' => $amount,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } else { // ✅ BONUS
                $rows[] = [
                    'employee_id' => $employeeId,
                    'bonus_id' => $typeId,
                    'custom_amount' => $amount,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($type === 'allowance') {
            employee_allowances::upsert(
                $rows,
                ['employee_id', 'allowance_id'],
                ['custom_amount', 'is_active', 'updated_at']
            );
        } elseif ($type === 'deduction') {
            employee_deductions::upsert(
                $rows,
                ['employee_id', 'deduction_id'],
                ['custom_amount', 'is_active', 'updated_at']
            );
        } else {
            EmployeeBonus::upsert(
                $rows,
                ['employee_id', 'bonus_id'],
                ['custom_amount', 'is_active', 'updated_at']
            );
        }

        return response()->json([
            'message' => 'Bulk update successful',
            'type' => $type,
            'affected_employees' => count($employeeIDs)
        ]);
    }

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
            $month = $request->data[0]['month'];
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
                    'allowances' => $employeeData['allowances'] ?? null,
                    'deductions' => $employeeData['deductions'] ?? null,
                    'bonuses' => $employeeData['bonuses'] ?? null,
                    'salary_breakdown' => $employeeData['salary_breakdown'] ?? null,
                    'month' => $month,
                    'year' => $year,
                ]);
            }

            $response = ['message' => 'Salary data saved successfully'];

            if (!empty($duplicateEntries)) {
                $response['duplicates'] = [
                    'message' => 'Some entries were skipped as duplicates',
                    'employee_numbers' => $duplicateEntries,
                    'count' => count($duplicateEntries)
                ];
            }

            return response()->json($response, 201);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Error saving salary data: ' . $e->getMessage()], 500);
        }
    }

    public function getProcessedSalaries()
    {
        $processedSalaries = salary_process::where('status', 'processed')
            ->with([
                'employee' => function ($query) {
                    $query->select('id', 'full_name', 'attendance_employee_no')
                        ->with([
                            'compensation' => function ($q) {
                                $q->select('employee_id', 'basic_salary', 'enable_epf_etf');
                            }
                        ])
                        ->with([
                            'compensation' => function ($q) {
                                $q->select('employee_id', 'bank_name', 'bank_account_no', 'branch_name');
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
                'salary_breakdown' => $salary->salary_breakdown,
                'allowances' => $salary->allowances,
                'deductions' => $salary->deductions,
                'bonuses' => $salary->bonuses,
            ];
        });

        return response()->json($response);
    }

    public function markAsIssued(Request $request)
    {
        $validated = $request->validate([
            'employee_ids' => 'required|array',
            'employee_ids.*' => 'exists:salary_processes,employee_id'
        ]);

        $salaryProcesses = salary_process::whereIn('employee_id', $validated['employee_ids'])
            ->where('status', 'processed')
            ->get();

        DB::beginTransaction();

        try {
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

                $process->update(['status' => 'issued']);
            }

            DB::commit();
            return response()->json(['message' => 'Payslips marked as issued and loan installments updated successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating payslips and loans: ' . $e->getMessage()], 500);
        }
    }

    public function fetchExcelData(Request $request)
    {
        $employees = employee::whereIn('id', $request->selectedEmployees)
            ->get([
                'id',
                'attendance_employee_no',
                'nic',
                'full_name',
            ]);

        $allowance = "";
        $deduction = "";
        $bonus = "";

        if ($request->bulkActionType == 'allowance') {
            $allowance = allowances::where('id', $request->bulkActionId)
                ->get(['id', 'allowance_name']);
        } elseif ($request->bulkActionType == 'deduction') {
            $deduction = deduction::where('id', $request->bulkActionId)
                ->get(['id', 'deduction_name']);
        } elseif ($request->bulkActionType == 'bonus') {
            $bonus = DB::table('bonuses')
                ->where('id', $request->bulkActionId)
                ->get(['id', 'bonus_name']);
        }

        return response()->json([$employees, $allowance, $deduction, $bonus], 200);
    }

    public function importExcelData(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
            'type' => 'required|in:allowances,deductions'
        ]);

        try {
            $import = null;
            $message = '';

            if ($request->type === 'allowances') {
                $import = new EmployeeAllowancesImport();
                $message = 'Employee allowances imported successfully';
            } else {
                $import = new EmployeeDeductionsImport();
                $message = 'Employee deductions imported successfully';
            }

            Excel::import($import, $request->file('file'));

            return response()->json([
                'message' => $message,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error importing file: ' . $e->getMessage()
            ], 422);
        }
    }

    public function updateStatus(Request $request)
    {
        DB::beginTransaction();
        try {
            if ($request->has('status') && $request->status == 'issued') {
                $salaryProcesses = salary_process::where('status', 'processed')->get();

                foreach ($salaryProcesses as $process) {
                    $loan = loans::where('employee_id', $process->employee_id)
                        ->where('status', 'active')
                        ->first();

                    if ($loan) {
                        $prevCount = (int)($loan->installment_count ?? 0);
                        $newInstallmentCount = max(0, $prevCount - 1);

                        $isLastInstallment = ($newInstallmentCount == 0);

                        $remainder = $loan->loan_amount % $loan->installment_amount;
                        $hasRemainder = ($remainder > 0);

                        if ($isLastInstallment && $hasRemainder && $loan->installment_count == 1) {
                            $process->installment_amount = $remainder;
                            $process->save();
                        }

                        $loan->installment_count = $newInstallmentCount;
                        $loan->status = $isLastInstallment ? 'completed' : 'active';
                        $loan->save();

                        if ($isLastInstallment) {
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

                    $process->update(['status' => 'issued']);
                }

                DB::commit();
                return response()->json(['message' => 'Payslips marked as issued and loan installments updated successfully']);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating payslips and loans: ' . $e->getMessage()], 500);
        }
    }
}



*/



