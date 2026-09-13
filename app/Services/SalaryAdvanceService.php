<?php

namespace App\Services;

use App\Models\PendingPayment;
use App\Models\SalaryAdvanceRequest;
use App\Models\employee;
use Illuminate\Support\Facades\Schema;

class SalaryAdvanceService
{
    public static function quota(employee $employee): array
    {
        $employee->loadMissing('compensation');
        $cfg = CompanyProcessSettings::packConfig($employee, 'salary_advance');
        $percent = (float) ($cfg['percent'] ?? 50);
        if ($percent <= 0) {
            $percent = 50;
        }
        $basic = (float) ($employee->compensation->basic_salary ?? 0);
        $cap = round($basic * $percent / 100, 2);

        $used = 0.0;
        if (Schema::hasTable('salary_advance_requests')) {
            $monthStart = now()->startOfMonth()->toDateString();
            $monthEnd = now()->endOfMonth()->toDateString();
            $requests = SalaryAdvanceRequest::where('employee_id', $employee->id)
                ->whereIn('status', ['PENDING', 'APPROVED'])
                ->where(function ($q) use ($monthStart, $monthEnd) {
                    $q->whereBetween('needed_on', [$monthStart, $monthEnd])
                        ->orWhere(function ($q2) use ($monthStart, $monthEnd) {
                            $q2->whereNull('needed_on')
                                ->whereBetween('created_at', [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
                        });
                })
                ->get();

            $paidIds = [];
            if (Schema::hasTable('pending_payments')) {
                $paidIds = PendingPayment::where('source_type', 'salary_advance')
                    ->where('status', 'PAID')
                    ->whereIn('source_id', $requests->pluck('id'))
                    ->pluck('source_id')
                    ->all();
            }

            $used = (float) $requests->reject(fn ($row) => in_array($row->id, $paidIds, true))->sum('amount');
        }

        return [
            'basic_salary' => round($basic, 2),
            'percent' => $percent,
            'cap' => $cap,
            'used' => round($used, 2),
            'available' => max(0, round($cap - $used, 2)),
        ];
    }
}
