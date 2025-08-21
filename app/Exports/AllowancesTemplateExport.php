<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class AllowancesTemplateExport implements FromArray, WithHeadings, WithTitle, WithStrictNullComparison, ShouldAutoSize
{
    public function array(): array
    {
        return [
            // Example data row
            // [
            //     'ALLOW001', // allowance_code
            //     'Travel Allowance', // allowance_name
            //     'active', // status
            //     'travel', // category
            //     'fixed', // allowance_type
            //     1, // company_id
            //     1, // department_id
            //     1000.00, // amount
            //     '2023-12-31', // fixed_date
            //     null, // variable_from
            //     null  // variable_to
            // ]
        ];
    }

    public function headings(): array
    {
        return [
            'allowance_code',
            'allowance_name',
            'status',
           
            'allowance_type',
            'company_id',
            'department_id',
            'amount',
            'fixed_date',
            'variable_from',
            'variable_to'
        ];
    }

    public function title(): string
    {
        return 'Allowances';
    }
}