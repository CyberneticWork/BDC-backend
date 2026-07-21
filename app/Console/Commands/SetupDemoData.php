<?php

namespace App\Console\Commands;

use App\Http\Controllers\SalaryProcessController;
use App\Models\company;
use App\Models\employee;
use App\Models\salary_process;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SetupDemoData extends Command
{
    protected $signature = 'demo:setup {--fresh : Re-seed demo salary records for the current month}';

    protected $description = 'Seed demo/mock HR & payroll data and process sample salaries for demonstration';

    public function handle(): int
    {
        if (!$this->isLocalDatabase()) {
            $this->error('Refusing to run demo setup on a non-local database.');
            return self::FAILURE;
        }

        $this->info('Setting up demo data...');

        $this->ensureAdminUser();
        $company = $this->ensureDemoCompany();
        $this->ensureEmployeePayrollFields($company->id);
        $this->seedPayrollMasters();

        $month = (int) date('n');
        $year = (int) date('Y');
        $monthPadded = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        if ($this->option('fresh')) {
            salary_process::whereIn('month', [$month, $monthPadded, (string) $month])
                ->where('year', (string) $year)
                ->delete();
            $this->warn("Cleared existing salary records for {$monthPadded}/{$year}.");
        }

        $processed = $this->processDemoSalaries($company->id, $month, $monthPadded, (string) $year);

        $this->newLine();
        $this->info('Demo setup complete.');
        $this->table(
            ['Item', 'Value'],
            [
                ['Login', 'admin@mail.com / 123456'],
                ['Demo company', "{$company->name} ({$company->company_code})"],
                ['Employees (active)', (string) employee::where('is_active', 1)->count()],
                ['Salaries processed', (string) $processed . " for {$monthPadded}/{$year}"],
                ['Frontend', 'http://127.0.0.1:5173/'],
            ]
        );

        return self::SUCCESS;
    }

    private function isLocalDatabase(): bool
    {
        $host = (string) config('database.connections.mysql.host');
        $db = (string) config('database.connections.mysql.database');

        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            && !str_contains(strtolower($db), 'apis_spmhr');
    }

    private function ensureAdminUser(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@mail.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('123456'),
                'role' => 'admin',
            ]
        );
    }

    private function ensureDemoCompany(): company
    {
        $company = company::updateOrCreate(
            ['company_code' => 'BDC-DEMO'],
            ['name' => 'BDC Bank (Demo)']
        );

        company::whereNull('company_code')->orWhere('company_code', '')->get()->each(function ($c) {
            if (empty($c->company_code)) {
                $c->update(['company_code' => 'CMP-' . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)]);
            }
        });

        return $company;
    }

    private function ensureEmployeePayrollFields(int $companyId): void
    {
        $employees = employee::with(['compensation', 'organizationAssignment'])
            ->where('is_active', 1)
            ->get();

        if ($employees->isEmpty()) {
            $this->warn('No active employees found. Run: php artisan db:seed --class=EmployeeSeeder');
            return;
        }

        $updated = 0;
        foreach ($employees as $index => $emp) {
            if (!$emp->compensation) {
                continue;
            }

            $comp = $emp->compensation;
            $changed = false;

            if ((float) ($comp->monthly_bonus ?? 0) <= 0) {
                $comp->monthly_bonus = 8000 + ($index * 1500);
                $changed = true;
            }
            if ($comp->sports_fund_percentage === null) {
                $comp->sports_fund_percentage = 2.0;
                $changed = true;
            }
            if ((float) ($comp->staff_fund_amount ?? 0) <= 0) {
                $comp->staff_fund_amount = 500 + ($index * 100);
                $changed = true;
            }
            if (!$comp->enable_epf_etf) {
                $comp->enable_epf_etf = true;
                $changed = true;
            }
            if (!$comp->bank_name) {
                $comp->bank_name = 'Peoples Bank';
                $comp->branch_name = 'Monaragala';
                $comp->bank_account_no = '10' . str_pad((string) ($emp->id + 100000000), 9, '0', STR_PAD_LEFT);
                $comp->account_holder_name = $emp->full_name;
                $changed = true;
            }
            if ($index % 2 === 0 && !$comp->br1 && !$comp->br2) {
                $comp->br1 = true;
                $changed = true;
            }
            if ($index % 3 === 0 && !$comp->br2) {
                $comp->br2 = true;
                $changed = true;
            }

            if ($changed) {
                $comp->save();
                $updated++;
            }
        }

        $this->line("Updated payroll fields on {$updated} employee compensation record(s).");
    }

    private function seedPayrollMasters(): void
    {
        $companyId = company::query()->orderBy('id')->value('id');
        if (!$companyId) {
            $this->warn('No company found — skipping payroll master seed.');
            return;
        }

        $month = (int) date('n');
        $year = (int) date('Y');

        $travelAllowance = \App\Models\allowances::updateOrCreate(
            ['allowance_code' => 'TA-001'],
            [
                'allowance_name' => 'Travel Allowance',
                'company_id' => $companyId,
                'department_id' => null,
                'amount' => 5000,
                'category' => 'travel',
                'status' => 'active',
                'allowance_type' => 'fixed',
                'fixed_date' => now()->toDateString(),
            ]
        );

        $annualBonus = \App\Models\bonuses::updateOrCreate(
            ['bonus_code' => 'AB-001'],
            [
                'bonus_name' => 'Annual Performance Bonus',
                'bonus_type' => 'fixed',
                'amount' => 50000,
                'company_id' => $companyId,
                'department_id' => null,
                'status' => 'active',
                'fixed_date' => now()->toDateString(),
                'is_annual' => true,
                'payment_months' => [4, 12],
            ]
        );

        $employees = employee::with('compensation')->where('is_active', 1)->limit(15)->get();
        foreach ($employees as $index => $emp) {
            \App\Models\employee_allowances::updateOrCreate(
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

            if ($index < 3) {
                \App\Models\EmployeeWiseDeduction::updateOrCreate(
                    ['deduction_code' => 'EWD-SALADV-' . $emp->attendance_employee_no],
                    [
                        'deduction_name' => 'Salary Advance',
                        'deduction_description' => 'Demo salary advance',
                        'employee_id' => $emp->id,
                        'amount' => 1000 + ($index * 500),
                        'date' => now()->startOfMonth()->toDateString(),
                        'status' => 'active',
                    ]
                );
            }

            if ($index < 3 && !\App\Models\loans::where('employee_id', $emp->id)->exists()) {
                \App\Models\loans::create([
                    'loan_id' => 'LOAN-DEMO-' . $emp->attendance_employee_no,
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

        $this->line('Payroll masters seeded (allowances, salary advance, loans).');
    }

    private function processDemoSalaries(int $companyId, int $monthInt, string $monthPadded, string $year): int
    {
        /** @var SalaryProcessController $controller */
        $controller = app(SalaryProcessController::class);
        $request = Request::create('/api/salary-process/employees', 'GET', [
            'month' => $monthPadded,
            'year' => $year,
            'company_id' => $companyId,
        ]);

        $response = $controller->getEmployeesByMonthAndCompany($request);
        $payload = $response->getData(true);
        $employees = $payload['data'] ?? [];

        if (empty($employees)) {
            $requestAll = Request::create('/api/salary-process/employees', 'GET', [
                'month' => $monthPadded,
                'year' => $year,
            ]);
            $responseAll = $controller->getEmployeesByMonthAndCompany($requestAll);
            $employees = $responseAll->getData(true)['data'] ?? [];
        }

        if (empty($employees)) {
            $this->warn('No employees returned for salary processing.');
            return 0;
        }

        DB::beginTransaction();
        try {
            $existing = salary_process::whereIn('month', [$monthInt, $monthPadded, (string) $monthInt])
                ->where('year', $year)
                ->pluck('employee_no')
                ->all();

            $toSave = array_values(array_filter($employees, function ($row) use ($existing) {
                return !in_array($row['emp_no'] ?? '', $existing, true);
            }));

            if (empty($toSave)) {
                $this->line('Salary records already exist for this month.');
                DB::commit();
                return salary_process::whereIn('month', [$monthInt, $monthPadded, (string) $monthInt])
                    ->where('year', $year)
                    ->count();
            }

            $storeRequest = Request::create('/api/salary-process/store', 'POST', [
                'month' => $monthInt,
                'year' => (int) $year,
                'data' => $toSave,
            ]);

            $storeResponse = $controller->storeSalaryData($storeRequest);
            if ($storeResponse->getStatusCode() >= 400) {
                throw new \RuntimeException($storeResponse->getContent());
            }

            DB::commit();
            $this->info('Processed ' . count($toSave) . ' salary record(s).');
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Salary processing failed: ' . $e->getMessage());
            return 0;
        }

        return salary_process::whereIn('month', [$monthInt, $monthPadded, (string) $monthInt])
            ->where('year', $year)
            ->count();
    }
}
