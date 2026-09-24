<?php

namespace App\Services;

use App\Models\children;
use App\Models\company;
use App\Models\compensation;
use App\Models\contact_detail;
use App\Models\departments;
use App\Models\designation;
use App\Models\employee;
use App\Models\employment_type;
use App\Models\organization_assignment;
use App\Models\spouse;
use App\Models\sub_departments;
use App\Services\EmployeeUserLinker;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class EmployeeExcelImportService
{
    /** Excel row numbers for each field (column B = label, C+ = employee data). */
    private const FIELD_ROWS = [
        'title' => 8,
        'attendance_no' => 9,
        'epf' => 10,
        'nic' => 11,
        'dob' => 12,
        'gender' => 13,
        'religion' => 14,
        'country_of_birth' => 15,
        'employment_status' => 19,
        'name_with_initials' => 28,
        'full_name' => 29,
        'display_name' => 30,
        'marital_status' => 31,
        'spouse_relationship' => 35,
        'spouse_name' => 36,
        'spouse_title' => 37,
        'spouse_dob' => 38,
        'spouse_nic' => 39,
        'child1_name' => 43,
        'child1_dob' => 44,
        'child1_nic' => 45,
        'child2_name' => 47,
        'child2_dob' => 48,
        'child2_nic' => 49,
        'permanent_address' => 53,
        'temporary_address' => 54,
        'email' => 55,
        'mobile' => 56,
        'land_line' => 57,
        'province' => 61,
        'electoral_division' => 62,
        'gn_division' => 63,
        'police_station' => 64,
        'district' => 65,
        'emergency_relationship' => 69,
        'emergency_name' => 70,
        'emergency_address' => 71,
        'emergency_tel' => 72,
        'basic_salary' => 76,
        'monthly_bonus' => 77,
        'sports_fund_percentage' => 79,
        'staff_fund_amount' => 81,
        'account_holder_name' => 85,
        'bank_name' => 86,
        'branch_name' => 87,
        'bank_account_no' => 88,
        'company_name' => 92,
        'company_code' => 93,
        'department' => 94,
        'sub_department' => 95,
        'supervisor' => 96,
        'date_joined' => 97,
        'designation' => 98,
        'employee_category' => 99,
    ];

    private array $rows = [];
    private array $employeeColumns = [];
    private ?array $rowFields = null;

    public function parse(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("Excel file not found: {$filePath}");
        }

        $workbook = IOFactory::load($filePath);
        $sheet = $workbook->getSheetByName('Employee Master')
            ?? $workbook->getSheet(0);

        $this->rows = $sheet->toArray(null, true, true, true);

        if ($this->isRowFormat()) {
            return $this->parseRowEmployees();
        }

        $this->employeeColumns = $this->detectEmployeeColumns();

        $employees = [];
        foreach ($this->employeeColumns as $column => $headerName) {
            $employees[] = $this->buildEmployeePayload($column, $headerName);
        }

        return $employees;
    }

    public function import(string $filePath, bool $dryRun = false): array
    {
        $employees = $this->parse($filePath);
        $summary = [
            'total' => count($employees),
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($employees as $payload) {
            $label = $payload['meta']['display_name'] ?: $payload['meta']['column'];

            try {
                if ($this->employeeExists($payload)) {
                    $summary['skipped']++;
                    $summary['details'][] = [
                        'status' => 'skipped',
                        'name' => $label,
                        'reason' => 'Employee already exists (NIC or Attendance No)',
                    ];
                    continue;
                }

                if ($dryRun) {
                    $summary['created']++;
                    $summary['details'][] = [
                        'status' => 'dry-run',
                        'name' => $label,
                        'reason' => 'Would create employee',
                    ];
                    continue;
                }

                DB::transaction(function () use ($payload) {
                    $this->createEmployeeRecord($payload);
                });

                $summary['created']++;
                $summary['details'][] = [
                    'status' => 'created',
                    'name' => $label,
                    'reason' => 'Imported successfully',
                ];
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['details'][] = [
                    'status' => 'failed',
                    'name' => $label,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $summary;
    }

    private function detectEmployeeColumns(): array
    {
        $headerRow = $this->rows[3] ?? [];
        $columns = [];

        foreach ($headerRow as $column => $value) {
            if (in_array($column, ['A', 'B'], true)) {
                continue;
            }
            $name = trim((string) $value);
            if ($name !== '') {
                $columns[$column] = $name;
            }
        }

        if ($columns === []) {
            throw new \RuntimeException('No employee columns found on row 3 of the Excel sheet.');
        }

        return $columns;
    }

    private function buildEmployeePayload(string $column, string $headerName): array
    {
        $attendanceNo = $this->cell($column, 'attendance_no');
        $displayName = $this->cell($column, 'display_name') ?: $headerName;
        $fullName = $this->cell($column, 'full_name') ?: $displayName;
        $nic = $this->normalizeNic($this->cell($column, 'nic'));
        $epf = $this->cell($column, 'epf') ?: ('NO-EPF-' . $attendanceNo);
        $email = $this->cell($column, 'email') ?: $this->generateImportEmail($attendanceNo, $displayName);
        $employmentStatus = $this->cell($column, 'employment_status') ?: 'Permanent';
        $designationName = $this->cell($column, 'designation') ?: 'Trainee';
        $companyCode = strtoupper($this->cell($column, 'company_code') ?: 'UNASSIGNED');
        $companyName = $this->cell($column, 'company_name') ?: $companyCode;
        $basicSalary = $this->parseMoney($this->cell($column, 'basic_salary'));
        $monthlyBonus = $this->parseMoney($this->cell($column, 'monthly_bonus'));
        $sportsFundPct = $this->parsePercentage($this->cell($column, 'sports_fund_percentage'));
        $staffFundAmount = $this->parseMoney($this->cell($column, 'staff_fund_amount'));

        if ($basicSalary <= 0) {
            throw new \RuntimeException("Basic salary is missing for {$displayName}");
        }

        if ($nic === '') {
            throw new \RuntimeException("NIC is missing for {$displayName}");
        }

        if ($attendanceNo === '') {
            throw new \RuntimeException("Attendance employee number is missing for {$displayName}");
        }

        $children = [];
        foreach ([1, 2] as $index) {
            $childName = $this->cell($column, "child{$index}_name");
            if ($childName === '') {
                continue;
            }
            $childDob = $this->parseDate($this->cell($column, "child{$index}_dob"));
            $children[] = [
                'name' => $childName,
                'dob' => $childDob,
                'age' => $childDob ? (int) floor(Carbon::parse($childDob)->diffInYears(Carbon::now())) : null,
                'nic' => $this->cell($column, "child{$index}_nic") ?: null,
            ];
        }

        $spouseName = $this->cell($column, 'spouse_name');
        $spouse = null;
        if ($spouseName !== '') {
            $spouseDob = $this->parseDate($this->cell($column, 'spouse_dob'));
            $spouse = [
                'type' => $this->cell($column, 'spouse_relationship') ?: 'Spouse',
                'title' => $this->cell($column, 'spouse_title') ?: null,
                'name' => $spouseName,
                'nic' => $this->normalizeNic($this->cell($column, 'spouse_nic')) ?: null,
                'dob' => $spouseDob,
                'age' => $spouseDob ? (int) floor(Carbon::parse($spouseDob)->diffInYears(Carbon::now())) : null,
            ];
        }

        return [
            'meta' => [
                'column' => $column,
                'display_name' => $displayName,
            ],
            'personal' => [
                'title' => $this->normalizeTitle($this->cell($column, 'title')),
                'attendanceEmpNo' => $attendanceNo,
                'epfNo' => $epf,
                'nicNumber' => $nic,
                'dob' => $this->parseDate($this->cell($column, 'dob')),
                'gender' => $this->normalizeGender($this->cell($column, 'gender')),
                'religion' => trim($this->cell($column, 'religion')) ?: null,
                'countryOfBirth' => $this->cell($column, 'country_of_birth') ?: 'Sri Lanka',
                'employmentStatus' => $this->resolveEmploymentTypeId($employmentStatus),
                'nameWithInitial' => $this->cell($column, 'name_with_initials') ?: $fullName,
                'fullName' => $fullName,
                'displayName' => $displayName,
                'maritalStatus' => $this->normalizeMaritalStatus($this->cell($column, 'marital_status')),
                'children' => $children,
                'spouse' => $spouse,
            ],
            'address' => [
                'permanentAddress' => $this->cell($column, 'permanent_address'),
                'temporaryAddress' => $this->cell($column, 'temporary_address') ?: null,
                'email' => strtolower($email),
                'landLine' => $this->cell($column, 'land_line') ?: null,
                'mobileLine' => $this->cell($column, 'mobile') ?: '0000000000',
                'gnDivision' => $this->cell($column, 'gn_division') ?: null,
                'policeStation' => $this->cell($column, 'police_station') ?: null,
                'district' => $this->cell($column, 'district') ?: 'Colombo District',
                'province' => $this->cell($column, 'province') ?: 'Western Province',
                'electoralDivision' => $this->cell($column, 'electoral_division') ?: null,
                'emergencyContact' => [
                    'relationship' => $this->cell($column, 'emergency_relationship') ?: 'Other',
                    'contactName' => $this->cell($column, 'emergency_name') ?: $fullName,
                    'contactAddress' => $this->cell($column, 'emergency_address') ?: null,
                    'contactTel' => $this->cell($column, 'emergency_tel') ?: $this->cell($column, 'mobile') ?: '0000000000',
                ],
            ],
            'compensation' => [
                'basicSalary' => $basicSalary,
                'monthlyBonus' => $monthlyBonus,
                'sportsFundPercentage' => $sportsFundPct,
                'staffFundAmount' => $staffFundAmount,
                'bankName' => $this->cell($column, 'bank_name') ?: null,
                'branchName' => $this->cell($column, 'branch_name') ?: null,
                'bankAccountNo' => $this->cell($column, 'bank_account_no') ?: null,
                'accountHolderName' => $this->cell($column, 'account_holder_name') ?: null,
                'secondaryEmp' => false,
                'primaryEmploymentBasic' => false,
                'enableEpfEtf' => $this->parseYesNo($this->cell($column, 'enable_epf_etf'), true),
                'otActive' => $this->parseYesNo($this->cell($column, 'ot_active'), false),
                'earlyDeduction' => false,
                'incrementActive' => false,
                'nopayActive' => $this->parseYesNo($this->cell($column, 'nopay_active'), true),
                'morningOt' => false,
                'eveningOt' => false,
                'ot_morning_rate' => 0,
                'ot_night_rate' => 0,
                'budgetaryReliefAllowance2015' => false,
                'budgetaryReliefAllowance2016' => false,
                'stamp' => false,
            ],
            'organization' => [
                'companyCode' => $companyCode,
                'companyName' => $companyName,
                'departmentName' => $this->cell($column, 'department') ?: null,
                'subDepartmentName' => $this->cell($column, 'sub_department') ?: null,
                'designationName' => $designationName,
                'currentSupervisor' => $this->cell($column, 'supervisor') ?: null,
                'dateOfJoined' => $this->parseDate($this->cell($column, 'date_joined')) ?: now()->toDateString(),
                'employeeCategory' => $this->normalizeEmployeeCategory($this->cell($column, 'employee_category')),
                'employmentStatusLabel' => $employmentStatus,
                'dayOff' => $this->cell($column, 'day_off') ?: 'Sunday',
            ],
        ];
    }

    private function createEmployeeRecord(array $payload): void
    {
        $personal = $payload['personal'];
        $address = $payload['address'];
        $comp = $payload['compensation'];
        $org = $payload['organization'];

        $company = $this->resolveCompany($org['companyCode'], $org['companyName']);
        $department = $this->resolveDepartment($company->id, $org['departmentName']);
        $subDepartment = $department
            ? $this->resolveSubDepartment($department->id, $org['subDepartmentName'])
            : null;
        $designation = $this->resolveDesignation($org['designationName']);

        $spouseRecord = null;
        if (!empty($personal['spouse'])) {
            $spouseRecord = spouse::create($personal['spouse']);
        }

        $isProbation = strtolower($org['employmentStatusLabel']) === 'probation';

        $orgAssignment = organization_assignment::create([
            'company_id' => $company->id,
            'department_id' => $department?->id,
            'sub_department_id' => $subDepartment?->id,
            'designation_id' => $designation->id,
            'current_supervisor' => $org['currentSupervisor'],
            'date_of_joining' => $org['dateOfJoined'],
            'day_off' => $org['dayOff'],
            'probationary_period' => $isProbation,
            'training_period' => strtolower($org['employmentStatusLabel']) === 'training',
            'contract_period' => strtolower($org['employmentStatusLabel']) === 'contract',
            'is_active' => true,
        ]);

        $employee = employee::create([
            'title' => $personal['title'],
            'attendance_employee_no' => $personal['attendanceEmpNo'],
            'epf' => $personal['epfNo'],
            'nic' => $personal['nicNumber'],
            'dob' => $personal['dob'],
            'gender' => strtolower($personal['gender']),
            'religion' => $personal['religion'],
            'country_of_birth' => $personal['countryOfBirth'],
            'name_with_initials' => $personal['nameWithInitial'],
            'full_name' => $personal['fullName'],
            'display_name' => $personal['displayName'],
            'marital_status' => strtolower($personal['maritalStatus']),
            'is_active' => true,
            'employment_type_id' => $personal['employmentStatus'],
            'organization_assignment_id' => $orgAssignment->id,
            'spouse_id' => $spouseRecord?->id,
            'email' => $address['email'],
        ]);

        app(EmployeeUserLinker::class)->ensureLogin(
            $employee,
            (string) ($address['email'] ?? ''),
            (string) ($personal['nicNumber'] ?? ''),
            (string) ($personal['fullName'] ?? ''),
        );

        foreach ($personal['children'] as $child) {
            if (empty($child['name']) || empty($child['dob']) || $child['age'] === null) {
                continue;
            }
            children::create([
                'employee_id' => $employee->id,
                'name' => $child['name'],
                'age' => $child['age'],
                'dob' => $child['dob'],
                'nic' => $child['nic'],
            ]);
        }

        contact_detail::create([
            'employee_id' => $employee->id,
            'permanent_address' => $address['permanentAddress'],
            'temporary_address' => $address['temporaryAddress'],
            'email' => $address['email'],
            'land_line' => $address['landLine'],
            'mobile_line' => $address['mobileLine'],
            'gn_division' => $address['gnDivision'],
            'police_station' => $address['policeStation'],
            'district' => $address['district'],
            'province' => $address['province'],
            'electoral_division' => $address['electoralDivision'],
            'emg_relationship' => $address['emergencyContact']['relationship'],
            'emg_name' => $address['emergencyContact']['contactName'],
            'emg_address' => $address['emergencyContact']['contactAddress'],
            'emg_tel' => $address['emergencyContact']['contactTel'],
        ]);

        compensation::create([
            'employee_id' => $employee->id,
            'employee_category' => $org['employeeCategory'],
            'basic_salary' => $comp['basicSalary'],
            'monthly_bonus' => $comp['monthlyBonus'],
            'sports_fund_percentage' => $comp['sportsFundPercentage'],
            'staff_fund_amount' => $comp['staffFundAmount'],
            'bank_name' => $comp['bankName'],
            'branch_name' => $comp['branchName'],
            'bank_account_no' => $comp['bankAccountNo'],
            'account_holder_name' => $comp['accountHolderName'],
            'secondary_emp' => $comp['secondaryEmp'],
            'primary_emp_basic' => $comp['primaryEmploymentBasic'],
            'enable_epf_etf' => $comp['enableEpfEtf'],
            'ot_active' => $comp['otActive'],
            'early_deduction' => $comp['earlyDeduction'],
            'increment_active' => $comp['incrementActive'],
            'active_nopay' => $comp['nopayActive'],
            'ot_morning' => $comp['morningOt'],
            'ot_evening' => $comp['eveningOt'],
            'ot_morning_rate' => $comp['ot_morning_rate'],
            'ot_night_rate' => $comp['ot_night_rate'],
            'br1' => $comp['budgetaryReliefAllowance2015'],
            'br2' => $comp['budgetaryReliefAllowance2016'],
            'stamp' => $comp['stamp'],
        ]);
    }

    private function employeeExists(array $payload): bool
    {
        $nic = $payload['personal']['nicNumber'];
        $attendanceNo = $payload['personal']['attendanceEmpNo'];

        return employee::query()
            ->where(function ($q) use ($nic, $attendanceNo) {
                $q->where('nic', $nic)->orWhere('attendance_employee_no', $attendanceNo);
            })
            ->exists();
    }

    private function resolveCompany(string $companyCode, string $companyName): company
    {
        $code = strtoupper(trim($companyCode));
        $name = trim($companyName);
        $existing = null;
        if ($code !== '' && $code !== 'YOURCODE') {
            $existing = company::query()->where('company_code', $code)->first();
        }
        if (!$existing && $name !== '' && $name !== 'YOUR COMPANY NAME') {
            $existing = company::query()->where('name', $name)->first();
        }

        if ($existing) {
            return $existing;
        }

        throw new \RuntimeException(
            "Unknown company '{$companyName}' / '{$companyCode}'. Create the company in Cybernetic Admin first, then use that exact name or code."
        );
    }

    private function resolveDepartment(int $companyId, ?string $name): ?departments
    {
        if (!$name) {
            return null;
        }

        return departments::firstOrCreate(
            ['company_id' => $companyId, 'name' => $name],
            ['company_id' => $companyId, 'name' => $name]
        );
    }

    private function resolveSubDepartment(int $departmentId, ?string $name): ?sub_departments
    {
        if (!$name) {
            return null;
        }

        return sub_departments::firstOrCreate(
            ['department_id' => $departmentId, 'name' => $name],
            ['department_id' => $departmentId, 'name' => $name]
        );
    }

    private function resolveDesignation(string $name): designation
    {
        return designation::firstOrCreate(
            ['name' => $name],
            ['description' => 'Imported from Excel']
        );
    }

    private function resolveEmploymentTypeId(string $label): int
    {
        $normalized = strtolower(trim($label));
        $map = [
            'permanent' => 'Permanent',
            'training' => 'Training',
            'contract' => 'Contract',
            'probation' => 'Probation',
            'daily wages' => 'Daily Wages Salary',
            'daily wages salary' => 'Daily Wages Salary',
        ];

        $name = $map[$normalized] ?? $label;
        $type = employment_type::where('name', $name)->first();

        if (!$type) {
            throw new \RuntimeException("Unknown employment status: {$label}");
        }

        return (int) $type->id;
    }

    private function isRowFormat(): bool
    {
        $header = $this->rows[1] ?? [];
        $joined = strtolower(implode(' ', array_map(fn ($v) => (string) $v, $header)));

        return str_contains($joined, 'attendance_no')
            || str_contains($joined, 'attendance no')
            || (str_contains($joined, 'nic') && str_contains($joined, 'full_name'));
    }

    private function parseRowEmployees(): array
    {
        $headerRow = $this->rows[1] ?? [];
        $indexToKey = [];
        foreach ($headerRow as $col => $label) {
            $key = $this->normalizeHeader((string) $label);
            if ($key !== '') {
                $indexToKey[$col] = $key;
            }
        }
        if ($indexToKey === []) {
            throw new \RuntimeException('Employee Master sheet has no recognised header row.');
        }

        $employees = [];
        foreach ($this->rows as $rowNum => $row) {
            if ((int) $rowNum <= 2) {
                continue;
            }
            $this->rowFields = [];
            foreach ($indexToKey as $col => $key) {
                $this->rowFields[$key] = trim((string) ($row[$col] ?? ''));
            }
            $attendance = $this->rowFields['attendance_no'] ?? '';
            $nic = $this->rowFields['nic'] ?? '';
            $name = $this->rowFields['full_name'] ?? $this->rowFields['display_name'] ?? '';
            $companyCode = strtoupper($this->rowFields['company_code'] ?? '');
            if ($attendance === '' && $nic === '' && $name === '') {
                continue;
            }
            if ($companyCode === 'YOURCODE' || ($this->rowFields['company_name'] ?? '') === 'YOUR COMPANY NAME') {
                continue;
            }
            $employees[] = $this->buildEmployeePayload('ROW', $name ?: $attendance);
        }
        $this->rowFields = null;

        if ($employees === []) {
            throw new \RuntimeException('No employee rows found. Fill data from row 3 downward.');
        }

        return $employees;
    }

    private function normalizeHeader(string $label): string
    {
        $label = strtolower(trim($label));
        $label = preg_replace('/\s*\*.*$/', '', $label);
        $label = preg_replace('/\([^)]*\)/', '', $label);
        $label = trim((string) preg_replace('/[^a-z0-9]+/', '_', $label), '_');
        $aliases = [
            'attendance_employee_no' => 'attendance_no',
            'emp_no' => 'attendance_no',
            'employee_no' => 'attendance_no',
            'epf_no' => 'epf',
            'nic_no' => 'nic',
            'date_of_birth' => 'dob',
            'name_with_initial' => 'name_with_initials',
            'mobile_no' => 'mobile',
            'mobile_line' => 'mobile',
            'landline' => 'land_line',
            'company' => 'company_name',
            'joined_date' => 'date_joined',
            'date_of_joined' => 'date_joined',
            'enable_epf_etf_yes_no' => 'enable_epf_etf',
            'ot_active_yes_no' => 'ot_active',
            'nopay_active_yes_no' => 'nopay_active',
        ];

        return $aliases[$label] ?? $label;
    }

    private function parseYesNo(string $value, bool $default): bool
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'yes', 'y', 'true', 'on'], true);
    }

    private function cell(string $column, string $field): string
    {
        if ($this->rowFields !== null) {
            return trim((string) ($this->rowFields[$field] ?? ''));
        }

        $row = self::FIELD_ROWS[$field] ?? null;
        if (!$row) {
            return '';
        }

        return trim((string) ($this->rows[$row][$column] ?? ''));
    }

    private function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value > 20000) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        $value = str_replace('\\', '', $value);

        foreach (['Y/m/d', 'Y-m-d', 'd/m/Y', 'm/d/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        return Carbon::parse($value)->format('Y-m-d');
    }

    private function parseMoney(?string $value): float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0.0;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $value));

        return (float) ($clean ?: 0);
    }

    private function parsePercentage(?string $value): float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0.0;
        }

        return (float) str_replace(['%', ' '], '', $value);
    }

    private function normalizeNic(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[0-9]{9}[vVxX]$/', $value)) {
            return strtoupper(substr($value, 0, 9)) . 'V';
        }

        return preg_replace('/\s+/', '', $value);
    }

    private function normalizeTitle(string $value): string
    {
        $value = rtrim(trim($value), '.');
        return match (strtolower($value)) {
            'mr' => 'Mr',
            'mrs' => 'Mrs',
            'ms' => 'Ms',
            'dr' => 'Dr',
            default => $value !== '' ? $value : 'Mr',
        };
    }

    private function normalizeGender(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            'female', 'f' => 'Female',
            'male', 'm' => 'Male',
            default => 'Other',
        };
    }

    private function normalizeMaritalStatus(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            'single' => 'Single',
            'married' => 'Married',
            'divorced' => 'Divorced',
            'widowed' => 'Widowed',
            default => 'Single',
        };
    }

    private function normalizeEmployeeCategory(string $value): string
    {
        $value = strtolower(trim($value));
        return str_contains($value, 'executive') && !str_contains($value, 'non')
            ? 'Executive'
            : 'Non-Executive';
    }

    private function generateImportEmail(string $attendanceNo, string $displayName): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $displayName));
        $slug = trim($slug, '.');

        return "{$attendanceNo}.{$slug}@import.local";
    }
}
