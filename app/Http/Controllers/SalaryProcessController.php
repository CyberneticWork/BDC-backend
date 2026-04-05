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
use App\Models\loans;
use App\Models\leave_master;
use App\Models\time_card;
use App\Models\Roster;
use Carbon\Carbon;
use App\Models\EmployeeBonus;
use App\Models\SalaryProcessAudit;

class SalaryProcessController extends Controller
{
    private function getLateMinutes(?string $inTime, ?string $shiftStartTime): int
    {
        if (!$inTime || !$shiftStartTime) return 0;
        $in = strtotime($inTime);
        $shiftStart = strtotime($shiftStartTime);
        if ($in === false || $shiftStart === false || $in <= $shiftStart) return 0;
        return (int) floor(($in - $shiftStart) / 60);
    }

    private function getApprovedLeaveInfoForDate(int $employeeId, string $date): array
    {
        $leave = leave_master::where('employee_id', $employeeId)
            ->whereIn('status', ['Approved', 'HR_Approved'])
            ->where(function ($query) use ($date) {
                $query->whereDate('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->whereDate('leave_from', '<=', $date)->whereDate('leave_to', '>=', $date);
                    });
            })
            ->orderByRaw("CASE WHEN status = 'Approved' THEN 1 WHEN status = 'HR_Approved' THEN 2 ELSE 3 END")
            ->first();

        if (!$leave) {
            return ['has_approved_leave' => false, 'is_half_day_leave' => false, 'leave_type' => null, 'leave_status' => null, 'leave_period' => null];
        }

        return ['has_approved_leave' => true, 'is_half_day_leave' => (bool) ($leave->is_half_day ?? false), 'leave_type' => $leave->leave_type, 'leave_status' => $leave->status, 'leave_period' => $leave->period];
    }

    private function getRosterShiftStartTime(int $employeeId, string $date): ?string
    {
        $roster = roster::with('shift')
            ->where('employee_id', $employeeId)
            ->where(function ($q) use ($date) {
                $q->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date);
            })
            ->orderBy('date_from', 'desc')->first();

        return ($roster && $roster->shift && $roster->shift->start_time) ? $roster->shift->start_time : null;
    }

    private function getRosterShiftWorkHours(int $employeeId, string $date, float $defaultHours = 8): float
    {
        $roster = roster::with('shift')
            ->where('employee_id', $employeeId)
            ->where(function ($q) use ($date) {
                $q->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date);
            })
            ->orderBy('date_from', 'desc')->first();

        if (!$roster || !$roster->shift || !$roster->shift->start_time || !$roster->shift->end_time) return $defaultHours;

        $start = strtotime($roster->shift->start_time);
        $end = strtotime($roster->shift->end_time);
        if ($end <= $start) $end = strtotime('+1 day', $end);

        $hours = ($end - $start) / 3600;
        return $hours > 0 ? round($hours, 2) : $defaultHours;
    }

    private function calculateLateDeductionData(int $employeeId, string $startDate, string $endDate, float $perDaySalary): array 
    {
        $cardsByDate = time_card::where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNull('deleted_at')
            ->orderBy('time', 'asc')
            ->get()->groupBy('date');

        $minorLateCounter = 0; $approvedLeaveLateCount = 0; $noDeductionLateCount = 0;
        $shortLeaveCount = 0; $halfDayCount = 0; $deductibleLateCount = 0;
        $shortLeaveDeductionAmount = 0; $halfDayDeductionAmount = 0;

        foreach ($cardsByDate as $date => $dayCards) {
            $inCard = $dayCards->first(function ($card) {
                $st = strtolower(trim($card->status));
                return $card->entry == 1 || in_array($st, ['in', 'late coming', 'late_coming']);
            });

            if (!$inCard) continue;
            $shiftStartTime = $this->getRosterShiftStartTime($employeeId, $date);
            if (!$shiftStartTime) continue;

            $lateMinutes = $this->getLateMinutes($inCard->time, $shiftStartTime);

            if ($lateMinutes > 0 && $lateMinutes <= 30) {
                $leaveInfo = $this->getApprovedLeaveInfoForDate($employeeId, $date);
                if ($leaveInfo['has_approved_leave']) { $approvedLeaveLateCount++; continue; }

                $minorLateCounter++;
                $shiftHours = $this->getRosterShiftWorkHours($employeeId, $date, 8);
                $hourlyRate = $shiftHours > 0 ? ($perDaySalary / $shiftHours) : 0;

                if ($minorLateCounter <= 3) { $noDeductionLateCount++; } 
                elseif ($minorLateCounter <= 5) {
                    $shortLeaveCount++; $deductibleLateCount++;
                    $shortLeaveDeductionAmount += ($hourlyRate * 2);
                } else {
                    $halfDayCount++; $deductibleLateCount++;
                    $halfDayDeductionAmount += ($hourlyRate * 4);
                }
            }
        }

        return [
            'approved_leave_late_count' => $approvedLeaveLateCount, 'no_deduction_late_count' => $noDeductionLateCount,
            'short_leave_count' => $shortLeaveCount, 'half_day_count' => $halfDayCount, 'deductible_late_count' => $deductibleLateCount,
            'short_leave_deduction' => round($shortLeaveDeductionAmount, 2), 'half_day_deduction' => round($halfDayDeductionAmount, 2),
        ];
    }

    public function index()
    {
        $salary = salary_process::all();
        return response()->json($salary, 200);
    }

    public function getProcessedSalaries(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');

        $processedSalaries = salary_process::whereIn('status', ['processed', 'issued'])
            ->when($month, fn ($query) => $query->where('month', $month))
            ->when($year, fn ($query) => $query->where('year', $year))
            ->with(['employee' => function ($query) {
                $query->select('id', 'full_name', 'attendance_employee_no')->with(['compensation' => function ($q) {
                    $q->select('employee_id', 'basic_salary', 'enable_epf_etf', 'bank_name', 'bank_account_no', 'branch_name');
                }]);
            }])->orderBy('created_at', 'desc')->get();

        $response = $processedSalaries->map(function ($salary) {
            return [
                'id' => $salary->id, 'employee_id' => $salary->employee_id, 'employee_no' => $salary->employee_no,
                'full_name' => $salary->full_name, 'company_name' => $salary->company_name, 'department_name' => $salary->department_name,
                'stamp' => $salary->stamp, 'basic_salary' => $salary->basic_salary, 'ot_morning' => $salary->ot_morning,
                'ot_evening' => $salary->ot_evening, 'month' => $salary->month, 'year' => $salary->year, 'status' => $salary->status,
                'compensation' => $salary->employee->compensation ?? null, 'bank_details' => $salary->employee->bankDetails ?? null,
                'salary_breakdown' => is_string($salary->salary_breakdown) ? json_decode($salary->salary_breakdown, true) : $salary->salary_breakdown,
                'allowances' => is_string($salary->allowances) ? json_decode($salary->allowances, true) : $salary->allowances,
                'deductions' => is_string($salary->deductions) ? json_decode($salary->deductions, true) : $salary->deductions,
                'bonuses' => is_string($salary->bonuses) ? json_decode($salary->bonuses, true) : $salary->bonuses,
            ];
        });

        return response()->json($response);
    }

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
                
                COALESCE(lo.loan_amount, 0) AS total_loan_amount,
                lo.installment_count AS installment_count,
                lo.installment_amount AS installment_amount,
                lo.status AS loan_status,
                lo.schedule AS loan_schedule,
                lo.deduct_from AS loan_deduct_from,
                lo.with_interest AS with_interest,
                lo.interest_rate_per_annum AS interest_rate_per_annum,

                COALESCE(SUM(CASE WHEN LOWER(TRIM(REPLACE(npr.type, ' ', '_'))) IN ('full_day', 'partial_absent') AND DAYNAME(npr.date) != 'Saturday' THEN COALESCE(npr.no_pay_count, 1) ELSE 0 END), 0) AS weekday_nopays,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(REPLACE(npr.type, ' ', '_'))) IN ('full_day', 'partial_absent') AND DAYNAME(npr.date) = 'Saturday' THEN COALESCE(npr.no_pay_count, 1) ELSE 0 END), 0) AS saturday_nopays,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(REPLACE(npr.type, ' ', '_'))) = 'early_out' THEN COALESCE(npr.no_pay_count, 1) ELSE 0 END), 0) AS early_out_nopays,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(REPLACE(npr.type, ' ', '_'))) = 'late_in' THEN COALESCE(npr.no_pay_count, 1) ELSE 0 END), 0) AS major_late_nopays,

                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', a.id, ',\"name\":\"', REPLACE(IFNULL(a.allowance_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ea.custom_amount, a.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(a.allowance_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(a.category, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_allowances ea JOIN allowances a ON a.id = ea.allowance_id
                    WHERE ea.employee_id = e.id AND ea.is_active = 1 AND a.status = 'active' AND (ea.month = ? AND ea.year = ?)
                ) AS allowances,

                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', dd.id, ',\"name\":\"', REPLACE(IFNULL(dd.deduction_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ed.custom_amount, dd.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(dd.deduction_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(dd.category, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_deductions ed JOIN deductions dd ON dd.id = ed.deduction_id
                    WHERE ed.employee_id = e.id AND ed.is_active = 1 AND dd.status = 'active' AND (ed.month = ? AND ed.year = ?)
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
            LEFT JOIN no_pay_records npr ON e.id = npr.employee_id AND LOWER(TRIM(npr.status)) = 'approved' AND npr.date BETWEEN ? AND ?

            WHERE e.is_active = '1'
        ";

        $params = [$month, $year, $month, $year, $month, $year, $startDate, $endDate];

        if ($company_id) { $query .= " AND oa.company_id = ? "; $params[] = $company_id; }
        if ($department_id) { $query .= " AND oa.department_id = ? "; $params[] = $department_id; }
        if ($search) { $query .= " AND (e.attendance_employee_no LIKE ? OR e.full_name LIKE ?) "; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }

        $query .= " GROUP BY e.id, e.attendance_employee_no, e.full_name, e.nic, c.name, d.name, sd.name, comp.basic_salary, comp.br1, comp.br2, comp.increment_active, comp.increment_value, comp.increment_effected_date, comp.ot_morning, comp.ot_evening, comp.enable_epf_etf, comp.stamp, comp.bank_name, comp.branch_name, comp.bank_account_no, c.id, oa.department_id, oa.probationary_period, oa.date_of_joining, e.epf, cd.permanent_address, cd.mobile_line, cd.emg_name, cd.emg_relationship, cd.emg_tel, lo.loan_amount, lo.installment_count, lo.installment_amount, lo.status, lo.schedule, lo.deduct_from, lo.with_interest, lo.interest_rate_per_annum";

        $results = DB::select($query, $params);
        $data = [];

        $formattedMonth = str_pad($month, 2, '0', STR_PAD_LEFT);
        $savedSalaries = \App\Models\salary_process::where('month', $formattedMonth)
            ->where('year', $year)
            ->get()
            ->keyBy('employee_id');

        foreach ($results as $result) {
            $employeeData = (array)$result;

            $employeeData['compensation'] = [
                'bank_name' => $result->bank_name ?? null,
                'branch_name' => $result->branch_name ?? null,
                'bank_account_no' => $result->bank_account_no ?? null,
            ];

            if ($savedSalaries->has($result->id)) {
                $saved = $savedSalaries->get($result->id);
                $employeeData['status'] = $saved->status;
                $employeeData['basic_salary'] = (float)$saved->basic_salary;
                $employeeData['ot_morning'] = $saved->ot_morning;
                $employeeData['ot_evening'] = $saved->ot_evening;
                $employeeData['enable_epf_etf'] = $saved->enable_epf_etf;
                $employeeData['br1'] = $saved->br1;
                $employeeData['br2'] = $saved->br2;
                $employeeData['stamp'] = $saved->stamp ? 25 : 0;
                $employeeData['total_loan_amount'] = $saved->total_loan_amount;
                $employeeData['installment_count'] = $saved->installment_count;
                $employeeData['installment_amount'] = $saved->installment_amount;
                $employeeData['approved_no_pay_days'] = (float)$saved->approved_no_pay_days;
                
                $bd = is_string($saved->salary_breakdown) ? json_decode($saved->salary_breakdown, true) : ($saved->salary_breakdown ?? []);

                // 🔥 OT AUTO-SYNC FIX 🔥
                // පඩිය Issued කරලා නැත්නම්, දැනට Approve කරලා තියෙන අලුත් OT මුදල් ටික ආයෙත් ඇදලා අරන් Sync කරනවා.
                if ($saved->status !== 'issued') {
                    $freshOtRows = over_time::where('employee_id', $result->id)
                        ->whereRaw('LOWER(status) = ?', ['approved'])
                        ->whereHas('timeCard', function ($q) use ($startDate, $endDate) { 
                            $q->whereBetween('date', [$startDate, $endDate]); 
                        })->get();

                    $mFees = ((int)($saved->ot_morning ?? 0) === 1) ? ((float)$freshOtRows->sum('morning_ot_amount') + (float)$freshOtRows->sum('morning_ot_special_amount')) : 0.0;
                    $nFees = ((int)($saved->ot_evening ?? 0) === 1) ? ((float)$freshOtRows->sum('evening_ot_amount') + (float)$freshOtRows->sum('evening_ot_special_amount')) : 0.0;
                    $hFees = (float)$freshOtRows->sum('holiday_ot_amount');

                    // කලින් සේව් වුණු OT වලට වඩා වෙනස් නම් Breakdown එක Update කරනවා
                    $oldMFees = (float)($bd['ot_morning_fees'] ?? 0);
                    $oldNFees = (float)($bd['ot_night_fees'] ?? 0);
                    $oldHFees = (float)($bd['holiday_ot_fees'] ?? 0);

                    if ($mFees != $oldMFees || $nFees != $oldNFees || $hFees != $oldHFees) {
                        $diff = ($mFees - $oldMFees) + ($nFees - $oldNFees) + ($hFees - $oldHFees);
                        
                        $bd['ot_morning_fees'] = round($mFees, 2);
                        $bd['ot_night_fees'] = round($nFees, 2);
                        $bd['holiday_ot_fees'] = round($hFees, 2);
                        $bd['ot_morning_hours'] = round((float)$freshOtRows->sum('morning_ot') + (float)$freshOtRows->sum('morning_ot_special'), 2);
                        $bd['ot_night_hours'] = round((float)$freshOtRows->sum('afternoon_ot') + (float)$freshOtRows->sum('evening_ot_special'), 2);
                        $bd['holiday_ot_hours'] = round((float)$freshOtRows->sum('holiday_ot_hours'), 2);
                        
                        $bd['gross_salary'] = (float)($bd['gross_salary'] ?? 0) + $diff;
                        $bd['net_salary'] = (float)($bd['net_salary'] ?? 0) + $diff;
                    }
                }

                $employeeData['salary_breakdown'] = $bd;
                $employeeData['allowances'] = is_string($saved->allowances) ? json_decode($saved->allowances, true) : $saved->allowances;
                $employeeData['deductions'] = is_string($saved->deductions) ? json_decode($saved->deductions, true) : $saved->deductions;
                $employeeData['bonuses'] = is_string($saved->bonuses) ? json_decode($saved->bonuses, true) : $saved->bonuses;
                
            } else {
                // සේව් කරලා නැති අලුත් සේවකයින් සඳහා ගණනය කිරීම්
                $allowancesArr = json_decode($result->allowances ?? '[]', true) ?: [];
                $deductionsArr = json_decode($result->deductions ?? '[]', true) ?: [];
                $bonusesArr = json_decode($result->bonuses ?? '[]', true) ?: [];

                $stampValue = ((int)($result->stamp ?? 0) === 1) ? 25 : 0;
                $basicSalary = (float)($employeeData['basic_salary'] ?? 0);
                $brAllowance = 0;

                if ((int)$result->br1 === 1 && (int)$result->br2 === 1) { $brAllowance = 3500; } 
                elseif ((int)$result->br1 === 1) { $brAllowance = 1000; } 
                elseif ((int)$result->br2 === 1) { $brAllowance = 2500; }
                
                $basicSalary += $brAllowance;

                $companyLeavesCount = DB::table('leave_calendars')->where('company_id', $company_id ?? 0)
                    ->where(function ($q) use ($startDate, $endDate) { 
                        $q->whereBetween('start_date', [$startDate, $endDate])->orWhereBetween('end_date', [$startDate, $endDate]); 
                    })->count();
                
                $workingDaysInMonth = max(1, $totalDaysInMonth - $companyLeavesCount);
                $perDaySalary = $basicSalary / $workingDaysInMonth;

                $weekdayNoPays = (float)($employeeData['weekday_nopays'] ?? 0);
                $fullDayNoPayDeduction = round($weekdayNoPays * $perDaySalary, 2);

                $otRows = over_time::where('employee_id', $employeeData['id'])
                    ->whereRaw('LOWER(status) = ?', ['approved'])
                    ->whereHas('timeCard', function ($q) use ($startDate, $endDate) { 
                        $q->whereBetween('date', [$startDate, $endDate]); 
                    })->get();

                $morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1) ? ((float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount')) : 0.0;
                $night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1) ? ((float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount')) : 0.0;
                $holiday_ot_fees = (float)$otRows->sum('holiday_ot_amount');

                $totalAllowances = array_reduce($allowancesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);
                $epfEtfBase = $basicSalary + $totalAllowances;
                $epfEmployeeDeduction = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.08) : 0;
                
                $grossSalary = $epfEtfBase + $morning_ot_fees + $night_ot_fees + $holiday_ot_fees;
                $netSalary = $grossSalary - ($epfEmployeeDeduction + $fullDayNoPayDeduction + $stampValue);

                $employeeData['salary_breakdown'] = [
                    'basic_salary' => round($basicSalary, 2),
                    'ot_morning_fees' => round($morning_ot_fees, 2),
                    'ot_night_fees' => round($night_ot_fees, 2),
                    'holiday_ot_fees' => round($holiday_ot_fees, 2),
                    'net_salary' => round($netSalary, 2),
                    'gross_salary' => round($grossSalary, 2),
                    'per_day_salary' => round($perDaySalary, 3),
                    // ... (අනිත් Breakdown දත්ත)
                ];
                $employeeData['allowances'] = $allowancesArr;
                $employeeData['deductions'] = $deductionsArr;
                $employeeData['bonuses'] = $bonusesArr;
            }

            $data[] = $employeeData;
        }

        return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }

    public function update(Request $request, string $id)
    {
        try {
            DB::beginTransaction();
            $salaryRecord = salary_process::where('employee_id', $id)->where('month', $request->month)->where('year', $request->year)->first();

            if (!$salaryRecord) {
                $salaryRecord = new salary_process();
                $salaryRecord->employee_id = $id;
            }

            $basicSalary = (float) $request->basic_salary;
            $frontendBreakdown = is_string($request->salary_breakdown) ? json_decode($request->salary_breakdown, true) : ($request->salary_breakdown ?? []);

            // 🔥 HOLIDAY OT UPDATE FIX 🔥
            // Manual Update කරන විට Holiday OT අතුරුදහන් වීම වැළැක්වීම
            $morningOtFees = (float) $request->ot_morning;
            $nightOtFees = (float) $request->ot_evening;
            $holidayOtFees = (float) ($frontendBreakdown['holiday_ot_fees'] ?? 0);

            $totalAllowances = 0;
            foreach (($request->allowances ?? []) as $allw) { $totalAllowances += (float)($allw['amount'] ?? 0); }
            
            $totalFixedDeductions = 0;
            foreach (($request->deductions ?? []) as $ded) { $totalFixedDeductions += (float)($ded['amount'] ?? 0); }

            $perDaySalary = $basicSalary / (cal_days_in_month(CAL_GREGORIAN, (int)$request->month, (int)$request->year) - 8);
            $noPayDeduction = (float)$request->approved_no_pay_days * $perDaySalary;
            
            $epfEtfBase = ($basicSalary - $noPayDeduction) + $totalAllowances;
            $epfEmployeeDeduction = $request->enable_epf_etf ? $epfEtfBase * 0.08 : 0;
            $stampValue = $request->stamp ? 25 : 0;

            // Gross Salary එකට Holiday OT එකතු කිරීම
            $grossSalary = $epfEtfBase + $morningOtFees + $nightOtFees + $holidayOtFees;
            $totalDeductions = $totalFixedDeductions + (float)($request->installment_amount ?? 0) + $epfEmployeeDeduction + $stampValue;
            $netSalary = $grossSalary - $totalDeductions;

            $updatedSalaryBreakdown = array_merge($frontendBreakdown, [
                'basic_salary' => $basicSalary,
                'ot_morning_fees' => $morningOtFees,
                'ot_night_fees' => $nightOtFees,
                'holiday_ot_fees' => $holidayOtFees,
                'gross_salary' => round($grossSalary, 2),
                'net_salary' => round($netSalary, 2),
                'total_deductions' => round($totalDeductions, 2)
            ]);

            $salaryRecord->fill($request->all());
            $salaryRecord->salary_breakdown = $updatedSalaryBreakdown;
            $salaryRecord->save();

            DB::commit();
            return response()->json(['message' => 'Salary record updated successfully', 'data' => $salaryRecord], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating salary record: ' . $e->getMessage()], 500);
        }
    }

    public function storeSalaryData(Request $request)
    {
        $validated = $request->validate(['data' => 'required|array']);
        try {
            $month = str_pad($request->month ?? $request->data[0]['month'], 2, '0', STR_PAD_LEFT);
            $year = $request->year ?? date('Y');

            foreach ($request->data as $employeeData) {
                $empNo = $employeeData['emp_no'] ?? $employeeData['employee_no'] ?? null;
                $empId = $employeeData['id'] ?? $employeeData['employee_id'] ?? null;
                if (!$empNo || !$empId) continue; 

                salary_process::updateOrCreate(
                    ['employee_no' => $empNo, 'month' => $month, 'year' => $year],
                    [
                        'employee_id' => $empId,
                        'full_name' => $employeeData['full_name'] ?? 'Unknown',
                        'basic_salary' => $employeeData['basic_salary'] ?? 0,
                        'ot_morning' => $employeeData['ot_morning'] ?? 0,
                        'ot_evening' => $employeeData['ot_evening'] ?? 0,
                        'allowances' => is_array($employeeData['allowances'] ?? null) ? json_encode($employeeData['allowances']) : ($employeeData['allowances'] ?? '[]'),
                        'deductions' => is_array($employeeData['deductions'] ?? null) ? json_encode($employeeData['deductions']) : ($employeeData['deductions'] ?? '[]'),
                        'salary_breakdown' => is_array($employeeData['salary_breakdown'] ?? null) ? json_encode($employeeData['salary_breakdown']) : ($employeeData['salary_breakdown'] ?? '{}'),
                        'status' => 'pending', 
                    ]
                );
            }
            return response()->json(['message' => 'All Salary data saved successfully!'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error saving salary data: ' . $e->getMessage()], 500);
        }
    }

    public function updateSlaryStatus(Request $request)
    {
        DB::beginTransaction();
        try {
            if ($request->has('status') && $request->status == 'issued') {
                $salaryProcesses = salary_process::where('status', 'processed')->get();
                foreach ($salaryProcesses as $process) {
                    $loan = loans::where('employee_id', $process->employee_id)->where('status', 'active')->first();
                    if ($loan) {
                        $newInstallmentCount = max(0, (int)$loan->installment_count - 1);
                        $loan->installment_count = $newInstallmentCount;
                        $loan->status = $newInstallmentCount == 0 ? 'completed' : 'active';
                        $loan->save();
                    }
                }
                salary_process::where('status', 'processed')->update(['status' => 'issued']);
                DB::commit();
                return response()->json(['message' => 'Salaries marked as issued'], 200);
            }
            return response()->json(['message' => 'Invalid status'], 400);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
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
use App\Models\loans;
use App\Models\leave_master;
use App\Models\time_card;
use App\Models\Roster;
use Carbon\Carbon;
use App\Models\EmployeeBonus;
use App\Models\SalaryProcessAudit;

class SalaryProcessController extends Controller
{
    private function getLateMinutes(?string $inTime, ?string $shiftStartTime): int
    {
        if (!$inTime || !$shiftStartTime) return 0;
        $in = strtotime($inTime);
        $shiftStart = strtotime($shiftStartTime);
        if ($in === false || $shiftStart === false || $in <= $shiftStart) return 0;
        return (int) floor(($in - $shiftStart) / 60);
    }

    private function getApprovedLeaveInfoForDate(int $employeeId, string $date): array
    {
        $leave = leave_master::where('employee_id', $employeeId)
            ->whereIn('status', ['Approved', 'HR_Approved'])
            ->where(function ($query) use ($date) {
                $query->whereDate('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->whereDate('leave_from', '<=', $date)->whereDate('leave_to', '>=', $date);
                    });
            })
            ->orderByRaw("CASE WHEN status = 'Approved' THEN 1 WHEN status = 'HR_Approved' THEN 2 ELSE 3 END")
            ->first();

        if (!$leave) {
            return ['has_approved_leave' => false, 'is_half_day_leave' => false, 'leave_type' => null, 'leave_status' => null, 'leave_period' => null];
        }

        return ['has_approved_leave' => true, 'is_half_day_leave' => (bool) ($leave->is_half_day ?? false), 'leave_type' => $leave->leave_type, 'leave_status' => $leave->status, 'leave_period' => $leave->period];
    }

    private function getRosterShiftStartTime(int $employeeId, string $date): ?string
    {
        $roster = roster::with('shift')
            ->where('employee_id', $employeeId)
            ->where(function ($q) use ($date) {
                $q->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date);
            })
            ->orderBy('date_from', 'desc')->first();

        return ($roster && $roster->shift && $roster->shift->start_time) ? $roster->shift->start_time : null;
    }

    private function getRosterShiftWorkHours(int $employeeId, string $date, float $defaultHours = 8): float
    {
        $roster = roster::with('shift')
            ->where('employee_id', $employeeId)
            ->where(function ($q) use ($date) {
                $q->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date);
            })
            ->orderBy('date_from', 'desc')->first();

        if (!$roster || !$roster->shift || !$roster->shift->start_time || !$roster->shift->end_time) return $defaultHours;

        $start = strtotime($roster->shift->start_time);
        $end = strtotime($roster->shift->end_time);
        if ($end <= $start) $end = strtotime('+1 day', $end);

        $hours = ($end - $start) / 3600;
        return $hours > 0 ? round($hours, 2) : $defaultHours;
    }

    private function calculateLateDeductionData(int $employeeId, string $startDate, string $endDate, float $perDaySalary): array 
    {
        $cardsByDate = time_card::where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNull('deleted_at')
            ->orderBy('time', 'asc')
            ->get()->groupBy('date');

        $minorLateCounter = 0; $approvedLeaveLateCount = 0; $noDeductionLateCount = 0;
        $shortLeaveCount = 0; $halfDayCount = 0; $deductibleLateCount = 0;
        $shortLeaveDeductionAmount = 0; $halfDayDeductionAmount = 0;

        foreach ($cardsByDate as $date => $dayCards) {
            $inCard = $dayCards->first(function ($card) {
                $st = strtolower(trim($card->status));
                return $card->entry == 1 || in_array($st, ['in', 'late coming', 'late_coming']);
            });

            if (!$inCard) continue;
            $shiftStartTime = $this->getRosterShiftStartTime($employeeId, $date);
            if (!$shiftStartTime) continue;

            $lateMinutes = $this->getLateMinutes($inCard->time, $shiftStartTime);

            if ($lateMinutes > 0 && $lateMinutes <= 30) {
                $leaveInfo = $this->getApprovedLeaveInfoForDate($employeeId, $date);
                if ($leaveInfo['has_approved_leave']) { $approvedLeaveLateCount++; continue; }

                $minorLateCounter++;
                $shiftHours = $this->getRosterShiftWorkHours($employeeId, $date, 8);
                $hourlyRate = $shiftHours > 0 ? ($perDaySalary / $shiftHours) : 0;

                if ($minorLateCounter <= 3) { $noDeductionLateCount++; } 
                elseif ($minorLateCounter <= 5) {
                    $shortLeaveCount++; $deductibleLateCount++;
                    $shortLeaveDeductionAmount += ($hourlyRate * 2);
                } else {
                    $halfDayCount++; $deductibleLateCount++;
                    $halfDayDeductionAmount += ($hourlyRate * 4);
                }
            }
        }

        return [
            'approved_leave_late_count' => $approvedLeaveLateCount, 'no_deduction_late_count' => $noDeductionLateCount,
            'short_leave_count' => $shortLeaveCount, 'half_day_count' => $halfDayCount, 'deductible_late_count' => $deductibleLateCount,
            'short_leave_deduction' => round($shortLeaveDeductionAmount, 2), 'half_day_deduction' => round($halfDayDeductionAmount, 2),
        ];
    }

    public function index()
    {
        $salary = salary_process::all();
        return response()->json($salary, 200);
    }

    public function getProcessedSalaries(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');

        $processedSalaries = salary_process::whereIn('status', ['processed', 'issued'])
            ->when($month, fn ($query) => $query->where('month', $month))
            ->when($year, fn ($query) => $query->where('year', $year))
            ->with(['employee' => function ($query) {
                $query->select('id', 'full_name', 'attendance_employee_no')->with(['compensation' => function ($q) {
                    $q->select('employee_id', 'basic_salary', 'enable_epf_etf', 'bank_name', 'bank_account_no', 'branch_name');
                }]);
            }])->orderBy('created_at', 'desc')->get();

        $response = $processedSalaries->map(function ($salary) {
            return [
                'id' => $salary->id, 'employee_id' => $salary->employee_id, 'employee_no' => $salary->employee_no,
                'full_name' => $salary->full_name, 'company_name' => $salary->company_name, 'department_name' => $salary->department_name,
                'stamp' => $salary->stamp, 'basic_salary' => $salary->basic_salary, 'ot_morning' => $salary->ot_morning,
                'ot_evening' => $salary->ot_evening, 'month' => $salary->month, 'year' => $salary->year, 'status' => $salary->status,
                'compensation' => $salary->employee->compensation ?? null, 'bank_details' => $salary->employee->bankDetails ?? null,
                'salary_breakdown' => is_string($salary->salary_breakdown) ? json_decode($salary->salary_breakdown, true) : $salary->salary_breakdown,
                'allowances' => is_string($salary->allowances) ? json_decode($salary->allowances, true) : $salary->allowances,
                'deductions' => is_string($salary->deductions) ? json_decode($salary->deductions, true) : $salary->deductions,
                'bonuses' => is_string($salary->bonuses) ? json_decode($salary->bonuses, true) : $salary->bonuses,
            ];
        });

        return response()->json($response);
    }

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
                
                COALESCE(lo.loan_amount, 0) AS total_loan_amount,
                lo.installment_count AS installment_count,
                lo.installment_amount AS installment_amount,
                lo.status AS loan_status,
                lo.schedule AS loan_schedule,
                lo.deduct_from AS loan_deduct_from,
                lo.with_interest AS with_interest,
                lo.interest_rate_per_annum AS interest_rate_per_annum,

                -- 🔥 Bug Fix: Any spaces or case differences are handled here (LOWER(TRIM(REPLACE))) 🔥
                COALESCE(SUM(CASE WHEN LOWER(TRIM(REPLACE(npr.type, ' ', '_'))) IN ('full_day', 'partial_absent') AND DAYNAME(npr.date) != 'Saturday' THEN COALESCE(npr.no_pay_count, 1) ELSE 0 END), 0) AS weekday_nopays,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(REPLACE(npr.type, ' ', '_'))) IN ('full_day', 'partial_absent') AND DAYNAME(npr.date) = 'Saturday' THEN COALESCE(npr.no_pay_count, 1) ELSE 0 END), 0) AS saturday_nopays,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(REPLACE(npr.type, ' ', '_'))) = 'early_out' THEN COALESCE(npr.no_pay_count, 1) ELSE 0 END), 0) AS early_out_nopays,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(REPLACE(npr.type, ' ', '_'))) = 'late_in' THEN COALESCE(npr.no_pay_count, 1) ELSE 0 END), 0) AS major_late_nopays,

                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', a.id, ',\"name\":\"', REPLACE(IFNULL(a.allowance_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ea.custom_amount, a.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(a.allowance_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(a.category, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_allowances ea JOIN allowances a ON a.id = ea.allowance_id
                    WHERE ea.employee_id = e.id AND ea.is_active = 1 AND a.status = 'active' AND (ea.month = ? AND ea.year = ?)
                ) AS allowances,

                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', dd.id, ',\"name\":\"', REPLACE(IFNULL(dd.deduction_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ed.custom_amount, dd.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(dd.deduction_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(dd.category, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_deductions ed JOIN deductions dd ON dd.id = ed.deduction_id
                    WHERE ed.employee_id = e.id AND ed.is_active = 1 AND dd.status = 'active' AND (ed.month = ? AND ed.year = ?)
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
            
            -- 🔥 Bug Fix: Lowercase and Trim ensure 'Approved' and 'approved' both match 🔥
            LEFT JOIN no_pay_records npr ON e.id = npr.employee_id AND LOWER(TRIM(npr.status)) = 'approved' AND npr.date BETWEEN ? AND ?

            WHERE e.is_active = '1'
        ";

        $params = [$month, $year, $month, $year, $month, $year, $startDate, $endDate];

        if ($company_id) { $query .= " AND oa.company_id = ? "; $params[] = $company_id; }
        if ($department_id) { $query .= " AND oa.department_id = ? "; $params[] = $department_id; }
        if ($search) { $query .= " AND (e.attendance_employee_no LIKE ? OR e.full_name LIKE ?) "; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }

        $query .= " GROUP BY e.id, e.attendance_employee_no, e.full_name, e.nic, c.name, d.name, sd.name, comp.basic_salary, comp.br1, comp.br2, comp.increment_active, comp.increment_value, comp.increment_effected_date, comp.ot_morning, comp.ot_evening, comp.enable_epf_etf, comp.stamp, comp.bank_name, comp.branch_name, comp.bank_account_no, c.id, oa.department_id, oa.probationary_period, oa.date_of_joining, e.epf, cd.permanent_address, cd.mobile_line, cd.emg_name, cd.emg_relationship, cd.emg_tel, lo.loan_amount, lo.installment_count, lo.installment_amount, lo.status, lo.schedule, lo.deduct_from, lo.with_interest, lo.interest_rate_per_annum";

        $results = DB::select($query, $params);
        $data = [];

        $formattedMonth = str_pad($month, 2, '0', STR_PAD_LEFT);
        $savedSalaries = \App\Models\salary_process::where('month', $formattedMonth)
            ->where('year', $year)
            ->get()
            ->keyBy('employee_id');

        foreach ($results as $result) {
            $employeeData = (array)$result;

            $employeeData['compensation'] = [
                'bank_name' => $result->bank_name ?? null,
                'branch_name' => $result->branch_name ?? null,
                'bank_account_no' => $result->bank_account_no ?? null,
            ];

            if ($savedSalaries->has($result->id)) {
                // =================================================================================
                // 🔥 AUTO-SYNC FIX 🔥
                // ඔයා පඩි සේව් කරාට පස්සේ No Pay Approve කරත්, ඒක Auto ඇදලා අරන් අප්ඩේට් කරනවා!
                // =================================================================================
                $saved = $savedSalaries->get($result->id);
                $employeeData['status'] = $saved->status;
                $employeeData['basic_salary'] = (float)$saved->basic_salary;
                $employeeData['increment_active'] = $saved->increment_active;
                $employeeData['increment_value'] = $saved->increment_value;
                $employeeData['increment_effected_date'] = $saved->increment_effected_date;
                $employeeData['ot_morning'] = $saved->ot_morning;
                $employeeData['ot_evening'] = $saved->ot_evening;
                $employeeData['enable_epf_etf'] = $saved->enable_epf_etf;
                $employeeData['br1'] = $saved->br1;
                $employeeData['br2'] = $saved->br2;
                $employeeData['stamp'] = $saved->stamp ? 25 : 0;
                $employeeData['total_loan_amount'] = $saved->total_loan_amount;
                $employeeData['installment_count'] = $saved->installment_count;
                $employeeData['installment_amount'] = $saved->installment_amount;
                
                $employeeData['approved_no_pay_days'] = (float)$saved->approved_no_pay_days;
                
                $bd = is_string($saved->salary_breakdown) ? json_decode($saved->salary_breakdown, true) : ($saved->salary_breakdown ?? []);

                // පඩිය 'issued' කරලා නැත්නම් විතරක් අලුත් No pay ගණන් Auto Sync කරනවා
                if ($saved->status !== 'issued') {
                    $perDaySalary = (float)($bd['per_day_salary'] ?? 0);
                    
                    $freshWeekday = (float)($result->weekday_nopays ?? 0);
                    $freshSat = (float)($result->saturday_nopays ?? 0);
                    $freshEarly = (float)($result->early_out_nopays ?? 0);
                    $freshLate = (float)($result->major_late_nopays ?? 0);
                    
                    $oldWeekdayDed = (float)($bd['full_day_nopay_deduction'] ?? 0);
                    $oldSatDed = (float)($bd['saturday_nopay_deduction'] ?? 0);
                    $oldEarlyDed = (float)($bd['early_out_nopay_deduction'] ?? 0);
                    $oldLateDed = (float)($bd['major_late_deduction'] ?? 0);
                    
                    $newWeekdayDed = round($freshWeekday * $perDaySalary, 2);
                    $newSatDed = round($freshSat * $perDaySalary, 2);
                    $newEarlyDed = round($freshEarly * $perDaySalary, 2);
                    $newLateDed = round($freshLate * $perDaySalary, 2);
                    
                    $diff = ($newWeekdayDed - $oldWeekdayDed) + ($newSatDed - $oldSatDed) + ($newEarlyDed - $oldEarlyDed) + ($newLateDed - $oldLateDed);
                    
                    // යම්කිසි වෙනසක් තියෙනවා නම් ඒක අලුත් කරනවා
                    if ($diff != 0 || $freshWeekday != (float)$saved->approved_no_pay_days) {
                        $employeeData['approved_no_pay_days'] = $freshWeekday;
                        
                        $bd['full_day_nopay_deduction'] = $newWeekdayDed;
                        $bd['no_pay_deduction'] = $newWeekdayDed; 
                        $bd['saturday_nopay_deduction'] = $newSatDed;
                        $bd['early_out_nopay_deduction'] = $newEarlyDed;
                        $bd['major_late_deduction'] = $newLateDed;
                        
                        $bd['total_deductions'] = (float)($bd['total_deductions'] ?? 0) + $diff;
                        $bd['net_salary'] = (float)($bd['net_salary'] ?? 0) - $diff;
                    }
                }

                $employeeData['salary_breakdown'] = $bd;
                $employeeData['allowances'] = is_string($saved->allowances) ? json_decode($saved->allowances, true) : $saved->allowances;
                $employeeData['deductions'] = is_string($saved->deductions) ? json_decode($saved->deductions, true) : $saved->deductions;
                $employeeData['bonuses'] = is_string($saved->bonuses) ? json_decode($saved->bonuses, true) : $saved->bonuses;
                
            } else {
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

                $installmentAmount = 0.0;
                $loanInterest = 0.0;
                $loanPrincipal = 0.0;
                $loanDeductFrom = $employeeData['loan_deduct_from'] ?? 'bonus'; 
                $loanStatus = strtolower((string)($employeeData['loan_status'] ?? ''));
                $isLoanApplicableForThisMonth = false;

                if ($loanStatus === 'active') {
                    $schedule = $employeeData['loan_schedule'] ?? null;
                    if (is_string($schedule)) { $schedule = is_array(json_decode($schedule, true)) ? json_decode($schedule, true) : null; }
                    
                    if (is_array($schedule) && count($schedule) > 0) {
                        foreach ($schedule as $r) {
                            $due = $r['due_date'] ?? $r['dueDate'] ?? null;
                            if ($due && date('Y-m', strtotime($due)) === $selectedMonthYear) {
                                $installmentAmount = (float)($r['installment_amount'] ?? $r['installmentAmount'] ?? 0);
                                $isLoanApplicableForThisMonth = true;
                                break;
                            }
                        }
                    } else {
                        if (((int)($employeeData['installment_count'] ?? 0) > 0)) {
                            $installmentAmount = (float)($employeeData['installment_amount'] ?? 0);
                            $isLoanApplicableForThisMonth = true;
                        }
                    }

                    if ($isLoanApplicableForThisMonth) {
                        if ($employeeData['with_interest'] && $employeeData['interest_rate_per_annum'] > 0) {
                            $loanAmountTotal = (float)($employeeData['total_loan_amount'] ?? 0);
                            $loanInterest = ($loanAmountTotal * ((float)$employeeData['interest_rate_per_annum'] / 100)) / 12;
                            if ($loanInterest > $installmentAmount) { $loanInterest = $installmentAmount; }
                        }
                        $loanPrincipal = $installmentAmount - $loanInterest;
                    } else {
                        $employeeData['total_loan_amount'] = 0;
                        $employeeData['installment_count'] = 0;
                        $employeeData['installment_amount'] = 0;
                    }
                } else {
                    $employeeData['total_loan_amount'] = 0;
                    $employeeData['installment_count'] = 0;
                    $employeeData['installment_amount'] = 0;
                }

                $companyLeavesCount = DB::table('leave_calendars')->where('company_id', $company_id ?? 0)
                    ->where(function ($q) use ($startDate, $endDate) { 
                        $q->whereBetween('start_date', [$startDate, $endDate])->orWhereBetween('end_date', [$startDate, $endDate]); 
                    })->count();
                
                $workingDaysInMonth = max(1, $totalDaysInMonth - $companyLeavesCount);
                $perDaySalary = $basicSalary / $workingDaysInMonth;

                $weekdayNoPays = (float)($employeeData['weekday_nopays'] ?? 0);
                $employeeData['approved_no_pay_days'] = $weekdayNoPays;

                $saturdayNoPays = (float)($employeeData['saturday_nopays'] ?? 0);
                $earlyOutNoPays = (float)($employeeData['early_out_nopays'] ?? 0);
                $majorLateNoPays = (float)($employeeData['major_late_nopays'] ?? 0);
                
                $fullDayNoPayDeduction = round($weekdayNoPays * $perDaySalary, 2);
                $saturdayNoPayBonusDeduction = round($saturdayNoPays * $perDaySalary, 2);
                
                $earlyOutNoPayDeduction = round($earlyOutNoPays * $perDaySalary, 2);
                $majorLateDeduction = round($majorLateNoPays * $perDaySalary, 2);

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

                $minorLateData = $this->calculateLateDeductionData((int)$employeeData['id'], $startDate, $endDate, $perDaySalary);
                $shortLeaveDeduction = $minorLateData['short_leave_deduction'] ?? 0;
                $halfDayDeduction = $minorLateData['half_day_deduction'] ?? 0;

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
                
                $otRows = over_time::with('timeCard:id,date,time,actual_date')->where('employee_id', $employeeData['id'])->whereRaw('LOWER(status) = ?', ['approved'])->whereHas('timeCard', function ($q) use ($startDate, $endDate) { $q->whereBetween('date', [$startDate, $endDate]); })->get();
                $morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1) ? ((float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount')) : 0.0;
                $night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1) ? ((float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount')) : 0.0;
                $holiday_ot_fees = (float)$otRows->sum('holiday_ot_amount');

                $basicGross = $basicSalary + $totalAllowances;
                $bonusGross = $totalBonuses;

                $basicDeductionsTotal = $epfEmployeeDeduction + $fullDayNoPayDeduction + $probationDeduction;
                $bonusDeductionsTotal = $saturdayNoPayBonusDeduction + $earlyOutNoPayDeduction + $shortLeaveDeduction + $halfDayDeduction + $majorLateDeduction + $totalFixedDeductions;

                if ($loanDeductFrom === 'basic') { $basicDeductionsTotal += $loanPrincipal; } else { $bonusDeductionsTotal += $loanPrincipal; }
                $bonusDeductionsTotal += $loanInterest;

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
                    'full_day_nopay_deduction' => round($fullDayNoPayDeduction, 2),
                    'no_pay_deduction' => round($fullDayNoPayDeduction, 2), 
                    'saturday_nopay_deduction' => round($saturdayNoPayBonusDeduction, 2), 
                    'early_out_nopay_deduction' => round($earlyOutNoPayDeduction, 2),
                    'short_leave_deduction' => round($shortLeaveDeduction, 2),
                    'half_day_deduction' => round($halfDayDeduction, 2),
                    'major_late_deduction' => round($majorLateDeduction, 2),
                    'epf_employee_deduction' => round($epfEmployeeDeduction, 2),
                    'probation_deduction' => round($probationDeduction, 2),
                    'stamp_duty' => $stampValue,
                    'loan_principal' => round($loanPrincipal, 2),
                    'loan_interest' => round($loanInterest, 2),
                    'loan_deduct_from' => $loanDeductFrom,
                    'loan_installment' => round($installmentAmount, 2),
                    'total_fixed_deductions' => round($totalFixedDeductions, 2),
                    'net_salary' => round($netSalary, 2),
                    'gross_salary' => round($grossSalary, 2),
                    'total_deductions' => round($totalDeductions, 2),
                ];
            }

            $data[] = $employeeData;
        }

        return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }

    public function update(Request $request, string $id)
    {
        try {
            DB::beginTransaction();

            $salaryRecord = salary_process::where('employee_id', $id)
                ->where('month', $request->month)
                ->where('year', $request->year)
                ->first();

            $originalData = [];

            if (!$salaryRecord) {
                $empDetails = \Illuminate\Support\Facades\DB::table('employees as e')
                    ->select(
                        'e.attendance_employee_no', 'e.full_name',
                        'c.name as company_name',
                        'd.name as department_name',
                        'sd.name as sub_department_name'
                    )
                    ->leftJoin('organization_assignments as oa', 'e.organization_assignment_id', '=', 'oa.id')
                    ->leftJoin('companies as c', 'oa.company_id', '=', 'c.id')
                    ->leftJoin('departments as d', 'oa.department_id', '=', 'd.id')
                    ->leftJoin('sub_departments as sd', 'oa.sub_department_id', '=', 'sd.id')
                    ->where('e.id', $id)
                    ->first();

                if (!$empDetails) {
                    return response()->json(['message' => 'Employee details not found!'], 404);
                }

                $salaryRecord = new salary_process();
                $salaryRecord->employee_id = $id;
                $salaryRecord->employee_no = $empDetails->attendance_employee_no ?? '-';
                $salaryRecord->full_name = $empDetails->full_name ?? 'Unknown';
                
                $salaryRecord->company_name = $empDetails->company_name ?? '-';
                $salaryRecord->department_name = $empDetails->department_name ?? '-';
                $salaryRecord->sub_department_name = $empDetails->sub_department_name ?? null;
            } else {
                $originalData = $salaryRecord->toArray();
            }

            $basicSalary = (float) $request->basic_salary;

            $brAllowance = 0;
            if ($request->br1 && $request->br2) {
                $brAllowance = 3500; $brStatus = 'Both BR1 and BR2';
            } elseif ($request->br1) {
                $brAllowance = 1000; $brStatus = 'BR1 Only';
            } elseif ($request->br2) {
                $brAllowance = 2500; $brStatus = 'BR2 Only';
            } else {
                $brStatus = 'None';
            }

            $year = $request->year;
            $month = $request->month;
            $totalDaysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
            $workingDaysInMonth = $totalDaysInMonth - 8; 

            $perDaySalary = $basicSalary / $workingDaysInMonth;
            $noPayDeduction = $request->approved_no_pay_days * $perDaySalary;
            $adjustedBasic = $basicSalary - $noPayDeduction;

            $allowances = $request->allowances ?? [];
            if (is_string($allowances)) {
                $decoded = json_decode($allowances, true);
                $allowances = is_array($decoded) ? $decoded : [];
            }
            $totalAllowances = 0;
            foreach ($allowances as $allowance) {
                $totalAllowances += (float)($allowance['amount'] ?? 0);
            }

            $epfEtfBase = $adjustedBasic + $totalAllowances;
            $epfEmployeeDeduction = $request->enable_epf_etf ? $epfEtfBase * 0.08 : 0;
            $epfEmployerContribution = $request->enable_epf_etf ? $epfEtfBase * 0.12 : 0;
            $etfEmployerContribution = $request->enable_epf_etf ? $epfEtfBase * 0.03 : 0;

            $deductions = $request->deductions ?? [];
            if (is_string($deductions)) {
                $decoded = json_decode($deductions, true);
                $deductions = is_array($decoded) ? $decoded : [];
            }
            $totalFixedDeductions = 0;
            foreach ($deductions as $deduction) {
                $totalFixedDeductions += (float)($deduction['amount'] ?? 0);
            }

            $stampValue = $request->stamp ? 25 : 0;
            $morningOtFees = (float) $request->ot_morning;
            $nightOtFees = (float) $request->ot_evening;
            
            $frontendBreakdown = is_string($request->salary_breakdown) 
                ? json_decode($request->salary_breakdown, true) 
                : ($request->salary_breakdown ?? []);

            $loanDeductFrom = $frontendBreakdown['loan_deduct_from'] ?? 'bonus';

            $grossSalary = $epfEtfBase + $morningOtFees + $nightOtFees;
            $totalDeductions = $totalFixedDeductions + ((float)($request->installment_amount ?? 0)) + $epfEmployeeDeduction;
            $netSalary = $grossSalary - $totalDeductions - $stampValue;

            $oldBreakdown = is_string($salaryRecord->salary_breakdown) ? json_decode($salaryRecord->salary_breakdown, true) : ($salaryRecord->salary_breakdown ?? []);
            $loanDeductFrom = $oldBreakdown['loan_deduct_from'] ?? 'bonus';
            $loanInterest = $oldBreakdown['loan_interest'] ?? 0;
            $newInstallment = (float)($request->installment_amount ?? 0);

            $updatedSalaryBreakdown = [
                'basic_salary' => $basicSalary,
                'br_allowance' => $brAllowance,
                'ot_morning_fees' => $morningOtFees,
                'ot_night_fees' => $nightOtFees,
                'adjusted_basic' => $adjustedBasic,
                'per_day_salary' => $perDaySalary,
                'no_pay_deduction' => $noPayDeduction,
                'full_day_nopay_deduction' => $noPayDeduction,
                'total_allowances' => $totalAllowances,
                'epf_etf_base' => $epfEtfBase,
                'epf_employee_deduction' => $epfEmployeeDeduction,
                'epf_employer_contribution' => $epfEmployerContribution,
                'etf_employer_contribution' => $etfEmployerContribution,
                'total_fixed_deductions' => $totalFixedDeductions,
                'loan_installment' => $newInstallment,
                'loan_principal'   => $newInstallment, 
                'loan_interest'    => 0, 
                'loan_deduct_from' => $loanDeductFrom,
                'gross_salary' => $grossSalary,
                'total_deductions' => $totalDeductions,
                'stamp' => $stampValue,
                'net_salary' => $netSalary
            ];

            $updatedData = [
                'basic_salary' => $request->basic_salary,
                'increment_active' => (bool)$request->increment_active,
                'increment_value' => $request->increment_value,
                'increment_effected_date' => $request->increment_effected_date,
                'ot_morning' => $request->ot_morning,
                'ot_evening' => $request->ot_evening,
                'enable_epf_etf' => (bool)$request->enable_epf_etf,
                'br1' => (bool)$request->br1,
                'br2' => (bool)$request->br2,
                'br_status' => $brStatus,
                'stamp' => (bool)$request->stamp,
                'total_loan_amount' => $request->total_loan_amount ?: 0,
                'installment_count' => $request->installment_count ?: 0,
                'installment_amount' => $request->installment_amount ?: 0,
                'approved_no_pay_days' => $request->approved_no_pay_days,
                'status' => $request->status, 
                'month' => $request->month,
                'year' => $request->year,
                'salary_breakdown' => $updatedSalaryBreakdown,
                'allowances' => $allowances,
                'deductions' => $deductions,
            ];

            $salaryRecord->fill($updatedData);
            $salaryRecord->save();

            DB::commit();
            return response()->json([
                'message' => 'Salary record updated successfully',
                'data' => $salaryRecord
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error updating salary record: ' . $e->getMessage()
            ], 500);
        }
    }

    public function storeSalaryData(Request $request)
    {
        $validated = $request->validate([
            'data' => 'required|array',
        ]);

        try {
            $month = str_pad($request->month ?? $request->data[0]['month'], 2, '0', STR_PAD_LEFT);
            $year = $request->year ?? date('Y');

            foreach ($request->data as $employeeData) {
                $empNo = $employeeData['emp_no'] ?? $employeeData['employee_no'] ?? null;
                $empId = $employeeData['id'] ?? $employeeData['employee_id'] ?? null;

                if (!$empNo || !$empId) continue; 

                salary_process::updateOrCreate(
                    [
                        'employee_no' => $empNo,
                        'month' => $month,
                        'year' => $year,
                    ],
                    [
                        'employee_id' => $empId,
                        'full_name' => $employeeData['full_name'] ?? 'Unknown',
                        'company_name' => $employeeData['company_name'] ?? null,
                        'department_name' => $employeeData['department_name'] ?? null,
                        'sub_department_name' => $employeeData['sub_department_name'] ?? null,
                        'basic_salary' => $employeeData['basic_salary'] ?? 0,
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
                        'allowances' => is_array($employeeData['allowances'] ?? null) ? json_encode($employeeData['allowances']) : ($employeeData['allowances'] ?? '[]'),
                        'deductions' => is_array($employeeData['deductions'] ?? null) ? json_encode($employeeData['deductions']) : ($employeeData['deductions'] ?? '[]'),
                        'bonuses' => is_array($employeeData['bonuses'] ?? null) ? json_encode($employeeData['bonuses']) : ($employeeData['bonuses'] ?? '[]'),
                        'salary_breakdown' => is_array($employeeData['salary_breakdown'] ?? null) ? json_encode($employeeData['salary_breakdown']) : ($employeeData['salary_breakdown'] ?? '{}'),
                        'status' => 'pending', 
                    ]
                );
            }

            return response()->json(['message' => 'All Salary data saved successfully!'], 200);
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
            if ($request->has('status') && $request->status == 'processed') {
                $salaryData = salary_process::where('status', 'pending')
                    ->orWhere('status', 'Unprocessed')
                    ->update(['status' => 'processed']);
                DB::commit();
                return response()->json(['message' => 'Salary status updated to processed', 'data' => $salaryData], 200);
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

                salary_process::where('status', 'processed')->update(['status' => 'issued']);
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






//======================================================================================================
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
use App\Models\Roster;
use Carbon\Carbon;
use App\Models\EmployeeBonus;

class SalaryProcessController extends Controller
{
    private function getLateMinutes(?string $inTime, ?string $shiftStartTime): int
    {
        if (!$inTime || !$shiftStartTime) return 0;
        $in = strtotime($inTime);
        $shiftStart = strtotime($shiftStartTime);
        if ($in === false || $shiftStart === false || $in <= $shiftStart) return 0;
        return (int) floor(($in - $shiftStart) / 60);
    }

    private function getApprovedLeaveInfoForDate(int $employeeId, string $date): array
    {
        $leave = leave_master::where('employee_id', $employeeId)
            ->whereIn('status', ['Approved', 'HR_Approved'])
            ->where(function ($query) use ($date) {
                $query->whereDate('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->whereDate('leave_from', '<=', $date)->whereDate('leave_to', '>=', $date);
                    });
            })
            ->orderByRaw("CASE WHEN status = 'Approved' THEN 1 WHEN status = 'HR_Approved' THEN 2 ELSE 3 END")
            ->first();

        if (!$leave) {
            return ['has_approved_leave' => false, 'is_half_day_leave' => false, 'leave_type' => null, 'leave_status' => null, 'leave_period' => null];
        }

        return ['has_approved_leave' => true, 'is_half_day_leave' => (bool) ($leave->is_half_day ?? false), 'leave_type' => $leave->leave_type, 'leave_status' => $leave->status, 'leave_period' => $leave->period];
    }

    private function getRosterShiftStartTime(int $employeeId, string $date): ?string
    {
        $roster = roster::with('shift')
            ->where('employee_id', $employeeId)
            ->where(function ($q) use ($date) {
                $q->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date);
            })
            ->orderBy('date_from', 'desc')->first();

        return ($roster && $roster->shift && $roster->shift->start_time) ? $roster->shift->start_time : null;
    }

    private function getRosterShiftWorkHours(int $employeeId, string $date, float $defaultHours = 8): float
    {
        $roster = roster::with('shift')
            ->where('employee_id', $employeeId)
            ->where(function ($q) use ($date) {
                $q->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date);
            })
            ->orderBy('date_from', 'desc')->first();

        if (!$roster || !$roster->shift || !$roster->shift->start_time || !$roster->shift->end_time) return $defaultHours;

        $start = strtotime($roster->shift->start_time);
        $end = strtotime($roster->shift->end_time);
        if ($end <= $start) $end = strtotime('+1 day', $end);

        $hours = ($end - $start) / 3600;
        return $hours > 0 ? round($hours, 2) : $defaultHours;
    }

    private function calculateLateDeductionData(int $employeeId, string $startDate, string $endDate, float $perDaySalary): array 
    {
        $cardsByDate = time_card::where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNull('deleted_at')
            ->orderBy('time', 'asc')
            ->get()->groupBy('date');

        $minorLateCounter = 0; $approvedLeaveLateCount = 0; $noDeductionLateCount = 0;
        $shortLeaveCount = 0; $halfDayCount = 0; $deductibleLateCount = 0;
        $shortLeaveDeductionAmount = 0; $halfDayDeductionAmount = 0;

        foreach ($cardsByDate as $date => $dayCards) {
            $inCard = $dayCards->first(function ($card) {
                $st = strtolower(trim($card->status));
                return $card->entry == 1 || in_array($st, ['in', 'late coming', 'late_coming']);
            });

            if (!$inCard) continue;
            $shiftStartTime = $this->getRosterShiftStartTime($employeeId, $date);
            if (!$shiftStartTime) continue;

            $lateMinutes = $this->getLateMinutes($inCard->time, $shiftStartTime);

            if ($lateMinutes > 0 && $lateMinutes <= 30) {
                $leaveInfo = $this->getApprovedLeaveInfoForDate($employeeId, $date);
                if ($leaveInfo['has_approved_leave']) { $approvedLeaveLateCount++; continue; }

                $minorLateCounter++;
                $shiftHours = $this->getRosterShiftWorkHours($employeeId, $date, 8);
                $hourlyRate = $shiftHours > 0 ? ($perDaySalary / $shiftHours) : 0;

                if ($minorLateCounter <= 3) { $noDeductionLateCount++; } 
                elseif ($minorLateCounter <= 5) {
                    $shortLeaveCount++; $deductibleLateCount++;
                    $shortLeaveDeductionAmount += ($hourlyRate * 2);
                } else {
                    $halfDayCount++; $deductibleLateCount++;
                    $halfDayDeductionAmount += ($hourlyRate * 4);
                }
            }
        }

        return [
            'approved_leave_late_count' => $approvedLeaveLateCount, 'no_deduction_late_count' => $noDeductionLateCount,
            'short_leave_count' => $shortLeaveCount, 'half_day_count' => $halfDayCount, 'deductible_late_count' => $deductibleLateCount,
            'short_leave_deduction' => round($shortLeaveDeductionAmount, 2), 'half_day_deduction' => round($halfDayDeductionAmount, 2),
        ];
    }

    public function getProcessedSalaries(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');

        $processedSalaries = salary_process::whereIn('status', ['processed', 'issued'])
            ->when($month, fn ($query) => $query->where('month', $month))
            ->when($year, fn ($query) => $query->where('year', $year))
            ->with(['employee' => function ($query) {
                $query->select('id', 'full_name', 'attendance_employee_no')->with(['compensation' => function ($q) {
                    $q->select('employee_id', 'basic_salary', 'enable_epf_etf', 'bank_name', 'bank_account_no', 'branch_name');
                }]);
            }])->orderBy('created_at', 'desc')->get();

        $response = $processedSalaries->map(function ($salary) {
            return [
                'id' => $salary->id, 'employee_id' => $salary->employee_id, 'employee_no' => $salary->employee_no,
                'full_name' => $salary->full_name, 'company_name' => $salary->company_name, 'department_name' => $salary->department_name,
                'stamp' => $salary->stamp, 'basic_salary' => $salary->basic_salary, 'ot_morning' => $salary->ot_morning,
                'ot_evening' => $salary->ot_evening, 'month' => $salary->month, 'year' => $salary->year, 'status' => $salary->status,
                'compensation' => $salary->employee->compensation ?? null, 'bank_details' => $salary->employee->bankDetails ?? null,
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

        if (!is_array($employeeIDs) || empty($employeeIDs)) return response()->json(['error' => 'No employees selected'], 400);
        if (!$month || !$year) return response()->json(['error' => 'Month and Year are required for bulk actions'], 400);

        $rows = [];
        foreach ($employeeIDs as $employeeId) {
            $baseRow = ['employee_id' => $employeeId, 'custom_amount' => $amount, 'is_active' => 1, 'month' => $month, 'year' => $year, 'created_at' => now(), 'updated_at' => now()];
            if ($type === 'allowance') { $baseRow['allowance_id'] = $typeId; $rows[] = $baseRow; } 
            elseif ($type === 'deduction') { $baseRow['deduction_id'] = $typeId; $rows[] = $baseRow; } 
            else { $baseRow['bonus_id'] = $typeId; $rows[] = $baseRow; }
        }

        if ($type === 'allowance') {
            employee_allowances::whereIn('employee_id', $employeeIDs)->where('allowance_id', $typeId)->where('month', $month)->where('year', $year)->delete();
            employee_allowances::insert($rows);
        } elseif ($type === 'deduction') {
            employee_deductions::whereIn('employee_id', $employeeIDs)->where('deduction_id', $typeId)->where('month', $month)->where('year', $year)->delete();
            employee_deductions::insert($rows);
        } else {
            EmployeeBonus::whereIn('employee_id', $employeeIDs)->where('bonus_id', $typeId)->where('month', $month)->where('year', $year)->delete();
            EmployeeBonus::insert($rows);
        }

        return response()->json(['message' => 'Bulk update successful for the selected month.', 'type' => $type, 'affected_employees' => count($employeeIDs)]);
    }

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
                
                -- ==============================================================================
                -- LOAN LOGIC CHANGED HERE
                -- Fetch active loan details only if it exists
                -- ==============================================================================
                COALESCE(lo.loan_amount, 0) AS total_loan_amount,
                lo.installment_count AS installment_count,
                lo.installment_amount AS installment_amount,
                lo.status AS loan_status,
                lo.schedule AS loan_schedule,
                lo.deduct_from AS loan_deduct_from,
                lo.with_interest AS with_interest,
                lo.interest_rate_per_annum AS interest_rate_per_annum,

                -- No Pay Types Split (Approved records only)
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
                        CONCAT('{\"id\":', dd.id, ',\"name\":\"', REPLACE(IFNULL(dd.deduction_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ed.custom_amount, dd.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(dd.deduction_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(dd.category, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_deductions ed JOIN deductions dd ON dd.id = ed.deduction_id
                    WHERE ed.employee_id = e.id AND ed.is_active = 1 AND dd.status = 'active' AND (ed.month = ? AND ed.year = ?)
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

        $query .= " GROUP BY e.id, e.attendance_employee_no, e.full_name, e.nic, c.name, d.name, sd.name, comp.basic_salary, comp.br1, comp.br2, comp.increment_active, comp.increment_value, comp.increment_effected_date, comp.ot_morning, comp.ot_evening, comp.enable_epf_etf, comp.stamp, comp.bank_name, comp.branch_name, comp.bank_account_no, c.id, oa.department_id, oa.probationary_period, oa.date_of_joining, e.epf, cd.permanent_address, cd.mobile_line, cd.emg_name, cd.emg_relationship, cd.emg_tel, lo.loan_amount, lo.installment_count, lo.installment_amount, lo.status, lo.schedule, lo.deduct_from, lo.with_interest, lo.interest_rate_per_annum";

        $results = DB::select($query, $params);
        $data = [];

        // ==============================================================================
        // අලුත් කොටස: දැනටමත් Database එකේ සේව් කරලා තියෙන පඩි විස්තර ටික එකවර ගන්නවා
        // ==============================================================================
        $formattedMonth = str_pad($month, 2, '0', STR_PAD_LEFT);
        $savedSalaries = \App\Models\salary_process::where('month', $formattedMonth)
            ->where('year', $year)
            ->get()
            ->keyBy('employee_id');

        foreach ($results as $result) {
            $employeeData = (array)$result;

            $employeeData['compensation'] = [
                'bank_name' => $result->bank_name ?? null,
                'branch_name' => $result->branch_name ?? null,
                'bank_account_no' => $result->bank_account_no ?? null,
            ];

            // DB එකේ මේ සේවකයාට අදාළව සේව් කරපු Record එකක් තියෙනවද කියලා බලනවා
            if ($savedSalaries->has($result->id)) {
                $saved = $savedSalaries->get($result->id);
                
                // සේව් කරපු එකක් තියෙනවා නම්, අලුතින් ගණනය කරන්නේ නැතුව කෙලින්ම සේව් කරපු දත්ත ටික දානවා
                $employeeData['status'] = $saved->status;
                $employeeData['basic_salary'] = (float)$saved->basic_salary;
                $employeeData['increment_active'] = $saved->increment_active;
                $employeeData['increment_value'] = $saved->increment_value;
                $employeeData['increment_effected_date'] = $saved->increment_effected_date;
                $employeeData['ot_morning'] = $saved->ot_morning;
                $employeeData['ot_evening'] = $saved->ot_evening;
                $employeeData['enable_epf_etf'] = $saved->enable_epf_etf;
                $employeeData['br1'] = $saved->br1;
                $employeeData['br2'] = $saved->br2;
                $employeeData['stamp'] = $saved->stamp ? 25 : 0;
                $employeeData['total_loan_amount'] = $saved->total_loan_amount;
                $employeeData['installment_count'] = $saved->installment_count;
                $employeeData['installment_amount'] = $saved->installment_amount;
                $employeeData['approved_no_pay_days'] = $saved->approved_no_pay_days;
                
                $employeeData['allowances'] = is_string($saved->allowances) ? json_decode($saved->allowances, true) : $saved->allowances;
                $employeeData['deductions'] = is_string($saved->deductions) ? json_decode($saved->deductions, true) : $saved->deductions;
                $employeeData['bonuses'] = is_string($saved->bonuses) ? json_decode($saved->bonuses, true) : $saved->bonuses;
                $employeeData['salary_breakdown'] = is_string($saved->salary_breakdown) ? json_decode($saved->salary_breakdown, true) : $saved->salary_breakdown;
                
            } else {
                // ==============================================================================
                // පරණ ගණනය කිරීම් (සේව් කරලා නැත්නම් විතරයි මේවා වැඩ කරන්නේ)
                // ==============================================================================
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

                $installmentAmount = 0.0;
                $loanInterest = 0.0;
                $loanPrincipal = 0.0;
                $loanDeductFrom = $employeeData['loan_deduct_from'] ?? 'bonus'; 
                $loanStatus = strtolower((string)($employeeData['loan_status'] ?? ''));
                $isLoanApplicableForThisMonth = false;

                if ($loanStatus === 'active') {
                    $schedule = $employeeData['loan_schedule'] ?? null;
                    if (is_string($schedule)) { $schedule = is_array(json_decode($schedule, true)) ? json_decode($schedule, true) : null; }
                    
                    if (is_array($schedule) && count($schedule) > 0) {
                        foreach ($schedule as $r) {
                            $due = $r['due_date'] ?? $r['dueDate'] ?? null;
                            if ($due && date('Y-m', strtotime($due)) === $selectedMonthYear) {
                                $installmentAmount = (float)($r['installment_amount'] ?? $r['installmentAmount'] ?? 0);
                                $isLoanApplicableForThisMonth = true;
                                break;
                            }
                        }
                    } else {
                        if (((int)($employeeData['installment_count'] ?? 0) > 0)) {
                            $installmentAmount = (float)($employeeData['installment_amount'] ?? 0);
                            $isLoanApplicableForThisMonth = true;
                        }
                    }

                    if ($isLoanApplicableForThisMonth) {
                        if ($employeeData['with_interest'] && $employeeData['interest_rate_per_annum'] > 0) {
                            $loanAmountTotal = (float)($employeeData['total_loan_amount'] ?? 0);
                            $loanInterest = ($loanAmountTotal * ((float)$employeeData['interest_rate_per_annum'] / 100)) / 12;
                            if ($loanInterest > $installmentAmount) { $loanInterest = $installmentAmount; }
                        }
                        $loanPrincipal = $installmentAmount - $loanInterest;
                    } else {
                        $employeeData['total_loan_amount'] = 0;
                        $employeeData['installment_count'] = 0;
                        $employeeData['installment_amount'] = 0;
                    }
                } else {
                    $employeeData['total_loan_amount'] = 0;
                    $employeeData['installment_count'] = 0;
                    $employeeData['installment_amount'] = 0;
                }

                $companyLeavesCount = DB::table('leave_calendars')->where('company_id', $company_id ?? 0)
                    ->where(function ($q) use ($startDate, $endDate) { 
                        $q->whereBetween('start_date', [$startDate, $endDate])->orWhereBetween('end_date', [$startDate, $endDate]); 
                    })->count();
                
                $workingDaysInMonth = max(1, $totalDaysInMonth - $companyLeavesCount);
                $perDaySalary = $basicSalary / $workingDaysInMonth;

                $weekdayNoPays = (float)($employeeData['weekday_nopays'] ?? 0);
                $saturdayNoPays = (float)($employeeData['saturday_nopays'] ?? 0);
                $earlyOutNoPays = (float)($employeeData['early_out_nopays'] ?? 0);
                $majorLateNoPays = (float)($employeeData['major_late_nopays'] ?? 0);
                
                $fullDayNoPayDeduction = round($weekdayNoPays * $perDaySalary, 2);
                $saturdayNoPayBonusDeduction = round($saturdayNoPays * $perDaySalary, 2);
                
                $earlyOutNoPayDeduction = round($earlyOutNoPays * $perDaySalary, 2);
                $majorLateDeduction = round($majorLateNoPays * $perDaySalary, 2);

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

                $minorLateData = $this->calculateLateDeductionData((int)$employeeData['id'], $startDate, $endDate, $perDaySalary);
                $shortLeaveDeduction = $minorLateData['short_leave_deduction'] ?? 0;
                $halfDayDeduction = $minorLateData['half_day_deduction'] ?? 0;

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
                
                $otRows = over_time::with('timeCard:id,date,time,actual_date')->where('employee_id', $employeeData['id'])->whereRaw('LOWER(status) = ?', ['approved'])->whereHas('timeCard', function ($q) use ($startDate, $endDate) { $q->whereBetween('date', [$startDate, $endDate]); })->get();
                $morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1) ? ((float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount')) : 0.0;
                $night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1) ? ((float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount')) : 0.0;
                $holiday_ot_fees = (float)$otRows->sum('holiday_ot_amount');

                $basicGross = $basicSalary + $totalAllowances;
                $bonusGross = $totalBonuses;

                $basicDeductionsTotal = $epfEmployeeDeduction + $fullDayNoPayDeduction + $probationDeduction;
                $bonusDeductionsTotal = $saturdayNoPayBonusDeduction + $earlyOutNoPayDeduction + $shortLeaveDeduction + $halfDayDeduction + $majorLateDeduction + $totalFixedDeductions;

                if ($loanDeductFrom === 'basic') { $basicDeductionsTotal += $loanPrincipal; } else { $bonusDeductionsTotal += $loanPrincipal; }
                $bonusDeductionsTotal += $loanInterest;

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
                    'full_day_nopay_deduction' => round($fullDayNoPayDeduction, 2),
                    'saturday_nopay_deduction' => round($saturdayNoPayBonusDeduction, 2), 
                    'early_out_nopay_deduction' => round($earlyOutNoPayDeduction, 2),
                    'short_leave_deduction' => round($shortLeaveDeduction, 2),
                    'half_day_deduction' => round($halfDayDeduction, 2),
                    'major_late_deduction' => round($majorLateDeduction, 2),
                    'epf_employee_deduction' => round($epfEmployeeDeduction, 2),
                    'probation_deduction' => round($probationDeduction, 2),
                    'stamp_duty' => $stampValue,
                    'loan_principal' => round($loanPrincipal, 2),
                    'loan_interest' => round($loanInterest, 2),
                    'loan_deduct_from' => $loanDeductFrom,
                    'loan_installment' => round($installmentAmount, 2),
                    'total_fixed_deductions' => round($totalFixedDeductions, 2),
                    'net_salary' => round($netSalary, 2),
                    'gross_salary' => round($grossSalary, 2),
                    'total_deductions' => round($totalDeductions, 2),
                ];
            } // else එක ඉවරයි

            $data[] = $employeeData;
        }

        return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }



public function storeSalaryData(Request $request)
    {
        $validated = $request->validate([
            'data' => 'required|array',
        ]);

        try {
            $month = str_pad($request->month ?? $request->data[0]['month'], 2, '0', STR_PAD_LEFT);
            $year = $request->year ?? date('Y');

            foreach ($request->data as $employeeData) {
                $empNo = $employeeData['emp_no'] ?? $employeeData['employee_no'] ?? null;
                $empId = $employeeData['id'] ?? $employeeData['employee_id'] ?? null;

                if (!$empNo || !$empId) continue; 

                salary_process::updateOrCreate(
                    [
                        'employee_no' => $empNo,
                        'month' => $month,
                        'year' => $year,
                    ],
                    [
                        'employee_id' => $empId,
                        'full_name' => $employeeData['full_name'] ?? 'Unknown',
                        'company_name' => $employeeData['company_name'] ?? null,
                        'department_name' => $employeeData['department_name'] ?? null,
                        'sub_department_name' => $employeeData['sub_department_name'] ?? null,
                        'basic_salary' => $employeeData['basic_salary'] ?? 0,
                        'increment_active' => $employeeData['increment_active'] ?? false,
                        'increment_value' => $employeeData['increment_value'] ?? null,
                        'increment_effected_date' => $employeeData['increment_effected_date'] ?? null,
                        'ot_morning' => $employeeData['ot_morning'] ?? 0,
                        'ot_evening' => $employeeData['ot_evening'] ?? 0,
                        'enable_epf_etf' => $employeeData['enable_epf_etf'] ?? false,
                        'br1' => $employeeData['br1'] ?? false,
                        'br2' => $employeeData['br2'] ?? false,
                        
                        // 👇 මෙන්න මේ පේළිය තමයි අඩුවෙලා තිබ්බේ!
                        'br_status' => $employeeData['br_status'] ?? '', 
                        
                        'total_loan_amount' => $employeeData['total_loan_amount'] ?? 0,
                        'installment_count' => $employeeData['installment_count'] ?? null,
                        'installment_amount' => $employeeData['installment_amount'] ?? null,
                        'approved_no_pay_days' => $employeeData['approved_no_pay_days'] ?? 0,
                        'allowances' => is_array($employeeData['allowances'] ?? null) ? json_encode($employeeData['allowances']) : ($employeeData['allowances'] ?? '[]'),
                        'deductions' => is_array($employeeData['deductions'] ?? null) ? json_encode($employeeData['deductions']) : ($employeeData['deductions'] ?? '[]'),
                        'bonuses' => is_array($employeeData['bonuses'] ?? null) ? json_encode($employeeData['bonuses']) : ($employeeData['bonuses'] ?? '[]'),
                        'salary_breakdown' => is_array($employeeData['salary_breakdown'] ?? null) ? json_encode($employeeData['salary_breakdown']) : ($employeeData['salary_breakdown'] ?? '{}'),
                        
                        'status' => 'pending', 
                    ]
                );
            }

            return response()->json(['message' => 'All Salary data saved successfully!'], 200);
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
            if ($request->has('status') && $request->status == 'processed') {
                $salaryData = salary_process::where('status', 'pending')
                                            ->orWhere('status', 'Unprocessed')
                                            ->update([
                    'status' => 'processed',
                ]);
                DB::commit();
                return response()->json(['message' => 'Salary status updated to processed', 'data' => $salaryData], 200);
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
}

*/
