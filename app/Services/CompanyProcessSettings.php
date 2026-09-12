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
                'label' => 'Multiple roster (manual table)',
                'group' => 'Attendance & payroll',
                'summary' => 'One employee can work more than one roster on the same day (e.g. R1 06:00–13:00 and R3 15:00–20:00). Overnight rosters such as R4 18:00–02:00 next day are allowed. Early/late IN–OUT are recorded; OT or penalty is applied from company config.',
                'affects' => ['attendance', 'ot', 'late', 'nopay', 'working_hours', 'salary', 'roster_calendar'],
                'available' => true,
            ],
            [
                'key' => 'leave_workflow',
                'label' => 'Covering-person leave workflow',
                'group' => 'Leave',
                'summary' => 'Off = keep the current leave process. On = portal balance + apply, mandatory covering person, then supervisor, then HR. Notifications on approve/reject. Email is sent when configured; SMS only if a provider is set.',
                'affects' => ['leave', 'employee_portal', 'leave_calendar'],
                'available' => true,
            ],
            [
                'key' => 'weekly_off',
                'label' => 'Weekly off management',
                'group' => 'Leave',
                'summary' => 'Off = keep the current day-off field only. On = earned weekly offs (1.5/week permanent max 6, 1/week contract-intern-probation max 4), portal apply, carry-forward, and an HR weekly-off schedule employees can see after approve.',
                'affects' => ['weekly_off', 'employee_portal'],
                'available' => true,
            ],
            [
                'key' => 'medical_claims',
                'label' => 'Medical claims',
                'group' => 'Benefits',
                'summary' => 'Off = no medical-claim module. On = portal bill upload, quota vs approved+pending, HR approve/reject, and approved claims go to Pending Payments.',
                'affects' => ['medical_claims', 'employee_portal', 'pending_payments'],
                'available' => true,
            ],
            [
                'key' => 'salary_advance',
                'label' => 'Salary advance quota',
                'group' => 'Benefits',
                'summary' => 'Off = keep the current portal advance (no quota). On = show available amount, validate the request, portal notify on HR decision, and approved advances go to Pending Payments.',
                'affects' => ['salary_advance', 'employee_portal', 'pending_payments'],
                'available' => true,
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

    public static function rosterPolicy($source): array
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
        $multi = is_array($cfg['multi_roster'] ?? null) ? $cfg['multi_roster'] : [];

        $allowed = ['ot', 'penalty', 'record_only'];
        $pick = function ($key, $default) use ($multi, $allowed) {
            $value = strtolower(trim((string) ($multi[$key] ?? $default)));
            return in_array($value, $allowed, true) ? $value : $default;
        };

        return [
            'early_in' => $pick('early_in', 'ot'),
            'late_in' => $pick('late_in', 'penalty'),
            'early_out' => $pick('early_out', 'penalty'),
            'late_out' => $pick('late_out', 'ot'),
        ];
    }

    public static function earlyInIsOt($source): bool
    {
        return self::rosterPolicy($source)['early_in'] === 'ot';
    }

    public static function lateOutIsOt($source): bool
    {
        return self::rosterPolicy($source)['late_out'] === 'ot';
    }

    public static function usesLeaveWorkflow($source): bool
    {
        return self::packEnabled($source, 'leave_workflow');
    }

    public static function usesWeeklyOff($source): bool
    {
        return self::packEnabled($source, 'weekly_off');
    }

    public static function usesMedicalClaims($source): bool
    {
        return self::packEnabled($source, 'medical_claims');
    }

    public static function usesSalaryAdvancePack($source): bool
    {
        return self::packEnabled($source, 'salary_advance');
    }

    public static function packConfig($source, string $key): array
    {
        $cfg = is_array(self::companyOf($source)?->process_config)
            ? self::companyOf($source)->process_config
            : [];

        return is_array($cfg[$key] ?? null) ? $cfg[$key] : [];
    }

    public static function packEnabled($source, string $key): bool
    {
        $cfg = is_array(self::companyOf($source)?->process_config)
            ? self::companyOf($source)->process_config
            : [];
        $flag = $cfg[$key] ?? false;
        if (is_array($flag)) {
            return !empty($flag['enabled']);
        }

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    public static function companyOf($source): ?company
    {
        if ($source instanceof company) {
            return $source;
        }
        if ($source instanceof employee) {
            $source->loadMissing('organizationAssignment.company');
            return $source->organizationAssignment?->company;
        }
        if (is_numeric($source)) {
            return company::find((int) $source);
        }

        return null;
    }
}
