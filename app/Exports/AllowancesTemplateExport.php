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
        return [];
    }

    public function headings(): array
    {
        return [
            'allowance_code',
            'allowance_name',
            'status',
            'company_id',
            'department_id',
            'amount',
        ];
    }

    public function title(): string
    {
        return 'Allowances';
    }
}
