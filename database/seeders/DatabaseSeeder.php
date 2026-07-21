<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\shifts;
use App\Models\employment_type;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed Users
        try {
            DB::beginTransaction();

            $users = [
                ['name' => 'Dilan Sriyantha', 'email' => 'dilan@mail.com', 'password' => bcrypt('123456'), 'role' => 'admin'],
                ['name' => 'Admin', 'email' => 'admin@mail.com', 'password' => bcrypt('123456'), 'role' => 'admin'],
            ];

            foreach ($users as $userData) {
                // updateOrCreate prevents crashes if you run the seeder twice
                User::updateOrCreate(['email' => $userData['email']], $userData);
            }

            DB::commit();
            $this->command->info('Users seeded successfully.');
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('User Seeding Failed: ' . $e->getMessage());
            $this->command->error('User Seeding Failed: ' . $e->getMessage());
        }

        // 2. Seed Employment Types
        try {
            DB::beginTransaction();

            $types = [
                ['id' => 1, 'name' => 'Permanent'],
                ['id' => 2, 'name' => 'Training'],
                ['id' => 3, 'name' => 'Contract'],
                ['id' => 4, 'name' => 'Daily Wages Salary'],
                ['id' => 5, 'name' => 'Probation'],
            ];

            foreach ($types as $type) {
                employment_type::updateOrCreate(['id' => $type['id']], $type);
            }

            DB::commit();
            $this->command->info('Employment types seeded successfully.');
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Employment Type Seeding Failed: ' . $e->getMessage());
            $this->command->error('Employment Type Seeding Failed!');
        }

        // 3. Seed Shifts
        try {
            DB::beginTransaction();

            $shifts = [
                ['001', 'No OT - WD', '08:00:00', '17:00:00', '08:00:00', '17:00:00', '00:00:00', false, 4.5],
            ];

            foreach ($shifts as $shift) {
                shifts::updateOrCreate(
                    ['shift_code' => $shift[0]],
                    [
                        'shift_description' => $shift[1],
                        'start_time' => $shift[2],
                        'end_time' => $shift[3],
                        'morning_ot_start' => $shift[4],
                        'midnight_roster' => $shift[7],
                    ]
                );
            }

            DB::commit();
            $this->command->info('Shifts seeded successfully.');
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Shifts Seeding Failed: ' . $e->getMessage());
            $this->command->error('Shifts Seeding Failed!');
        }

        // 4. Run External Dependent Seeders Loop
        $dependentSeeders = [
            EmployeeSeeder::class,
            creator_roles::class,
            EmergencyContactRelationshipTypesSeeder::class,
            PayrollTestSeeder::class,
        ];

        foreach ($dependentSeeders as $seeder) {
            try {
                $this->call($seeder);
            } catch (Throwable $e) {
                Log::error("External Seeder Failed [{$seeder}]: " . $e->getMessage());
                $this->command->error("Seeder [{$seeder}] failed! Continuing to next seeder...");
            }
        }
    }
}
