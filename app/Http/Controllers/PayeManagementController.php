<?php

namespace App\Http\Controllers;

use App\Models\company;
use App\Models\employee;
use App\Models\Holiday;
use App\Models\leaveCalendar;
use App\Models\PayeTaxTable;
use App\Models\salary_process;
use App\Models\time_card;
use App\Services\CompanyProcessSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class PayeManagementController extends Controller
{
    /**
     * Display a listing of PAYE calculations for employees.
     */
    public function index(Request $request)
    {
        $companyId = $request->query('company_id');
        $month = (int) ($request->query('salary_month') ?? $request->query('month') ?? date('n'));
        $year = (int) ($request->query('salary_year') ?? $request->query('year') ?? date('Y'));
        $search = trim((string) $request->query('search', ''));
        $employeeNo = trim((string) $request->query('employee_no', ''));

        if (!$companyId && $request->user()) {
            $userCompanyId = $request->user()->employee?->organizationAssignment?->company_id;
            if ($userCompanyId) {
                $companyId = $userCompanyId;
            }
        }

        if (!$companyId) {
            $firstComp = company::query()->where('portal_active', true)->first()
                ?? company::query()->orderBy('id')->first();
            $companyId = $firstComp?->id;
        }

        $company = $companyId ? company::find((int) $companyId) : null;
        $featureEnabled = $company ? CompanyProcessSettings::usesStampDutyPayeHoliday($company) : false;

        if (!$featureEnabled) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'data' => [],
                'feature_enabled' => false,
                'company_enabled' => false,
                'company' => $company ? [
                    'id' => $company->id,
                    'name' => $company->name,
                    'company_code' => $company->company_code,
                ] : null,
                'message' => 'Stamp Duty, PAYE, No-Pay and Off-Day + Holiday Processing feature is not enabled for this company in Cybernetic Admin.',
                'summary' => [
                    'total_gross' => 0,
                    'total_taxable' => 0,
                    'total_taxable_remuneration' => 0,
                    'total_paye' => 0,
                    'total_paye_deduction' => 0,
                    'total_employees' => 0,
                    'taxable_employees_count' => 0,
                    'paye_active_count' => 0,
                ],
            ], 403);
        }

        $empQuery = employee::query()
            ->with([
                'organizationAssignment.company:id,name,company_code,nopay_working_days',
                'organizationAssignment.department:id,name',
                'compensation',
            ])
            ->whereHas('organizationAssignment', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            })
            ->whereHas('compensation', function ($q) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('compensation', 'paye_active')) {
                    $q->where('paye_active', true);
                }
            });

        if ($employeeNo !== '') {
            $empQuery->where(function ($q) use ($employeeNo) {
                $q->where('attendance_employee_no', $employeeNo)
                  ->orWhere('id', $employeeNo);
            });
        } elseif ($search !== '') {
            $empQuery->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('name_with_initials', 'like', "%{$search}%")
                  ->orWhere('attendance_employee_no', 'like', "%{$search}%")
                  ->orWhere('nic', 'like', "%{$search}%");
            });
        }

        $employees = $empQuery->orderBy('attendance_employee_no')->get();
        $empIds = $employees->pluck('id')->all();

        // Check for existing processed salaries
        $monthKeys = [str_pad((string) $month, 2, '0', STR_PAD_LEFT), (string) $month, (int) $month];
        $empNos = $employees->pluck('attendance_employee_no')->filter()->values()->all();
        $salaryProcesses = salary_process::query()
            ->whereIn('employee_no', $empNos)
            ->whereIn('month', $monthKeys)
            ->where('year', $year)
            ->get()
            ->keyBy('employee_no');

        // Resolve active tax table
        $dateStr = sprintf('%04d-%02d-01', $year, $month);
        $taxTable = null;
        if (\Illuminate\Support\Facades\Schema::hasTable('paye_tax_tables')) {
            $taxTable = PayeTaxTable::query()
                ->where('is_active', true)
                ->where('effective_from', '<=', $dateStr)
                ->where(function ($q) use ($dateStr) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', $dateStr);
                })
                ->orderBy('effective_from', 'desc')
                ->first() ?? PayeTaxTable::query()->where('is_active', true)->orderBy('id', 'desc')->first();
        }

        // Month boundary for pre-payroll Gross Salary calculation
        $monthStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $monthEnd = (clone $monthStart)->endOfMonth();
        $startDateStr = $monthStart->toDateString();
        $endDateStr = $monthEnd->toDateString();

        // Retrieve holidays in this month safely
        $holidayDates = [];
        if (Schema::hasTable('holidays') && Schema::hasColumn('holidays', 'date')) {
            $holidayDates = Holiday::query()
                ->whereNull('deleted_at')
                ->whereBetween('date', [$startDateStr, $endDateStr])
                ->pluck('date')
                ->map(fn($d) => Carbon::parse($d)->toDateString())
                ->all();
        }

        if (class_exists(leaveCalendar::class)) {
            $leaveCalDates = leaveCalendar::query()
                ->where(function ($q) use ($companyId) {
                    $q->where('company_id', $companyId)
                      ->orWhereNull('company_id');
                })
                ->where(function ($q) use ($startDateStr, $endDateStr) {
                    $q->where('start_date', '<=', $endDateStr)
                      ->where('end_date', '>=', $startDateStr);
                })
                ->get();

            foreach ($leaveCalDates as $cal) {
                $cur = Carbon::parse($cal->start_date);
                $calEnd = Carbon::parse($cal->end_date ?? $cal->start_date);
                while ($cur->lte($calEnd)) {
                    if ($cur->between($monthStart, $monthEnd)) {
                        $holidayDates[] = $cur->toDateString();
                    }
                    $cur->addDay();
                }
            }
        }
        $holidayDates = array_values(array_unique($holidayDates));

        // Group attendance punches and no-pay records by employee
        $attendanceRecords = time_card::query()
            ->whereIn('employee_id', $empIds)
            ->whereBetween('date', [$startDateStr, $endDateStr])
            ->whereNull('deleted_at')
            ->get(['employee_id', 'date', 'status'])
            ->groupBy('employee_id');

        $noPayRecords = DB::table('no_pay_records')
            ->whereIn('employee_id', $empIds)
            ->whereBetween('date', [$startDateStr, $endDateStr])
            ->whereNull('deleted_at')
            ->whereIn('status', ['Approved', 'Pending'])
            ->select('employee_id', DB::raw('SUM(no_pay_count) as total_no_pay'))
            ->groupBy('employee_id')
            ->pluck('total_no_pay', 'employee_id');

        $rows = [];
        $totalGross = 0.0;
        $totalTaxable = 0.0;
        $totalPaye = 0.0;
        $taxableEmployeesCount = 0;

        foreach ($employees as $emp) {
            $comp = $emp->compensation;
            $basicSalary = (float) ($comp?->basic_salary ?? 0);
            $incrementAmount = (float) ($comp?->increment_amount ?? 0);
            $payeSettingActive = (bool) ($comp?->paye_active ?? false);
            $attendanceNo = (string) ($emp->attendance_employee_no ?? $emp->id);
            $salProcess = $salaryProcesses->get($attendanceNo);

            $holidaySettingActive = (bool) ($comp?->holiday_processing ?? false);
            $offDaySettingActive = (bool) ($comp?->off_day_processing ?? false);
            $activeNopay = !empty($comp?->active_nopay);
            $divisor = (int) ($comp?->nopay_working_days ?? 26);
            if ($divisor <= 0) $divisor = 26;
            $dailyRate = round($basicSalary / $divisor, 2);

            $taxTableName = $taxTable?->name ?? 'Standard APIT';
            $relief = (float) ($taxTable?->personal_relief_monthly ?? 0.0);

            if ($salProcess) {
                $sb = is_string($salProcess->salary_breakdown)
                    ? json_decode($salProcess->salary_breakdown, true)
                    : ($salProcess->salary_breakdown ?? []);

                // Gross Salary = Basic Salary + Holiday Payment + Off-Day Payment + Increment Amount - No-Pay Amount
                $grossSalary = (float) ($sb['gross_salary'] ?? $sb['total_for_epf'] ?? $salProcess->gross_salary ?? $basicSalary);
                $payeAmount = (float) ($sb['paye_amount'] ?? $sb['paye'] ?? 0);
                $holidayAmount = $holidaySettingActive ? (float) ($sb['holiday_amount'] ?? $sb['holiday_ot_fees'] ?? 0) : 0.0;
                $offDayAmount = $offDaySettingActive ? (float) ($sb['off_day_amount'] ?? 0) : 0.0;
                $noPayAmount = (float) ($sb['nopay_amount'] ?? $sb['full_day_nopay_deduction'] ?? 0);
                $incrementAmount = (float) ($sb['increment_amount'] ?? $incrementAmount);
                $calculationStatus = 'Processed in Payroll (' . ucfirst($salProcess->status ?? 'Processed') . ')';

                // Recalculate breakdown details for presentation
                if ($payeSettingActive) {
                    $calc = PayeTaxTable::calculatePaye($grossSalary, $dateStr, $taxTable);
                    $taxableAmount = $calc['taxable_amount'];
                    $bandBreakdown = $calc['band_breakdown'];
                    $taxTableName = $calc['tax_table_name'];
                    $relief = $calc['personal_relief_monthly'];
                    if ($payeAmount <= 0 && $calc['paye_amount'] > 0) {
                        $payeAmount = $calc['paye_amount'];
                    }
                } else {
                    $payeAmount = 0.0;
                    $taxableAmount = 0.0;
                    $bandBreakdown = [];
                    $calculationStatus = 'Disabled in Employment Settings';
                }
            } else {
                // Pre-payroll computation using official formula:
                // Gross Salary = Basic Salary + Holiday Payment + Off-Day Payment + Increment Amount - No-Pay Amount
                $empPunches = $attendanceRecords->get($emp->id) ?? collect();
                $workedDates = $empPunches
                    ->whereNotIn('status', ['absent', 'rejected', 'leave'])
                    ->pluck('date')
                    ->map(fn($d) => Carbon::parse($d)->toDateString())
                    ->unique()
                    ->all();

                $configuredDayOff = trim((string) ($emp->organizationAssignment?->day_off ?? ''));
                $workedOffDays = 0;
                $workedHolidays = 0;

                $mCur = clone $monthStart;
                while ($mCur->lte($monthEnd)) {
                    $dStr = $mCur->toDateString();
                    $dayName = $mCur->format('l');
                    $isOff = $configuredDayOff !== '' && strcasecmp($configuredDayOff, 'none') !== 0 && strcasecmp($dayName, $configuredDayOff) === 0;
                    $isHol = in_array($dStr, $holidayDates, true);
                    $didWork = in_array($dStr, $workedDates, true);

                    if ($didWork) {
                        if ($isHol && $holidaySettingActive) {
                            $workedHolidays++;
                        } elseif ($isOff && $offDaySettingActive) {
                            $workedOffDays++;
                        } elseif ($isHol && !$holidaySettingActive && $isOff && $offDaySettingActive) {
                            $workedOffDays++;
                        }
                    }
                    $mCur->addDay();
                }

                $offDayAmount = $offDaySettingActive ? round($dailyRate * $workedOffDays, 2) : 0.0;
                $holidayAmount = $holidaySettingActive ? round($dailyRate * $workedHolidays, 2) : 0.0;

                $noPayCount = (float) ($noPayRecords[$emp->id] ?? 0);
                $noPayAmount = $activeNopay ? round($dailyRate * $noPayCount, 2) : 0.0;

                $grossSalary = max(0, round($basicSalary + $holidayAmount + $offDayAmount + $incrementAmount - $noPayAmount, 2));

                if (!$payeSettingActive) {
                    $payeAmount = 0.0;
                    $taxableAmount = 0.0;
                    $bandBreakdown = [];
                    $calculationStatus = 'Disabled in Employment Settings';
                } else {
                    $calc = PayeTaxTable::calculatePaye($grossSalary, $dateStr, $taxTable);
                    $taxableAmount = $calc['taxable_amount'];
                    $payeAmount = $calc['paye_amount'];
                    $bandBreakdown = $calc['band_breakdown'];
                    $taxTableName = $calc['tax_table_name'];
                    $relief = $calc['personal_relief_monthly'];
                    $calculationStatus = $calc['status'];
                }
            }

            $totalGross += $grossSalary;
            $totalTaxable += $taxableAmount;
            $totalPaye += $payeAmount;
            if ($payeAmount > 0) {
                $taxableEmployeesCount++;
            }

            $empName = !empty($emp->full_name) ? $emp->full_name : (!empty($emp->name_with_initials) ? $emp->name_with_initials : 'Unnamed');
            $statusLabel = !$payeSettingActive
                ? 'Exempt'
                : ($payeAmount > 0 ? 'Taxable' : 'Exempt');

            $rows[] = [
                'id' => $emp->id,
                'employee_id' => $emp->id,
                'employee_no' => $attendanceNo,
                'name' => $empName,
                'employee_name' => $empName,
                'company_id' => $companyId,
                'company_name' => $emp->organizationAssignment?->company?->name ?? $company?->name ?? '',
                'department_name' => $emp->organizationAssignment?->department?->name ?? '-',
                'basic_salary' => round($basicSalary, 2),
                'increment_amount' => round($incrementAmount, 2),
                'holiday_amount' => round($holidayAmount, 2),
                'off_day_amount' => round($offDayAmount, 2),
                'nopay_amount' => round($noPayAmount, 2),
                'gross_salary' => round($grossSalary, 2),
                'taxable_remuneration' => round($grossSalary, 2),
                'tax_table_name' => $taxTableName,
                'relief_amount' => round($relief, 2),
                'tax_relief_monthly' => round($relief, 2),
                'taxable_amount' => round($taxableAmount, 2),
                'paye_amount' => round($payeAmount, 2),
                'applicable_formula' => $calc['applicable_formula'] ?? '',
                'applicable_range' => $calc['applicable_range'] ?? '',
                'applicable_rate' => $calc['applicable_rate'] ?? 0,
                'fixed_deduction' => $calc['fixed_deduction'] ?? 0,
                'paye_setting_active' => $payeSettingActive,
                'band_breakdown' => $bandBreakdown,
                'status' => $statusLabel,
                'calculation_status' => $calculationStatus,
                'salary_month' => sprintf('%04d-%02d', $year, $month),
                'has_payroll_record' => !empty($salProcess),
                'payroll_status' => $salProcess?->status ?? null,
            ];
        }

        $totalRecords = count($rows);

        if ($request->filled('page') && $request->filled('per_page') && !$request->has('all')) {
            $perPage = (int) $request->query('per_page');
            $page = (int) $request->query('page', 1);
            $offset = ($page - 1) * $perPage;
            $pagedData = array_slice($rows, $offset, $perPage);
        } else {
            $page = 1;
            $perPage = $totalRecords;
            $pagedData = $rows;
        }

        return response()->json([
            'data' => $pagedData,
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'company_code' => $company->company_code,
            ] : null,
            'tax_table' => $taxTable ? [
                'id' => $taxTable->id,
                'name' => $taxTable->name,
                'code' => $taxTable->code,
                'effective_from' => $taxTable->effective_from?->format('Y-m-d'),
                'effective_to' => $taxTable->effective_to?->format('Y-m-d'),
                'personal_relief_monthly' => (float) $taxTable->personal_relief_monthly,
                'personal_relief_annual' => (float) $taxTable->personal_relief_annual,
                'description' => $taxTable->description,
                'slabs' => $taxTable->slabs,
                'range_table' => $taxTable->getRangeTable(),
            ] : null,
            'company_enabled' => true,
            'feature_enabled' => true,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalRecords,
                'last_page' => $perPage > 0 ? (int) ceil($totalRecords / $perPage) : 1,
            ],
            'summary' => [
                'total_gross' => round($totalGross, 2),
                'total_taxable' => round($totalTaxable, 2),
                'total_taxable_remuneration' => round($totalTaxable, 2),
                'total_paye' => round($totalPaye, 2),
                'total_paye_deduction' => round($totalPaye, 2),
                'total_employees' => $totalRecords,
                'taxable_employees_count' => $taxableEmployeesCount,
                'paye_active_count' => count(array_filter($rows, fn($r) => !empty($r['paye_setting_active']))),
            ],
        ]);
    }

    /**
     * Get available tax tables.
     */
    public function taxTables()
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('paye_tax_tables')) {
            return response()->json(['data' => []]);
        }
        $tables = PayeTaxTable::query()->orderBy('effective_from', 'desc')->get();
        return response()->json(['data' => $tables]);
    }

    /**
     * Store or update a tax table.
     */
    public function saveTaxTable(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date',
            'personal_relief_monthly' => 'required|numeric|min:0',
            'personal_relief_annual' => 'required|numeric|min:0',
            'slabs' => 'required|array|min:1',
            'is_active' => 'sometimes|boolean',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $id = $request->input('id');
        $payload = $validator->validated();

        if ($id) {
            $table = PayeTaxTable::findOrFail($id);
            $table->update($payload);
        } else {
            $table = PayeTaxTable::create($payload);
        }

        return response()->json(['data' => $table, 'message' => 'Tax table saved successfully.']);
    }
}
