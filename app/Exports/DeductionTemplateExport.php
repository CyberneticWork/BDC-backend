<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class DeductionTemplateExport implements FromArray, WithHeadings, WithTitle, WithStrictNullComparison, ShouldAutoSize
{
    public function array(): array
    {
        return [
            // Example data row
            // [
            //     'DED001', // deduction_code
            //     'EPF Deduction', // deduction_name
            //     'EPF contribution', // description
            //     500.00, // amount
            //     'active', // status
            //     'EPF', // category
            //     'fixed', // deduction_type
            //     1, // company_id
            //     1, // department_id
            //     '2023-01-01', // startDate
            //     null // endDate
            // ]
        ];
    }

    public function headings(): array
    {
        return [
            'deduction_code',
            'deduction_name',
            'description',
            'amount',
            'status',
            'deduction_type',
            'company_id',
            'department_id',
            'startDate',
            'endDate'
        ];
    }

    public function title(): string
    {
        return 'Deductions';
    }
}