<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KpiTasksSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tasks = [
            'Job Knowledge and Skills',
            'Quality of Work',
            'Productivity',
            'Communication Skills',
            'Teamwork and Collaboration',
            'Behavior at work',
            'Problem-Solving and Decision-Making',
            'Attendance and Punctuality',
            'Adaptability and Flexibility',
            'Self-Development',
            'Discipline and conduct at work',
            'Adherence to the given Guidelines',
        ];

        foreach ($tasks as $task) {
            DB::table('kpi_tasks')->insert([
                'task_name' => $task,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
