<?php

namespace App\Services;

use App\Models\company;
use App\Models\employee;
use App\Models\leaveCalendar;

/**
 * Leave Calendar holidays count as paid (NoPay skipped) only when the
 * Cybernetic Admin holiday_calendar pack is enabled for the company.
 */
class LeaveCalendarHolidayService
{
    public static function packEnabledForCompany(?int $companyId): bool
    {
        if (!$companyId) {
            return false;
        }

        $company = company::find($companyId);

        return $company ? CompanyProcessSettings::usesHolidayCalendar($company) : false;
    }

    public static function isHoliday(?int $companyId, ?int $departmentId, string $date): bool
    {
        if (!$companyId || !self::packEnabledForCompany($companyId)) {
            return false;
        }

        $companyHoliday = leaveCalendar::where('company_id', $companyId)
            ->where(function ($query) use ($date) {
                $query->where(function ($q) use ($date) {
                    $q->whereNull('end_date')->whereDate('start_date', $date);
                })->orWhere(function ($q) use ($date) {
                    $q->whereNotNull('end_date')
                        ->whereDate('start_date', '<=', $date)
                        ->whereDate('end_date', '>=', $date);
                });
            })
            ->exists();

        if ($companyHoliday) {
            return true;
        }

        if ($departmentId) {
            return leaveCalendar::where('department_id', $departmentId)
                ->where(function ($query) use ($date) {
                    $query->where(function ($q) use ($date) {
                        $q->whereNull('end_date')->whereDate('start_date', $date);
                    })->orWhere(function ($q) use ($date) {
                        $q->whereNotNull('end_date')
                            ->whereDate('start_date', '<=', $date)
                            ->whereDate('end_date', '>=', $date);
                    });
                })
                ->exists();
        }

        return false;
    }

    public static function isHolidayForEmployee(?employee $employee, string $date): bool
    {
        if (!$employee) {
            return false;
        }
        $org = $employee->organizationAssignment;
        if (!$org) {
            return false;
        }

        return self::isHoliday((int) $org->company_id, $org->department_id ? (int) $org->department_id : null, $date);
    }

    /** Count Leave Calendar holiday entries overlapping a month when pack is ON. */
    public static function holidayCountInRange(?int $companyId, string $startDate, string $endDate): int
    {
        if (!$companyId || !self::packEnabledForCompany($companyId)) {
            return 0;
        }

        return (int) leaveCalendar::where('company_id', $companyId)
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate]);
            })
            ->count();
    }
}
