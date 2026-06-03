<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;




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
                'name' => 'Dilan Sriyantha',
                'email' => 'dilan@mail.com',
                'password' => '123456',
                'role' => 'admin'
            ],
            [
                'name' => 'Admin',
                'email' => 'admin@mail.com',
                'password' => '123456',
                'role' => 'admin'
            ]
        ];

        foreach ($users as $userData) {
            User::create($userData);
        }

        employment_type::insert([
            [
                'id' => 1,
                'name' => 'Permanent',
            ],
            [
                'id' => 2,
                'name' => 'Training',
            ],
            [
                'id' => 3,
                'name' => 'Contract',
            ],
            [
                'id' => 4,
                'name' => 'Daily Wages Salary',
            ],
            [
                'id' => 5,
                'name' => 'Probation',
            ]
        ]);

        $shifts = [
            ['001', 'No OT - WD', '08:00:00', '17:00:00', '08:00:00', '17:00:00', '00:00:00', false, 4.5],
        ];

        foreach ($shifts as $shift) {
            shifts::create([
                'shift_code' => $shift[0],
                'shift_description' => $shift[1],
                'start_time' => $shift[2],
                'end_time' => $shift[3],
                'morning_ot_start' => $shift[4],


                'midnight_roster' => $shift[7],

            ]);
        }

        $this->call([
            // EmployeeSeeder::class,
            // AllowanceSeeder::class,
            // DeductionSeeder::class,
            // KpiTasksSeeder::class,
            creator_roles::class,
        ]);

        $employees = employee::all();
    }
}
