<?php

namespace App\Services;

use App\Models\company;
use App\Models\compensation;
use App\Models\employee;
use App\Models\over_time;
use App\Models\Roster;
use App\Models\shifts;
use App\Models\time_card;
use App\Services\Overtime\OvertimeCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class RecalculateOvertimeFromPunches
{
    public function __construct(private OvertimeCalculator $calculator)
    {
    }

    public function run(?int $companyId = null): array
    {
        $this->enableOtFlags();
        $shift = $this->ensureDefaultShift();

        $query = time_card::query()->whereNull('deleted_at');
        if ($companyId) {
            $query->whereHas('employee.organizationAssignment', fn ($q) => $q->where('company_id', $companyId));
        }

        $cards = $query->orderBy('employee_id')->orderBy('date')->orderBy('time')->get();
        $grouped = $cards->groupBy(fn ($c) => $c->employee_id . '|' . Carbon::parse($c->date)->toDateString());

        $created = 0;
        $skipped = 0;

        foreach ($grouped as $key => $dayCards) {
            [$employeeId, $date] = explode('|', $key, 2);
            $employee = employee::with('compensation', 'organizationAssignment')->find($employeeId);
            if (!$employee) {
                $skipped++;
                continue;
            }

            $in = $dayCards->first(fn ($c) => in_array($c->status, ['IN', 'Late Coming'], true));
            $out = $dayCards->last(fn ($c) => in_array($c->status, ['OUT', 'Early OUT'], true));
            if (!$in || !$out || $in->id === $out->id) {
                $skipped++;
                continue;
            }

            $this->ensureRoster($employee, $shift, $date);
            if ($this->writeOt($employee, $shift, $in, $out, $date)) {
                $created++;
            } else {
                $skipped++;
            }
        }

        return compact('created', 'skipped');
    }

    private function enableOtFlags(): void
    {
        $payload = ['ot_active' => 1];
        if (Schema::hasColumn('compensation', 'ot_morning')) {
            $payload['ot_morning'] = 1;
        }
        if (Schema::hasColumn('compensation', 'ot_evening')) {
            $payload['ot_evening'] = 1;
        }
        compensation::query()->whereNull('deleted_at')->update($payload);
    }

    private function ensureDefaultShift(): shifts
    {
        $existing = shifts::where('shift_code', 'JAY-DAY')->first();
        if ($existing) {
            return $existing;
        }

        return shifts::create([
            'shift_code' => 'JAY-DAY',
            'shift_description' => 'Default day shift 08:00-17:00',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'morning_ot_start' => '05:00:00',
            'morning_ot_end' => '08:00:00',
            'night_ot_start' => '17:00:00',
            'night_ot_end' => '23:59:00',
            'midnight_roster' => false,
        ]);
    }

    private function ensureRoster(employee $employee, shifts $shift, string $date): void
    {
        $org = $employee->organizationAssignment;
        $exists = Roster::query()
            ->where('employee_id', $employee->id)
            ->where('shift_code', $shift->id)
            ->where(function ($q) use ($date) {
                $q->whereNull('date_from')->orWhereDate('date_from', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('date_to')->orWhereDate('date_to', '>=', $date);
            })
            ->exists();
        if ($exists) {
            return;
        }

        Roster::create([
            'roster_id' => 'JAY-DAY-' . $employee->id,
            'shift_code' => $shift->id,
            'company_id' => $org?->company_id,
            'department_id' => $org?->department_id,
            'employee_id' => $employee->id,
            'is_recurring' => true,
            'recurrence_pattern' => 'daily',
            'date_from' => $date,
            'date_to' => Carbon::parse($date)->addYear()->toDateString(),
            'status' => 'Active',
        ]);
    }

    private function writeOt(employee $employee, shifts $shift, time_card $in, time_card $out, string $date): bool
    {
        $clockIn = Carbon::parse($in->date . ' ' . $in->time);
        $clockOut = Carbon::parse($out->date . ' ' . $out->time);
        if ($clockOut->lte($clockIn)) {
            $clockOut->addDay();
        }

        $breakdown = $this->calculator->calculate($employee, $shift, $clockIn, $clockOut, false);
        $hours = $breakdown['hours'] ?? [];
        $amounts = $breakdown['amounts'] ?? [];
        $total = (float) ($hours['total'] ?? 0);
        $minHours = CompanyProcessSettings::otMinimumHours($employee);
        if ($total <= $minHours) {
            return false;
        }

        $payload = [
            'employee_id' => $employee->id,
            'shift_code' => $shift->id,
            'ot_hours' => $total,
            'morning_ot' => (float) ($hours['morning_regular'] ?? 0),
            'afternoon_ot' => (float) ($hours['evening_regular'] ?? 0),
            'morning_ot_amount' => (float) ($amounts['morning_regular'] ?? 0),
            'evening_ot_amount' => (float) ($amounts['evening_regular'] ?? 0),
            'holiday_ot_hours' => 0,
            'holiday_ot_amount' => 0,
            'total_ot_amount' => (float) ($amounts['total'] ?? 0),
            'status' => 'pending',
        ];

        $ot = over_time::withTrashed()->where('time_cards_id', $out->id)->first();
        if ($ot) {
            $ot->restore();
            $ot->update($payload);
        } else {
            $payload['time_cards_id'] = $out->id;
            over_time::create($payload);
        }

        $out->working_hours = round($clockIn->diffInMinutes($clockOut) / 60, 2);
        $out->save();

        return true;
    }
}
