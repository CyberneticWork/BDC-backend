<?php

namespace App\Services;

use App\Models\company;
use App\Models\compensation;
use App\Models\contact_detail;
use App\Models\designation;
use App\Models\employee;
use App\Models\employment_type;
use App\Models\organization_assignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class JaySeafoodExcelImportService
{
    public function import(string $filePath, bool $dryRun = false): array
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("Excel file not found: {$filePath}");
        }

        $sheet = IOFactory::load($filePath)->getSheet(0);
        $rows = $this->parseRows($sheet);

        $summary = [
            'total' => count($rows),
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        $seenNic = [];
        $seenAttendance = [];
        $usedMobiles = [];

        $company = null;
        $designation = null;
        $employmentTypeId = null;
        if (!$dryRun) {
            $company = $this->resolveCompany();
            $designation = designation::firstOrCreate(
                ['name' => 'Unassigned'],
                ['description' => 'Placeholder until client fills designation']
            );
            $employmentTypeId = (int) (employment_type::where('name', 'Permanent')->value('id')
                ?: employment_type::query()->value('id'));
            if (!$employmentTypeId) {
                throw new \RuntimeException('No employment type exists in the jay database.');
            }
        }

        foreach ($rows as $row) {
            $label = $row['display_name'] ?: ('Emp ' . $row['attendance_employee_no']);

            if ($row['skip_reason']) {
                $summary['skipped']++;
                $summary['details'][] = ['status' => 'skipped', 'name' => $label, 'reason' => $row['skip_reason']];
                continue;
            }

            $nicKey = strtoupper($row['nic']);
            if (isset($seenNic[$nicKey])) {
                $summary['skipped']++;
                $summary['details'][] = [
                    'status' => 'skipped',
                    'name' => $label,
                    'reason' => 'Duplicate NIC in Excel (kept first occurrence ' . $seenNic[$nicKey] . ')',
                ];
                continue;
            }
            if (isset($seenAttendance[$row['attendance_employee_no']])) {
                $summary['skipped']++;
                $summary['details'][] = [
                    'status' => 'skipped',
                    'name' => $label,
                    'reason' => 'Duplicate attendance number in Excel',
                ];
                continue;
            }

            $seenNic[$nicKey] = $row['attendance_employee_no'];
            $seenAttendance[$row['attendance_employee_no']] = true;

            $mobile = $row['mobile_line'];
            if ($mobile === '' || isset($usedMobiles[$mobile])) {
                $mobile = $this->uniqueMobile($row['attendance_employee_no'], $usedMobiles);
            }
            $usedMobiles[$mobile] = true;
            $row['mobile_line'] = $mobile;

            try {
                if ($dryRun) {
                    $summary['created']++;
                    $summary['details'][] = [
                        'status' => 'dry-run',
                        'name' => $label,
                        'reason' => 'Would create ' . $row['full_name'] . ' / NIC ' . $row['nic'],
                    ];
                    continue;
                }

                DB::transaction(function () use ($row, $company, $designation, $employmentTypeId) {
                    $this->createEmployee($row, $company, $designation, $employmentTypeId);
                });

                $summary['created']++;
                $summary['details'][] = [
                    'status' => 'created',
                    'name' => $label,
                    'reason' => $row['full_name'] . ' (' . $row['attendance_employee_no'] . ')',
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

    public function parseRows(Worksheet $sheet): array
    {
        $highest = (int) $sheet->getHighestRow();
        $rows = [];
        for ($r = 2; $r <= $highest; $r++) {
            $rows[] = $this->mapRow($sheet, $r);
        }

        return array_values(array_filter($rows, fn ($row) => $row !== null));
    }

    private function mapRow(Worksheet $sheet, int $r): ?array
    {
        $empNo = $this->cell($sheet, 'A', $r);
        $attendance = $this->cell($sheet, 'B', $r) ?: $empNo;
        $nicRaw = $this->cell($sheet, 'C', $r);
        $surname = $this->cell($sheet, 'J', $r);
        $initials = $this->cell($sheet, 'K', $r);
        $forename = $this->cell($sheet, 'L', $r);
        $display = $this->cell($sheet, 'M', $r);

        if ($this->isBlank($empNo) && $this->isBlank($attendance) && $this->isBlank($nicRaw) && $this->isBlank($surname)) {
            return null;
        }

        $skip = null;
        if (!preg_match('/^\d+$/', $attendance)) {
            $skip = 'Not an employee row (attendance number is not numeric)';
        }

        $nic = $this->normalizeNic($nicRaw);
        if (!$skip && ($nic === '' || in_array($nic, ['111111111', 'NULL'], true))) {
            $skip = 'NIC missing or placeholder — skipped so client can add later';
        }
        if (!$skip && !preg_match('/\d{8,}/', $nic)) {
            $skip = 'NIC value is not usable: ' . $nicRaw;
        }

        $fullName = trim(preg_replace('/\s+/', ' ', trim($surname . ' ' . $forename)));
        if ($fullName === '') {
            $fullName = $display ?: ('Employee ' . $attendance);
        }
        $nameWithInitials = trim(preg_replace('/\s+/', ' ', trim($initials . ' ' . $surname)));
        if ($nameWithInitials === '') {
            $nameWithInitials = $fullName;
        }

        $gender = $this->normalizeGender($this->cell($sheet, 'E', $r));
        $title = $gender === 'female' ? 'Mrs' : 'Mr';
        $marital = $this->normalizeMarital($this->cell($sheet, 'F', $r));
        if ($gender === 'female' && $marital === 'single') {
            $title = 'Ms';
        }

        $addrParts = [];
        foreach (['R', 'S', 'T', 'U'] as $col) {
            $part = $this->cell($sheet, $col, $r);
            if ($part !== '') {
                $addrParts[] = $part;
            }
        }
        $permanent = implode(', ', $addrParts);
        if ($permanent === '') {
            $permanent = 'To be updated';
        }

        $contact1 = $this->normalizePhone($this->cell($sheet, 'W', $r));
        $contact2 = $this->normalizePhone($this->cell($sheet, 'X', $r));
        $mobile = $contact2 !== '' ? $contact2 : $contact1;
        $land = ($contact1 !== '' && $contact1 !== $mobile) ? $contact1 : null;

        $religionCol = $this->cell($sheet, 'N', $r);
        $raceCol = $this->cell($sheet, 'G', $r);
        $religion = $this->normalizeReligion($religionCol !== '' ? $religionCol : $raceCol);

        $active = strtoupper($this->cell($sheet, 'I', $r));
        $isActive = !in_array($active, ['FALSE', '0', 'NO', 'N'], true);

        $emailLocal = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $display ?: $fullName));
        $emailLocal = trim($emailLocal, '.') ?: ('emp' . $attendance);

        return [
            'row' => $r,
            'skip_reason' => $skip,
            'title' => $title,
            'attendance_employee_no' => $attendance,
            'epf' => 'NO-EPF-' . $attendance,
            'nic' => $nic,
            'dob' => $this->parseDob($sheet, $r) ?: '1970-01-01',
            'gender' => $gender,
            'religion' => $religion,
            'country_of_birth' => $this->cell($sheet, 'H', $r) ?: 'Sri Lanka',
            'name_with_initials' => $nameWithInitials,
            'full_name' => $fullName,
            'display_name' => $display ?: $forename ?: $fullName,
            'marital_status' => $marital,
            'is_active' => $isActive,
            'permanent_address' => $permanent,
            'district' => $this->cell($sheet, 'V', $r) ?: null,
            'email' => $attendance . '.' . $emailLocal . '@jay.local',
            'land_line' => $land,
            'mobile_line' => $mobile,
            'emg_name' => $this->cell($sheet, 'Q', $r) ?: $fullName,
            'emg_address' => $permanent,
            'emg_tel' => $mobile !== '' ? $mobile : ($land ?: '0000000000'),
        ];
    }

    private function createEmployee(array $row, company $company, designation $designation, int $employmentTypeId): void
    {
        $org = organization_assignment::create([
            'company_id' => $company->id,
            'department_id' => null,
            'sub_department_id' => null,
            'designation_id' => $designation->id,
            'current_supervisor' => null,
            'date_of_joining' => null,
            'day_off' => 'Sunday',
            'is_active' => $row['is_active'],
        ]);

        $employee = employee::create([
            'title' => $row['title'],
            'attendance_employee_no' => $row['attendance_employee_no'],
            'epf' => $row['epf'],
            'nic' => $row['nic'],
            'dob' => $row['dob'],
            'gender' => $row['gender'],
            'religion' => $row['religion'],
            'country_of_birth' => $row['country_of_birth'],
            'name_with_initials' => $row['name_with_initials'],
            'full_name' => $row['full_name'],
            'display_name' => $row['display_name'],
            'marital_status' => $row['marital_status'],
            'is_active' => $row['is_active'],
            'employment_type_id' => $employmentTypeId,
            'organization_assignment_id' => $org->id,
            'email' => $row['email'],
        ]);

        User::create([
            'name' => $row['full_name'],
            'email' => $row['email'],
            'nic' => $row['nic'],
            'employee_id' => $employee->id,
            'password' => Hash::make($row['nic']),
            'role' => 'employee',
        ]);

        contact_detail::create([
            'employee_id' => $employee->id,
            'permanent_address' => $row['permanent_address'],
            'email' => $row['email'],
            'land_line' => $row['land_line'],
            'mobile_line' => $row['mobile_line'],
            'district' => $row['district'],
            'emg_relationship' => 'Other',
            'emg_name' => $row['emg_name'],
            'emg_address' => $row['emg_address'],
            'emg_tel' => $row['emg_tel'],
        ]);

        compensation::create([
            'employee_id' => $employee->id,
            'employee_category' => 'Non-Executive',
            'basic_salary' => 0,
            'monthly_bonus' => 0,
            'enable_epf_etf' => true,
            'active_nopay' => true,
        ]);
    }

    private function resolveCompany(): company
    {
        $existing = company::query()
            ->where('company_code', 'JAY')
            ->orWhere('company_code', 'JCFOOD')
            ->orWhere('name', 'like', '%Jay%')
            ->orWhere('name', 'like', '%JC Food%')
            ->first();
        if ($existing) {
            return $existing;
        }

        return company::create([
            'company_code' => 'JAY',
            'name' => 'Jay Seafood',
            'location' => 'Sri Lanka',
            'portal_active' => true,
        ]);
    }

    public function purgeEmployees(): int
    {
        $ids = employee::withTrashed()->pluck('id')->all();
        $orgIds = employee::withTrashed()->pluck('organization_assignment_id')->filter()->all();
        $spouseIds = employee::withTrashed()->pluck('spouse_id')->filter()->all();

        User::withTrashed()->whereNotNull('employee_id')->forceDelete();

        $tables = DB::select("
            SELECT TABLE_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND COLUMN_NAME = 'employee_id'
              AND TABLE_NAME NOT IN ('users', 'employees')
        ");

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $name = $table->TABLE_NAME;
            try {
                DB::table($name)->delete();
            } catch (\Throwable) {
                // ignore views / non-deletable
            }
        }
        employee::withTrashed()->forceDelete();
        if ($orgIds) {
            organization_assignment::withTrashed()->whereIn('id', $orgIds)->forceDelete();
        }
        if ($spouseIds) {
            DB::table('spouses')->whereIn('id', $spouseIds)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return count($ids);
    }

    private function cell(Worksheet $sheet, string $col, int $row): string
    {
        $value = trim((string) $sheet->getCell($col . $row)->getFormattedValue());
        $value = preg_replace('/\s+/', ' ', $value);
        if ($value === '' || strcasecmp($value, 'NULL') === 0) {
            return '';
        }

        return $value;
    }

    private function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '' || strcasecmp(trim($value), 'NULL') === 0;
    }

    private function parseDob(Worksheet $sheet, int $row): ?string
    {
        $cell = $sheet->getCell('D' . $row);
        $raw = $cell->getValue();
        if (is_numeric($raw) && ExcelDate::isDateTime($cell)) {
            return ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
        }

        $text = $this->cell($sheet, 'D', $row);
        if (preg_match('/(\d{4})年(\d{1,2})月(\d{1,2})日/u', $text, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        return null;
    }

    private function normalizeNic(string $value): string
    {
        $value = strtoupper(preg_replace('/\s+/', '', $value));
        if (preg_match('/^[0-9]{9}[VX]$/', $value)) {
            return substr($value, 0, 9) . 'V';
        }

        return $value;
    }

    private function normalizeGender(string $value): string
    {
        $value = strtolower(trim($value));
        return str_starts_with($value, 'f') ? 'female' : (str_starts_with($value, 'm') ? 'male' : 'other');
    }

    private function normalizeMarital(string $value): string
    {
        $value = strtolower(preg_replace('/\s+/', '', $value));
        return match (true) {
            str_contains($value, 'widow') => 'widowed',
            str_contains($value, 'divorce') => 'divorced',
            str_contains($value, 'unmarried') || $value === 'single' => 'single',
            str_contains($value, 'married') => 'married',
            default => 'single',
        };
    }

    private function normalizeReligion(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $lower = strtolower($value);
        $map = [
            'buddist' => 'Buddhist',
            'buddhist' => 'Buddhist',
            'kristian' => 'Christian',
            'christian' => 'Christian',
            'catolic' => 'Catholic',
            'catholic' => 'Catholic',
            'hindu' => 'Hindu',
            'islam' => 'Islam',
            'muslim' => 'Islam',
        ];
        if (isset($map[$lower])) {
            return $map[$lower];
        }
        if (in_array($lower, ['sihala', 'sinhala', 'tamil'], true)) {
            return null;
        }

        return $value;
    }

    private function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);
        if ($digits === '' || $digits === '0') {
            return '';
        }

        return $digits;
    }

    private function uniqueMobile(string $attendanceNo, array $used): string
    {
        $base = '07' . str_pad(substr(preg_replace('/\D+/', '', $attendanceNo), -8), 8, '0', STR_PAD_LEFT);
        $candidate = $base;
        $i = 0;
        while (isset($used[$candidate])) {
            $i++;
            $candidate = $base . $i;
        }

        return $candidate;
    }
}
