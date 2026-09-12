<?php

namespace App\Services;

use App\Models\company;
use App\Models\employee;

class CompanyProcessSettings
{
    public const SPM_STANDARD = 'spm_standard';
    public const SHIFT_ROSTER = 'shift_roster';

    public static function catalog(): array
    {
        return [
            [
                'key' => self::SPM_STANDARD,
                'label' => 'SPM current process',
                'group' => 'Attendance & payroll',
                'summary' => 'One roster shift per day. Working hours are the full IN–OUT span. OT, late, NoPay and salary stay on the current SPM Tax rules.',
                'affects' => ['attendance', 'ot', 'late', 'nopay', 'working_hours', 'salary'],
                'available' => true,
            ],
            [
                'key' => self::SHIFT_ROSTER,
                'label' => 'Shift time & roster',
                'group' => 'Attendance & payroll',
                'summary' => 'More than one shift on the same day is allowed. Working hours, OT, late, attendance and salary are calculated from each assigned shift window.',
                'affects' => ['attendance', 'ot', 'late', 'nopay', 'working_hours', 'salary'],
                'available' => true,
            ],
            [
                'key' => 'leave_rules',
                'label' => 'Leave rules pack',
                'group' => 'Coming soon',
                'summary' => 'Company-specific leave entitlements and short-leave windows.',
                'affects' => ['leave'],
                'available' => false,
            ],
            [
                'key' => 'holiday_calendar',
                'label' => 'Holiday calendar pack',
                'group' => 'Coming soon',
                'summary' => 'Per-company public holiday and OT holiday mapping.',
                'affects' => ['ot', 'nopay', 'salary'],
                'available' => false,
            ],
        ];
    }

    public static function normalize(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));
        if ($mode === self::SHIFT_ROSTER) {
            return self::SHIFT_ROSTER;
        }

        return self::SPM_STANDARD;
    }

    public static function modeOf($source): string
    {
        if ($source instanceof company) {
            return self::normalize($source->attendance_process ?? null);
        }
        if ($source instanceof employee) {
            $company = $source->organizationAssignment?->company;
            if ($company instanceof company) {
                return self::modeOf($company);
            }
            $id = $source->organizationAssignment?->company_id;
            if ($id) {
                $company = company::find($id);
                return self::modeOf($company);
            }
            return self::SPM_STANDARD;
        }
        if (is_numeric($source)) {
            return self::modeOf(company::find((int) $source));
        }

        return self::SPM_STANDARD;
    }

    public static function usesShiftRoster($source): bool
    {
        return self::modeOf($source) === self::SHIFT_ROSTER;
    }
}
