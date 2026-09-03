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
    public const FREE_MINUTES = 90;          // 1h 30m — no action
    public const ONE_SHORT_MAX = 120;        // >90 and <=120 → 1 short leave
    public const TWO_SHORT_MAX = 150;        // >120 and <=150 → 2 short leaves
    public const SHORT_LEAVE_DAYS = 0.25;    // each short leave = 0.25 day
    public const DEFAULT_SHIFT_MINUTES = 480; // 8 hours
    public const REASON_TAG = 'Monthly Late Deduction';

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

            if (($row['short_leave_count'] ?? 0) <= 0
                && ($row['annual_leave_days'] ?? 0) <= 0
                && ($row['casual_leave_days'] ?? 0) <= 0
                && ($row['nopay_days'] ?? 0) <= 0
            ) {
                // Within free band — store for audit but no leave/nopay
                $this->upsertItem($run->id, $row, 'skipped', [], [], 'Within free allowance (≤ 1h 30m)');
                $skipped[] = [
                    'employee_id' => $row['employee_id'],
                    'employee_name' => $row['employee_name'],
                    'reason' => 'Within free late allowance',
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
        $lateDays = $this->collectLateDays($employeeId, $startDate, $endDate);
        $totalLateMinutes = (int) array_sum(array_column($lateDays, 'late_minutes'));
        $lateDayCount = count($lateDays);
        $shiftMinutes = $this->resolveShiftMinutes($employeeId, $startDate, $endDate);

        $tier = $this->resolveTier($totalLateMinutes);
        $balances = $this->getLeaveBalances($employeeId, $year);

        $annualAvailable = (float) ($balances['Annual Leave']['available'] ?? 0);
        $casualAvailable = (float) ($balances['Casual Leave']['available'] ?? 0);

        $shortLeaveCount = $tier['short_leave_count'];
        $shortLeaveDaysNeeded = $shortLeaveCount * self::SHORT_LEAVE_DAYS;
        $excessMinutes = $tier['excess_minutes'];
        $excessDays = $shiftMinutes > 0 ? round($excessMinutes / $shiftMinutes, 4) : 0;

        // --- Allocate short leaves: Casual first, then Annual ---
        $shortFromCasual = 0.0;
        $shortFromAnnual = 0.0;
        $shortUncovered = 0.0;
        $remainingShort = $shortLeaveDaysNeeded;

        $takeCasual = min($remainingShort, max(0, $casualAvailable));
        $shortFromCasual = $takeCasual;
        $remainingShort = round($remainingShort - $takeCasual, 4);
        $casualLeft = round($casualAvailable - $takeCasual, 4);

        $takeAnnual = min($remainingShort, max(0, $annualAvailable));
        $shortFromAnnual = $takeAnnual;
        $remainingShort = round($remainingShort - $takeAnnual, 4);
        $annualLeft = round($annualAvailable - $takeAnnual, 4);

        $shortUncovered = max(0, $remainingShort);

        // --- Allocate excess days (beyond 2h 30m): Annual first, then Casual ---
        $annualFromExcess = 0.0;
        $casualFromExcess = 0.0;
        $remainingExcess = $excessDays;

        $takeAnnualEx = min($remainingExcess, max(0, $annualLeft));
        $annualFromExcess = $takeAnnualEx;
        $remainingExcess = round($remainingExcess - $takeAnnualEx, 4);
        $annualLeft = round($annualLeft - $takeAnnualEx, 4);

        $takeCasualEx = min($remainingExcess, max(0, $casualLeft));
        $casualFromExcess = $takeCasualEx;
        $remainingExcess = round($remainingExcess - $takeCasualEx, 4);
        $casualLeft = round($casualLeft - $takeCasualEx, 4);

        $nopayDays = round($remainingExcess + $shortUncovered, 4);
        $nopayMinutes = (int) round($nopayDays * $shiftMinutes);

        $annualLeaveDays = round($shortFromAnnual + $annualFromExcess, 4);
        $casualLeaveDays = round($shortFromCasual + $casualFromExcess, 4);

        $breakdownSteps = $this->buildBreakdownSteps(
            $totalLateMinutes,
            $tier,
            $shortLeaveCount,
            $shortFromCasual,
            $shortFromAnnual,
            $shortUncovered,
            $excessMinutes,
            $excessDays,
            $annualFromExcess,
            $casualFromExcess,
            $nopayDays,
            $annualAvailable,
            $casualAvailable,
            $shiftMinutes
        );

        return [
            'total_late_minutes' => $totalLateMinutes,
            'total_late_display' => $this->formatMinutes($totalLateMinutes),
            'late_day_count' => $lateDayCount,
            'late_days' => $lateDays,
            'free_minutes' => self::FREE_MINUTES,
            'chargeable_minutes' => max(0, $totalLateMinutes - self::FREE_MINUTES),
            'excess_minutes' => $excessMinutes,
            'excess_days' => $excessDays,
            'shift_minutes' => $shiftMinutes,
            'shift_hours' => round($shiftMinutes / 60, 2),
            'band' => $tier['band'],
            'band_label' => $tier['band_label'],
            'short_leave_count' => $shortLeaveCount,
            'short_leave_days' => $shortLeaveDaysNeeded,
            'short_from_casual' => $shortFromCasual,
            'short_from_annual' => $shortFromAnnual,
            'short_uncovered_days' => $shortUncovered,
            'annual_leave_days' => $annualLeaveDays,
            'casual_leave_days' => $casualLeaveDays,
            'annual_from_excess' => $annualFromExcess,
            'casual_from_excess' => $casualFromExcess,
            'nopay_days' => $nopayDays,
            'nopay_minutes' => $nopayMinutes,
            'nopay_display' => $this->formatMinutes($nopayMinutes),
            'annual_balance_before' => $annualAvailable,
            'casual_balance_before' => $casualAvailable,
            'annual_balance_after' => max(0, $annualLeft),
            'casual_balance_after' => max(0, $casualLeft),
            'has_deduction' => $shortLeaveCount > 0 || $excessMinutes > 0,
            'breakdown' => $breakdownSteps,
        ];
    }

    private function resolveTier(int $totalLateMinutes): array
    {
        if ($totalLateMinutes <= self::FREE_MINUTES) {
            return [
                'band' => 'free',
                'band_label' => 'Within free allowance (≤ 1h 30m)',
                'short_leave_count' => 0,
                'excess_minutes' => 0,
            ];
        }

        if ($totalLateMinutes <= self::ONE_SHORT_MAX) {
            return [
                'band' => 'one_short',
                'band_label' => 'Exceeds 1h 30m up to 2h → 1 Short Leave',
                'short_leave_count' => 1,
                'excess_minutes' => 0,
            ];
        }

        if ($totalLateMinutes <= self::TWO_SHORT_MAX) {
            return [
                'band' => 'two_short',
                'band_label' => 'Exceeds 2h up to 2h 30m → 2 Short Leaves',
                'short_leave_count' => 2,
                'excess_minutes' => 0,
            ];
        }

        return [
            'band' => 'excess',
            'band_label' => 'Exceeds 2h 30m → 2 Short Leaves + remaining from Annual/Casual/NoPay',
            'short_leave_count' => 2,
            'excess_minutes' => $totalLateMinutes - self::TWO_SHORT_MAX,
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
        $lateDays = $row['late_days'] ?? [];
        $anchorDate = !empty($lateDays)
            ? end($lateDays)['date']
            : Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $reportingDate = now()->toDateString();
        $reasonBase = self::REASON_TAG . " {$year}-" . str_pad((string) $month, 2, '0', STR_PAD_LEFT)
            . " | Total late: {$row['total_late_display']} | Band: {$row['band_label']}";

        // Short leaves from Casual
        $shortCasualUnits = (int) round(($row['short_from_casual'] ?? 0) / self::SHORT_LEAVE_DAYS);
        for ($i = 0; $i < $shortCasualUnits; $i++) {
            $date = $this->pickLeaveDate($lateDays, $i, $anchorDate);
            $leave = leave_master::create([
                'employee_id' => $employeeId,
                'reporting_date' => $reportingDate,
                'leave_type' => 'Casual Leave',
                'leave_date' => $date,
                'leave_from' => null,
                'leave_to' => null,
                'is_half_day' => false,
                'is_short_leave' => true,
                'short_leave_slot' => 'Morning',
                'leave_duration' => self::SHORT_LEAVE_DAYS,
                'reason' => $reasonBase . " | Auto Short Leave #" . ($i + 1) . " (from Casual)",
                'status' => 'Approved',
                'over_limit' => false,
            ]);
            $leaveIds[] = $leave->id;
        }

        // Short leaves from Annual
        $shortAnnualUnits = (int) round(($row['short_from_annual'] ?? 0) / self::SHORT_LEAVE_DAYS);
        for ($i = 0; $i < $shortAnnualUnits; $i++) {
            $date = $this->pickLeaveDate($lateDays, $shortCasualUnits + $i, $anchorDate);
            $leave = leave_master::create([
                'employee_id' => $employeeId,
                'reporting_date' => $reportingDate,
                'leave_type' => 'Annual Leave',
                'leave_date' => $date,
                'leave_from' => null,
                'leave_to' => null,
                'is_half_day' => false,
                'is_short_leave' => true,
                'short_leave_slot' => 'Morning',
                'leave_duration' => self::SHORT_LEAVE_DAYS,
                'reason' => $reasonBase . " | Auto Short Leave #" . ($i + 1) . " (from Annual)",
                'status' => 'Approved',
                'over_limit' => false,
            ]);
            $leaveIds[] = $leave->id;
        }

        // Excess Annual leave days (non-short)
        $annualExcess = (float) ($row['annual_from_excess'] ?? 0);
        if ($annualExcess > 0) {
            $leaveIds = array_merge(
                $leaveIds,
                $this->createDurationLeaves(
                    $employeeId,
                    'Annual Leave',
                    $annualExcess,
                    $lateDays,
                    $anchorDate,
                    $reportingDate,
                    $reasonBase . ' | Excess late beyond 2h 30m from Annual'
                )
            );
        }

        // Excess Casual leave days (non-short)
        $casualExcess = (float) ($row['casual_from_excess'] ?? 0);
        if ($casualExcess > 0) {
            $leaveIds = array_merge(
                $leaveIds,
                $this->createDurationLeaves(
                    $employeeId,
                    'Casual Leave',
                    $casualExcess,
                    $lateDays,
                    $anchorDate,
                    $reportingDate,
                    $reasonBase . ' | Excess late beyond 2h 30m from Casual'
                )
            );
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
                . " | Excess after leave balances | {$row['nopay_display']} ({$nopayDays} day)",
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
        $steps = [];

        $steps[] = [
            'step' => 1,
            'title' => 'Monthly late total',
            'detail' => $this->formatMinutes($totalLateMinutes),
            'type' => 'info',
        ];

        $steps[] = [
            'step' => 2,
            'title' => 'Policy band',
            'detail' => $tier['band_label'],
            'type' => $tier['band'] === 'free' ? 'ok' : 'warn',
        ];

        if ($totalLateMinutes <= self::FREE_MINUTES) {
            $steps[] = [
                'step' => 3,
                'title' => 'Action',
                'detail' => 'No deduction — within free 1 hour 30 minutes',
                'type' => 'ok',
            ];
            return $steps;
        }

        $steps[] = [
            'step' => 3,
            'title' => 'Short leave required',
            'detail' => "{$shortLeaveCount} short leave(s) = "
                . ($shortLeaveCount * self::SHORT_LEAVE_DAYS) . ' day(s)',
            'type' => 'deduct',
        ];

        if ($shortFromCasual > 0) {
            $steps[] = [
                'step' => 4,
                'title' => 'Short leave from Casual',
                'detail' => "{$shortFromCasual} day(s) auto-applied as Short Leave (Casual)",
                'type' => 'casual',
            ];
        }
        if ($shortFromAnnual > 0) {
            $steps[] = [
                'step' => 5,
                'title' => 'Short leave from Annual',
                'detail' => "{$shortFromAnnual} day(s) auto-applied as Short Leave (Annual)",
                'type' => 'annual',
            ];
        }
        if ($shortUncovered > 0) {
            $steps[] = [
                'step' => 6,
                'title' => 'Short leave without balance',
                'detail' => "{$shortUncovered} day(s) → NoPay (insufficient leave balance)",
                'type' => 'nopay',
            ];
        }

        if ($excessMinutes > 0) {
            $steps[] = [
                'step' => 7,
                'title' => 'Excess beyond 2h 30m',
                'detail' => $this->formatMinutes($excessMinutes)
                    . ' = ' . $excessDays . ' day(s) at '
                    . round($shiftMinutes / 60, 2) . 'h shift',
                'type' => 'warn',
            ];

            if ($annualFromExcess > 0) {
                $steps[] = [
                    'step' => 8,
                    'title' => 'Excess from Annual Leave',
                    'detail' => "{$annualFromExcess} day(s) auto-applied (balance was {$annualAvailable})",
                    'type' => 'annual',
                ];
            }
            if ($casualFromExcess > 0) {
                $steps[] = [
                    'step' => 9,
                    'title' => 'Excess from Casual Leave',
                    'detail' => "{$casualFromExcess} day(s) auto-applied (balance was {$casualAvailable})",
                    'type' => 'casual',
                ];
            }
        }

        if ($nopayDays > 0) {
            $steps[] = [
                'step' => 10,
                'title' => 'NoPay (remaining)',
                'detail' => "{$nopayDays} day(s) — leave balances exhausted",
                'type' => 'nopay',
            ];
        }

        return $steps;
    }

    private function buildSummary(array $items): array
    {
        $withLate = count($items);
        $withDeduction = 0;
        $shortLeaves = 0;
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
            $shortLeaves += (int) ($item['short_leave_count'] ?? 0);
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
            'total_short_leaves' => $shortLeaves,
            'total_annual_days' => round($annualDays, 4),
            'total_casual_days' => round($casualDays, 4),
            'total_nopay_days' => round($nopayDays, 4),
        ];
    }

    public function rulesMeta(): array
    {
        return [
            [
                'band' => '≤ 1 hour 30 minutes',
                'action' => 'No deduction',
                'color' => 'green',
            ],
            [
                'band' => '> 1h 30m and ≤ 2 hours',
                'action' => 'Deduct 1 Short Leave (0.25 day)',
                'color' => 'amber',
            ],
            [
                'band' => '> 2 hours and ≤ 2h 30m',
                'action' => 'Deduct 2 Short Leaves (0.50 day)',
                'color' => 'orange',
            ],
            [
                'band' => '> 2 hours 30 minutes',
                'action' => '2 Short Leaves + remaining time from Annual → Casual → NoPay',
                'color' => 'red',
            ],
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
