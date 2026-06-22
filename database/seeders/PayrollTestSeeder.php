<?php

namespace Database\Seeders;

use App\Models\allowances;
use App\Models\bonuses;
use App\Models\employee;
use App\Models\employee_allowances;
use App\Models\EmployeeBonus;
use App\Models\EmployeeWiseBonus;
use App\Models\EmployeeWiseDeduction;
use App\Models\loans;
use Illuminate\Database\Seeder;

class PayrollTestSeeder extends Seeder
{
    public function run(): void
    {
        $month = (int) date('n');
        $year = (int) date('Y');

        // Regular allowance (not monthly bonus — that lives on compensation)
        $travelAllowance = allowances::updateOrCreate(
            ['allowance_code' => 'TA-001'],
            [
                'allowance_name' => 'Travel Allowance',
                'company_id' => 1,
                'department_id' => null,
                'amount' => 5000,
                'category' => 'travel',
                'status' => 'active',
                'allowance_type' => 'fixed',
                'fixed_date' => now()->toDateString(),
            ]
        );

        // Annual bonus master — paid in April and December
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
                    'allowance_id' => $travelAllowance->id,
                    'month' => $month,
                    'year' => $year,
                ],
                [
                    'custom_amount' => 5000 + ($index * 500),
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

            EmployeeWiseBonus::updateOrCreate(
                ['bonus_code' => 'EWB-' . $emp->attendance_employee_no],
                [
                    'bonus_name' => 'Special Bonus',
                    'bonus_description' => 'Employee-wise bonus sample',
                    'employee_id' => $emp->id,
                    'amount' => 3000 + ($index * 500),
                    'date' => now()->startOfMonth()->toDateString(),
                    'is_annual' => false,
                    'status' => 'active',
                ]
            );

            if ($index < 3) {
                EmployeeWiseDeduction::updateOrCreate(
                    ['deduction_code' => 'EWD-' . $emp->attendance_employee_no],
                    [
                        'deduction_name' => 'Union Fee',
                        'deduction_description' => 'Employee-wise deduction sample',
                        'employee_id' => $emp->id,
                        'amount' => 200 + ($index * 50),
                        'date' => now()->startOfMonth()->toDateString(),
                        'status' => 'active',
                    ]
                );
            }

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
        $this->command->info('Monthly bonus: set on compensation (salary split)');
        $this->command->info('Annual bonus: ' . $annualBonus->bonus_code . ' (paid in Apr & Dec)');
        $this->command->info('Login: admin@mail.com / 123456');
    }
}
