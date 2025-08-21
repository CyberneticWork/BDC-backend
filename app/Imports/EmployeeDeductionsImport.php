<?php

namespace App\Imports;

use App\Models\employee;
use App\Models\employee_deductions;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class EmployeeDeductionsImport implements ToModel, WithHeadingRow, WithValidation
{
    public function model(array $row)
    {
        $emloyee = employee::where('attendance_employee_no', $row['employee_no'] ?? $row['EMPLOYEE_NO'])->first();
        return new employee_deductions([
            'employee_id' => $emloyee->id,
            'attendance_employee_no' => $row['employee_no'] ?? $row['EMPLOYEE_NO'], // Handles both cases
            'deduction_id' => $row['deduction_id'] ?? $row['deduction id'] ?? $row['Deduction ID'], // Multiple possible headers
            'custom_amount' => $row['amount_lkr'] ?? $row['amount (lkr)'] ?? $row['Amount (LKR)'], // Multiple possible headers
            'is_active' => true
        ]);
    }

    public function rules(): array
    {
        return [
            'attendance_employee_no' => 'required|exists:employees,attendance_employee_no',
            'deduction_id' => 'required|exists:deductions,id',
            'deduction_amount_lkr' => 'required|numeric|min:0'
        ];
    }

    public function prepareForValidation($data)
    {
        // Normalize all possible header variations
        $data['attendance_employee_no'] = $data['employee_no'] ?? $data['EMPLOYEE_NO'] ?? null;
        $data['deduction_id'] = $data['Deduction ID'] ?? $data['deduction id'] ?? $data['deduction_id'] ?? $data['deduction_id'] ?? null;
        $data['deduction_amount_lkr'] = $data['Amount (LKR)'] ?? $data['amount (lkr)'] ?? $data['amount_lkr'] ?? null;

        return $data;
    }
}
