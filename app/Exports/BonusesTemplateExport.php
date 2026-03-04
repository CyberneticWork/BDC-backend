<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class BonusesTemplateExport implements FromArray, WithHeadings, WithTitle, WithStrictNullComparison, ShouldAutoSize
{
    public function array(): array
    {
        return [];
    }

    public function headings(): array
    {
        return [
            'bonus_code',
            'bonus_name',
            'status',
            'bonus_type',
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
        return 'Bonuses';
    }
}
