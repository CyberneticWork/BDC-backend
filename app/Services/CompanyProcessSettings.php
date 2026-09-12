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

    public const OT_CURRENT = 'current';
    public const OT_MINUTE_BAND = 'minute_band';

    public static function otHourMode($source): string
    {
        $company = null;
        if ($source instanceof company) {
            $company = $source;
        } elseif ($source instanceof employee) {
            $source->loadMissing('organizationAssignment.company');
            $company = $source->organizationAssignment?->company;
        } elseif (is_numeric($source)) {
            $company = company::find((int) $source);
        }

        $cfg = is_array($company?->process_config) ? $company->process_config : [];
        $mode = strtolower(trim((string) ($cfg['ot_hour_calculation'] ?? self::OT_CURRENT)));

        return $mode === self::OT_MINUTE_BAND ? self::OT_MINUTE_BAND : self::OT_CURRENT;
    }

    public static function usesMinuteBandOt($source): bool
    {
        return self::otHourMode($source) === self::OT_MINUTE_BAND;
    }

    public static function otHoursFromMinutes(int $minutes, $source): float
    {
        $minutes = max(0, $minutes);
        if (self::usesMinuteBandOt($source)) {
            return self::roundMinuteBand($minutes);
        }

        return round(((int) floor($minutes / 30) * 30) / 60, 2);
    }

    public static function roundMinuteBand(int $minutes): float
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours === 0) {
            if ($mins >= 45) {
                return 0.45;
            }
            if ($mins >= 30) {
                return 0.30;
            }

            return 0.0;
        }

        if ($mins <= 14) {
            return (float) $hours;
        }
        if ($mins <= 29) {
            return $hours + 0.15;
        }
        if ($mins <= 44) {
            return $hours + 0.30;
        }

        return $hours + 0.45;
    }
}
