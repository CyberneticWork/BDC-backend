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
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $salaryData = salary_process::with(['employee', 'noPayRecord', 'overTime', 'allowance', 'loan', 'deduction'])
            ->orderBy('process_date', 'desc')
            ->get();

        return response()->json($salaryData);
    }

    /**
     * Store a newly created resource in storage.
     */
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

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
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

    public function getEmployeesByMonthAndCompany(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $company_id = $request->query('company_id');
        $department_id = $request->query('department_id');
        // Optional KPI processing type: 'monthly' or '6month' (default none)
        $kpiTypeRaw = strtolower((string) $request->query('kpi_type', ''));
        $kpiType = in_array($kpiTypeRaw, ['monthly', '6month', 'six_month']) ? $kpiTypeRaw : '';

        // Build the date range strings for the query
        $startDate = "{$year}-{$month}-01";
        $lastDay = date('t', strtotime($startDate));
        $endDate = "{$year}-{$month}-{$lastDay}";

        // Calculate working days by checking leave_calendars for company holidays
        $totalDaysInMonth = $lastDay;

        // Get company leaves for the specified month and company
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

        // Count leave days that fall within the month
        $leaveDaysCount = 0;
        foreach ($companyLeaves as $leave) {
            $leaveStart = max($startDate, $leave->start_date);
            $leaveEnd = $leave->end_date ? min($endDate, $leave->end_date) : $leaveStart;

            // Calculate days between start and end dates (inclusive)
            $leaveDaysCount += date_diff(date_create($leaveStart), date_create($leaveEnd))->days + 1;
        }

        // Calculate actual working days
        $workingDaysInMonth = $totalDaysInMonth - $leaveDaysCount;

        // Build the query with all the data consolidated in one SQL statement
        $query = "
        SELECT
            e.id,
            e.attendance_employee_no AS emp_no,
            e.full_name,
            c.name AS company_name,
            d.name AS department_name,
            sd.name AS sub_department_name,
            comp.basic_salary,
            oa.probationary_period,  -- add probation flag

            -- Compensation flags
            comp.increment_active,
            CASE
                WHEN comp.increment_active = 1 THEN comp.increment_value
                ELSE NULL
            END AS increment_value,
            CASE
                WHEN comp.increment_active = 1 THEN comp.increment_effected_date
                ELSE NULL
            END AS increment_effected_date,
            comp.ot_morning,
            comp.ot_evening,
            comp.enable_epf_etf,
            comp.br1,
            comp.br2,
            comp.ot_morning_rate,
            comp.ot_night_rate,
            comp.stamp,

            -- BR status
            CASE
                WHEN comp.br1 = 1 AND comp.br2 = 1 THEN 'Both BR1 and BR2'
                WHEN comp.br1 = 1 THEN 'BR1 Only'
                WHEN comp.br2 = 1 THEN 'BR2 Only'
                ELSE 'None'
            END AS br_status,

            -- Loans
            COALESCE(SUM(lo.loan_amount), 0) AS total_loan_amount,
            lo.installment_count,
            lo.installment_amount,

            -- Dynamic installment amount for this month (handle last remainder)
            MAX(
                CASE
                    WHEN lo.installment_count = 1
                         AND MOD(lo.loan_amount, lo.installment_amount) > 0
                    THEN MOD(lo.loan_amount, lo.installment_amount)
                    ELSE lo.installment_amount
                END
            ) AS calculated_installment_amount,

            -- No pay records
            COALESCE(COUNT(npr.id), 0) AS approved_no_pay_days,

            -- Consolidated Allowances (as JSON-like string)
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

            -- Consolidated Deductions (as JSON-like string)
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
        FROM
            employees e
        JOIN
            organization_assignments oa ON e.organization_assignment_id = oa.id
        JOIN
            companies c ON oa.company_id = c.id
        LEFT JOIN
            departments d ON oa.department_id = d.id
        LEFT JOIN
            sub_departments sd ON oa.sub_department_id = sd.id
        LEFT JOIN
            compensation comp ON e.id = comp.employee_id
        LEFT JOIN
            loans lo ON e.id = lo.employee_id AND lo.status = 'active'
        LEFT JOIN
            no_pay_records npr ON e.id = npr.employee_id
            AND npr.status = 'Approved'
            AND npr.date BETWEEN ? AND ?
        WHERE
            oa.company_id = ?
    ";

        // Add department filter if specified
        if ($department_id) {
            $query .= " AND oa.department_id = ?";
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
        comp.ot_night_rate,  comp.enable_epf_etf,
        lo.installment_count, lo.installment_amount,
        c.id, oa.department_id,
        comp.stamp,
        oa.probationary_period          -- add to group by
";

        // Prepare parameters
        $params = [$startDate, $endDate, $company_id];
        if ($department_id) {
            $params[] = $department_id;
        }
        $params = array_merge($params, [$endDate, $startDate, $startDate, $endDate, $startDate, $endDate]);

        // Execute the query
        $results = DB::select($query, $params);

        // Process results and calculate salaries
        $data = [];
        foreach ($results as $result) {
            // Parse JSON
            $allowances = json_decode($result->allowances ?? '[]', true) ?: [];
            $deductions = json_decode($result->deductions ?? '[]', true) ?: [];

            $employeeData = (array) $result;
            $employeeData['allowances'] = $allowances;
            $employeeData['deductions'] = $deductions;

            // Stamp fee mapping
            $stampValue = ($result->stamp == 1) ? 25 : 0;
            $employeeData['stamp'] = $stampValue;

            // Base and BR
            $basicSalary = (float) $employeeData['basic_salary'];
            $brAllowance = 0;
            if ($result->br1 == 1 && $result->br2 == 1) {
                $brAllowance = 3500;
            } elseif ($result->br1 == 1) {
                $brAllowance = 1000;
            } elseif ($result->br2 == 1) {
                $brAllowance = 2500;
            }
            $basicSalary += $brAllowance;

            $approvedNoPayDays = (int) $employeeData['approved_no_pay_days'];

            // Use calculated installment amount (handles last remainder)
            $calculatedInstallment = isset($employeeData['calculated_installment_amount'])
                ? (float) $employeeData['calculated_installment_amount']
                : null;
            $installmentAmount = (float) ($calculatedInstallment ?? ($employeeData['installment_amount'] ?? 0));

            // Ensure frontend and storeSalaryData receive the adjusted value
            $employeeData['installment_amount'] = $installmentAmount;

            // 1. Increment
            if (
                !empty($employeeData['increment_active']) &&
                !empty($employeeData['increment_effected_date']) &&
                strtotime($employeeData['increment_effected_date']) <= strtotime($endDate)
            ) {
                $incrementValue = (float) $employeeData['increment_value'];
                $basicSalary += $incrementValue;
            }

            // Probation over-limit days for this month
            $employeeData['probationary_period'] = (bool) ($result->probationary_period ?? false);
            $probationOverLimitDays = 0.0;
            if ($employeeData['probationary_period']) {
                $probationOverLimitDays = (float) (leave_master::where('employee_id', $employeeData['id'])
                    ->where('status', 'Approved') // only approved
                    ->where(function ($q) use ($startDate, $endDate) {
                        $q->whereBetween('leave_date', [$startDate, $endDate])
                            ->orWhere(function ($q2) use ($startDate, $endDate) {
                                $q2->where('leave_from', '<=', $endDate)
                                    ->where('leave_to', '>=', $startDate);
                            });
                    })
                    ->sum('over_limit') ?? 0);
            }
            $employeeData['probation_over_limit'] = $probationOverLimitDays;

            // 2. No-pay + probation over-limit deductions
            $perDaySalary = $basicSalary / $workingDaysInMonth;
            $noPayDeduction = $approvedNoPayDays * $perDaySalary;
            $probationDeduction = $probationOverLimitDays * $perDaySalary;
            $adjustedBasic = $basicSalary - $noPayDeduction - $probationDeduction;

            // KPI calculations (optional)
            $kpiAllowance = 0.0;        // monthly KPI that contributes to allowances (EPF base)
            $kpiBonusAllowance = 0.0;   // six-month KPI bonus that does NOT contribute to EPF base

            if ($kpiType === 'monthly') {
                // Use average percentage for evaluations whose period overlaps the selected month
                $percentage = DB::table('performance_evaluations')
                    ->where('employee_id', $employeeData['id'])
                    ->where(function ($q) use ($startDate, $endDate) {
                        $q->where('start_date', '<=', $endDate)
                            ->where('end_date', '>=', $startDate);
                    })
                    ->avg('percentage');

                if (!is_null($percentage)) {
                    $kpiAllowance = ($adjustedBasic * ((float) $percentage)) / 100.0;

                    // Append as a synthetic allowance entry so it flows into total allowances and EPF base
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
                // Previous 6-month window including selected month
                $sixMonthStart = Carbon::parse($startDate)->subMonths(5)->startOfMonth()->toDateString();

                $percentage = DB::table('performance_appraisals')
                    ->where('employee_id', $employeeData['id'])
                    ->where(function ($q) use ($sixMonthStart, $endDate) {
                        $q->where('start_date', '<=', $endDate)
                            ->where('end_date', '>=', $sixMonthStart);
                    })
                    ->avg('percentage');

                if (!is_null($percentage)) {
                    // This is treated as bonus allowance (excluded from EPF base)
                    $kpiBonusAllowance = ($adjustedBasic * ((float) $percentage)) / 100.0;

                    // Also add it to the allowances list for UI display, but mark as kpi_bonus
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

            // 3. Allowances (include monthly KPI if added above)
            // Ensure the modified allowances array is reflected back on the payload
            $employeeData['allowances'] = $allowances;
            $totalAllowances = array_reduce($allowances, function ($carry, $item) {
                return $carry + (float) $item['amount'];
            }, 0);

            // EPF/ETF eligibility: exclude KPI bonus (6M) from EPF base
            $epfEligibleAllowances = array_reduce($allowances, function ($carry, $item) {
                $category = isset($item['category']) ? strtolower((string) $item['category']) : '';
                if ($category === 'kpi_bonus') {
                    return $carry;
                }
                return $carry + (float) $item['amount'];
            }, 0);

            // EPF/ETF base
            $epfEtfBase = $adjustedBasic + $epfEligibleAllowances;

            // 4. EPF 8%
            $epfEmployeeDeduction = $employeeData['enable_epf_etf'] ? $epfEtfBase * 0.08 : 0;

            // 5. Employer EPF/ETF (display)
            $epfEmployerContribution = $employeeData['enable_epf_etf'] ? $epfEtfBase * 0.12 : 0;
            $etfEmployerContribution = $employeeData['enable_epf_etf'] ? $epfEtfBase * 0.03 : 0;

            // 6. Fixed deductions
            $totalFixedDeductions = array_reduce($deductions, function ($carry, $item) {
                return $carry + (float) $item['amount'];
            }, 0);

            // OT fees
            $morning_ot_fees = 0;
            if ($employeeData['ot_morning'] == 1) {
                $empid = $employeeData['id'];
                $morning_ot_time = over_time::where('employee_id', $empid)
                    ->where('status', 'approved')
                    ->value('morning_ot');
                $morning_ot_fees = $morning_ot_time * $employeeData['ot_morning_rate'];
            }
            $night_ot_fees = 0;
            if ($employeeData['ot_evening'] == 1) {
                $empid = $employeeData['id'];
                $night_ot_time = over_time::where('employee_id', $empid)
                    ->where('status', 'approved')
                    ->value('afternoon_ot');
                $night_ot_fees = $night_ot_time * $employeeData['ot_night_rate'];
            }

            // 7. Gross (include 6-month KPI bonus but exclude it from EPF base)
            $grossSalary = $epfEtfBase + $morning_ot_fees + $night_ot_fees + $kpiBonusAllowance;

            // 8. Net (use adjusted installment)
            $totalDeductions = $totalFixedDeductions + $installmentAmount + $epfEmployeeDeduction;
            $netSalary = $grossSalary - $totalDeductions;

            if ($stampValue) {
                $netSalary -= $stampValue;
            }

            $employeeData['salary_breakdown'] = [
                'basic_salary' => $basicSalary,
                'br_allowance' => $brAllowance,
                'ot_morning_fees' => $morning_ot_fees,
                'ot_night_fees' => $night_ot_fees,
                'adjusted_basic' => $adjustedBasic,
                'per_day_salary' => $perDaySalary,
                'no_pay_deduction' => $noPayDeduction,
                'probation_over_limit_days' => $probationOverLimitDays,   // added
                'probation_deduction' => $probationDeduction,            // added
                'kpi_allowance' => round($kpiAllowance, 2),              // monthly KPI added to allowances
                'kpi_bonus_allowance' => round($kpiBonusAllowance, 2),   // 6-month KPI bonus (excluded from EPF base)
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

            // Include probation flag and over_limit (sum within requested month)
            $employeeData['probationary_period'] = (bool) ($result->probationary_period ?? false);
            $employeeData['probation_over_limit'] = 0.0;

            if ($employeeData['probationary_period']) {
                // Sum over_limit for any leave records falling within the month window
                $overLimitSum = leave_master::where('employee_id', $employeeData['id'])
                    ->where('status', 'Approved') // only approved
                    ->where(function ($q) use ($startDate, $endDate) {
                        $q->whereBetween('leave_date', [$startDate, $endDate])
                            ->orWhere(function ($q2) use ($startDate, $endDate) {
                                $q2->where('leave_from', '<=', $endDate)
                                    ->where('leave_to', '>=', $startDate);
                            });
                    })
                    ->sum('over_limit');

                $employeeData['probation_over_limit'] = (float) ($overLimitSum ?? 0);
            }

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
        $employeeIDs = $request->selectedEmployees; // This is an array of IDs
        $type = $request->bulkActionType;
        $amount = $request->bulkActionAmount; // Changed from 'amount' to match JSON

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
            employee_allowances::insert($records);
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
            employee_deductions::insert($records);
        }

        return response()->json([
            'message' => 'Bulk update successful',
            'affected_employees' => count($employeeIDs),
            'type' => $type,
            'amount' => $amount
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
                    'ot_morning' => $employeeData['salary_breakdown']['ot_morning_fees'] ?? false,
                    'ot_evening' => $employeeData['salary_breakdown']['ot_night_fees'] ?? false,
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
