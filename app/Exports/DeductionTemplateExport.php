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
        return [];
    }

    public function headings(): array
    {
        return [
            'deduction_code',
            'deduction_name',
            'description',
            'amount',
            'status',
            'company_id',
            'department_id',
        ];
    }

    public function title(): string
    {
        return 'Deductions';
    }
}
