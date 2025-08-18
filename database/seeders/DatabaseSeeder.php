<?php

namespace Database\Seeders;

use Carbon\Carbon;
use App\Models\User;
use App\Models\roles;
use App\Models\shifts;
use App\Models\spouse;
use App\Models\company;
use App\Models\children;
use App\Models\employee;
use App\Models\allowances;
use App\Models\departments;
use App\Models\designation;
use App\Models\compensation;
use App\Models\contact_detail;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\pay_deductions;
use App\Models\employment_type;
use App\Models\sub_departments;
use Illuminate\Database\Seeder;
use App\Models\organization_assignment;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $users = [
            [
                'name' => 'Isuru Bandara',
                'email' => 'isuru@mail.com',
                'password' => '123456789',
                'role' => 'admin',
            ],
            [
                'name' => 'Pawani Madhubashini',
                'email' => 'pawani@mail.com',
                'password' => '123456789',
                'role' => 'admin',
            ],
            [
                'name' => 'Eshan Dananjaya',
                'email' => 'eshan@mail.com',
                'password' => '123456789',
                'role' => 'admin',
            ],
            [
                'name' => 'Janitha Sandaruwan',
                'email' => 'janitha@mail.com',
                'password' => '123456789',
                'role' => 'admin',
            ],
            [
                'name' => 'Thushara Kumara',
                'email' => 'thushara@mail.com',
                'password' => '123456789',
                'role' => 'admin',
            ],
        ];

        foreach ($users as $userData) {
            User::create($userData);
        }

        employment_type::insert([
            [
                'id' => 1,
                'name' => 'Permanent Basis',
            ],
            [
                'id' => 2,
                'name' => 'Training',
            ],
            [
                'id' => 3,
                'name' => 'Contract Basis',
            ],
            [
                'id' => 4,
                'name' => 'Daily Wages Salary',
            ],
        ]);






        designation::insert([
            ['name' => 'Software Engineer', 'description' => 'Responsible for software development'],
            ['name' => 'HR Manager', 'description' => 'Manages HR operations'],
            ['name' => 'Finance Analyst', 'description' => 'Analyzes financial data'],
            ['name' => 'Marketing Specialist', 'description' => 'Handles marketing campaigns'],
            ['name' => 'Operations Manager', 'description' => 'Oversees daily operations'],
        ]);

        $shifts = [
            ['001', 'No OT - WD', '08:00:00', '17:00:00', '08:00:00', '17:00:00', '00:00:00', false, 4.5],
            // ['002', 'Snr Mgt - FM', '08:15:00', '17:15:00', '08:15:00', '17:15:00', '00:00:00', false, 4.5],
            // ['003', 'Snr Mgt - Purchasing', '07:45:00', '16:45:00', '07:45:00', '16:45:00', '00:00:00', false, 4.5],
            // ['004', 'Office Executive - WD', '08:00:00', '17:00:00', '08:00:00', '17:00:00', '08:00:00', false, 5.0],
            // ['005', 'Office Executive - WE', '08:00:00', '13:00:00', '08:00:00', '13:00:00', '08:00:00', false, 5.0],
            // ['006', 'Production Mgr - WD', '08:00:00', '17:00:00', '07:30:00', '17:15:00', '08:00:00', false, 4.5],
            // ['007', 'Production Mgr - WE', '08:00:00', '13:00:00', '07:30:00', '17:15:00', '08:00:00', false, 5.0],
            // ['008', 'QC - WE', '08:00:00', '13:00:00', '08:00:00', '17:00:00', '08:00:00', false, 5.0],
            // ['009', 'No OT with Late - WD', '08:00:00', '17:00:00', '08:00:00', '17:00:00', '08:00:00', false, 4.5],
            // ['010', 'Production - WD', '08:00:00', '17:00:00', '07:30:00', '17:00:00', '08:00:00', false, 5.0],
            // ['011', 'Production - WE', '08:00:00', '13:00:00', '07:30:00', '17:00:00', '08:00:00', false, 5.0],
            // ['012', 'Production - Trainee', '08:00:00', '17:00:00', '08:00:00', '17:00:00', '08:00:00', false, 4.5],
            // ['013', 'No OT with Late - WE', '12:00:00', '17:00:00', '12:00:00', '17:00:00', '12:00:00', false, 5.0],
            // ['014', 'OT with Late - WE', '12:00:00', '17:00:00', '11:30:00', '17:00:00', '12:00:00', false, 5.0],
            // ['015', 'Security - WD Morning', '07:00:00', '15:00:00', '07:00:00', '19:00:00', '07:00:00', false, 4.0],
            // ['016', 'Security - WD Night', '19:00:00', '03:00:00', '07:00:00', '07:00:00', '07:00:00', true, 4.0],
            // ['017', 'Security - WE Morning', '07:00:00', '15:00:00', '07:00:00', '19:00:00', '07:00:00', false, 5.0],
            // ['018', 'Security - WE Night', '19:00:00', '00:00:00', '07:00:00', '07:00:00', '07:00:00', true, 5.0],
        ];

        foreach ($shifts as $shift) {
            shifts::create([
                'shift_code' => $shift[0],
                'shift_description' => $shift[1],
                'start_time' => $shift[2],
                'end_time' => $shift[3],
                'morning_ot_start' => $shift[4],
                'special_ot_start' => $shift[5],
                'late_deduction' => $shift[6],
                'midnight_roster' => $shift[7],
                'nopay_hour_halfday' => $shift[8],
            ]);
        }

        // $allowances = [
        //     ['001', 'Traveling Allowance'],
        //     ['002', 'Special Allowance'],
        //     ['003', 'Attendance Allowance'],
        //     ['004', 'Production Incentive'],
        //     ['005', 'Medical Reimbursement'],
        //     ['006', 'Other Reimbursement'],
        // ];

        // foreach ($allowances as [$code, $name]) {
        //     allowances::create([
        //         'allowance_code' => $code,
        //         'allowance_name' => $name,
        //     ]);
        // }

        pay_deductions::insert([
            [
                'pay_deduction_code' => '001',
                'pay_deduction_name' => 'Salary Advance',
                'pay_deduction_amount' => 0.00,
            ],
            [
                'pay_deduction_code' => '002',
                'pay_deduction_name' => 'Meal Deduction',
                'pay_deduction_amount' => 0.00,
            ],
            [
                'pay_deduction_code' => '003',
                'pay_deduction_name' => 'Other Deduction',
                'pay_deduction_amount' => 0.00,
            ],
            [
                'pay_deduction_code' => '004',
                'pay_deduction_name' => 'Bond Deduction',
                'pay_deduction_amount' => 0.00,
            ],
        ]);



        $this->call([
            EmployeeSeeder::class,
            // AllowanceSeeder::class,
            // DeductionSeeder::class,
        ]);

        $employees = employee::all();

        // Sri Lankan banks for realism
        $banks = [
            ['name' => 'Bank of Ceylon', 'code' => '7010'],
            ['name' => 'People\'s Bank', 'code' => '7035'],
            ['name' => 'Commercial Bank', 'code' => '7056'],
            ['name' => 'Hatton National Bank', 'code' => '7083'],
            ['name' => 'Sampath Bank', 'code' => '7278'],
            ['name' => 'NDB Bank', 'code' => '7154'],
            ['name' => 'DFCC Bank', 'code' => '7456'],
            ['name' => 'Seylan Bank', 'code' => '7297'],
        ];

        // Branches for each bank (simplified)
        $branches = [
            ['name' => 'Colombo Main Branch', 'code' => '001'],
            ['name' => 'Kandy City Branch', 'code' => '002'],
            ['name' => 'Galle Fort Branch', 'code' => '003'],
            ['name' => 'Negombo Branch', 'code' => '004'],
            ['name' => 'Kurunegala Branch', 'code' => '005'],
            ['name' => 'Jaffna Branch', 'code' => '006'],
            ['name' => 'Matara Branch', 'code' => '007'],
            ['name' => 'Ratnapura Branch', 'code' => '008'],
        ];

        foreach ($employees as $employee) {
            // Determine base salary based on company and position
            $companyId = $employee->organizationAssignment->company_id;

            // Salary ranges based on company (ABC and XYZ pay more)
            if ($companyId == 1 || $companyId == 2) {
                $baseSalary = rand(60000, 250000); // 60k to 250k LKR
            } else {
                $baseSalary = rand(40000, 180000); // 40k to 180k LKR
            }

            // Adjust salary based on years of service
            $joiningDate = Carbon::parse($employee->organizationAssignment->date_of_joining);
            $yearsOfService = $joiningDate->diffInYears(Carbon::now());

            if ($yearsOfService > 0) {
                $incrementPercentage = min(50, $yearsOfService * 5); // Max 50% increment
                $baseSalary = $baseSalary * (1 + ($incrementPercentage / 100));
            }

            // Round to nearest 1000
            $baseSalary = round($baseSalary / 1000) * 1000;

            // Determine if employee is eligible for increment
            $isEligibleForIncrement = rand(0, 1) && $yearsOfService > 1;

            // Random bank details
            $bank = $banks[array_rand($banks)];
            $branch = $branches[array_rand($branches)];

            compensation::create([
                'employee_id' => $employee->id,
                'basic_salary' => $baseSalary,
                'increment_value' => $isEligibleForIncrement ? rand(5, 15) . '%' : null,
                'increment_effected_date' => $isEligibleForIncrement ? Carbon::now()->subMonths(rand(1, 11))->format('Y-m-d') : null,

                'enable_epf_etf' => rand(0, 1),
                'ot_active' => rand(0, 1),
                'early_deduction' => rand(0, 1),
                'increment_active' => $isEligibleForIncrement,
                'active_nopay' => rand(0, 1) ? true : false,
                'ot_morning' => rand(0, 1),
                'ot_evening' => rand(0, 1),
                'ot_morning_rate' => rand(20, 100),
                'ot_night_rate' => rand(20, 100),

                'bank_name' => $bank['name'],
                'branch_name' => $branch['name'],
                'bank_code' => $bank['code'],
                'branch_code' => $branch['code'],
                'bank_account_no' => '10' . rand(100000000, 999999999),

                'br1' => rand(0, 1),
                'br2' => rand(0, 1),

                'comments' => rand(0, 1) ? 'Regular employee with standard benefits' : null,
                'secondary_emp' => rand(0, 1) ? true : false,
                'primary_emp_basic' => rand(0, 1) ? true : false,
            ]);
        }



    }
}
