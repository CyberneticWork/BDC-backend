<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\shifts;
use App\Models\employment_type;
use Illuminate\Database\Seeder;

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
                'role' => 'admin',
            ],
            [
                'name' => 'Admin',
                'email' => 'admin@mail.com',
                'password' => '123456',
                'role' => 'admin',
            ],
        ];

        foreach ($users as $userData) {
            User::create($userData);
        }

        employment_type::insert([
            ['id' => 1, 'name' => 'Permanent'],
            ['id' => 2, 'name' => 'Training'],
            ['id' => 3, 'name' => 'Contract'],
            ['id' => 4, 'name' => 'Daily Wages Salary'],
            ['id' => 5, 'name' => 'Probation'],
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
            EmployeeSeeder::class,
            creator_roles::class,
            PayrollTestSeeder::class,
        ]);
    }
}
