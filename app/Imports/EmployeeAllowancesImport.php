<?php

namespace App\Imports;

use App\Models\employee;
use App\Models\employee_allowances;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class EmployeeAllowancesImport implements ToModel, WithHeadingRow, WithValidation
{
    public function model(array $row)
    {
        $emloyee = employee::where('attendance_employee_no', $row['employee_no'] ?? $row['EMPLOYEE_NO'])->first();
        return new employee_allowances([
            'employee_id' => $emloyee->id,
            'attendance_employee_no' => $row['employee_no'] ?? $row['EMPLOYEE_NO'], // Handles both cases
            'allowance_id' => $row['allowance_id'] ?? $row['allowance id'] ?? $row['Allowance ID'], // Multiple possible headers
            'custom_amount' => $row['amount_lkr'] ?? $row['amount (lkr)'] ?? $row['Amount (LKR)'], // Multiple possible headers
            'is_active' => true
        ]);
    }

    public function rules(): array
    {
        return [
            'attendance_employee_no' => 'required|exists:employees,attendance_employee_no',
            'allowance_id' => 'required|exists:allowances,id',
            'amount_lkr' => 'required|numeric|min:0'
        ];
    }

    public function prepareForValidation($data)
    {
        // Normalize all possible header variations
        $data['attendance_employee_no'] = $data['employee_no'] ?? $data['EMPLOYEE_NO'] ?? null;
        $data['allowance_id'] = $data['Allowance ID'] ?? $data['allowance id'] ?? $data['allowance_id'] ?? null;
        $data['amount_lkr'] = $data['Amount (LKR)'] ?? $data['amount (lkr)'] ?? $data['amount_lkr'] ?? null;

        return $data;
    }
}
