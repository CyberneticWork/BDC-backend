<?php

namespace App\Services;

use App\Models\EmployeeMedicalQuota;
use App\Models\MedicalClaim;
use App\Models\employee;
use Illuminate\Support\Facades\Schema;

class MedicalClaimService
{
    public static function quota(employee $employee, ?int $year = null): array
    {
        $year = $year ?: (int) now()->year;
        $cfg = CompanyProcessSettings::packConfig($employee, 'medical_claims');
        $allocated = (float) ($cfg['annual_quota'] ?? 0);

        if (Schema::hasTable('employee_medical_quotas')) {
            $row = EmployeeMedicalQuota::where('employee_id', $employee->id)
                ->where('year', $year)
                ->first();
            if ($row) {
                $allocated = (float) $row->allocated_amount;
            }
        }

        $pending = 0.0;
        $approved = 0.0;
        if (Schema::hasTable('medical_claims')) {
            $pending = (float) MedicalClaim::where('employee_id', $employee->id)
                ->where('year', $year)
                ->where('status', 'PENDING')
                ->sum('amount');
            $approved = (float) MedicalClaim::where('employee_id', $employee->id)
                ->where('year', $year)
                ->where('status', 'APPROVED')
                ->sum('amount');
        }

        $available = max(0, round($allocated - $pending - $approved, 2));

        return [
            'year' => $year,
            'allocated' => round($allocated, 2),
            'pending' => round($pending, 2),
            'approved' => round($approved, 2),
            'available' => $available,
        ];
    }
}
