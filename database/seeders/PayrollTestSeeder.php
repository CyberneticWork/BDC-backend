<?php

namespace Database\Seeders;

use App\Models\allowances;
use App\Models\bonuses;
use App\Models\compensation;
use App\Models\employee;
use App\Models\employee_allowances;
use App\Models\EmployeeBonus;
use App\Models\loans;
use Illuminate\Database\Seeder;

class PayrollTestSeeder extends Seeder
{
    public function run(): void
    {
        $month = (int) date('n');
        $year = (int) date('Y');

        // Monthly bonus allowance master (employee-wise amounts assigned below)
        $monthlyBonusAllowance = allowances::updateOrCreate(
            ['allowance_code' => 'MB-001'],
            [
                'allowance_name' => 'Monthly Bonus',
                'company_id' => 1,
                'department_id' => null,
                'amount' => 15000,
                'category' => 'monthly_bonus',
                'status' => 'active',
                'allowance_type' => 'fixed',
                'fixed_date' => now()->toDateString(),
            ]
        );

        // Annual bonus — paid in April and December
        $annualBonus = bonuses::updateOrCreate(
            ['bonus_code' => 'AB-001'],
            [
                'bonus_name' => 'Annual Performance Bonus',
                'bonus_type' => 'fixed',
                'amount' => 50000,
                'company_id' => 1,
                'department_id' => null,
                'status' => 'active',
                'fixed_date' => now()->toDateString(),
                'is_annual' => true,
                'payment_months' => [4, 12],
            ]
        );

        $employees = employee::with('compensation')->where('is_active', 1)->limit(10)->get();

        if ($employees->isEmpty()) {
            $this->command->warn('No employees found. Run EmployeeSeeder first.');
            return;
        }

        foreach ($employees as $index => $emp) {
            $monthlyBonus = 10000 + ($index * 2500);
            $sportsPct = 2.0;
            $staffFund = 500 + ($index * 100);

            if ($emp->compensation) {
                $emp->compensation->update([
                    'monthly_bonus' => $monthlyBonus,
                    'sports_fund_percentage' => $sportsPct,
                    'staff_fund_amount' => $staffFund,
                    'enable_epf_etf' => true,
                ]);
            }

            employee_allowances::updateOrCreate(
                [
                    'employee_id' => $emp->id,
                    'allowance_id' => $monthlyBonusAllowance->id,
                    'month' => $month,
                    'year' => $year,
                ],
                [
                    'custom_amount' => $monthlyBonus,
                    'is_active' => true,
                ]
            );

            EmployeeBonus::updateOrCreate(
                [
                    'employee_id' => $emp->id,
                    'bonus_id' => $annualBonus->id,
                    'month' => null,
                    'year' => $year,
                ],
                [
                    'custom_amount' => 50000 + ($index * 5000),
                    'is_active' => true,
                ]
            );

            // Sample loan for first 3 employees
            if ($index < 3 && !loans::where('employee_id', $emp->id)->exists()) {
                loans::create([
                    'loan_id' => 'LOAN-TEST-' . $emp->attendance_employee_no,
                    'employee_id' => $emp->id,
                    'loan_amount' => 100000 + ($index * 25000),
                    'interest_rate_per_annum' => 12,
                    'installment_amount' => 5000,
                    'start_from' => now()->startOfMonth()->toDateString(),
                    'with_interest' => true,
                    'installment_count' => 24,
                    'status' => 'active',
                    'deduct_from' => 'bonus',
                    'schedule' => [],
                ]);
            }
        }

        $this->command->info('Payroll test data seeded for ' . $employees->count() . ' employees.');
        $this->command->info('Monthly bonus allowance: ' . $monthlyBonusAllowance->allowance_code);
        $this->command->info('Annual bonus: ' . $annualBonus->bonus_code . ' (paid in Apr & Dec)');
        $this->command->info('Login: admin@mail.com / 123456');
    }
}
