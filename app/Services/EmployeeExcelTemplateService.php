<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeExcelTemplateService
{
    /** Canonical column keys — must match EmployeeExcelImportService row import. */
    public const COLUMNS = [
        'title' => 'Title *',
        'attendance_no' => 'Attendance No *',
        'epf' => 'EPF No *',
        'nic' => 'NIC *',
        'dob' => 'Date of Birth * (YYYY-MM-DD)',
        'gender' => 'Gender *',
        'religion' => 'Religion',
        'country_of_birth' => 'Country of Birth',
        'employment_status' => 'Employment Status *',
        'name_with_initials' => 'Name with Initials *',
        'full_name' => 'Full Name *',
        'display_name' => 'Display Name *',
        'marital_status' => 'Marital Status *',
        'spouse_relationship' => 'Spouse Relationship',
        'spouse_title' => 'Spouse Title',
        'spouse_name' => 'Spouse Name',
        'spouse_dob' => 'Spouse DOB (YYYY-MM-DD)',
        'spouse_nic' => 'Spouse NIC',
        'child1_name' => 'Child 1 Name',
        'child1_dob' => 'Child 1 DOB (YYYY-MM-DD)',
        'child1_nic' => 'Child 1 NIC',
        'child2_name' => 'Child 2 Name',
        'child2_dob' => 'Child 2 DOB (YYYY-MM-DD)',
        'child2_nic' => 'Child 2 NIC',
        'permanent_address' => 'Permanent Address *',
        'temporary_address' => 'Temporary Address',
        'email' => 'Email *',
        'mobile' => 'Mobile *',
        'land_line' => 'Land Line',
        'province' => 'Province *',
        'district' => 'District *',
        'electoral_division' => 'Electoral Division',
        'gn_division' => 'GN Division',
        'police_station' => 'Police Station',
        'emergency_relationship' => 'Emergency Relationship *',
        'emergency_name' => 'Emergency Contact Name *',
        'emergency_address' => 'Emergency Contact Address',
        'emergency_tel' => 'Emergency Contact Tel *',
        'basic_salary' => 'Basic Salary *',
        'monthly_bonus' => 'Monthly Bonus',
        'sports_fund_percentage' => 'Sports Fund %',
        'staff_fund_amount' => 'Staff Fund Amount',
        'account_holder_name' => 'Account Holder Name',
        'bank_name' => 'Bank Name',
        'branch_name' => 'Branch Name',
        'bank_account_no' => 'Bank Account No',
        'company_name' => 'Company Name *',
        'company_code' => 'Company Code *',
        'department' => 'Department',
        'sub_department' => 'Sub Department',
        'supervisor' => 'Supervisor',
        'date_joined' => 'Date Joined * (YYYY-MM-DD)',
        'designation' => 'Designation *',
        'employee_category' => 'Employee Category *',
        'day_off' => 'Day Off',
        'enable_epf_etf' => 'Enable EPF/ETF (Yes/No)',
        'ot_active' => 'OT Active (Yes/No)',
        'nopay_active' => 'NoPay Active (Yes/No)',
    ];

    public function spreadsheet(): Spreadsheet
    {
        $book = new Spreadsheet();
        $this->writeInstructions($book->getActiveSheet());
        $this->writeLookups($book->createSheet());
        $this->writeMaster($book->createSheet());
        $book->setActiveSheetIndex(2);

        return $book;
    }

    public function downloadResponse(string $filename = 'employee_master_import.xlsx'): StreamedResponse
    {
        $book = $this->spreadsheet();

        return new StreamedResponse(function () use ($book) {
            $writer = new Xlsx($book);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function saveTo(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $writer = new Xlsx($this->spreadsheet());
        $writer->save($path);
    }

    private function writeInstructions(Worksheet $sheet): void
    {
        $sheet->setTitle('Instructions');
        $sheet->setCellValue('A1', 'Employee Master Excel — fill and upload');
        $sheet->mergeCells('A1:B1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $lines = [
            ['1', 'Open the "Employee Master" sheet. One employee = one row. Do not rename header cells.'],
            ['2', 'Columns marked * are required. Dates must be YYYY-MM-DD (example 1990-01-15).'],
            ['3', 'Company Name / Company Code must already exist in HR (Cybernetic Admin). Department and designation are created if missing.'],
            ['4', 'Employment Status: Permanent, Contract, Probation, Training, Daily Wages Salary'],
            ['5', 'Gender: Male / Female / Other. Marital Status: Single / Married / Divorced / Widowed'],
            ['6', 'Employee Category: Executive or Non-Executive. Title: Mr / Mrs / Ms / Dr'],
            ['7', 'NIC: old 9 digits + V, or new 12 digits. First login password for the employee portal is the NIC.'],
            ['8', 'Attendance No must match the fingerprint / time-card employee number.'],
            ['9', 'Delete the sample row before upload if it is not a real employee.'],
            ['10', 'In HR: Show Employee → Download template / Upload Excel, or Add Employee Master.'],
        ];
        $row = 3;
        foreach ($lines as [$n, $text]) {
            $sheet->setCellValue("A{$row}", $n);
            $sheet->setCellValue("B{$row}", $text);
            $row++;
        }
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(120);
        $sheet->getStyle('B3:B12')->getAlignment()->setWrapText(true);
    }

    private function writeLookups(Worksheet $sheet): void
    {
        $sheet->setTitle('Lookups');
        $lists = [
            'A' => ['Title', 'Mr', 'Mrs', 'Ms', 'Dr'],
            'B' => ['Gender', 'Male', 'Female', 'Other'],
            'C' => ['MaritalStatus', 'Single', 'Married', 'Divorced', 'Widowed'],
            'D' => ['EmploymentStatus', 'Permanent', 'Contract', 'Probation', 'Training', 'Daily Wages Salary'],
            'E' => ['Category', 'Executive', 'Non-Executive'],
            'F' => ['YesNo', 'Yes', 'No'],
            'G' => ['DayOff', 'Sunday', 'Saturday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        ];
        foreach ($lists as $col => $values) {
            foreach ($values as $i => $value) {
                $sheet->setCellValue($col.($i + 1), $value);
            }
        }
        $sheet->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
    }

    private function writeMaster(Worksheet $sheet): void
    {
        $sheet->setTitle('Employee Master');
        $keys = array_keys(self::COLUMNS);
        $labels = array_values(self::COLUMNS);

        foreach ($labels as $i => $label) {
            $col = $this->col($i + 1);
            $sheet->setCellValue($col.'1', $keys[$i]);
            $sheet->setCellValue($col.'2', $label);
            $sheet->getColumnDimension($col)->setWidth(max(16, min(28, strlen($label) + 2)));
        }

        $sheet->getStyle('A1:'.$this->col(count($keys)).'1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:'.$this->col(count($keys)).'1')->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F766E');
        $sheet->getStyle('A2:'.$this->col(count($keys)).'2')->getFont()->setItalic(true)->setSize(9);
        $sheet->getStyle('A2:'.$this->col(count($keys)).'2')->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('CCFBF1');
        $sheet->getStyle('A2:'.$this->col(count($keys)).'2')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(36);
        $sheet->freezePane('A3');
        $sheet->setAutoFilter('A1:'.$this->col(count($keys)).'1');

        $sample = [
            'title' => 'Mr',
            'attendance_no' => 'EMP001',
            'epf' => 'EPF001',
            'nic' => '900000000V',
            'dob' => '1990-01-15',
            'gender' => 'Male',
            'religion' => 'Buddhist',
            'country_of_birth' => 'Sri Lanka',
            'employment_status' => 'Permanent',
            'name_with_initials' => 'T. Employee',
            'full_name' => 'Test Employee',
            'display_name' => 'Test Employee',
            'marital_status' => 'Single',
            'permanent_address' => '123 Sample Road, Colombo',
            'email' => 'test.employee@company.lk',
            'mobile' => '0771234567',
            'province' => 'Western Province',
            'district' => 'Colombo',
            'emergency_relationship' => 'Parent',
            'emergency_name' => 'A. Guardian',
            'emergency_tel' => '0770000000',
            'basic_salary' => '50000',
            'monthly_bonus' => '0',
            'company_name' => 'YOUR COMPANY NAME',
            'company_code' => 'YOURCODE',
            'department' => 'HR',
            'designation' => 'Officer',
            'date_joined' => date('Y-m-d'),
            'employee_category' => 'Non-Executive',
            'day_off' => 'Sunday',
            'enable_epf_etf' => 'Yes',
            'ot_active' => 'No',
            'nopay_active' => 'Yes',
        ];
        foreach ($keys as $i => $key) {
            $sheet->setCellValue($this->col($i + 1).'3', $sample[$key] ?? '');
        }

        $dropdowns = [
            'title' => 'Lookups!$A$2:$A$5',
            'gender' => 'Lookups!$B$2:$B$4',
            'marital_status' => 'Lookups!$C$2:$C$5',
            'employment_status' => 'Lookups!$D$2:$D$6',
            'employee_category' => 'Lookups!$E$2:$E$3',
            'enable_epf_etf' => 'Lookups!$F$2:$F$3',
            'ot_active' => 'Lookups!$F$2:$F$3',
            'nopay_active' => 'Lookups!$F$2:$F$3',
            'day_off' => 'Lookups!$G$2:$G$8',
        ];
        foreach ($keys as $i => $key) {
            if (!isset($dropdowns[$key])) {
                continue;
            }
            $col = $this->col($i + 1);
            $validation = $sheet->getCell($col.'3')->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
            $validation->setAllowBlank(true);
            $validation->setShowDropDown(true);
            $validation->setFormula1($dropdowns[$key]);
            $sheet->setDataValidation($col.'3:'.$col.'200', $validation);
        }
    }

    private function col(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }
}