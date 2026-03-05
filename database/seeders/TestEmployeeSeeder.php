<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\employee;
use App\Models\contact_detail;
use App\Models\organization_assignment;
use Illuminate\Support\Facades\Hash;

class TestEmployeeSeeder extends Seeder
{
    public function run()
    {
        // Create organization assignment first
        $orgAssignment = organization_assignment::create([
            'company_id' => 1,
            'department_id' => 1,
            'designation_id' => 1,
            'date_of_joining' => '2020-01-15',
        ]);

        // Create test employee record
        $employee = employee::create([
            'attendance_employee_no' => 'EMP999',
            'title' => 'Mr',
            'name_with_initials' => 'K.S. Perera',
            'full_name' => 'Kamal Sunil Perera',
            'display_name' => 'Kamal Perera',
            'gender' => 'male',
            'dob' => '1990-05-15',
            'nic' => '199012345678',
            'epf' => 'EPF999',
            'marital_status' => 'married',
            'religion' => 'Buddhist',
            'country_of_birth' => 'Sri Lanka',
            'employment_type_id' => 1,
            'organization_assignment_id' => $orgAssignment->id,
            'is_active' => 1,
        ]);

        // Create contact details
        contact_detail::create([
            'employee_id' => $employee->id,
            'email' => 'kamal.perera@company.com',
            'mobile_line' => '0771234567',
            'land_line' => '0112345678',
            'permanent_address' => 'No. 123, Galle Road, Colombo 03',
            'temporary_address' => 'No. 123, Galle Road, Colombo 03',
            'province' => 'Western',
            'district' => 'Colombo',
            'emg_name' => 'Nimal Perera',
            'emg_relationship' => 'Brother',
            'emg_tel' => '0779876543',
        ]);

        // Create user account for employee
        $user = User::create([
            'name' => 'Kamal Perera',
            'email' => 'kamal.perera@company.com',
            'password' => Hash::make('password123'),
            'employee_id' => $employee->id,
            'role' => 'employee',
        ]);

        $this->command->info('Test employee created successfully!');
        $this->command->info('Email: kamal.perera@company.com');
        $this->command->info('Password: password123');
        $this->command->info('Employee ID: ' . $employee->id);
    }
}
