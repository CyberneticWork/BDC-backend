<?php

namespace App\Services;

use App\Models\ExcessLateDecision;
use App\Models\NoPayRecord;
use App\Models\employee;
use App\Models\leave_master;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExcessLateService
{
    public const REASON_TAG = 'Excess Late >30m';

    public function __construct(private MonthlyLateDeductionService $monthly)
    {
    }

    public function preview(int $year, int $month, ?int $companyId = null, ?string $search = null): array
    {
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();
        $employees = $this->monthly->getPolicyEmployees($companyId, $search);
        $items = [];

        foreach ($employees as $employee) {
            $empId = (int) $employee->id;
            $lateDays = $this->monthly->listLateDays($empId, $startDate, $endDate);
            $overDays = array_values(array_filter(
                $lateDays,
                fn ($d) => (int) ($d['late_minutes'] ?? 0) > MonthlyLateDeductionService::GRACE_MINUTE_LIMIT
            ));
            if (count($overDays) === 0) {
                continue;
            }

            $shiftMinutes = $this->monthly->shiftMinutesForEmployee($empId, $startDate, $endDate);
            $comp = $employee->compensation;
            $basic = (float) ($comp->basic_salary ?? 0);
            $bonus = (float) ($comp->monthly_bonus ?? 0);
            $company = optional(optional($employee->organizationAssignment)->company);
            $workingDays = max(1, (int) ($company->nopay_working_days ?? 30));

            $decisions = ExcessLateDecision::where('employee_id', $empId)
                ->whereBetween('late_date', [$startDate->toDateString(), $endDate->toDateString()])
                ->get()
                ->keyBy(fn ($row) => $row->late_date->toDateString());

            $dayRows = [];
            $pending = 0;
            $leaveCount = 0;
            $rejectCount = 0;
            $rejectAmount = 0.0;

            foreach ($overDays as $day) {
                $date = $day['date'];
                $mins = (int) $day['late_minutes'];
                $existing = $decisions->get($date);
                $quotes = $this->quoteAmounts($mins, $shiftMinutes, $basic, $bonus, $workingDays);
                $action = $existing?->action ?? 'pending';
                if ($action === 'pending') {
                    $pending++;
                } elseif ($action === 'leave') {
                    $leaveCount++;
                } else {
                    $rejectCount++;
                    $rejectAmount += (float) ($existing->nopay_amount ?? 0);
                }

                $dayRows[] = array_merge($day, [
                    'shift_minutes' => $shiftMinutes,
                    'nopay_days' => $quotes['days'],
                    'amount_if_basic' => $quotes['basic_amount'],
                    'amount_if_bonus' => $quotes['bonus_amount'],
                    'decision' => $action,
                    'leave_type' => $existing?->leave_type,
                    'deduct_from' => $existing?->deduct_from,
                    'nopay_amount' => $existing ? (float) $existing->nopay_amount : null,
                ]);
            }

            $items[] = [
                'employee_id' => $empId,
                'employee_no' => $employee->attendance_employee_no,
                'employee_name' => $employee->display_name
                    ?? $employee->name_with_initials
                    ?? $employee->full_name,
                'company_name' => $company->name ?? null,
                'company_id' => $company->id ?? null,
                'basic_salary' => $basic,
                'monthly_bonus' => $bonus,
                'nopay_working_days' => $workingDays,
                'shift_minutes' => $shiftMinutes,
                'over_30_day_count' => count($overDays),
                'pending_count' => $pending,
                'leave_count' => $leaveCount,
                'reject_count' => $rejectCount,
                'reject_nopay_amount' => round($rejectAmount, 2),
                'days' => $dayRows,
            ];
        }

        return [
            'year' => $year,
            'month' => $month,
            'month_name' => $startDate->format('F'),
            'employees' => $items,
            'summary' => [
                'employee_count' => count($items),
                'pending_days' => array_sum(array_column($items, 'pending_count')),
                'leave_days' => array_sum(array_column($items, 'leave_count')),
                'reject_days' => array_sum(array_column($items, 'reject_count')),
            ],
        ];
    }

    public function decide(
        int $employeeId,
        string $lateDate,
        string $action,
        ?string $leaveType = null,
        ?string $deductFrom = null
    ): ExcessLateDecision {
        $date = Carbon::parse($lateDate)->toDateString();
        $employee = employee::with(['organizationAssignment.company', 'compensation'])->findOrFail($employeeId);
        $this->assertPolicyEnabled($employee);

        $start = Carbon::parse($date)->startOfDay();
        $days = $this->monthly->listLateDays($employeeId, $start, $start->copy()->endOfDay());
        $match = null;
        foreach ($days as $day) {
            if ($day['date'] === $date && (int) $day['late_minutes'] > MonthlyLateDeductionService::GRACE_MINUTE_LIMIT) {
                $match = $day;
                break;
            }
        }
        if (!$match) {
            throw new \InvalidArgumentException('No late over 30 minutes found on this date.');
        }

        $shiftMinutes = $this->monthly->shiftMinutesForEmployee($employeeId, $start, $start);
        $mins = (int) $match['late_minutes'];
        $company = optional(optional($employee->organizationAssignment)->company);
        $workingDays = max(1, (int) ($company->nopay_working_days ?? 30));
        $basic = (float) ($employee->compensation->basic_salary ?? 0);
        $bonus = (float) ($employee->compensation->monthly_bonus ?? 0);
        $quotes = $this->quoteAmounts($mins, $shiftMinutes, $basic, $bonus, $workingDays);

        return DB::transaction(function () use (
            $employeeId, $date, $action, $leaveType, $deductFrom, $mins, $shiftMinutes, $quotes, $match
        ) {
            $row = ExcessLateDecision::where('employee_id', $employeeId)->whereDate('late_date', $date)->first();
            $this->clearPrevious($row);

            $payload = [
                'employee_id' => $employeeId,
                'late_date' => $date,
                'late_minutes' => $mins,
                'shift_minutes' => $shiftMinutes,
                'action' => $action,
                'leave_type' => null,
                'leave_id' => null,
                'nopay_id' => null,
                'deduct_from' => null,
                'nopay_days' => $quotes['days'],
                'nopay_amount' => 0,
                'decided_by' => Auth::id(),
                'decided_at' => now(),
                'notes' => self::REASON_TAG . " {$match['late_display']}",
            ];

            if ($action === 'leave') {
                $type = $leaveType === 'Annual Leave' ? 'Annual Leave' : 'Casual Leave';
                $leave = $this->createLeave($employeeId, $date, $type, $quotes['days'], $match['late_display']);
                $payload['leave_type'] = $type;
                $payload['leave_id'] = $leave->id;
            } else {
                $from = $deductFrom === 'basic' ? 'basic' : 'bonus';
                $amount = $from === 'basic' ? $quotes['basic_amount'] : $quotes['bonus_amount'];
                $nopay = $this->createNoPay($employeeId, $date, $quotes['days'], $mins, $from, $amount, $match['late_display']);
                $payload['deduct_from'] = $from;
                $payload['nopay_id'] = $nopay->id;
                $payload['nopay_amount'] = $amount;
            }

            return ExcessLateDecision::updateOrCreate(
                ['employee_id' => $employeeId, 'late_date' => $date],
                $payload
            );
        });
    }

    public function monthTotalsForSalary(int $employeeId, int $year, int $month): array
    {
        if (!Schema::hasTable('excess_late_decisions')) {
            return ['basic_amount' => 0.0, 'bonus_amount' => 0.0, 'days' => 0.0, 'minutes' => 0];
        }

        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();
        $rows = ExcessLateDecision::where('employee_id', $employeeId)
            ->where('action', 'reject')
            ->whereBetween('late_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $basic = 0.0;
        $bonus = 0.0;
        $days = 0.0;
        $minutes = 0;
        foreach ($rows as $row) {
            $days += (float) $row->nopay_days;
            $minutes += (int) $row->late_minutes;
            if ($row->deduct_from === 'basic') {
                $basic += (float) $row->nopay_amount;
            } else {
                $bonus += (float) $row->nopay_amount;
            }
        }

        return [
            'basic_amount' => round($basic, 2),
            'bonus_amount' => round($bonus, 2),
            'days' => round($days, 4),
            'minutes' => $minutes,
        ];
    }

    private function quoteAmounts(int $lateMinutes, int $shiftMinutes, float $basic, float $bonus, int $workingDays): array
    {
        $shift = max(1, $shiftMinutes);
        $days = round($lateMinutes / $shift, 4);
        $divisor = max(1, $workingDays);

        return [
            'days' => $days,
            'basic_amount' => round(($basic / $divisor) * $days, 2),
            'bonus_amount' => round(($bonus / $divisor) * $days, 2),
        ];
    }

    private function createLeave(int $employeeId, string $date, string $leaveType, float $days, string $lateDisplay)
    {
        $isHalf = $days >= 0.45;
        $isShort = !$isHalf && $days <= 0.3;

        return leave_master::create([
            'employee_id' => $employeeId,
            'reporting_date' => now()->toDateString(),
            'leave_type' => $leaveType,
            'leave_date' => $date,
            'is_half_day' => $isHalf,
            'period' => $isHalf ? 'Morning' : null,
            'is_short_leave' => $isShort,
            'short_leave_slot' => $isShort ? 'Morning' : null,
            'leave_duration' => $isHalf ? 0.5 : ($isShort ? 0.25 : $days),
            'reason' => self::REASON_TAG . " | {$date} late {$lateDisplay} | leave applied",
            'status' => 'Approved',
            'over_limit' => 0,
        ]);
    }

    private function createNoPay(int $employeeId, string $date, float $days, int $minutes, string $from, float $amount, string $lateDisplay)
    {
        return NoPayRecord::create([
            'employee_id' => $employeeId,
            'date' => $date,
            'no_pay_count' => $days,
            'description' => self::REASON_TAG
                . " | late {$lateDisplay} | deduct {$from} | amount {$amount}",
            'status' => 'Approved',
            'processed_by' => Auth::id(),
            'type' => 'LATE_OVER30',
            'hours' => intdiv($minutes, 60),
            'minutes' => $minutes % 60,
        ]);
    }

    private function clearPrevious(?ExcessLateDecision $row): void
    {
        if (!$row) {
            return;
        }
        if ($row->leave_id) {
            leave_master::where('id', $row->leave_id)->delete();
        }
        if ($row->nopay_id) {
            NoPayRecord::where('id', $row->nopay_id)->delete();
        }
    }

    private function assertPolicyEnabled(employee $employee): void
    {
        if (!Schema::hasColumn('companies', 'late_attendance_policy_enabled')) {
            throw new \RuntimeException('Late attendance policy is not configured.');
        }
        $enabled = (bool) optional(optional($employee->organizationAssignment)->company)->late_attendance_policy_enabled;
        if (!$enabled) {
            throw new \RuntimeException('Late attendance policy is not enabled for this company.');
        }
    }
}
