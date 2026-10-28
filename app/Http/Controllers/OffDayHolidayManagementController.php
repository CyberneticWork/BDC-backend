<?php

namespace App\Http\Controllers;

use App\Models\company;
use App\Models\employee;
use App\Models\Holiday;
use App\Models\leaveCalendar;
use App\Models\salary_process;
use App\Models\time_card;
use App\Services\CompanyProcessSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OffDayHolidayManagementController extends Controller
{
    /**
     * Display a listing of employee off-day and holiday work records and calculated amounts.
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
                'message' => 'Stamp Duty, PAYE, No-Pay and Off-Day + Holiday Processing feature is not enabled for this company in Cybernetic Admin.',
                'summary' => [
                    'total_off_day_amount' => 0,
                    'total_off_day_pay' => 0,
                    'total_holiday_amount' => 0,
                    'total_holiday_pay' => 0,
                    'total_combined_amount' => 0,
                    'total_additional_pay' => 0,
                    'total_employees' => 0,
                    'total_off_days_worked' => 0,
                    'total_holidays_worked' => 0,
                    'employees_with_off_day_work' => 0,
                    'employees_with_holiday_work' => 0,
                ],
            ], 403);
        }

        // Configure divisor (default: company nopay_working_days or 30)
        $divisor = (float) ($company?->nopay_working_days ?? 30);
        if ($divisor < 1) {
            $divisor = 30.0;
        }

        // Determine month date range
        $monthStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $monthEnd = Carbon::createFromDate($year, $month, 1)->endOfMonth();
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

        // Also retrieve company leave calendar dates (mercantile / public holidays)
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
        $holidayDates = array_values(array_unique($holidayDates));

        // Query employees with either off-day processing or holiday processing enabled
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
                $hasOffDay = \Illuminate\Support\Facades\Schema::hasColumn('compensation', 'off_day_processing');
                $hasHoliday = \Illuminate\Support\Facades\Schema::hasColumn('compensation', 'holiday_processing');
                if ($hasOffDay && $hasHoliday) {
                    $q->where(function ($sub) {
                        $sub->where('off_day_processing', true)
                            ->orWhere('holiday_processing', true);
                    });
                } elseif ($hasOffDay) {
                    $q->where('off_day_processing', true);
                } elseif ($hasHoliday) {
                    $q->where('holiday_processing', true);
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

        // Fetch attendance punches for this month for all queried employees
        $attendanceRecords = time_card::query()
            ->whereIn('employee_id', $empIds)
            ->whereBetween('date', [$startDateStr, $endDateStr])
            ->whereNull('deleted_at')
            ->get(['employee_id', 'date', 'status', 'time', 'entry'])
            ->groupBy('employee_id');

        $rows = [];
        $totalOffDayAmount = 0.0;
        $totalHolidayAmount = 0.0;
        $totalCombinedAmount = 0.0;
        $empWithOffDayWork = 0;
        $empWithHolidayWork = 0;

        foreach ($employees as $emp) {
            $comp = $emp->compensation;
            $basicSalary = (float) ($comp?->basic_salary ?? 0);
            $dailyRate = $divisor > 0 ? round($basicSalary / $divisor, 2) : 0.0;
            $offDaySettingActive = (bool) ($comp?->off_day_processing ?? false);
            $holidaySettingActive = (bool) ($comp?->holiday_processing ?? false);
            $configuredDayOff = trim((string) ($emp->organizationAssignment?->day_off ?? ''));
            $attendanceNo = (string) ($emp->attendance_employee_no ?? $emp->id);
            $salProcess = $salaryProcesses->get($attendanceNo);

            $empPunches = $attendanceRecords->get($emp->id) ?? collect();
            // Distinct dates worked (where employee had punch records that are not purely absent)
            $workedDates = $empPunches
                ->filter(function ($card) {
                    $st = strtolower(trim((string) $card->status));
                    return !in_array($st, ['absent', 'rejected', 'leave'], true);
                })
                ->map(fn($card) => Carbon::parse($card->date)->toDateString())
                ->unique()
                ->values()
                ->all();

            $workedOffDays = [];
            $workedHolidays = [];

            // Process each day in the month
            $cursor = $monthStart->copy();
            while ($cursor->lte($monthEnd)) {
                $dateStr = $cursor->toDateString();
                $dayName = $cursor->format('l'); // Sunday, Monday, etc.

                $isConfiguredOffDay = $configuredDayOff !== ''
                    && strcasecmp($configuredDayOff, 'none') !== 0
                    && strcasecmp($dayName, $configuredDayOff) === 0;

                $isHoliday = in_array($dateStr, $holidayDates, true);
                $worked = in_array($dateStr, $workedDates, true);

                if ($worked) {
                    // Holiday takes precedence if enabled
                    if ($isHoliday && $holidaySettingActive) {
                        $workedHolidays[] = $dateStr;
                    } elseif ($isConfiguredOffDay && $offDaySettingActive) {
                        $workedOffDays[] = $dateStr;
                    } elseif ($isHoliday && !$holidaySettingActive && $isConfiguredOffDay && $offDaySettingActive) {
                        // When holiday setting is OFF but day is also configured off-day, off-day applies
                        $workedOffDays[] = $dateStr;
                    }
                }

                $cursor->addDay();
            }

            $offDayCount = $offDaySettingActive ? count($workedOffDays) : 0;
            $holidayCount = $holidaySettingActive ? count($workedHolidays) : 0;

            if ($salProcess) {
                $sb = is_string($salProcess->salary_breakdown)
                    ? json_decode($salProcess->salary_breakdown, true)
                    : ($salProcess->salary_breakdown ?? []);

                if ($offDaySettingActive && isset($sb['off_day_amount'])) {
                    $offDayAmount = (float) $sb['off_day_amount'];
                    $offDayCount = (int) ($sb['off_day_count'] ?? $offDayCount);
                } else {
                    $offDayAmount = $offDaySettingActive ? round($dailyRate * $offDayCount, 2) : 0.0;
                }

                if ($holidaySettingActive && (isset($sb['holiday_amount']) || isset($sb['holiday_ot_fees']))) {
                    $holidayAmount = (float) ($sb['holiday_amount'] ?? $sb['holiday_ot_fees']);
                    $holidayCount = (int) ($sb['holiday_count'] ?? $holidayCount);
                } else {
                    $holidayAmount = $holidaySettingActive ? round($dailyRate * $holidayCount, 2) : 0.0;
                }

                $totalAmount = round($offDayAmount + $holidayAmount, 2);
                $status = 'Processed in Payroll (' . ucfirst($salProcess->status ?? 'Processed') . ')';
            } else {
                $offDayAmount = $offDaySettingActive ? round($dailyRate * $offDayCount, 2) : 0.0;
                $holidayAmount = $holidaySettingActive ? round($dailyRate * $holidayCount, 2) : 0.0;
                $totalAmount = round($offDayAmount + $holidayAmount, 2);
                $status = $totalAmount > 0 ? 'Eligible' : 'No Additional Pay';
            }

            if ($offDayCount > 0) {
                $empWithOffDayWork++;
            }
            if ($holidayCount > 0) {
                $empWithHolidayWork++;
            }

            $totalOffDayAmount += $offDayAmount;
            $totalHolidayAmount += $holidayAmount;
            $totalCombinedAmount += $totalAmount;

            $empName = $emp->full_name ?? $emp->name_with_initials ?? 'Unnamed';

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
                'daily_rate' => $dailyRate,
                'divisor' => (int) $divisor,
                'configured_day_off' => $configuredDayOff ?: 'Not specified',
                'scheduled_day_off' => $configuredDayOff ?: 'Sunday',
                'off_day_processing' => $offDaySettingActive,
                'off_day_processing_active' => $offDaySettingActive,
                'off_days_worked' => $offDayCount,
                'off_day_count' => $offDayCount,
                'off_day_amount' => $offDayAmount,
                'worked_off_days' => $workedOffDays,
                'holiday_processing' => $holidaySettingActive,
                'holiday_processing_active' => $holidaySettingActive,
                'holidays_worked' => $holidayCount,
                'holiday_count' => $holidayCount,
                'holiday_amount' => $holidayAmount,
                'worked_holidays' => $workedHolidays,
                'total_amount' => $totalAmount,
                'total_off_day_holiday_amount' => $totalAmount,
                'status' => $status,
                'salary_month' => sprintf('%04d-%02d', $year, $month),
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
            'company_enabled' => true,
            'feature_enabled' => true,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalRecords,
                'last_page' => $perPage > 0 ? (int) ceil($totalRecords / $perPage) : 1,
            ],
            'summary' => [
                'total_employees' => $totalRecords,
                'total_off_days_worked' => array_sum(array_column($rows, 'off_days_worked')),
                'total_off_day_pay' => round($totalOffDayAmount, 2),
                'total_off_day_amount' => round($totalOffDayAmount, 2),
                'total_holidays_worked' => array_sum(array_column($rows, 'holidays_worked')),
                'total_holiday_pay' => round($totalHolidayAmount, 2),
                'total_holiday_amount' => round($totalHolidayAmount, 2),
                'total_additional_pay' => round($totalCombinedAmount, 2),
                'total_combined_amount' => round($totalCombinedAmount, 2),
                'employees_with_off_day_work' => $empWithOffDayWork,
                'employees_with_holiday_work' => $empWithHolidayWork,
                'divisor' => (int) $divisor,
                'holidays_in_month' => count($holidayDates),
            ],
        ]);
    }
}
