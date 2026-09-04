<?php

namespace App\Services;

use App\Models\EmployeeLeaveBalance;
use App\Models\MonthlyLateDeductionItem;
use App\Models\MonthlyLateDeductionRun;
use App\Models\NoPayRecord;
use App\Models\employee;
use App\Models\leave_master;
use App\Models\Roster;
use App\Models\time_card;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class MonthlyLateDeductionService
{
    /** First N late days that are ≤ GRACE_MINUTE_LIMIT get no deduction. */
    public const GRACE_DAY_LIMIT = 3;
    /** Late minutes allowed on a grace day (inclusive). */
    public const GRACE_MINUTE_LIMIT = 30;
    /** Any day over grace minutes (e.g. 35) → half-day deduction. */
    public const HALF_DAY = 0.5;
    public const SHORT_LEAVE_DAYS = 0.25; // kept for schema compatibility
    public const DEFAULT_SHIFT_MINUTES = 480; // 8 hours
    public const REASON_TAG = 'Monthly Late Deduction';

    // Legacy constants (no longer used for banding; kept to avoid breakages)
    public const FREE_MINUTES = 90;
    public const ONE_SHORT_MAX = 120;
    public const TWO_SHORT_MAX = 150;

    /**
     * Preview monthly late deductions for employees.
     */
    public function preview(int $year, int $month, ?int $companyId = null, ?string $search = null): array
    {
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();

        $employees = $this->getEmployees($companyId, $search);
        $items = [];

        foreach ($employees as $employee) {
            $calc = $this->calculateForEmployee((int) $employee->id, $startDate, $endDate, $year);

            // Only include employees with any late minutes, or already applied records
            $existing = MonthlyLateDeductionItem::where('employee_id', $employee->id)
                ->where('year', $year)
                ->where('month', $month)
                ->first();

            if ($calc['total_late_minutes'] <= 0 && (!$existing || $existing->status !== 'applied')) {
                continue;
            }

            $items[] = array_merge($calc, [
                'employee_id' => (int) $employee->id,
                'employee_no' => $employee->attendance_employee_no,
                'employee_name' => $employee->display_name
                    ?? $employee->name_with_initials
                    ?? $employee->full_name,
                'company_name' => optional(optional($employee->organizationAssignment)->company)->name,
                'month' => $month,
                'year' => $year,
                'already_applied' => $existing && $existing->status === 'applied',
                'existing_item_id' => $existing?->id,
                'existing_status' => $existing?->status,
            ]);
        }

        usort($items, function ($a, $b) {
            return ($b['total_late_minutes'] <=> $a['total_late_minutes'])
                ?: strcmp($a['employee_name'] ?? '', $b['employee_name'] ?? '');
        });

        $summary = $this->buildSummary($items);

        return [
            'year' => $year,
            'month' => $month,
            'month_name' => $startDate->format('F'),
            'period' => [
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
            ],
            'rules' => $this->rulesMeta(),
            'summary' => $summary,
            'employees' => $items,
        ];
    }

    /**
     * Apply deductions: create leave masters + nopay, persist items.
     */
    public function apply(int $year, int $month, ?int $companyId = null, array $employeeIds = []): array
    {
        $preview = $this->preview($year, $month, $companyId);
        $employees = $preview['employees'];

        if (!empty($employeeIds)) {
            $idSet = array_flip(array_map('intval', $employeeIds));
            $employees = array_values(array_filter($employees, fn ($e) => isset($idSet[$e['employee_id']])));
        }

        $run = MonthlyLateDeductionRun::create([
            'month' => $month,
            'year' => $year,
            'company_id' => $companyId,
            'status' => 'applied',
            'applied_by' => Auth::id(),
            'applied_at' => now(),
            'employee_count' => 0,
        ]);

        $applied = [];
        $skipped = [];
        $errors = [];

        foreach ($employees as $row) {
            if (!empty($row['already_applied'])) {
                $skipped[] = [
                    'employee_id' => $row['employee_id'],
                    'employee_name' => $row['employee_name'],
                    'reason' => 'Already applied for this month',
                ];
                continue;
            }

            if (!($row['has_deduction'] ?? false)
                && ($row['half_day_count'] ?? 0) <= 0
                && ($row['nopay_days'] ?? 0) <= 0
            ) {
                // Within free band — store for audit but no leave/nopay
                $this->upsertItem($run->id, $row, 'skipped', [], [], 'Within free late days (≤30m, first 3 days)');
                $skipped[] = [
                    'employee_id' => $row['employee_id'],
                    'employee_name' => $row['employee_name'],
                    'reason' => 'Within free late allowance (day-by-day)',
                ];
                continue;
            }

            try {
                DB::beginTransaction();

                $leaveIds = $this->createLeaveRecords($row, $year, $month);
                $nopayIds = $this->createNoPayRecord($row, $year, $month);

                $this->upsertItem($run->id, $row, 'applied', $leaveIds, $nopayIds, null);

                DB::commit();

                $applied[] = [
                    'employee_id' => $row['employee_id'],
                    'employee_name' => $row['employee_name'],
                    'short_leave_count' => $row['short_leave_count'],
                    'annual_leave_days' => $row['annual_leave_days'],
                    'casual_leave_days' => $row['casual_leave_days'],
                    'nopay_days' => $row['nopay_days'],
                    'leave_ids' => $leaveIds,
                    'nopay_ids' => $nopayIds,
                ];
            } catch (\Throwable $e) {
                DB::rollBack();
                $errors[] = [
                    'employee_id' => $row['employee_id'],
                    'employee_name' => $row['employee_name'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        $run->update(['employee_count' => count($applied)]);

        return [
            'run_id' => $run->id,
            'applied_count' => count($applied),
            'skipped_count' => count($skipped),
            'error_count' => count($errors),
            'applied' => $applied,
            'skipped' => $skipped,
            'errors' => $errors,
            'preview_summary' => $preview['summary'],
        ];
    }

    public function calculateForEmployee(int $employeeId, Carbon $startDate, Carbon $endDate, int $year): array
    {
        $lateDaysRaw = $this->collectLateDays($employeeId, $startDate, $endDate);
        // Day-by-day policy (not monthly total minutes)
        usort($lateDaysRaw, fn ($a, $b) => strcmp($a['date'], $b['date']));

        $graceUsed = 0;
        $evaluatedDays = [];
        $halfDayCount = 0;
        $halfDayDaysNeeded = 0.0;
        $graceDayCount = 0;

        foreach ($lateDaysRaw as $day) {
            $mins = (int) ($day['late_minutes'] ?? 0);
            if ($mins <= 0) {
                continue;
            }

            if ($mins <= self::GRACE_MINUTE_LIMIT) {
                if ($graceUsed < self::GRACE_DAY_LIMIT) {
                    $graceUsed++;
                    $graceDayCount++;
                    $evaluatedDays[] = array_merge($day, [
                        'action' => 'grace',
                        'deduct_days' => 0.0,
                        'action_label' => "Grace {$graceUsed}/" . self::GRACE_DAY_LIMIT
                            . " (≤" . self::GRACE_MINUTE_LIMIT . "m) — no deduction",
                    ]);
                    continue;
                }

                // Grace used up: even ≤30m late becomes half day
                $halfDayCount++;
                $halfDayDaysNeeded += self::HALF_DAY;
                $evaluatedDays[] = array_merge($day, [
                    'action' => 'half_day',
                    'deduct_days' => self::HALF_DAY,
                    'action_label' => '≤' . self::GRACE_MINUTE_LIMIT
                        . 'm but grace already used — half day',
                ]);
                continue;
            }

            // e.g. 35 minutes → half day that day
            $halfDayCount++;
            $halfDayDaysNeeded += self::HALF_DAY;
            $evaluatedDays[] = array_merge($day, [
                'action' => 'half_day',
                'deduct_days' => self::HALF_DAY,
                'action_label' => '>' . self::GRACE_MINUTE_LIMIT
                    . "m late ({$day['late_display']}) — half day",
            ]);
        }

        $totalLateMinutes = (int) array_sum(array_column($evaluatedDays, 'late_minutes'));
        $lateDayCount = count($evaluatedDays);
        $shiftMinutes = $this->resolveShiftMinutes($employeeId, $startDate, $endDate);
        $balances = $this->getLeaveBalances($employeeId, $year);

        $annualAvailable = (float) ($balances['Annual Leave']['available'] ?? 0);
        $casualAvailable = (float) ($balances['Casual Leave']['available'] ?? 0);

        // Allocate half days: Casual first, then Annual, remainder NoPay
        $remaining = $halfDayDaysNeeded;
        $casualLeaveDays = min($remaining, max(0, $casualAvailable));
        $remaining = round($remaining - $casualLeaveDays, 4);
        $casualLeft = round($casualAvailable - $casualLeaveDays, 4);

        $annualLeaveDays = min($remaining, max(0, $annualAvailable));
        $remaining = round($remaining - $annualLeaveDays, 4);
        $annualLeft = round($annualAvailable - $annualLeaveDays, 4);

        $nopayDays = max(0, $remaining);
        $nopayMinutes = (int) round($nopayDays * $shiftMinutes);

        $band = $halfDayCount > 0
            ? ($nopayDays > 0 ? 'half_day_nopay' : 'half_day')
            : 'grace_only';
        $bandLabel = $halfDayCount > 0
            ? "{$halfDayCount} half-day late deduction(s) (day-by-day)"
            : 'Within grace (≤' . self::GRACE_MINUTE_LIMIT . 'm on first '
                . self::GRACE_DAY_LIMIT . ' late days)';

        $breakdownSteps = $this->buildDayByDayBreakdown(
            $evaluatedDays,
            $graceDayCount,
            $halfDayCount,
            $halfDayDaysNeeded,
            $casualLeaveDays,
            $annualLeaveDays,
            $nopayDays,
            $annualAvailable,
            $casualAvailable
        );

        // Tag deducted days with leave source for createLeaveRecords
        $deductQueue = array_values(array_filter(
            $evaluatedDays,
            fn ($d) => ($d['deduct_days'] ?? 0) > 0
        ));
        $assigned = [];
        $casualSlots = (int) round($casualLeaveDays / self::HALF_DAY);
        $annualSlots = (int) round($annualLeaveDays / self::HALF_DAY);
        $nopaySlots = (int) round($nopayDays / self::HALF_DAY);
        $ci = 0;
        $ai = 0;
        $ni = 0;
        foreach ($deductQueue as $d) {
            if ($ci < $casualSlots) {
                $d['leave_source'] = 'Casual Leave';
                $ci++;
            } elseif ($ai < $annualSlots) {
                $d['leave_source'] = 'Annual Leave';
                $ai++;
            } else {
                $d['leave_source'] = 'NoPay';
                $ni++;
            }
            $assigned[] = $d;
        }

        // Merge assignment back into evaluated days for UI
        $byDate = [];
        foreach ($assigned as $d) {
            $byDate[$d['date']] = $d;
        }
        $lateDaysOut = array_map(function ($d) use ($byDate) {
            if (isset($byDate[$d['date']])) {
                return $byDate[$d['date']];
            }
            return $d;
        }, $evaluatedDays);

        return [
            'total_late_minutes' => $totalLateMinutes,
            'total_late_display' => $this->formatMinutes($totalLateMinutes),
            'late_day_count' => $lateDayCount,
            'late_days' => $lateDaysOut,
            'grace_day_count' => $graceDayCount,
            'grace_day_limit' => self::GRACE_DAY_LIMIT,
            'grace_minute_limit' => self::GRACE_MINUTE_LIMIT,
            'half_day_count' => $halfDayCount,
            'half_day_days' => $halfDayDaysNeeded,
            'free_minutes' => self::GRACE_MINUTE_LIMIT,
            'chargeable_minutes' => (int) array_sum(array_map(
                fn ($d) => ($d['deduct_days'] ?? 0) > 0 ? (int) $d['late_minutes'] : 0,
                $lateDaysOut
            )),
            'excess_minutes' => 0,
            'excess_days' => 0,
            'shift_minutes' => $shiftMinutes,
            'shift_hours' => round($shiftMinutes / 60, 2),
            'band' => $band,
            'band_label' => $bandLabel,
            // Schema compatibility: short_leave_* unused under day-by-day half-day policy
            'short_leave_count' => 0,
            'short_leave_days' => 0,
            'short_from_casual' => 0,
            'short_from_annual' => 0,
            'short_uncovered_days' => 0,
            'annual_leave_days' => $annualLeaveDays,
            'casual_leave_days' => $casualLeaveDays,
            'annual_from_excess' => $annualLeaveDays,
            'casual_from_excess' => $casualLeaveDays,
            'nopay_days' => $nopayDays,
            'nopay_minutes' => $nopayMinutes,
            'nopay_display' => $this->formatMinutes($nopayMinutes),
            'annual_balance_before' => $annualAvailable,
            'casual_balance_before' => $casualAvailable,
            'annual_balance_after' => max(0, $annualLeft),
            'casual_balance_after' => max(0, $casualLeft),
            'has_deduction' => $halfDayCount > 0,
            'breakdown' => $breakdownSteps,
            'deducted_days' => $assigned,
        ];
    }

    private function resolveTier(int $totalLateMinutes): array
    {
        // Legacy — day-by-day policy no longer uses monthly minute tiers.
        return [
            'band' => 'day_by_day',
            'band_label' => 'Day-by-day late policy',
            'short_leave_count' => 0,
            'excess_minutes' => 0,
        ];
    }

    private function collectLateDays(int $employeeId, Carbon $startDate, Carbon $endDate): array
    {
        $cardsByDate = time_card::where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNull('deleted_at')
            ->orderBy('time', 'asc')
            ->get()
            ->groupBy(function ($card) {
                return Carbon::parse($card->date)->toDateString();
            });

        $lateDays = [];

        foreach ($cardsByDate as $date => $dayCards) {
            $inCard = $dayCards->first(function ($card) {
                $st = strtolower(trim((string) $card->status));
                return (int) $card->entry === 1 || in_array($st, ['in', 'late coming', 'late_coming'], true);
            });

            if (!$inCard) {
                continue;
            }

            $shiftStart = $this->getRosterShiftStartTime($employeeId, $date);
            if (!$shiftStart) {
                continue;
            }

            $lateMinutes = $this->getLateMinutes($inCard->time, $shiftStart);
            if ($lateMinutes <= 0) {
                continue;
            }

            $lateDays[] = [
                'date' => $date,
                'in_time' => $inCard->time,
                'shift_start' => $shiftStart,
                'late_minutes' => $lateMinutes,
                'late_display' => $this->formatMinutes($lateMinutes),
                'status' => $inCard->status,
            ];
        }

        return $lateDays;
    }

    private function getLeaveBalances(int $employeeId, int $year): array
    {
        $employee = employee::with('organizationAssignment')->find($employeeId);
        $result = [
            'Annual Leave' => ['total' => 0, 'used' => 0, 'available' => 0],
            'Casual Leave' => ['total' => 0, 'used' => 0, 'available' => 0],
        ];

        if (!$employee) {
            return $result;
        }

        $manual = EmployeeLeaveBalance::where('employee_id', $employeeId)
            ->where('year', $year)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get();

        if ($manual->isNotEmpty()) {
            foreach ($manual as $balance) {
                $type = $balance->leave_type;
                $used = $this->getUsedLeaveDays($employeeId, $type, $year);
                $total = (float) $balance->entitled_days;
                $result[$type] = [
                    'total' => $total,
                    'used' => $used,
                    'available' => max(0, round($total - $used, 4)),
                ];
            }
            return $result;
        }

        $org = $employee->organizationAssignment;
        if (!$org || !$org->date_of_joining) {
            return $result;
        }

        $joinDate = Carbon::parse($org->date_of_joining);
        $asOf = Carbon::create($year, 12, 31);
        if ($asOf->isFuture()) {
            $asOf = Carbon::now();
        }

        $law = app(ShopAndOfficeLeaveCalculator::class)->calculate($joinDate, $asOf);

        foreach (['Annual Leave' => $law['annual_days'], 'Casual Leave' => $law['casual_days']] as $type => $total) {
            $used = $this->getUsedLeaveDays($employeeId, $type, $year);
            $result[$type] = [
                'total' => (float) $total,
                'used' => $used,
                'available' => max(0, round((float) $total - $used, 4)),
            ];
        }

        return $result;
    }

    private function getUsedLeaveDays(int $employeeId, string $leaveType, int $year): float
    {
        $leaves = leave_master::where('employee_id', $employeeId)
            ->where('leave_type', $leaveType)
            ->whereIn('status', ['Approved', 'HR_Approved', 'Pending', 'Pending_Supervisor'])
            ->where(function ($q) use ($year) {
                $q->whereYear('leave_date', $year)
                    ->orWhereYear('leave_from', $year)
                    ->orWhereYear('leave_to', $year);
            })
            ->whereNull('deleted_at')
            ->get();

        $total = 0.0;
        foreach ($leaves as $leave) {
            if ($leave->is_short_leave) {
                $total += 0.25;
            } elseif ($leave->is_half_day) {
                $total += 0.5;
            } else {
                $total += (float) ($leave->leave_duration ?? 1);
            }
        }

        return round($total, 4);
    }

    private function createLeaveRecords(array $row, int $year, int $month): array
    {
        $leaveIds = [];
        $employeeId = (int) $row['employee_id'];
        $reportingDate = now()->toDateString();
        $reasonBase = self::REASON_TAG . " {$year}-" . str_pad((string) $month, 2, '0', STR_PAD_LEFT)
            . " | Day-by-day late | {$row['band_label']}";

        $deducted = $row['deducted_days'] ?? [];
        if (empty($deducted)) {
            $deducted = array_values(array_filter(
                $row['late_days'] ?? [],
                fn ($d) => ($d['deduct_days'] ?? 0) > 0 && ($d['leave_source'] ?? '') !== 'NoPay'
                    && in_array($d['leave_source'] ?? '', ['Casual Leave', 'Annual Leave'], true)
            ));
        }

        foreach ($deducted as $day) {
            $source = $day['leave_source'] ?? null;
            if (!$source || $source === 'NoPay') {
                continue;
            }

            $leave = leave_master::create([
                'employee_id' => $employeeId,
                'reporting_date' => $reportingDate,
                'leave_type' => $source,
                'leave_date' => $day['date'],
                'leave_from' => null,
                'leave_to' => null,
                'is_half_day' => true,
                'period' => 'Morning',
                'is_short_leave' => false,
                'short_leave_slot' => null,
                'leave_duration' => self::HALF_DAY,
                'reason' => $reasonBase
                    . " | {$day['date']} late {$day['late_display']}"
                    . " | " . ($day['action_label'] ?? 'half day')
                    . " | Half day from {$source}",
                'status' => 'Approved',
                'over_limit' => 0,
            ]);
            $leaveIds[] = $leave->id;
        }

        return $leaveIds;
    }

    /**
     * Split a duration into full / half / short leave records.
     */
    private function createDurationLeaves(
        int $employeeId,
        string $leaveType,
        float $days,
        array $lateDays,
        string $anchorDate,
        string $reportingDate,
        string $reason
    ): array {
        $ids = [];
        $remaining = round($days, 4);
        $slot = 0;

        while ($remaining >= 0.999) {
            $date = $this->pickLeaveDate($lateDays, $slot++, $anchorDate);
            $leave = leave_master::create([
                'employee_id' => $employeeId,
                'reporting_date' => $reportingDate,
                'leave_type' => $leaveType,
                'leave_date' => $date,
                'is_half_day' => false,
                'is_short_leave' => false,
                'leave_duration' => 1,
                'reason' => $reason . ' (Full Day)',
                'status' => 'Approved',
                'over_limit' => false,
            ]);
            $ids[] = $leave->id;
            $remaining = round($remaining - 1, 4);
        }

        if ($remaining >= 0.499) {
            $date = $this->pickLeaveDate($lateDays, $slot++, $anchorDate);
            $leave = leave_master::create([
                'employee_id' => $employeeId,
                'reporting_date' => $reportingDate,
                'leave_type' => $leaveType,
                'leave_date' => $date,
                'is_half_day' => true,
                'period' => 'Morning',
                'is_short_leave' => false,
                'leave_duration' => 0.5,
                'reason' => $reason . ' (Half Day)',
                'status' => 'Approved',
                'over_limit' => false,
            ]);
            $ids[] = $leave->id;
            $remaining = round($remaining - 0.5, 4);
        }

        // Remaining fraction as short leave units (0.25) or a fractional leave_duration
        while ($remaining >= 0.249) {
            $date = $this->pickLeaveDate($lateDays, $slot++, $anchorDate);
            $leave = leave_master::create([
                'employee_id' => $employeeId,
                'reporting_date' => $reportingDate,
                'leave_type' => $leaveType,
                'leave_date' => $date,
                'is_half_day' => false,
                'is_short_leave' => true,
                'short_leave_slot' => 'Morning',
                'leave_duration' => 0.25,
                'reason' => $reason . ' (Short Leave unit)',
                'status' => 'Approved',
                'over_limit' => false,
            ]);
            $ids[] = $leave->id;
            $remaining = round($remaining - 0.25, 4);
        }

        if ($remaining > 0.001) {
            $date = $this->pickLeaveDate($lateDays, $slot++, $anchorDate);
            $leave = leave_master::create([
                'employee_id' => $employeeId,
                'reporting_date' => $reportingDate,
                'leave_type' => $leaveType,
                'leave_date' => $date,
                'is_half_day' => false,
                'is_short_leave' => false,
                'leave_duration' => $remaining,
                'reason' => $reason . " (Partial {$remaining} day)",
                'status' => 'Approved',
                'over_limit' => false,
            ]);
            $ids[] = $leave->id;
        }

        return $ids;
    }

    private function createNoPayRecord(array $row, int $year, int $month): array
    {
        $nopayDays = (float) ($row['nopay_days'] ?? 0);
        if ($nopayDays <= 0) {
            return [];
        }

        $lateDays = $row['late_days'] ?? [];
        $date = !empty($lateDays)
            ? end($lateDays)['date']
            : Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $minutes = (int) ($row['nopay_minutes'] ?? 0);
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        // Remove prior auto monthly late nopay for same emp/month to avoid duplicates
        NoPayRecord::where('employee_id', $row['employee_id'])
            ->where('type', 'LATE_MONTHLY')
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->where('description', 'like', '%' . self::REASON_TAG . '%')
            ->delete();

        $record = NoPayRecord::create([
            'employee_id' => $row['employee_id'],
            'date' => $date,
            'no_pay_count' => $nopayDays,
            'description' => self::REASON_TAG
                . " {$year}-" . str_pad((string) $month, 2, '0', STR_PAD_LEFT)
                . " | Day-by-day half-day shortfall after leave | {$row['nopay_display']} ({$nopayDays} day)",
            'status' => 'Approved',
            'processed_by' => Auth::id(),
            'type' => 'LATE_MONTHLY',
            'hours' => $hours,
            'minutes' => $mins,
        ]);

        return [$record->id];
    }

    private function upsertItem(
        int $runId,
        array $row,
        string $status,
        array $leaveIds,
        array $nopayIds,
        ?string $notes
    ): MonthlyLateDeductionItem {
        return MonthlyLateDeductionItem::updateOrCreate(
            [
                'employee_id' => $row['employee_id'],
                'year' => $row['year'],
                'month' => $row['month'],
            ],
            [
                'run_id' => $runId,
                'total_late_minutes' => $row['total_late_minutes'],
                'late_day_count' => $row['late_day_count'],
                'free_minutes' => $row['free_minutes'],
                'chargeable_minutes' => $row['chargeable_minutes'],
                'excess_minutes' => $row['excess_minutes'],
                'short_leave_count' => $row['short_leave_count'],
                'short_leave_days' => $row['short_leave_days'],
                'annual_leave_days' => $row['annual_leave_days'],
                'casual_leave_days' => $row['casual_leave_days'],
                'nopay_days' => $row['nopay_days'],
                'nopay_minutes' => $row['nopay_minutes'],
                'annual_balance_before' => $row['annual_balance_before'],
                'casual_balance_before' => $row['casual_balance_before'],
                'annual_balance_after' => $row['annual_balance_after'],
                'casual_balance_after' => $row['casual_balance_after'],
                'band' => $row['band'],
                'status' => $status,
                'breakdown' => $row['breakdown'],
                'late_days' => $row['late_days'],
                'created_leave_ids' => $leaveIds,
                'created_nopay_ids' => $nopayIds,
                'notes' => $notes,
            ]
        );
    }

    private function pickLeaveDate(array $lateDays, int $index, string $fallback): string
    {
        if (empty($lateDays)) {
            return $fallback;
        }
        $rev = array_values(array_reverse($lateDays));
        $i = $index % count($rev);
        return $rev[$i]['date'];
    }

    private function getEmployees(?int $companyId, ?string $search)
    {
        $query = employee::with(['organizationAssignment.company'])
            ->where('is_active', true)
            ->whereNull('deleted_at');

        if ($companyId) {
            $query->whereHas('organizationAssignment', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        }

        if ($search) {
            $s = trim($search);
            $query->where(function ($q) use ($s) {
                $q->where('full_name', 'like', "%{$s}%")
                    ->orWhere('display_name', 'like', "%{$s}%")
                    ->orWhere('name_with_initials', 'like', "%{$s}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$s}%")
                    ->orWhere('nic', 'like', "%{$s}%");
            });
        }

        return $query->orderBy('full_name')->get();
    }

    private function getLateMinutes(?string $inTime, ?string $shiftStartTime): int
    {
        if (!$inTime || !$shiftStartTime) {
            return 0;
        }

        try {
            $in = Carbon::parse($inTime);
            $start = Carbon::parse($shiftStartTime);
            // Compare time-of-day only
            $inTod = Carbon::createFromTime($in->hour, $in->minute, $in->second);
            $startTod = Carbon::createFromTime($start->hour, $start->minute, $start->second);
            if ($inTod->lessThanOrEqualTo($startTod)) {
                return 0;
            }
            return (int) $startTod->diffInMinutes($inTod);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getRosterShiftStartTime(int $employeeId, string $date): ?string
    {
        $query = Roster::with('shift')
            ->where('employee_id', $employeeId)
            ->where(function ($q) use ($date) {
                $q->whereDate('date_from', '<=', $date)
                    ->whereDate('date_to', '>=', $date);
            });

        $this->excludeCancelledRosters($query);

        $roster = $query->orderBy('date_from', 'desc')->first();

        return $roster?->shift?->start_time;
    }

    private function resolveShiftMinutes(int $employeeId, Carbon $startDate, Carbon $endDate): int
    {
        $query = Roster::with('shift')
            ->where('employee_id', $employeeId)
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereDate('date_from', '<=', $endDate->toDateString())
                    ->whereDate('date_to', '>=', $startDate->toDateString());
            });

        $this->excludeCancelledRosters($query);

        $roster = $query->orderBy('date_from', 'desc')->first();

        if ($roster?->shift?->start_time && $roster?->shift?->end_time) {
            try {
                $start = Carbon::parse($roster->shift->start_time);
                $end = Carbon::parse($roster->shift->end_time);
                if ($end->lessThanOrEqualTo($start)) {
                    $end->addDay();
                }
                $mins = (int) $start->diffInMinutes($end);
                if ($mins > 0) {
                    return $mins;
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return self::DEFAULT_SHIFT_MINUTES;
    }

    /**
     * Only filter cancelled rosters when the status column exists (older live DBs may not have it yet).
     */
    private function excludeCancelledRosters($query): void
    {
        if (!Schema::hasColumn('rosters', 'status')) {
            return;
        }

        $query->where(function ($q) {
            $q->whereNull('status')->orWhere('status', '!=', 'Cancelled');
        });
    }

    private function formatMinutes(int $minutes): string
    {
        $h = intdiv(max(0, $minutes), 60);
        $m = max(0, $minutes) % 60;
        if ($h > 0 && $m > 0) {
            return "{$h}h {$m}m";
        }
        if ($h > 0) {
            return "{$h}h";
        }
        return "{$m}m";
    }

    private function buildDayByDayBreakdown(
        array $evaluatedDays,
        int $graceDayCount,
        int $halfDayCount,
        float $halfDayDaysNeeded,
        float $casualLeaveDays,
        float $annualLeaveDays,
        float $nopayDays,
        float $annualAvailable,
        float $casualAvailable
    ): array {
        $steps = [];
        $step = 1;

        $steps[] = [
            'step' => $step++,
            'title' => 'Policy',
            'detail' => 'Day-by-day late check (not monthly total). First '
                . self::GRACE_DAY_LIMIT . ' late days ≤'
                . self::GRACE_MINUTE_LIMIT . 'm = no deduction. Any day >'
                . self::GRACE_MINUTE_LIMIT . 'm = half day.',
            'type' => 'info',
        ];

        $steps[] = [
            'step' => $step++,
            'title' => 'Grace days used',
            'detail' => "{$graceDayCount} / " . self::GRACE_DAY_LIMIT . ' (≤'
                . self::GRACE_MINUTE_LIMIT . 'm late, no deduction)',
            'type' => $graceDayCount > 0 ? 'ok' : 'info',
        ];

        foreach ($evaluatedDays as $day) {
            $type = ($day['action'] ?? '') === 'grace' ? 'ok' : 'deduct';
            $steps[] = [
                'step' => $step++,
                'title' => $day['date'] . ' — late ' . ($day['late_display'] ?? ''),
                'detail' => $day['action_label'] ?? '',
                'type' => $type,
            ];
        }

        if ($halfDayCount <= 0) {
            $steps[] = [
                'step' => $step++,
                'title' => 'Result',
                'detail' => 'No half-day deduction this month',
                'type' => 'ok',
            ];
            return $steps;
        }

        $steps[] = [
            'step' => $step++,
            'title' => 'Half days required',
            'detail' => "{$halfDayCount} day(s) × 0.5 = {$halfDayDaysNeeded} day(s)",
            'type' => 'deduct',
        ];

        if ($casualLeaveDays > 0) {
            $steps[] = [
                'step' => $step++,
                'title' => 'From Casual Leave',
                'detail' => "{$casualLeaveDays} day(s) (balance was {$casualAvailable})",
                'type' => 'casual',
            ];
        }
        if ($annualLeaveDays > 0) {
            $steps[] = [
                'step' => $step++,
                'title' => 'From Annual Leave',
                'detail' => "{$annualLeaveDays} day(s) (balance was {$annualAvailable})",
                'type' => 'annual',
            ];
        }
        if ($nopayDays > 0) {
            $steps[] = [
                'step' => $step++,
                'title' => 'NoPay (remaining)',
                'detail' => "{$nopayDays} day(s) — leave balances exhausted",
                'type' => 'nopay',
            ];
        }

        return $steps;
    }

    private function buildBreakdownSteps(
        int $totalLateMinutes,
        array $tier,
        int $shortLeaveCount,
        float $shortFromCasual,
        float $shortFromAnnual,
        float $shortUncovered,
        int $excessMinutes,
        float $excessDays,
        float $annualFromExcess,
        float $casualFromExcess,
        float $nopayDays,
        float $annualAvailable,
        float $casualAvailable,
        int $shiftMinutes
    ): array {
        // Legacy monthly-total breakdown kept for compatibility; day-by-day uses buildDayByDayBreakdown.
        return [[
            'step' => 1,
            'title' => 'Legacy band',
            'detail' => $tier['band_label'] ?? 'n/a',
            'type' => 'info',
        ]];
    }

    public function rulesMeta(): array
    {
        return [
            [
                'band' => 'Day-by-day (not monthly total)',
                'action' => 'Each late punch is judged on that day alone',
                'color' => 'blue',
            ],
            [
                'band' => 'First 3 late days ≤ 30 minutes',
                'action' => 'No deduction (grace)',
                'color' => 'green',
            ],
            [
                'band' => 'Any day > 30 minutes (e.g. 35m)',
                'action' => 'Half-day deduction that day',
                'color' => 'orange',
            ],
            [
                'band' => '≤ 30m after grace used up',
                'action' => 'Half-day deduction that day',
                'color' => 'amber',
            ],
            [
                'band' => 'Leave / NoPay',
                'action' => 'Half days taken from Casual → Annual → remaining NoPay',
                'color' => 'red',
            ],
        ];
    }

    private function buildSummary(array $items): array
    {
        $withLate = count($items);
        $withDeduction = 0;
        $halfDays = 0;
        $annualDays = 0.0;
        $casualDays = 0.0;
        $nopayDays = 0.0;
        $totalLateMinutes = 0;
        $applied = 0;

        foreach ($items as $item) {
            $totalLateMinutes += (int) ($item['total_late_minutes'] ?? 0);
            if (!empty($item['has_deduction'])) {
                $withDeduction++;
            }
            $halfDays += (int) ($item['half_day_count'] ?? 0);
            $annualDays += (float) ($item['annual_leave_days'] ?? 0);
            $casualDays += (float) ($item['casual_leave_days'] ?? 0);
            $nopayDays += (float) ($item['nopay_days'] ?? 0);
            if (!empty($item['already_applied'])) {
                $applied++;
            }
        }

        return [
            'employees_with_late' => $withLate,
            'employees_with_deduction' => $withDeduction,
            'already_applied' => $applied,
            'total_late_minutes' => $totalLateMinutes,
            'total_late_display' => $this->formatMinutes($totalLateMinutes),
            'total_short_leaves' => 0,
            'total_half_days' => $halfDays,
            'total_annual_days' => round($annualDays, 4),
            'total_casual_days' => round($casualDays, 4),
            'total_nopay_days' => round($nopayDays, 4),
        ];
    }

    /**
     * Whether monthly late deduction was already applied for employee/month.
     */
    public function isApplied(int $employeeId, int $year, int $month): bool
    {
        return MonthlyLateDeductionItem::where('employee_id', $employeeId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'applied')
            ->exists();
    }
}
