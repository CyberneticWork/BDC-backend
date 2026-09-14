<?php

namespace App\Services;

use App\Models\employee;
use App\Models\employee_allowances;
use App\Models\employee_deductions;
use App\Models\EmployeeBonus;
use App\Models\EmployeeWiseAllowance;
use App\Models\EmployeeWiseDeduction;
use App\Models\EmployeeWiseBonus;
use App\Models\loans;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class EmployeeReportService
{
    public function buildReport(
        ?int $employeeId = null,
        ?string $employeeNo = null,
        ?int $companyId = null,
        ?int $departmentId = null,
        bool $activeOnly = false
    ): array {
        $query = employee::with([
            'employmentType:id,name',
            'spouse',
            'children',
            'contactDetail',
            'compensation',
            'documents',
            'organizationAssignment.company:id,name',
            'organizationAssignment.department:id,name',
            'organizationAssignment.subDepartment:id,name',
            'organizationAssignment.designation:id,name',
        ])->excludeContract()->orderBy('full_name');

        if ($employeeId) {
            $query->where('id', $employeeId);
        } elseif ($employeeNo) {
            $query->where('attendance_employee_no', $employeeNo);
        }

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        if ($companyId || $departmentId) {
            $query->whereHas('organizationAssignment', function ($q) use ($companyId, $departmentId) {
                if ($companyId) {
                    $q->where('company_id', $companyId);
                }
                if ($departmentId) {
                    $q->where('department_id', $departmentId);
                }
            });
        }

        $employees = $query->get();
        $ids = $employees->pluck('id');

        $allowancesByEmp = $this->groupByEmployee(
            employee_allowances::with('allowance:id,allowance_name,allowance_code,amount')
                ->whereIn('employee_id', $ids)
                ->where('is_active', true)
                ->get()
        );

        $deductionsByEmp = $this->groupByEmployee(
            employee_deductions::with('deduction:id,deduction_name,deduction_code,amount')
                ->whereIn('employee_id', $ids)
                ->where('is_active', true)
                ->get()
        );

        $bonusesByEmp = $this->groupByEmployee(
            EmployeeBonus::with('bonus:id,bonus_name,bonus_code,amount')
                ->whereIn('employee_id', $ids)
                ->where('is_active', true)
                ->get()
        );

        $wiseAllowancesByEmp = $this->groupByEmployee(
            EmployeeWiseAllowance::whereIn('employee_id', $ids)->whereIn('status', ['active', 'Active'])->get()
        );

        $wiseDeductionsByEmp = $this->groupByEmployee(
            EmployeeWiseDeduction::whereIn('employee_id', $ids)->whereIn('status', ['active', 'Active'])->get()
        );

        $wiseBonusesByEmp = $this->groupByEmployee(
            EmployeeWiseBonus::whereIn('employee_id', $ids)->whereIn('status', ['active', 'Active'])->get()
        );

        $loansByEmp = $this->groupByEmployee(
            loans::whereIn('employee_id', $ids)->orderBy('created_at', 'desc')->get()
        );

        $employeeReports = $employees->map(function ($emp) use (
            $allowancesByEmp,
            $deductionsByEmp,
            $bonusesByEmp,
            $wiseAllowancesByEmp,
            $wiseDeductionsByEmp,
            $wiseBonusesByEmp,
            $loansByEmp
        ) {
            return $this->formatEmployee(
                $emp,
                $allowancesByEmp->get($emp->id, collect()),
                $deductionsByEmp->get($emp->id, collect()),
                $bonusesByEmp->get($emp->id, collect()),
                $wiseAllowancesByEmp->get($emp->id, collect()),
                $wiseDeductionsByEmp->get($emp->id, collect()),
                $wiseBonusesByEmp->get($emp->id, collect()),
                $loansByEmp->get($emp->id, collect())
            );
        });

        $scope = $employeeId || $employeeNo ? 'employee' : ($companyId || $departmentId ? 'filtered' : 'all');

        return [
            'generated_at' => now()->toIso8601String(),
            'report_date' => Carbon::today()->toDateString(),
            'filter' => [
                'employee_id' => $employeeId,
                'employee_no' => $employeeNo ?? ($employeeReports->first()['employee_no'] ?? null),
                'company_id' => $companyId,
                'department_id' => $departmentId,
                'active_only' => $activeOnly,
                'scope' => $scope,
            ],
            'summary' => $this->buildSummary($employeeReports),
            'employees' => $employeeReports->values()->all(),
        ];
    }

    private function groupByEmployee(Collection $items): Collection
    {
        return $items->groupBy('employee_id');
    }

    private function buildSummary(Collection $employeeReports): array
    {
        $byCompany = [];
        $byDepartment = [];

        foreach ($employeeReports as $emp) {
            $company = $emp['organization']['company'] ?? 'Unassigned';
            $department = $emp['organization']['department'] ?? 'Unassigned';
            $byCompany[$company] = ($byCompany[$company] ?? 0) + 1;
            $byDepartment[$department] = ($byDepartment[$department] ?? 0) + 1;
        }

        return [
            'total_employees' => $employeeReports->count(),
            'active_employees' => $employeeReports->where('status', 'Active')->count(),
            'inactive_employees' => $employeeReports->where('status', 'Inactive')->count(),
            'by_company' => $byCompany,
            'by_department' => $byDepartment,
            'total_basic_salary' => round($employeeReports->sum('payroll_totals.basic_salary'), 2),
            'total_monthly_bonus' => round($employeeReports->sum('payroll_totals.monthly_bonus'), 2),
            'total_allowances' => round($employeeReports->sum('payroll_totals.total_allowances'), 2),
            'total_deductions' => round($employeeReports->sum('payroll_totals.total_deductions'), 2),
            'total_bonuses' => round($employeeReports->sum('payroll_totals.total_bonuses'), 2),
            'total_active_loans' => (int) $employeeReports->sum('payroll_totals.active_loan_count'),
            'total_loan_outstanding' => round($employeeReports->sum('payroll_totals.loan_outstanding'), 2),
        ];
    }

    private function formatEmployee(
        employee $employee,
        Collection $allowances,
        Collection $deductions,
        Collection $bonuses,
        Collection $wiseAllowances,
        Collection $wiseDeductions,
        Collection $wiseBonuses,
        Collection $employeeLoans
    ): array {
        $org = $employee->organizationAssignment;
        $contact = $employee->contactDetail;
        $comp = $employee->compensation;

        $allowanceRows = $allowances->map(fn ($row) => [
            'type' => 'master',
            'code' => $row->allowance?->allowance_code,
            'name' => $row->allowance?->allowance_name,
            'amount' => round((float) ($row->custom_amount ?? $row->allowance?->amount ?? 0), 2),
            'month' => $row->month,
            'year' => $row->year,
        ])->values()->all();

        $wiseAllowanceRows = $wiseAllowances->map(fn ($row) => [
            'type' => 'employee_wise',
            'code' => $row->allowance_code,
            'name' => $row->allowance_name,
            'description' => $row->allowance_description,
            'amount' => round((float) $row->amount, 2),
            'date' => $this->fmtDate($row->date),
            'status' => $row->status,
        ])->values()->all();

        $deductionRows = $deductions->map(fn ($row) => [
            'type' => 'master',
            'code' => $row->deduction?->deduction_code,
            'name' => $row->deduction?->deduction_name,
            'amount' => round((float) ($row->custom_amount ?? $row->deduction?->amount ?? 0), 2),
            'month' => $row->month,
            'year' => $row->year,
        ])->values()->all();

        $wiseDeductionRows = $wiseDeductions->map(fn ($row) => [
            'type' => 'employee_wise',
            'code' => $row->deduction_code,
            'name' => $row->deduction_name,
            'description' => $row->deduction_description,
            'amount' => round((float) $row->amount, 2),
            'date' => $this->fmtDate($row->date),
            'status' => $row->status,
        ])->values()->all();

        $bonusRows = $bonuses->map(fn ($row) => [
            'type' => 'master',
            'code' => $row->bonus?->bonus_code,
            'name' => $row->bonus?->bonus_name,
            'amount' => round((float) ($row->custom_amount ?? $row->bonus?->amount ?? 0), 2),
            'month' => $row->month,
            'year' => $row->year,
        ])->values()->all();

        $wiseBonusRows = $wiseBonuses->map(fn ($row) => [
            'type' => 'employee_wise',
            'code' => $row->bonus_code,
            'name' => $row->bonus_name,
            'description' => $row->bonus_description,
            'amount' => round((float) $row->amount, 2),
            'date' => $this->fmtDate($row->date),
            'is_annual' => (bool) $row->is_annual,
            'payment_months' => $row->payment_months,
            'status' => $row->status,
        ])->values()->all();

        $loanRows = $employeeLoans->map(fn ($loan) => [
            'loan_id' => $loan->loan_id,
            'loan_amount' => round((float) $loan->loan_amount, 2),
            'interest_rate' => round((float) $loan->interest_rate_per_annum, 2),
            'installment_amount' => round((float) $loan->installment_amount, 2),
            'installments_remaining' => (int) $loan->installment_count,
            'with_interest' => (bool) $loan->with_interest,
            'deduct_from' => $this->loanDeductLabel($loan),
            'start_from' => $this->fmtDate($loan->start_from),
            'status' => $loan->status,
            'outstanding_estimate' => round((float) $loan->installment_count * (float) $loan->installment_amount, 2),
        ])->values()->all();

        $totalAllowances = collect($allowanceRows)->sum('amount') + collect($wiseAllowanceRows)->sum('amount');
        $totalDeductions = collect($deductionRows)->sum('amount') + collect($wiseDeductionRows)->sum('amount');
        $totalBonuses = collect($bonusRows)->sum('amount') + collect($wiseBonusRows)->sum('amount');
        $activeLoans = $employeeLoans->where('status', 'active');
        $loanOutstanding = $activeLoans->sum(fn ($l) => (float) $l->installment_count * (float) $l->installment_amount);

        return [
            'id' => $employee->id,
            'employee_no' => $employee->attendance_employee_no,
            'status' => $employee->is_active ? 'Active' : 'Inactive',
            'personal' => [
                'title' => $employee->title,
                'full_name' => $employee->full_name,
                'name_with_initials' => $employee->name_with_initials,
                'display_name' => $employee->display_name,
                'nic' => $employee->nic,
                'epf_no' => $employee->epf,
                'dob' => $this->fmtDate($employee->dob),
                'gender' => $employee->gender,
                'marital_status' => $employee->marital_status,
                'religion' => $employee->religion,
                'country_of_birth' => $employee->country_of_birth,
                'employment_type' => $employee->employmentType?->name,
                'email' => $employee->email,
            ],
            'contact' => [
                'email' => $contact?->email,
                'mobile' => $contact?->mobile_line,
                'land_line' => $contact?->land_line,
                'permanent_address' => $contact?->permanent_address,
                'temporary_address' => $contact?->temporary_address,
                'district' => $contact?->district,
                'province' => $contact?->province,
                'gn_division' => $contact?->gn_division,
                'police_station' => $contact?->police_station,
                'electoral_division' => $contact?->electoral_division,
                'emergency_name' => $contact?->emg_name,
                'emergency_relationship' => $contact?->emg_relationship,
                'emergency_phone' => $contact?->emg_tel,
                'emergency_address' => $contact?->emg_address,
            ],
            'organization' => [
                'company' => $org?->company?->name,
                'department' => $org?->department?->name,
                'sub_department' => $org?->subDepartment?->name,
                'designation' => $org?->designation?->name,
                'supervisor' => $org?->current_supervisor,
                'date_of_joining' => $this->fmtDate($org?->date_of_joining),
                'confirmation_date' => $this->fmtDate($org?->confirmation_date),
                'day_off' => $org?->day_off,
                'probationary_period' => $org?->probationary_period,
                'probationary_from' => $this->fmtDate($org?->probationary_period_from),
                'probationary_to' => $this->fmtDate($org?->probationary_period_to),
                'training_period' => $org?->training_period,
                'training_from' => $this->fmtDate($org?->training_period_from),
                'training_to' => $this->fmtDate($org?->training_period_to),
                'contract_period' => $org?->contract_period,
                'contract_from' => $this->fmtDate($org?->contract_period_from),
                'contract_to' => $this->fmtDate($org?->contract_period_to),
                'date_of_resigning' => $this->fmtDate($org?->date_of_resigning),
                'resigned_reason' => $org?->resigned_reason,
                'org_status' => $org?->is_active ? 'Active' : 'Inactive',
            ],
            'compensation' => [
                'employee_category' => $comp?->employee_category,
                'basic_salary' => round((float) ($comp?->basic_salary ?? 0), 2),
                'monthly_bonus' => round((float) ($comp?->monthly_bonus ?? 0), 2),
                'sports_fund_percentage' => round((float) ($comp?->sports_fund_percentage ?? 0), 2),
                'staff_fund_amount' => round((float) ($comp?->staff_fund_amount ?? 0), 2),
                'increment_value' => round((float) ($comp?->increment_value ?? 0), 2),
                'increment_effected_date' => $this->fmtDate($comp?->increment_effected_date),
                'increment_active' => (bool) ($comp?->increment_active ?? false),
                'bank_name' => $comp?->bank_name,
                'branch_name' => $comp?->branch_name,
                'bank_code' => $comp?->bank_code,
                'branch_code' => $comp?->branch_code,
                'bank_account_no' => $comp?->bank_account_no,
                'enable_epf_etf' => (bool) ($comp?->enable_epf_etf ?? false),
                'ot_active' => (bool) ($comp?->ot_active ?? false),
                'ot_active_special' => (bool) ($comp?->ot_active_special ?? false),
                'early_deduction' => (bool) ($comp?->early_deduction ?? false),
                'active_nopay' => (bool) ($comp?->active_nopay ?? false),
                'ot_morning_rate' => $comp?->ot_morning_rate,
                'ot_night_rate' => $comp?->ot_night_rate,
                'comments' => $comp?->comments,
            ],
            'family' => [
                'spouse' => $employee->spouse ? [
                    'type' => $employee->spouse->type,
                    'name' => $employee->spouse->name,
                    'nic' => $employee->spouse->nic,
                    'dob' => $this->fmtDate($employee->spouse->dob),
                    'age' => $employee->spouse->age,
                ] : null,
                'children' => $employee->children?->map(fn ($c) => [
                    'name' => $c->name,
                    'nic' => $c->nic,
                    'dob' => $this->fmtDate($c->dob),
                    'age' => $c->age,
                ])->values()->all() ?? [],
            ],
            'documents' => $employee->documents?->map(fn ($d) => [
                'type' => $d->document_type,
                'name' => $d->document_name,
            ])->values()->all() ?? [],
            'allowances' => array_merge($allowanceRows, $wiseAllowanceRows),
            'deductions' => array_merge($deductionRows, $wiseDeductionRows),
            'bonuses' => array_merge($bonusRows, $wiseBonusRows),
            'loans' => $loanRows,
            'payroll_totals' => [
                'basic_salary' => round((float) ($comp?->basic_salary ?? 0), 2),
                'monthly_bonus' => round((float) ($comp?->monthly_bonus ?? 0), 2),
                'total_allowances' => round($totalAllowances, 2),
                'total_deductions' => round($totalDeductions, 2),
                'total_bonuses' => round($totalBonuses, 2),
                'active_loan_count' => $activeLoans->count(),
                'loan_outstanding' => round($loanOutstanding, 2),
            ],
        ];
    }

    private function fmtDate(mixed $date): ?string
    {
        if (!$date) {
            return null;
        }

        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Throwable) {
            return (string) $date;
        }
    }

    private function loanDeductLabel($loan): string
    {
        $pay = function (?string $from, ?string $fallback): string {
            $v = strtolower(trim((string) ($from ?: $fallback ?: 'bonus')));
            return $v === 'basic' ? 'Basic' : 'Monthly Bonus';
        };
        $legacy = $loan->deduct_from ?? 'bonus';
        $inst = $pay($loan->installment_deduct_from ?? null, $legacy === 'basic' ? 'basic' : 'bonus');
        $interest = $pay($loan->interest_deduct_from ?? null, $loan->installment_deduct_from ?? ($legacy === 'basic' ? 'basic' : 'bonus'));
        if ($inst === $interest) {
            return "Installment and interest from {$inst}";
        }
        return "Installment from {$inst}, interest from {$interest}";
    }
}
