<?php

namespace App\Services;

use App\Models\PendingPayment;
use App\Models\employee;
use Illuminate\Support\Facades\Schema;

class PendingPaymentService
{
    public static function record(employee $employee, string $sourceType, int $sourceId, float $amount): ?PendingPayment
    {
        if (!Schema::hasTable('pending_payments')) {
            return null;
        }
        $employee->loadMissing('organizationAssignment');

        return PendingPayment::updateOrCreate(
            [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ],
            [
                'company_id' => $employee->organizationAssignment->company_id ?? null,
                'employee_id' => $employee->id,
                'amount' => $amount,
                'status' => 'PENDING',
            ]
        );
    }
}
