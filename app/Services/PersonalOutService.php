<?php

namespace App\Services;

use App\Models\NoPayRecord;
use App\Models\PersonalOutEntry;
use App\Models\employee;
use App\Models\leave_master;
use App\Models\time_card;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal out during working hours:
 * first 30 minutes free, then up to 2 hours = short leave (0.25), more than 2 hours = half day (0.5).
 * Taken from Casual, then Annual leave; any remainder is NoPay deducted from the monthly bonus.
 */
class PersonalOutService
{
    public const TYPE = 'PERSONAL_OUT';
    public const REASON_TAG = '[Personal Out]';
    public const FREE_MINUTES = 30;
    public const SHORT_LEAVE_MAX_MINUTES = 120;
    public const SHORT_LEAVE_DAYS = 0.25;
    public const HALF_DAY = 0.5;
    public const LEAVE_ORDER = ['Casual Leave', 'Annual Leave'];

    public static function ensureTable(): void
    {
        if (Schema::hasTable('personal_out_entries')) {
            return;
        }

        Schema::create('personal_out_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->index();
            $table->date('date')->index();
            $table->time('out_time');
            $table->time('in_time');
            $table->unsignedInteger('minutes')->default(0);
            $table->decimal('deduct_days', 5, 2)->default(0);
            $table->decimal('leave_days', 5, 2)->default(0);
            $table->decimal('nopay_days', 5, 2)->default(0);
            $table->json('allocation')->nullable();
            $table->json('leave_master_ids')->nullable();
            $table->unsignedBigInteger('nopay_record_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function minutesBetween(string $outTime, string $inTime): int
    {
        $out = Carbon::createFromFormat('H:i', substr($outTime, 0, 5));
        $in = Carbon::createFromFormat('H:i', substr($inTime, 0, 5));

        if ($in->lessThanOrEqualTo($out)) {
            throw new \InvalidArgumentException('Back-in time must be after the out time.');
        }

        return (int) $out->diffInMinutes($in);
    }

    public function deductDaysFor(int $minutes): float
    {
        if ($minutes <= self::FREE_MINUTES) {
            return 0.0;
        }

        return $minutes <= self::SHORT_LEAVE_MAX_MINUTES ? self::SHORT_LEAVE_DAYS : self::HALF_DAY;
    }

    public function deductLabel(float $deductDays): string
    {
        if ($deductDays <= 0) {
            return 'Within ' . self::FREE_MINUTES . ' minutes - no deduction';
        }

        return $deductDays === self::SHORT_LEAVE_DAYS ? 'Short leave (0.25)' : 'Half day (0.5)';
    }

    public function preview(int $employeeId, string $date, ?string $outTime, ?string $inTime): array
    {
        $employee = employee::with(['organizationAssignment.company', 'compensation'])->findOrFail($employeeId);
        $year = (int) Carbon::parse($date)->year;
        $balances = app(MonthlyLateDeductionService::class)->getLeaveBalances($employeeId, $year);

        $result = [
            'employee' => $this->employeeSummary($employee),
            'date' => Carbon::parse($date)->toDateString(),
            'balances' => $balances,
            'punches' => $this->punchesFor($employeeId, $date),
            'rate' => $this->nopayRate($employee),
            'minutes' => null,
            'deduct_days' => null,
            'deduct_label' => null,
            'allocation' => [],
            'nopay_days' => 0,
            'nopay_amount' => 0,
        ];

        if ($outTime && $inTime) {
            $minutes = $this->minutesBetween($outTime, $inTime);
            $deductDays = $this->deductDaysFor($minutes);
            $allocation = $this->allocate($deductDays, $balances);
            $nopayDays = $this->sumAllocation($allocation, 'NoPay');

            $result['minutes'] = $minutes;
            $result['deduct_days'] = $deductDays;
            $result['deduct_label'] = $this->deductLabel($deductDays);
            $result['allocation'] = $allocation;
            $result['nopay_days'] = $nopayDays;
            $result['nopay_amount'] = round($nopayDays * $result['rate']['per_day'], 2);
        }

        return $result;
    }

    public function create(array $data): PersonalOutEntry
    {
        self::ensureTable();

        $employeeId = (int) $data['employee_id'];
        $date = Carbon::parse($data['date'])->toDateString();
        $outTime = substr($data['out_time'], 0, 5);
        $inTime = substr($data['in_time'], 0, 5);
        $reason = trim((string) ($data['reason'] ?? ''));

        $minutes = $this->minutesBetween($outTime, $inTime);

        $overlap = PersonalOutEntry::where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->where('out_time', '<', $inTime . ':00')
            ->where('in_time', '>', $outTime . ':00')
            ->exists();
        if ($overlap) {
            throw new \InvalidArgumentException('A personal out entry already covers this time for the employee.');
        }

        $employee = employee::findOrFail($employeeId);
        $deductDays = $this->deductDaysFor($minutes);
        $balances = app(MonthlyLateDeductionService::class)->getLeaveBalances($employeeId, (int) Carbon::parse($date)->year);
        $allocation = $this->allocate($deductDays, $balances);
        $nopayDays = $this->sumAllocation($allocation, 'NoPay');

        return DB::transaction(function () use ($employee, $date, $outTime, $inTime, $minutes, $deductDays, $allocation, $nopayDays, $reason) {
            $timeLabel = "{$outTime}-{$inTime} ({$minutes} min)";
            $description = self::REASON_TAG . " {$timeLabel}" . ($reason !== '' ? " | {$reason}" : '');

            $leaveIds = [];
            foreach ($allocation as $part) {
                if ($part['source'] === 'NoPay') {
                    continue;
                }
                $leaveIds[] = $this->createLeave($employee->id, $date, $outTime, $part['source'], (float) $part['days'], $description)->id;
            }

            $nopayRecordId = null;
            if ($nopayDays > 0) {
                $nopayRecordId = NoPayRecord::create([
                    'employee_id' => $employee->id,
                    'date' => $date,
                    'no_pay_count' => $nopayDays,
                    'description' => $description . ' | No leave balance - deducted from monthly bonus',
                    'status' => 'Approved',
                    'processed_by' => Auth::id(),
                    'type' => self::TYPE,
                    'hours' => intdiv($minutes, 60),
                    'minutes' => $minutes % 60,
                    'start_time' => $outTime,
                    'end_time' => $inTime,
                ])->id;
            }

            return PersonalOutEntry::create([
                'employee_id' => $employee->id,
                'date' => $date,
                'out_time' => $outTime,
                'in_time' => $inTime,
                'minutes' => $minutes,
                'deduct_days' => $deductDays,
                'leave_days' => round($deductDays - $nopayDays, 2),
                'nopay_days' => $nopayDays,
                'allocation' => $allocation,
                'leave_master_ids' => $leaveIds,
                'nopay_record_id' => $nopayRecordId,
                'reason' => $reason !== '' ? $reason : null,
                'created_by' => Auth::id(),
            ]);
        });
    }

    public function delete(PersonalOutEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $leaveIds = array_filter((array) ($entry->leave_master_ids ?? []));
            if ($leaveIds) {
                leave_master::whereIn('id', $leaveIds)->delete();
            }
            if ($entry->nopay_record_id) {
                NoPayRecord::where('id', $entry->nopay_record_id)->delete();
            }
            $entry->delete();
        });
    }

    public function list(int $year, int $month, ?int $companyId, ?string $search): array
    {
        if (!Schema::hasTable('personal_out_entries')) {
            return [];
        }

        $query = PersonalOutEntry::with(['employee.organizationAssignment.company', 'createdBy:id,name'])
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->orderByDesc('date')
            ->orderByDesc('out_time');

        if ($companyId) {
            $query->whereHas('employee.organizationAssignment', fn ($q) => $q->where('company_id', $companyId));
        }
        if ($search = trim((string) $search)) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        return $query->get()->map(fn (PersonalOutEntry $e) => [
            'id' => $e->id,
            'employee' => $e->employee ? $this->employeeSummary($e->employee) : null,
            'date' => $e->date?->toDateString(),
            'out_time' => substr((string) $e->out_time, 0, 5),
            'in_time' => substr((string) $e->in_time, 0, 5),
            'minutes' => $e->minutes,
            'deduct_days' => $e->deduct_days,
            'leave_days' => $e->leave_days,
            'nopay_days' => $e->nopay_days,
            'allocation' => $e->allocation ?? [],
            'reason' => $e->reason,
            'created_by' => $e->createdBy->name ?? null,
            'created_at' => $e->created_at?->toDateTimeString(),
        ])->all();
    }

    public function searchEmployees(?int $companyId, ?string $search): array
    {
        $query = employee::with('organizationAssignment.company')
            ->where('is_active', true)
            ->whereNull('deleted_at');

        if ($companyId) {
            $query->whereHas('organizationAssignment', fn ($q) => $q->where('company_id', $companyId));
        }
        if ($search = trim((string) $search)) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$search}%")
                    ->orWhere('nic', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('full_name')->limit(30)->get()
            ->map(fn ($e) => $this->employeeSummary($e))
            ->all();
    }

    /**
     * Mid-shift device punches (OUT followed by IN) for the day, used to pre-fill the form.
     */
    public function punchesFor(int $employeeId, string $date): array
    {
        $punches = time_card::where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->whereNull('deleted_at')
            ->orderBy('time')
            ->get(['id', 'time', 'status']);

        if ($punches->count() <= 2) {
            return [];
        }

        $middle = $punches->slice(1, $punches->count() - 2)->values();
        $pairs = [];
        for ($i = 0; $i < $middle->count(); $i++) {
            $current = $middle[$i];
            $next = $middle[$i + 1] ?? null;
            if (
                in_array(strtoupper((string) $current->status), ['OUT', 'EARLY OUT'], true)
                && $next
                && in_array(strtoupper((string) $next->status), ['IN', 'LATE COMING'], true)
            ) {
                $out = substr((string) $current->time, 0, 5);
                $in = substr((string) $next->time, 0, 5);
                $pairs[] = [
                    'out_time' => $out,
                    'in_time' => $in,
                    'minutes' => (int) Carbon::createFromFormat('H:i', $out)->diffInMinutes(Carbon::createFromFormat('H:i', $in)),
                ];
                $i++;
            }
        }

        return $pairs;
    }

    /**
     * Split the deduction across Casual → Annual leave in 0.25 units; the rest is NoPay.
     */
    private function allocate(float $deductDays, array $balances): array
    {
        $remaining = $deductDays;
        $parts = [];

        foreach (self::LEAVE_ORDER as $type) {
            if ($remaining <= 0) {
                break;
            }
            $available = floor(((float) ($balances[$type]['available'] ?? 0)) * 4) / 4;
            $take = min($remaining, $available);
            if ($take > 0) {
                $parts[] = ['source' => $type, 'days' => $take];
                $remaining = round($remaining - $take, 2);
            }
        }

        if ($remaining > 0) {
            $parts[] = ['source' => 'NoPay', 'days' => $remaining];
        }

        return $parts;
    }

    private function sumAllocation(array $allocation, string $source): float
    {
        return round(array_sum(array_map(
            fn ($p) => $p['source'] === $source ? (float) $p['days'] : 0,
            $allocation
        )), 2);
    }

    private function createLeave(int $employeeId, string $date, string $outTime, string $leaveType, float $days, string $reason): leave_master
    {
        $isShort = $days < self::HALF_DAY;

        return leave_master::create([
            'employee_id' => $employeeId,
            'reporting_date' => now()->toDateString(),
            'leave_type' => $leaveType,
            'leave_date' => $date,
            'leave_from' => null,
            'leave_to' => null,
            'is_half_day' => !$isShort,
            'period' => $isShort ? null : ($outTime < '12:30' ? 'Morning' : 'Afternoon'),
            'is_short_leave' => $isShort,
            'short_leave_slot' => $isShort ? ($outTime < '10:30' ? 'slot1' : 'slot2') : null,
            'leave_duration' => $isShort ? self::SHORT_LEAVE_DAYS : self::HALF_DAY,
            'reason' => $reason . ' | ' . ($isShort ? 'Short leave' : 'Half day') . " from {$leaveType}",
            'status' => 'Approved',
            'over_limit' => 0,
        ]);
    }

    /**
     * NoPay day value used by payroll: (basic + monthly bonus) / company NoPay working days.
     */
    private function nopayRate(employee $employee): array
    {
        $basic = (float) ($employee->compensation->basic_salary ?? 0);
        $bonus = (float) ($employee->compensation->monthly_bonus ?? 0);
        $days = (float) ($employee->organizationAssignment->company->nopay_working_days ?? 30);
        if ($days < 1) {
            $days = 30;
        }

        return [
            'basic' => $basic,
            'monthly_bonus' => $bonus,
            'working_days' => $days,
            'per_day' => round(($basic + $bonus) / $days, 2),
        ];
    }

    private function employeeSummary(employee $employee): array
    {
        return [
            'id' => $employee->id,
            'emp_no' => $employee->attendance_employee_no,
            'name' => $employee->full_name,
            'company_id' => $employee->organizationAssignment->company_id ?? null,
            'company_name' => $employee->organizationAssignment->company->name ?? null,
        ];
    }
}
