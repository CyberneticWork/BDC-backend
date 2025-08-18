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

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {


        // First, let's create more companies
        company::insert([
            [
                'id' => 1,
                'name' => 'ABC Pvt Ltd'
            ],
            [
                'id' => 2,
                'name' => 'XYZ Pvt Ltd'
            ],
            [
                'id' => 3,
                'name' => 'Tech Solutions Inc'
            ],
            [
                'id' => 4,
                'name' => 'Global Enterprises'
            ],
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

        // // Common data for all employees
        $titles = ['Mr', 'Mrs', 'Ms', 'Dr'];
        $genders = ['male', 'female'];
        $maritalStatuses = ['single', 'married', 'divorced', 'widowed'];
        $employmentTypes = [1, 2, 3, 4];
        $daysOff = ['Sunday', 'Monday', 'Saturday'];
        $designations = [1, 2, 3, 4, 5];

        // // Sri Lankan names for realism
        $firstNamesMale = ['Nimesh', 'Madhawa', 'Kamal', 'Arjun', 'Esala', 'Kavinda', 'Dinitha', 'Kushan', 'Saman', 'Nimal'];
        $firstNamesFemale = ['Hiruni', 'Madara', 'Achini', 'Piumi', 'Rasangi', 'Chinthani', 'Oshini', 'Shashini', 'Nethmi', 'Iresha'];
        $lastNames = ['Bandara', 'Sandaruwan', 'Dananjaya', 'Gunawardane', 'Rupasinghe', 'Wickramasinghe', 'Jayawardena', 'Dissanayake', 'Jaaliya', 'Ranaweera'];

        // // Generate 30 employees
        for ($i = 1; $i <= 20; $i++) {
            $gender = $genders[array_rand($genders)];
            $title = $gender === 'male' ? $titles[0] : (rand(0, 1) ? $titles[1] : $titles[2]);

            $firstName = $gender === 'male' ? $firstNamesMale[array_rand($firstNamesMale)] : $firstNamesFemale[array_rand($firstNamesFemale)];
            $lastName = $lastNames[array_rand($lastNames)];
            $fullName = "$firstName $lastName";
            $nameWithInitials = substr($firstName, 0, 1) . ". $lastName";
            $displayName = strtolower(substr($firstName, 0, 3) . substr($lastName, 0, 3)) . $i;

            $maritalStatus = $maritalStatuses[array_rand($maritalStatuses)];
            $hasSpouse = $maritalStatus === 'married' && rand(0, 1);

            // Create spouse if needed
            $spouseId = null;
            if ($hasSpouse) {
                $spouseGender = $gender === 'male' ? 'wife' : 'husband';
                $spouseTitle = $gender === 'male' ? $titles[1] : $titles[0];
                $spouseFirstName = $gender === 'male' ? $firstNamesFemale[array_rand($firstNamesFemale)] : $firstNamesMale[array_rand($firstNamesMale)];
                $spouseLastName = $lastName; // Assuming same last name
                $spouseFullName = "$spouseFirstName $spouseLastName";

                $spouse = spouse::create([
                    'id' => $i,
                    'type' => $spouseGender,
                    'title' => $spouseTitle,
                    'name' => $spouseFullName,
                    'nic' => '9' . rand(111111111, 999999999) . ($gender === 'male' ? '2' : '1'),
                    'age' => rand(25, 45),
                    'dob' => date('Y-m-d', strtotime('-' . rand(25, 45) . ' years')),
                ]);

                $spouseId = $spouse->id;
            }

            // Random company, department, sub-department assignment
            $companyId = rand(1, 4);
            $department = departments::where('company_id', $companyId)->inRandomOrder()->first();
            $subDepartment = sub_departments::where('department_id', $department->id)->inRandomOrder()->first();

            // Create organization assignment
            $orgAssignment = organization_assignment::create([
                'id' => $i,
                'company_id' => $companyId,
                'current_supervisor' => $i > 5 ? rand(1, 5) : null, // First 5 employees have no supervisor
                'date_of_joining' => date('Y-m-d', strtotime('-' . rand(0, 10) . ' years')),
                'department_id' => $department->id,
                'sub_department_id' => $subDepartment->id,
                'designation_id' => $designations[array_rand($designations)],
                'day_off' => $daysOff[array_rand($daysOff)],
                'confirmation_date' => rand(0, 1) ? date('Y-m-d', strtotime('-' . rand(0, 9) . ' years')) : null,
            ]);

            // Create employee
            $employee = employee::create([
                'title' => $title,
                'attendance_employee_no' => $i,
                'epf' => $i,
                'nic' => ($gender === 'male' ? '8' : '7') . rand(111111111, 999999999) . 'V',
                'dob' => date('Y-m-d', strtotime('-' . rand(25, 50) . ' years')),
                'gender' => $gender,
                'name_with_initials' => $nameWithInitials,
                'full_name' => $fullName,
                'display_name' => $displayName,
                'is_active' => 1,
                'employment_type_id' => $employmentTypes[array_rand($employmentTypes)],
                'organization_assignment_id' => $orgAssignment->id,
                'marital_status' => $maritalStatus,
                'spouse_id' => $spouseId,
            ]);

            // Create contact details
            $mobilePrefixes = ['071', '072', '075', '076', '077', '078'];
            contact_detail::create([
                'employee_id' => $employee->id,
                'permanent_address' => rand(1, 999) . ' Main St, ' . ['Colombo', 'Kandy', 'Galle', 'Matara', 'Negombo', 'Kurunegala'][array_rand([0, 1, 2, 3, 4, 5])],
                'mobile_line' => $mobilePrefixes[array_rand($mobilePrefixes)] . rand(1000000, 9999999),
                'email' => strtolower(str_replace(' ', '.', $fullName)) . '@' . ['abc.com', 'xyz.com', 'techsol.com', 'globalent.com'][$companyId - 1],
                'emg_relationship' => 'Friend',
                'emg_name' => $firstNamesMale[array_rand($firstNamesMale)] . ' ' . $lastNames[array_rand($lastNames)],
                'emg_tel' => $mobilePrefixes[array_rand($mobilePrefixes)] . rand(1000000, 9999999),
                'emg_address' => rand(1, 999) . ' Main St, ' . ['Colombo', 'Kandy', 'Galle', 'Matara', 'Negombo', 'Kurunegala'][array_rand([0, 1, 2, 3, 4, 5])],

            ]);

            // Create children for married employees
            if ($hasSpouse && rand(0, 1)) {
                $numChildren = rand(1, 3);
                $childrenData = [];

                for ($j = 0; $j < $numChildren; $j++) {
                    $childAge = rand(1, 18);
                    $childGender = rand(0, 1) ? 'male' : 'female';
                    $childFirstName = $childGender === 'male' ? $firstNamesMale[array_rand($firstNamesMale)] : $firstNamesFemale[array_rand($firstNamesFemale)];

                    $childrenData[] = [
                        'name' => "$childFirstName $lastName",
                        'nic' => $childAge > 15 ? '2' . rand(111111111, 999999999) . 'V' : null,
                        'age' => $childAge,
                        'dob' => date('Y-m-d', strtotime('-' . $childAge . ' years')),
                        'employee_id' => $employee->id,
                    ];
                }

                children::insert($childrenData);
            }
        }
    }
}
