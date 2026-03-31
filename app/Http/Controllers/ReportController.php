<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\salary_process;

class ReportController extends Controller
{
    public function getMonthlyReportData(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $months = [str_pad($month, 2, '0', STR_PAD_LEFT), (int)$month, (string)(int)$month];

        $salaries = salary_process::with('employee.compensation')
            ->whereIn('month', $months)
            ->where('year', $year)
            ->whereIn('status', ['pending', 'processed', 'issued'])
            ->get();

        $data = $salaries->map(function ($s) {
            $breakdown = is_string($s->salary_breakdown) ? json_decode($s->salary_breakdown, true) : ($s->salary_breakdown ?? []);
            
            $allowances = is_string($s->allowances) ? json_decode($s->allowances, true) : ($s->allowances ?? []);
            $deductions = is_string($s->deductions) ? json_decode($s->deductions, true) : ($s->deductions ?? []);
            $bonuses = is_string($s->bonuses) ? json_decode($s->bonuses, true) : ($s->bonuses ?? []);
            
            $comp = $s->employee->compensation ?? null;

            return [
                'emp_no' => $s->employee_no ?? '-',
                'name' => $s->full_name ?? '-',
                'bank' => $comp->bank_name ?? '-',
                'branch' => $comp->branch_name ?? '-',
                'account' => $comp->bank_account_no ?? '-',
                
                'basic_salary' => (float)($breakdown['basic_salary'] ?? 0),
                'gross_salary' => (float)($breakdown['gross_salary'] ?? 0),
                'net_salary' => (float)($breakdown['net_salary'] ?? 0),
                'total_deductions' => (float)($breakdown['total_deductions'] ?? 0),
                
                'enable_epf_etf' => (int)($s->enable_epf_etf ?? 0),
                'epf_base' => (float)($breakdown['epf_etf_base'] ?? 0),
                'epf_8' => (float)($breakdown['epf_employee_deduction'] ?? 0),
                'epf_12' => (float)($breakdown['epf_employer_contribution'] ?? 0),
                'etf_3' => (float)($breakdown['etf_employer_contribution'] ?? 0),
                
                'no_pay_days' => (float)($s->approved_no_pay_days ?? 0),
                'no_pay_amount' => (float)($breakdown['full_day_nopay_deduction'] ?? 0) + (float)($breakdown['half_day_deduction'] ?? 0),
                
                'loan_amount' => (float)($s->total_loan_amount ?? 0),
                'loan_installment' => (float)($breakdown['loan_installment'] ?? 0),
                'loan_interest' => (float)($breakdown['loan_interest'] ?? 0),
                
                // 🔥 අලුතින් එකතු කළ OT විස්තර
                'ot_morning_hours' => (float)($breakdown['ot_morning_hours'] ?? 0),
                'ot_morning_fees' => (float)($breakdown['ot_morning_fees'] ?? 0),
                'ot_night_hours' => (float)($breakdown['ot_night_hours'] ?? 0),
                'ot_night_fees' => (float)($breakdown['ot_night_fees'] ?? 0),
                'holiday_ot_hours' => (float)($breakdown['holiday_ot_hours'] ?? 0),
                'holiday_ot_fees' => (float)($breakdown['holiday_ot_fees'] ?? 0),

                'raw_allowances' => $allowances,
                'raw_deductions' => $deductions,
                'raw_bonuses' => $bonuses,
            ];
        });

        return response()->json($data);
    }
}