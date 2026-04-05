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
            $coinage = is_string($s->coinage_breakdown) ? json_decode($s->coinage_breakdown, true) : ($s->coinage_breakdown ?? null);
            
            $comp = $s->employee->compensation ?? null;

            // 1. Bank එකට අදාළ Earnings
            $basicSalary = (float)($breakdown['basic_salary'] ?? 0);
            $totalAllowances = array_reduce($allowances, fn($c, $a) => $c + (float)($a['amount'] ?? 0), 0);
            $bankEarnings = $basicSalary + $totalAllowances;

            // 2. Bank එකෙන් කැපෙන No Pay සහ අනිත් දේවල්
            $bankNoPay = (float)($breakdown['full_day_nopay_deduction'] ?? 0) + (float)($breakdown['half_day_deduction'] ?? 0);
            $epf8 = (float)($breakdown['epf_employee_deduction'] ?? 0);
            $loanDed = (float)($breakdown['loan_installment'] ?? 0) + (float)($breakdown['loan_interest'] ?? 0);
            $stampDuty = (float)($breakdown['stamp_duty'] ?? 0);
            
            $bankDeductions = $epf8 + $bankNoPay + $loanDed + $stampDuty;

            // 3. Bank Amount එක සහ Cash Amount එක
            $bankAmount = max(0, $bankEarnings - $bankDeductions);
            $netSalary = (float)($breakdown['net_salary'] ?? 0);
            $cashAmount = max(0, $netSalary - $bankAmount);

            // 🔥 4. No Pay Report එකට අදාළ සම්පූර්ණ No Pay ගාණ හැදීම
            $saturdayNoPay = (float)($breakdown['saturday_nopay_deduction'] ?? 0);
            $earlyOutNoPay = (float)($breakdown['early_out_nopay_deduction'] ?? 0);
            $totalNoPayForReport = $bankNoPay + $saturdayNoPay + $earlyOutNoPay; // ඔක්කොම එකතු කළා!

            return [
                'process_id' => $s->id,
                'emp_no' => $s->employee_no ?? '-',
                'name' => $s->full_name ?? '-',
                'bank' => $comp->bank_name ?? '-',
                'branch' => $comp->branch_name ?? '-',
                'account' => $comp->bank_account_no ?? '-',
                
                'basic_salary' => $basicSalary,
                'gross_salary' => (float)($breakdown['gross_salary'] ?? 0),
                'net_salary' => $netSalary,
                'total_deductions' => (float)($breakdown['total_deductions'] ?? 0),
                
                'enable_epf_etf' => (int)($s->enable_epf_etf ?? 0),
                'epf_base' => (float)($breakdown['epf_etf_base'] ?? 0),
                'epf_8' => $epf8,
                'epf_12' => (float)($breakdown['epf_employer_contribution'] ?? 0),
                'etf_3' => (float)($breakdown['etf_employer_contribution'] ?? 0),
                
                'no_pay_days' => (float)($s->approved_no_pay_days ?? 0),
                'no_pay_amount' => $totalNoPayForReport, // 🔥 සම්පූර්ණ ගාණ මෙතනින් යවනවා
                
                'loan_amount' => (float)($s->total_loan_amount ?? 0),
                'loan_installment' => (float)($breakdown['loan_installment'] ?? 0),
                'loan_interest' => (float)($breakdown['loan_interest'] ?? 0),
                
                'ot_morning_hours' => (float)($breakdown['ot_morning_hours'] ?? 0),
                'ot_morning_fees' => (float)($breakdown['ot_morning_fees'] ?? 0),
                'ot_night_hours' => (float)($breakdown['ot_night_hours'] ?? 0),
                'ot_night_fees' => (float)($breakdown['ot_night_fees'] ?? 0),
                'holiday_ot_hours' => (float)($breakdown['holiday_ot_hours'] ?? 0),
                'holiday_ot_fees' => (float)($breakdown['holiday_ot_fees'] ?? 0),

                'bank_amount' => $bankAmount,
                'cash_amount' => $cashAmount,
                
                'saved_coinage' => $coinage,
                'raw_allowances' => $allowances,
                'raw_deductions' => $deductions,
                'raw_bonuses' => $bonuses,
            ];
        });

        return response()->json($data);
    }

    public function saveCoinageData(Request $request)
    {
        $request->validate(['coinage_data' => 'required|array']);
        try {
            foreach ($request->coinage_data as $data) {
                if (isset($data['process_id'])) {
                    salary_process::where('id', $data['process_id'])->update([
                        'coinage_breakdown' => json_encode($data['notes'])
                    ]);
                }
            }
            return response()->json(['message' => 'Coinage data saved successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to save: ' . $e->getMessage()], 500);
        }
    }
}



/*
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
    */