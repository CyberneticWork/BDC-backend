<?php

namespace App\Services;

use App\Models\WeeklyOffEntry;
use App\Models\employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class WeeklyOffService
{
    public static function policy(employee $employee): array
    {
        $employee->loadMissing(['employmentType', 'organizationAssignment']);
        $type = strtolower((string) ($employee->employmentType->name ?? ''));
        $probation = (bool) ($employee->organizationAssignment->probationary_period ?? false);
        $restricted = $probation
            || str_contains($type, 'contract')
            || str_contains($type, 'intern')
            || str_contains($type, 'probation')
            || str_contains($type, 'training')
            || str_contains($type, 'trainee');

        if ($restricted) {
            return [
                'band' => 'contract_intern_probation',
                'weekly_rate' => 1.0,
                'max_bank' => 4.0,
            ];
        }

        return [
            'band' => 'permanent',
            'weekly_rate' => 1.5,
            'max_bank' => 6.0,
        ];
    }

    public static function balance(employee $employee, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?: Carbon::now();
        $policy = self::policy($employee);
        $join = $employee->organizationAssignment->date_of_joining ?? null;
        $weeks = 0.0;
        if ($join) {
            $weeks = max(0, Carbon::parse($join)->startOfDay()->diffInDays($asOf->copy()->startOfDay()) / 7);
        }
        $earned = round($weeks * $policy['weekly_rate'], 4);

        $used = 0.0;
        if (Schema::hasTable('weekly_off_entries')) {
            $used = (float) WeeklyOffEntry::where('employee_id', $employee->id)
                ->whereIn('status', ['Pending', 'Approved'])
                ->sum('days');
        }

        $uncapped = max(0, round($earned - $used, 4));
        $available = min($policy['max_bank'], $uncapped);

        return [
            'band' => $policy['band'],
            'weekly_rate' => $policy['weekly_rate'],
            'max_bank' => $policy['max_bank'],
            'weeks_elapsed' => round($weeks, 2),
            'earned' => $earned,
            'used' => round($used, 4),
            'available' => round($available, 4),
            'join_date' => $join,
        ];
    }
}
