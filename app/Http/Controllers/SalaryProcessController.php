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
use Illuminate\Support\Facades\Validator;
use App\Models\loans;
use App\Models\leave_master; // add this
use Carbon\Carbon;


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
                // Get all salary processes that are being marked as issued
                $salaryProcesses = salary_process::where('status', 'processed')->get();

                foreach ($salaryProcesses as $process) {
                    // Get installment_count from salary_process
                    $installmentCount = $process->installment_count;

                    // Reduce installment_count by 1 in loans table if exists
                    if ($installmentCount !== null) {
                        // Fetch active loan to track when it completes
                        $loan = loans::where('employee_id', $process->employee_id)
                            ->where('status', 'active')
                            ->first();

                        if ($loan) {
                            $prevCount = (int) ($loan->installment_count ?? 0);
                            $newInstallmentCount = max(0, $prevCount - 1);

                            $loan->installment_count = $newInstallmentCount;
                            $loan->status = $newInstallmentCount == 0 ? 'completed' : 'active';
                            $loan->save();

                            // When loan completes, log to completed_loans
                            if ($newInstallmentCount == 0) {
                                DB::table('completed_loans')->insert([
                                    'employee_id' => $loan->employee_id,
                                    'loan_id' => $loan->id,
                                    'loan_amount' => $loan->loan_amount,
                                    'interest_rate_per_annum' => $loan->interest_rate_per_annum,
                                    'with_interest' => $loan->with_interest,
                                    // store the count at completion (before it hits 0)
                                    'installment_count' => $prevCount,
                                    'end_date' => now()->toDateString(),
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }
                }

                // Update all processed salaries to issued
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


        // if ($request->has('status') && $request->status == 'processed') {
        //     $salaryData = salary_process::where('status', 'pending')->update([
        //         'status' => 'processed',
        //     ]);
        //     return response()->json($salaryData, 200);
        // }
        // if ($request->has('status') && $request->status == 'issued') {
        //     $salaryData = salary_process::where('status', 'processed')->update([
        //         'status' => 'issued',
        //     ]);
        //     return response()->json($salaryData, 200);
        // }
        // return response()->json(['message' => 'Invalid status'], 400);

    }

   
    public function show(string $id)
    {
        //
    }

    
    public function update(Request $request, string $id)
    {
        //
    }

    
    public function destroy(string $id)
    {
        //
    }
    // public function getEmployeesByMonthAndCompany(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'month' => 'required|integer|between:1,12',
    //         'year' => 'required|integer',
    //         'company_id' => 'required|exists:companies,id',
    //         'department_id' => 'nullable|exists:departments,id'
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'message' => 'Validation failed',
    //             'errors' => $validator->errors()
    //         ], 422);
    //     }

    //     $month = $request->month;
    //     $year = $request->year;
    //     $startDate = "$year-$month-01";
    //     $endDate = date('Y-m-t', strtotime($startDate));

    //     // Simplified roster date condition
    //     $rosterCondition = function ($query) use ($startDate, $endDate) {
    //         $query->where(function ($q) use ($startDate, $endDate) {
    //             $q->whereNull('date_from')
    //                 ->whereNull('date_to');
    //         })->orWhere(function ($q) use ($startDate, $endDate) {
    //             $q->where('date_from', '<=', $endDate)
    //                 ->where('date_to', '>=', $startDate);
    //         });
    //     };

    //     $query = Employee::with([
    //         'organizationAssignment.company',
    //         'organizationAssignment.department',
    //         'organizationAssignment.subDepartment',
    //         'organizationAssignment.designation',
    //         'compensation',
    //         'rosters' => function ($query) use ($rosterCondition) {
    //             $query->where($rosterCondition)
    //                 ->with('shift');
    //         }
    //     ])
    //         ->whereHas('organizationAssignment', function ($query) use ($request) {
    //             $query->where('company_id', $request->company_id);
    //             if ($request->has('department_id')) {
    //                 $query->where('department_id', $request->department_id);
    //             }
    //         })
    //         ->whereHas('rosters', $rosterCondition);

    //     $employees = $query->get();

    //     return response()->json([
    //         'data' => $employees,
    //         'month' => $month,
    //         'year' => $year,
    //         'company_id' => $request->company_id,
    //         'department_id' => $request->department_id ?? null,
    //     ]);
    // }



/*
public function getEmployeesByMonthAndCompany(Request $request)
{
    $month = $request->query('month');
    $year = $request->query('year');
    $company_id = $request->query('company_id');
    $department_id = $request->query('department_id');

    $kpiTypeRaw = strtolower((string) $request->query('kpi_type', ''));
    $kpiType = in_array($kpiTypeRaw, ['monthly', '6month', 'six_month']) ? $kpiTypeRaw : '';

    $startDate = "{$year}-{$month}-01";
    $lastDay = date('t', strtotime($startDate));
    $endDate = "{$year}-{$month}-{$lastDay}";
    $selectedMonthYear = date('Y-m', strtotime($startDate));

    // working days calculation
    $totalDaysInMonth = (int) $lastDay;

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

    // ✅ SQL Query (UPDATED LOAN FIELDS)
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

            COALESCE(COUNT(npr.id), 0) AS approved_no_pay_days,

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
            ) AS deductions

        FROM employees e
        JOIN organization_assignments oa ON e.organization_assignment_id = oa.id
        JOIN companies c ON oa.company_id = c.id
        LEFT JOIN departments d ON oa.department_id = d.id
        LEFT JOIN sub_departments sd ON oa.sub_department_id = sd.id
        LEFT JOIN compensation comp ON e.id = comp.employee_id
        LEFT JOIN loans lo ON e.id = lo.employee_id AND lo.status = 'active'
        LEFT JOIN no_pay_records npr ON e.id = npr.employee_id
            AND npr.status = 'Approved'
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
        $employeeData = (array) $result;

        $allowances = json_decode($result->allowances ?? '[]', true) ?: [];
        $deductions  = json_decode($result->deductions ?? '[]', true) ?: [];

        // Stamp
        $stampValue = ((int)($result->stamp ?? 0) === 1) ? 25 : 0;
        $employeeData['stamp'] = $stampValue;

        // Base + BR
        $basicSalary = (float)($employeeData['basic_salary'] ?? 0);
        $brAllowance = 0;
        if ((int)$result->br1 === 1 && (int)$result->br2 === 1) $brAllowance = 3500;
        elseif ((int)$result->br1 === 1) $brAllowance = 1000;
        elseif ((int)$result->br2 === 1) $brAllowance = 2500;

        $basicSalary += $brAllowance;

        $approvedNoPayDays = (int)($employeeData['approved_no_pay_days'] ?? 0);

        // ✅ LOAN INSTALLMENT (Schedule-based month selection)
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
        } else {
            $installmentAmount = 0.0;
        }

        // ensure storeSalaryData + frontend get final value
        $employeeData['installment_amount'] = $installmentAmount;

        // 1) Increment
        if (!empty($employeeData['increment_active']) &&
            !empty($employeeData['increment_effected_date']) &&
            strtotime($employeeData['increment_effected_date']) <= strtotime($endDate)
        ) {
            $basicSalary += (float)($employeeData['increment_value'] ?? 0);
        }

        // probation over-limit
        $employeeData['probationary_period'] = (bool)($result->probationary_period ?? false);
        $probationOverLimitDays = 0.0;

        if ($employeeData['probationary_period']) {
            $probationOverLimitDays = (float)(leave_master::where('employee_id', $employeeData['id'])
                ->where('status', 'Approved')
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('leave_date', [$startDate, $endDate])
                        ->orWhere(function ($q2) use ($startDate, $endDate) {
                            $q2->where('leave_from', '<=', $endDate)
                                ->where('leave_to', '>=', $startDate);
                        });
                })
                ->sum('over_limit') ?? 0);
        }

        // 2) no-pay + probation
        $perDaySalary = $basicSalary / $workingDaysInMonth;
        $noPayDeduction = $approvedNoPayDays * $perDaySalary;
        $probationDeduction = $probationOverLimitDays * $perDaySalary;
        $adjustedBasic = $basicSalary - $noPayDeduction - $probationDeduction;

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
                $allowances[] = [
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
                $allowances[] = [
                    'id' => null,
                    'name' => 'KPI Bonus (6M)',
                    'amount' => round($kpiBonusAllowance, 2),
                    'is_custom' => 1,
                    'code' => 'KPI6M',
                    'category' => 'kpi_bonus'
                ];
            }
        }

        // set allowance/deductions back
        $employeeData['allowances'] = $allowances;
        $employeeData['deductions']  = $deductions;

        $totalAllowances = array_reduce($allowances, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

        $epfEligibleAllowances = array_reduce($allowances, function ($carry, $item) {
            $category = strtolower((string)($item['category'] ?? ''));
            if ($category === 'kpi_bonus') return $carry; // exclude 6M bonus from EPF base
            return $carry + (float)($item['amount'] ?? 0);
        }, 0);

        $epfEtfBase = $adjustedBasic + $epfEligibleAllowances;

        $epfEmployeeDeduction = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.08) : 0;
        $epfEmployerContribution = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.12) : 0;
        $etfEmployerContribution = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.03) : 0;

        $totalFixedDeductions = array_reduce($deductions, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);
        

        
        // ✅ OT (MONTH FILTER + DETAILS)
        $empid = $employeeData['id'];

        $otRows = over_time::with('timeCard:id,date,time,actual_date')
            ->where('employee_id', $empid)
            ->where('status', 'approved')
            ->whereHas('timeCard', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('date', [$startDate, $endDate])
                    ->orWhereBetween('actual_date', [$startDate, $endDate]);
            })
            ->get([
                'ot_hours',
                'total_ot_amount',
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

        // OT totals (amount)
        $morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1)
            ? ((float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount'))
            : 0;

        $night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1)
            ? ((float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount'))
            : 0;








        // OT details list (dates/hours)
        $overtimeDetails = $otRows->map(function ($ot) {
            //$otRows = [];          // ✅ always defined
            $tc = $ot->timeCard;
            $displayDate = $tc && $tc->actual_date ? $tc->actual_date : ($tc->date ?? null);

            return [
                'date' => $displayDate,
                'out_time' => $tc->time ?? null,
                'ot_hours' => (float)($ot->ot_hours ?? 0),
                'total_ot_amount' => (float)($ot->total_ot_amount ?? 0),
                'morning_regular_hours' => (float)($ot->morning_ot ?? 0),
                'morning_special_hours' => (float)($ot->morning_ot_special ?? 0),
                'evening_regular_hours' => (float)($ot->afternoon_ot ?? 0),
                'evening_special_hours' => (float)($ot->evening_ot_special ?? 0),
            ];
        })->values();

        $employeeData['overtime_details'] = $overtimeDetails;
        $employeeData['overtime_total_amount'] = round((float)$overtimeDetails->sum('total_ot_amount'), 2);
        $employeeData['overtime_total_hours']  = round((float)$overtimeDetails->sum('ot_hours'), 2);

        // Gross include KPI bonus but exclude from EPF base already
        $grossSalary = $epfEtfBase + $morning_ot_fees + $night_ot_fees + $kpiBonusAllowance;

        // Net
        $totalDeductions = $totalFixedDeductions + $installmentAmount + $epfEmployeeDeduction;
        $netSalary = $grossSalary - $totalDeductions;
        if ($stampValue) $netSalary -= $stampValue;

        $employeeData['salary_breakdown'] = [
            'basic_salary' => $basicSalary,
            'br_allowance' => $brAllowance,
            'ot_morning_fees' => $morning_ot_fees,
            'ot_night_fees' => $night_ot_fees,
            'adjusted_basic' => $adjustedBasic,
            'per_day_salary' => $perDaySalary,
            'no_pay_deduction' => $noPayDeduction,
            'probation_over_limit_days' => $probationOverLimitDays,
            'probation_deduction' => $probationDeduction,
            'kpi_allowance' => round($kpiAllowance, 2),
            'kpi_bonus_allowance' => round($kpiBonusAllowance, 2),
            'total_allowances' => $totalAllowances,
            'epf_etf_base' => $epfEtfBase,
            'epf_employee_deduction' => $epfEmployeeDeduction,
            'epf_employer_contribution' => $epfEmployerContribution,
            'etf_employer_contribution' => $etfEmployerContribution,
            'total_fixed_deductions' => $totalFixedDeductions,
            'loan_installment' => $installmentAmount,
            'gross_salary' => $grossSalary,
            'total_deductions' => $totalDeductions,
            'stamp' => $stampValue,
            'net_salary' => $netSalary
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

*/



public function getEmployeesByMonthAndCompany(Request $request)
{
    $month = $request->query('month');
    $year = $request->query('year');
    $company_id = $request->query('company_id');
    $department_id = $request->query('department_id');

    $kpiTypeRaw = strtolower((string) $request->query('kpi_type', ''));
    $kpiType = in_array($kpiTypeRaw, ['monthly', '6month', 'six_month']) ? $kpiTypeRaw : '';

    $startDate = "{$year}-{$month}-01";
    $lastDay = date('t', strtotime($startDate));
    $endDate = "{$year}-{$month}-{$lastDay}";
    $selectedMonthYear = date('Y-m', strtotime($startDate));

    // Working days calculation
    $totalDaysInMonth = (int) $lastDay;

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

    // SQL query
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

            COALESCE(COUNT(npr.id), 0) AS approved_no_pay_days,

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
            ) AS deductions

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
        $employeeData = (array) $result;

        $allowances = json_decode($result->allowances ?? '[]', true) ?: [];
        $deductions = json_decode($result->deductions ?? '[]', true) ?: [];

        // Stamp
        $stampValue = ((int)($result->stamp ?? 0) === 1) ? 25 : 0;
        $employeeData['stamp'] = $stampValue;

        // Base + BR
        $basicSalary = (float)($employeeData['basic_salary'] ?? 0);
        $brAllowance = 0;
        if ((int)$result->br1 === 1 && (int)$result->br2 === 1) $brAllowance = 3500;
        elseif ((int)$result->br1 === 1) $brAllowance = 1000;
        elseif ((int)$result->br2 === 1) $brAllowance = 2500;

        $basicSalary += $brAllowance;

        $approvedNoPayDays = (int)($employeeData['approved_no_pay_days'] ?? 0);

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
                if ($installmentAmount <= 0) $installmentAmount = 0.0;
            } else {
                $count = (int)($employeeData['installment_count'] ?? 0);
                $installmentAmount = ($count > 0) ? (float)($employeeData['installment_amount'] ?? 0) : 0.0;
            }
        }

        $employeeData['installment_amount'] = $installmentAmount;

        // Increment
        if (!empty($employeeData['increment_active']) &&
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

        // no-pay + probation
        $perDaySalary = $basicSalary / $workingDaysInMonth;
        $noPayDeduction = $approvedNoPayDays * $perDaySalary;
        $probationDeduction = $probationOverLimitDays * $perDaySalary;
        $adjustedBasic = $basicSalary - $noPayDeduction - $probationDeduction;

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
                $allowances[] = [
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
                $allowances[] = [
                    'id' => null,
                    'name' => 'KPI Bonus (6M)',
                    'amount' => round($kpiBonusAllowance, 2),
                    'is_custom' => 1,
                    'code' => 'KPI6M',
                    'category' => 'kpi_bonus'
                ];
            }
        }

        // put back arrays
        $employeeData['allowances'] = $allowances;
        $employeeData['deductions'] = $deductions;

        $totalAllowances = array_reduce($allowances, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

        $epfEligibleAllowances = array_reduce($allowances, function ($carry, $item) {
            $category = strtolower((string)($item['category'] ?? ''));
            if ($category === 'kpi_bonus') return $carry;
            return $carry + (float)($item['amount'] ?? 0);
        }, 0);

        $epfEtfBase = $adjustedBasic + $epfEligibleAllowances;



        $epfEmployeeDeduction = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.08) : 0;
        $epfEmployerContribution = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.12) : 0;
        $etfEmployerContribution = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.03) : 0;

        $totalFixedDeductions = array_reduce($deductions, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);
      

/*
        // ✅ OT (case-insensitive + month filter)
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

$morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1)
    ? ((float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount'))
    : 0;

$night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1)
    ? ((float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount'))
    : 0;

// ✅ Total Allowances (include KPI bonus too)
$totalAllowances = array_reduce(
    $allowances,
    fn($c, $i) => $c + (float)($i['amount'] ?? 0),
    0
);

// ✅ EPF Eligible Allowances (exclude KPI 6M bonus only)
$epfEligibleAllowances = array_reduce($allowances, function ($carry, $item) {
    $category = strtolower((string)($item['category'] ?? ''));
    if ($category === 'kpi_bonus') return $carry;
    return $carry + (float)($item['amount'] ?? 0);
}, 0);

$epfEtfBase = $adjustedBasic + $epfEligibleAllowances;

// ✅ GROSS = Adjusted Basic + ALL allowances + OT
$grossSalary = $adjustedBasic + $totalAllowances + $morning_ot_fees + $night_ot_fees;

// ✅ EPF/ETF
$epfEmployeeDeduction = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.08) : 0;
$epfEmployerContribution = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.12) : 0;
$etfEmployerContribution = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.03) : 0;

// ✅ Net
$totalDeductions = $totalFixedDeductions + $installmentAmount + $epfEmployeeDeduction;
$netSalary = $grossSalary - $totalDeductions;

if ($stampValue) $netSalary -= $stampValue;

        $employeeData['salary_breakdown'] = [
            'basic_salary' => $basicSalary,
            'br_allowance' => $brAllowance,
            'ot_morning_fees' => $morning_ot_fees,
            'ot_night_fees' => $night_ot_fees,
            'adjusted_basic' => $adjustedBasic,
            'per_day_salary' => $perDaySalary,
            'no_pay_deduction' => $noPayDeduction,
            'probation_over_limit_days' => $probationOverLimitDays,
            'probation_deduction' => $probationDeduction,
            'kpi_allowance' => round($kpiAllowance, 2),
            'kpi_bonus_allowance' => round($kpiBonusAllowance, 2),
            'total_allowances' => $totalAllowances,
            'epf_etf_base' => $epfEtfBase,
            'epf_employee_deduction' => $epfEmployeeDeduction,
            'epf_employer_contribution' => $epfEmployerContribution,
            'etf_employer_contribution' => $etfEmployerContribution,
            'total_fixed_deductions' => $totalFixedDeductions,
            'loan_installment' => $installmentAmount,
            'gross_salary' => $grossSalary,
            'total_deductions' => $totalDeductions,
            'stamp' => $stampValue,
            'net_salary' => $netSalary
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
*/


// ✅ OT (case-insensitive + month filter)
// ✅ OT (month filter + case-insensitive)
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

$employeeData['_debug_ot_count'] = $otRows->count();
$employeeData['_debug_ot_total_amount'] = (float) $otRows->sum('total_ot_amount');
$employeeData['_debug_sum_morning_amount'] =
    (float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount');
$employeeData['_debug_sum_evening_amount'] =
    (float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount');
$employeeData['_debug_ot_dates'] = $otRows->pluck('timeCard.actual_date')->filter()->take(3)->values();


/*
$morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1)
    ? ((float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount'))
    : 0.0;

$night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1)
    ? ((float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount'))
    : 0.0;
*/


$sumMorning = (float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount');
$sumNight   = (float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount');

$morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1) ? $sumMorning : 0.0;
$night_ot_fees   = ((int)($employeeData['ot_evening'] ?? 0) === 1) ? $sumNight : 0.0;

/**
 * ✅ FALLBACK: morning/night amounts columns 0 
 
 */
$totalOt = (float)$otRows->sum('total_ot_amount');

if ($totalOt > 0 && ($morning_ot_fees + $night_ot_fees) <= 0) {
    // simplest fallback: put everything as night OR split 50/50
    $night_ot_fees = $totalOt;   // 
}


// ✅ OT details list
$overtimeDetails = $otRows->map(function ($ot) {
    $tc = $ot->timeCard;
    $displayDate = ($tc && !empty($tc->actual_date)) ? $tc->actual_date : ($tc->date ?? null);

    return [
        'date' => $displayDate,
        'out_time' => $tc->time ?? null,
        'ot_hours' => (float)($ot->ot_hours ?? 0),
        'total_ot_amount' => (float)($ot->total_ot_amount ?? 0),
    ];
})->values();

$employeeData['overtime_details'] = $overtimeDetails;
$employeeData['overtime_total_amount'] = round((float)$otRows->sum('total_ot_amount'), 2);
$employeeData['overtime_total_hours']  = round((float)$otRows->sum('ot_hours'), 2);

// ✅ NOW calculate Gross AFTER OT is ready
$totalAllowances = array_reduce($allowances, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

// Gross = adjusted basic + ALL allowances + OT + KPI bonus(if you keep separate)
$grossSalary = $adjustedBasic + $totalAllowances + $morning_ot_fees + $night_ot_fees + $kpiBonusAllowance;

// Deductions + Net
$totalFixedDeductions = array_reduce($deductions, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);
$totalDeductions = $totalFixedDeductions + $installmentAmount + $epfEmployeeDeduction;

//$netSalary = $grossSalary - $totalDeductions - $stampValue;

//if ($stampValue) $netSalary -= $stampValue;

$netSalary = $grossSalary - $totalDeductions;

if ($stampValue) {
    $netSalary -= $stampValue; // only once
}

        $employeeData['salary_breakdown'] = [
            'basic_salary' => $basicSalary,
            'br_allowance' => $brAllowance,
            'ot_morning_fees' => $morning_ot_fees,
            'ot_night_fees' => $night_ot_fees,
            'adjusted_basic' => $adjustedBasic,
            'per_day_salary' => $perDaySalary,
            'no_pay_deduction' => $noPayDeduction,
            'probation_over_limit_days' => $probationOverLimitDays,
            'probation_deduction' => $probationDeduction,
            'kpi_allowance' => round($kpiAllowance, 2),
            'kpi_bonus_allowance' => round($kpiBonusAllowance, 2),
            'total_allowances' => $totalAllowances,
            'epf_etf_base' => $epfEtfBase,
            'epf_employee_deduction' => $epfEmployeeDeduction,
            'epf_employer_contribution' => $epfEmployerContribution,
            'etf_employer_contribution' => $etfEmployerContribution,
            'total_fixed_deductions' => $totalFixedDeductions,
            'loan_installment' => $installmentAmount,
            'gross_salary' => $grossSalary,
            'total_deductions' => $totalDeductions,
            'stamp' => $stampValue,
            'net_salary' => $netSalary
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


/*

    public function updateEmployeesAllowances(Request $request)
    {
        $employeeIDs = $request->selectedEmployees; // This is an array of IDs
        $type = $request->bulkActionType;
        $amount = $request->bulkActionAmount; // Changed from 'amount' to match JSON
              // allowance_id OR deduction_id

        if (!is_array($employeeIDs) || empty($employeeIDs)) {
            return response()->json(['error' => 'No employees selected'], 400);
        }

        $typeId = $request->bulkActionId;

        if ($type == "allowance") {
            $records = [];
            foreach ($employeeIDs as $employeeId) {
                $records[] = [
                    'employee_id' => $employeeId,
                    'allowance_id' => $typeId,
                    'custom_amount' => $amount,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            //employee_allowances::insert($records);
            employee_allowances::upsert(
        $rows,
        ['employee_id', 'allowance_id'],
        ['custom_amount', 'is_active', 'updated_at']
    );
        }
        if ($type == "deduction") {
            $records = [];
            foreach ($employeeIDs as $employeeId) {
                $records[] = [
                    'employee_id' => $employeeId,
                    'deduction_id' => $typeId,
                    'custom_amount' => $amount,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            //employee_deductions::insert($records);
             // ✅ duplicate (employee_id + deduction_id) නම් UPDATE කරයි
    employee_deductions::upsert(
        $rows,
        ['employee_id', 'deduction_id'], // unique columns
        ['custom_amount', 'is_active', 'updated_at'] // update columns
    );
        }

        return response()->json([
            'message' => 'Bulk update successful',
            'affected_employees' => count($employeeIDs),
            'type' => $type,
            'amount' => $amount
        ], 200);
    }
        */


    public function updateEmployeesAllowances(Request $request)
{
    $employeeIDs = $request->selectedEmployees;
    $type       = $request->bulkActionType;   // "allowance" | "deduction"
    $amount     = $request->bulkActionAmount; // numeric
    $typeId     = $request->bulkActionId;     // allowance_id OR deduction_id

    if (!is_array($employeeIDs) || empty($employeeIDs)) {
        return response()->json(['error' => 'No employees selected'], 400);
    }

    if (!in_array($type, ['allowance', 'deduction'], true)) {
        return response()->json([
            'error' => 'Invalid bulkActionType',
            'received' => $type
        ], 422);
    }

    if (!$typeId) {
        return response()->json(['error' => 'bulkActionId is required'], 422);
    }

    // ✅ always define
    $rows = [];

    foreach ($employeeIDs as $employeeId) {
        if ($type === 'allowance') {
            $rows[] = [
                'employee_id'   => $employeeId,
                'allowance_id'  => $typeId,
                'custom_amount' => $amount,
                'is_active'     => 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        } else { // deduction
            $rows[] = [
                'employee_id'   => $employeeId,
                'deduction_id'  => $typeId,
                'custom_amount' => $amount,
                'is_active'     => 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }
    }

    // ✅ UPSERT to avoid duplicate key crash
    if ($type === 'allowance') {
        employee_allowances::upsert(
            $rows,
            ['employee_id', 'allowance_id'],
            ['custom_amount', 'is_active', 'updated_at']
        );
    } else {
        employee_deductions::upsert(
            $rows,
            ['employee_id', 'deduction_id'],
            ['custom_amount', 'is_active', 'updated_at']
        );
    }

    return response()->json([
        'message' => 'Bulk update successful',
        'affected_employees' => count($employeeIDs),
        'type' => $type,
        'amount' => $amount,
        'id' => $typeId
    ], 200);
}

    public function storeSalaryData(Request $request)
    {
        $validated = $request->validate([
            'data' => 'required|array',
            'data.*.emp_no' => 'required|string',
            'data.*.full_name' => 'required|string',
            'month' => 'sometimes|integer|between:1,12',
            'year' => 'sometimes|integer',
            // Add other validation rules as needed
        ]);

        try {
            $month = $request->data[0]['month'];
            $year = $request->year ?? date('Y');
            $duplicateEntries = [];

            foreach ($request->data as $employeeData) {
                // Check if record already exists for this employee in this month/year
                $existingRecord = salary_process::where('employee_no', $employeeData['emp_no'])
                    ->where('month', $month)
                    ->where('year', $year)
                    ->first();

                if ($existingRecord) {
                    $duplicateEntries[] = $employeeData['emp_no'];
                    continue; // Skip this record
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
                    //'ot_morning' => $employeeData['salary_breakdown']['ot_morning_fees'] ?? false,
                    //'ot_evening' => $employeeData['salary_breakdown']['ot_night_fees'] ?? false,
                    'ot_morning' => $employeeData['ot_morning'] ?? 0,   // ✅ flag
                    'ot_evening' => $employeeData['ot_evening'] ?? 0,   // ✅ flag
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

        // Format the response
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
                'deductions' => $salary->deductions
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

        // Get all relevant salary processes
        $salaryProcesses = salary_process::whereIn('employee_id', $validated['employee_ids'])
            ->where('status', 'processed')
            ->get();

        DB::beginTransaction();

        try {
            foreach ($salaryProcesses as $process) {
                // Get installment_count from salary_process
                $installmentCount = $process->installment_count;

                // Reduce installment_count by 1 in loans table
                if ($installmentCount !== null) {
                    // Fetch active loan
                    $loan = loans::where('employee_id', $process->employee_id)
                        ->where('status', 'active')
                        ->first();

                    if ($loan) {
                        $prevCount = (int) ($loan->installment_count ?? 0);
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

                // Mark salary as issued
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
        if ($request->bulkActionType == 'allowance') {
            $allowance = allowances::where('id', $request->bulkActionId)
                ->get(['id', 'allowance_name']);
        } elseif ($request->bulkActionType == 'deduction') {
            $deduction = deduction::where('id', $request->bulkActionId)
                ->get(['id', 'deduction_name']);
        }

        return response()->json([$employees, $allowance, $deduction], 200);
    }


    public function importExcelData(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
            'type' => 'required|in:allowances,deductions' // Add this to specify import type
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
                // 'imported_count' => $import->getRowCount()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error importing file: ' . $e->getMessage()
            ], 422);
        }
    }

    // When processing salary and handling loan installments
    public function updateStatus(Request $request)
    {
        DB::beginTransaction();
        try {
            if ($request->has('status') && $request->status == 'issued') {
                // Get all salary processes being marked as issued
                $salaryProcesses = salary_process::where('status', 'processed')->get();

                foreach ($salaryProcesses as $process) {
                    // Get the loan for this employee
                    $loan = Loans::where('employee_id', $process->employee_id)
                        ->where('status', 'active')
                        ->first();

                    if ($loan) {
                        // Calculate new installment count
                        $prevCount = (int) ($loan->installment_count ?? 0);
                        $newInstallmentCount = max(0, $prevCount - 1);

                        // Determine if this is the last installment
                        $isLastInstallment = ($newInstallmentCount == 0);

                        // Calculate if there's a remainder for the final payment
                        $remainder = $loan->loan_amount % $loan->installment_amount;
                        $hasRemainder = ($remainder > 0);

                        // If this is the last installment and there's a remainder, use the remainder as the installment amount
                        if ($isLastInstallment && $hasRemainder && $loan->installment_count == 1) {
                            $process->installment_amount = $remainder;
                            $process->save();
                        }

                        // Update the loan record
                        $loan->installment_count = $newInstallmentCount;
                        $loan->status = $isLastInstallment ? 'completed' : 'active';
                        $loan->save();

                        // Log completed loan once it finishes
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

                    // Mark salary as issued
                    $process->update(['status' => 'issued']);
                }

                DB::commit();
                return response()->json(['message' => 'Payslips marked as issued and loan installments updated successfully']);
            }

            // Other status handling...

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating payslips and loans: ' . $e->getMessage()], 500);
        }
    }
}
