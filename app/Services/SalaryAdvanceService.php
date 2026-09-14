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

    public static function applyHrDeductFrom(SalaryAdvanceRequest $row): void
    {
        if (!Schema::hasColumn('salary_advance_requests', 'deduct_from')) {
            return;
        }
        $row->loadMissing('employee.organizationAssignment.company');
        if (!$row->employee || !CompanyProcessSettings::usesSalaryAdvancePack($row->employee)) {
            return;
        }
        $row->deduct_from = CompanyProcessSettings::salaryAdvanceHrDeductFrom($row->employee);
    }

    public static function isNamedAdvanceDeduction(?string $name): bool
    {
        $n = strtolower(trim((string) $name));
        if ($n === '') {
            return false;
        }

        return str_contains($n, 'salary advance')
            || str_contains($n, 'salary_advance')
            || $n === 'advance';
    }

    /**
     * Approved HR-path advances (HR created, or HR approved) for the payroll month.
     *
     * @return array{basic: float, bonus: float, total: float}
     */
    public static function monthPayrollDeductions(int $employeeId, int $year, int $month): array
    {
        $empty = ['basic' => 0.0, 'bonus' => 0.0, 'total' => 0.0];
        if (!Schema::hasTable('salary_advance_requests') || $employeeId < 1) {
            return $empty;
        }

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));
        $q = SalaryAdvanceRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'APPROVED')
            ->where(function ($w) use ($start, $end) {
                $w->whereBetween('needed_on', [$start, $end])
                    ->orWhere(function ($w2) use ($start, $end) {
                        $w2->whereNull('needed_on')
                            ->whereBetween('created_at', [$start . ' 00:00:00', $end . ' 23:59:59']);
                    });
            });

        if (Schema::hasColumn('salary_advance_requests', 'deduct_from')) {
            $q->whereIn('deduct_from', ['basic', 'bonus']);
        } else {
            return $empty;
        }

        $basic = 0.0;
        $bonus = 0.0;
        foreach ($q->get(['amount', 'deduct_from']) as $row) {
            $amt = (float) $row->amount;
            if (strtolower((string) $row->deduct_from) === 'basic') {
                $basic += $amt;
            } else {
                $bonus += $amt;
            }
        }

        return [
            'basic' => round($basic, 2),
            'bonus' => round($bonus, 2),
            'total' => round($basic + $bonus, 2),
        ];
    }
}
