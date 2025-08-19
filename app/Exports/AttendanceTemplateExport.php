<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class AttendanceTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'EmployeeNIC Number',
            'Date',
            'Time',
            'Entry',
            'status',
            'reason',
        ];
    }

    public function array(): array
    {
        return [
            ['7918165353V', '8/6/2025', '8:00:00', '1', 'IN', '','','//example data'],
            ['7909888036V', '8/7/2025', '8:10:00', '1', 'IN', '','','//example data'],
            ['7909888036V', '8/7/2025', '17:10:00', '2', 'OUT', '','','//example data'],
            ['7338883660V', '8/8/2025', '', '', 'Absent', 'illness','','//example data'],
        ];
    }
}
