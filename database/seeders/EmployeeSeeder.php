<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
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
use App\Models\contact_detail;
use App\Models\pay_deductions;
use App\Models\employment_type;
use App\Models\sub_departments;
use App\Models\organization_assignment;
use App\Models\compensation;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create companies
        company::insert([
            ['id' => 1, 'name' => 'ABC Pvt Ltd'],
            ['id' => 2, 'name' => 'XYZ Pvt Ltd'],
            ['id' => 3, 'name' => 'Tech Solutions Inc'],
            ['id' => 4, 'name' => 'Global Enterprises'],
        ]);

        // Departments for all companies
        $departments = [
            ['name' => 'IT', 'company_id' => 1],
            ['name' => 'Human Resources', 'company_id' => 1],
            ['name' => 'Finance', 'company_id' => 2],
            ['name' => 'Marketing', 'company_id' => 2],
            ['name' => 'Operations', 'company_id' => 1],
            ['name' => 'Research & Development', 'company_id' => 3],
            ['name' => 'Customer Support', 'company_id' => 3],
            ['name' => 'Sales', 'company_id' => 4],
            ['name' => 'Quality Assurance', 'company_id' => 4],
            ['name' => 'Production', 'company_id' => 3],
        ];
        foreach ($departments as $dept) {
            departments::create($dept);
        }

        // Sub-departments
        $subDepartments = [
            'IT' => ['Software Development', 'Network Administration', 'Technical Support', 'Database Administration'],
            'Human Resources' => ['Recruitment', 'Employee Relations', 'Training & Development', 'Compensation & Benefits'],
            'Finance' => ['Accounts Payable', 'Accounts Receivable', 'Financial Planning', 'Tax'],
            'Marketing' => ['Digital Marketing', 'Brand Management', 'Market Research', 'Public Relations'],
            'Operations' => ['Logistics', 'Facilities', 'Supply Chain', 'Inventory Management'],
            'Research & Development' => ['Product Research', 'Innovation Lab', 'Prototyping'],
            'Customer Support' => ['Technical Support', 'Complaint Resolution', 'Customer Success'],
            'Sales' => ['Inside Sales', 'Field Sales', 'Account Management'],
            'Quality Assurance' => ['Testing', 'Process Improvement', 'Compliance'],
            'Production' => ['Manufacturing', 'Assembly', 'Packaging']
        ];
        foreach ($subDepartments as $deptName => $subs) {
            $department = departments::where('name', $deptName)->first();
            if ($department) {
                foreach ($subs as $sub) {
                    sub_departments::create([
                        'name' => $sub,
                        'department_id' => $department->id
                    ]);
                }
            }
        }

        // Common data for all employees
        $titles = ['Mr', 'Mrs', 'Ms', 'Dr'];
        $genders = ['male', 'female'];
        $maritalStatuses = ['single', 'married', 'divorced', 'widowed'];
        $employmentTypes = [1, 2, 3, 4];
        $daysOff = ['Sunday', 'Monday', 'Saturday'];
        $designations = [1, 2, 3, 4, 5];
        $firstNamesMale = ['Nimesh', 'Madhawa', 'Kamal', 'Arjun', 'Esala', 'Kavinda', 'Dinitha', 'Kushan', 'Saman', 'Nimal'];
        $firstNamesFemale = ['Hiruni', 'Madara', 'Achini', 'Piumi', 'Rasangi', 'Chinthani', 'Oshini', 'Shashini', 'Nethmi', 'Iresha'];
        $lastNames = ['Bandara', 'Sandaruwan', 'Dananjaya', 'Gunawardane', 'Rupasinghe', 'Wickramasinghe', 'Jayawardena', 'Dissanayake', 'Jaaliya', 'Ranaweera'];
        $mobilePrefixes = ['071', '072', '075', '076', '077', '078'];
        $companyDomains = ['abc.com', 'xyz.com', 'techsol.com', 'globalent.com'];
        $cities = ['Kandy'];
        $provinces = ['Central'];
        $postalCodes = ['20000'];
        $emgRelationships = ['Father', 'Mother', 'Brother', 'Sister', 'Uncle', 'Aunt'];

        for ($i = 1; $i <= 20; $i++) {
            $gender = $genders[array_rand($genders)];
            $title = $gender === 'male' ? $titles[0] : (rand(0, 1) ? $titles[1] : $titles[2]);
            $firstName = $gender === 'male' ? $firstNamesMale[array_rand($firstNamesMale)] : $firstNamesFemale[array_rand($firstNamesFemale)];
            $lastName = $lastNames[array_rand($lastNames)];
            $fullName = "$firstName $lastName";
            $nameWithInitials = substr($firstName, 0, 1) . ". $lastName";
            $displayName = strtolower(substr($firstName, 0, 3) . substr($lastName, 0, 3)) . $i;
            $maritalStatus = $maritalStatuses[array_rand($maritalStatuses)];
            $hasSpouse = $maritalStatus === 'married';
            $dob = date('Y-m-d', strtotime('-' . rand(25, 50) . ' years'));
            $birthYear = date('Y', strtotime($dob));
            $birthMonth = date('m', strtotime($dob));
            $birthDay = date('d', strtotime($dob));
            $serial = rand(1000, 9999);
            $nic = $birthYear . $birthMonth . $birthDay . $serial;

            // Spouse
            $spouseId = null;
            if ($hasSpouse) {
                $spouseGender = $gender === 'male' ? 'wife' : 'husband';
                $spouseTitle = $gender === 'male' ? $titles[1] : $titles[0];
                $spouseFirstName = $gender === 'male' ? $firstNamesFemale[array_rand($firstNamesFemale)] : $firstNamesMale[array_rand($firstNamesMale)];
                $spouseLastName = $lastName;
                $spouseFullName = "$spouseFirstName $spouseLastName";
                $spouseDob = date('Y-m-d', strtotime('-' . rand(25, 45) . ' years'));
                $spouseAge = 2025 - date('Y', strtotime($spouseDob));
                $spouseBirthYear = date('Y', strtotime($spouseDob));
                $spouseBirthMonth = date('m', strtotime($spouseDob));
                $spouseBirthDay = date('d', strtotime($spouseDob));
                $spouseSerial = rand(1000, 9999);
                $spouseNic = $spouseBirthYear . $spouseBirthMonth . $spouseBirthDay . $spouseSerial;
                $spouse = spouse::create([
                    'type' => $spouseGender,
                    'title' => $spouseTitle,
                    'name' => $spouseFullName,
                    'nic' => $spouseNic,
                    'age' => $spouseAge,
                    'dob' => $spouseDob,
                ]);
                $spouseId = $spouse->id;
            }

            // Company, department, sub-department
            $companyId = rand(1, 4);
            $department = departments::where('company_id', $companyId)->inRandomOrder()->first();
            $subDepartment = sub_departments::where('department_id', $department->id)->inRandomOrder()->first();

            // Organization assignment
            $orgAssignment = organization_assignment::create([
                'company_id' => $companyId,
                'current_supervisor' => $i > 5 ? rand(1, 5) : null,
                'date_of_joining' => date('Y-m-d', strtotime('-' . rand(0, 10) . ' years')),
                'department_id' => $department->id,
                'sub_department_id' => $subDepartment->id,
                'designation_id' => $designations[array_rand($designations)],
                'day_off' => $daysOff[array_rand($daysOff)],
                'confirmation_date' => rand(0, 1) ? date('Y-m-d', strtotime('-' . rand(0, 9) . ' years')) : null,
                'is_active' => 1,
            ]);

            // Employee
            $employee = employee::create([
                'title' => $title,
                'attendance_employee_no' => 'EMP00' . $i,
                'epf' => '00' . $i,
                'nic' => $nic,
                'dob' => $dob,
                'gender' => $gender,
                'name_with_initials' => $nameWithInitials,
                'full_name' => $fullName,
                'display_name' => $displayName,
                'is_active' => 1,
                'employment_type_id' => $employmentTypes[array_rand($employmentTypes)],
                'organization_assignment_id' => $orgAssignment->id,
                'marital_status' => $maritalStatus,
                'spouse_id' => $spouseId,
                'profile_photo_path' => null,
            ]);

            // User (login)
            $email = strtolower(str_replace(' ', '.', $fullName . $i)) . '@' . $companyDomains[$companyId - 1];
            $password = bcrypt('password123');
            User::create([
                'name' => $fullName,
                'email' => $email,
                'password' => $password,
                'role' => 'employee',
            ]);

            // Contact details
            $city = 'Kandy';
            $province = 'Central';
            $postalCode = '20000';
            $emgCity = 'Kandy';
            $emgProvince = 'Central';
            $emgPostalCode = '20000';
            contact_detail::create([
                'employee_id' => $employee->id,
                'permanent_address' => 'No. ' . rand(1, 999) . ', Main Street, ' . $city . ' ' . $postalCode . ', ' . $province . ' Province',
                'mobile_line' => $mobilePrefixes[array_rand($mobilePrefixes)] . rand(1000000, 9999999),
                'email' => $email,
                'emg_relationship' => $emgRelationships[array_rand($emgRelationships)],
                'emg_name' => $firstNamesMale[array_rand($firstNamesMale)] . ' ' . $lastNames[array_rand($lastNames)],
                'emg_tel' => $mobilePrefixes[array_rand($mobilePrefixes)] . rand(1000000, 9999999),
                'emg_address' => 'No. ' . rand(1, 999) . ', Main Street, ' . $emgCity . ' ' . $emgPostalCode . ', ' . $emgProvince . ' Province',
            ]);

            // Compensation
            $baseSalary = rand(40000, 250000);
            $yearsOfService = rand(0, 10);
            if ($yearsOfService > 0) {
                $incrementPercentage = min(50, $yearsOfService * 5);
                $baseSalary = $baseSalary * (1 + ($incrementPercentage / 100));
            }
            $baseSalary = round($baseSalary / 1000) * 1000;
            $isEligibleForIncrement = rand(0, 1) && $yearsOfService > 1;
            compensation::create([
                'employee_id' => $employee->id,
                'basic_salary' => $baseSalary,
                'increment_value' => $isEligibleForIncrement ? rand(5, 15) : null,
                'increment_effected_date' => $isEligibleForIncrement ? date('Y-m-d', strtotime('-' . rand(1, 11) . ' months')) : null,
                'enable_epf_etf' => rand(0, 1),
                'ot_active' => 1,
                'early_deduction' => rand(0, 1),
                'increment_active' => $isEligibleForIncrement,
                'active_nopay' => rand(0, 1) ? true : false,
                'ot_morning' => 1,
                'ot_evening' => 1,
                'ot_morning_rate' => 100,
                'ot_night_rate' => 200,
                'bank_name' => "Peoples Bank",
                'branch_name' => "Katu",
                'bank_code' => 318,
                'branch_code' => 1234,
                'bank_account_no' => '10' . rand(100000000, 999999999),
                'br1' => rand(0, 1),
                'br2' => rand(0, 1),
                'comments' => rand(0, 1) ? 'Regular employee with standard benefits' : null,
                'secondary_emp' => rand(0, 1) ? true : false,
                'primary_emp_basic' => rand(0, 1) ? true : false,
                'stamp' => rand(0, 1),
            ]);

            // Children
            if ($hasSpouse) {
                $numChildren = rand(1, 3);
                for ($j = 0; $j < $numChildren; $j++) {
                    $childAge = rand(1, 18);
                    $childGender = rand(0, 1) ? 'male' : 'female';
                    $childFirstName = $childGender === 'male' ? $firstNamesMale[array_rand($firstNamesMale)] : $firstNamesFemale[array_rand($firstNamesFemale)];
                    $childDob = date('Y-m-d', strtotime('-' . $childAge . ' years'));
                    $childNic = null;
                    if ($childAge > 15) {
                        $childBirthYear = date('Y', strtotime($childDob));
                        $childBirthMonth = date('m', strtotime($childDob));
                        $childBirthDay = date('d', strtotime($childDob));
                        $childSerial = rand(1000, 9999);
                        $childNic = $childBirthYear . $childBirthMonth . $childBirthDay . $childSerial;
                    }
                    children::create([
                        'name' => "$childFirstName $lastName",
                        'nic' => $childNic,
                        'age' => $childAge,
                        'dob' => $childDob,
                        'employee_id' => $employee->id,
                    ]);
                }
            }
        }
    }
}
