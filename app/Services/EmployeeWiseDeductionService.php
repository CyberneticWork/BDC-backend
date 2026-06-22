<?php

namespace App\Services;

use App\Models\EmployeeWiseDeduction;
use Illuminate\Support\Collection;

class EmployeeWiseDeductionService
{
    public function getAll(): Collection
    {
        return EmployeeWiseDeduction::with('employee')->get()->map(function ($deduction) {
            $deduction->employee_name = $deduction->employee?->full_name ?? 'N/A';
            $deduction->makeHidden('employee');

            return $deduction;
        });
    }

    public function createOne(array $data): EmployeeWiseDeduction
    {
        $record = EmployeeWiseDeduction::create([
            'deduction_code' => $data['deduction_code'],
            'deduction_name' => $data['deduction_name'],
            'deduction_description' => $data['deduction_description'] ?? null,
            'employee_id' => $data['employee_id'],
            'amount' => $data['amount'],
            'date' => $data['date'],
        ]);

        return $this->getOneById($record->id);
    }

    public function getOneById(int $id): EmployeeWiseDeduction
    {
        $record = EmployeeWiseDeduction::findOrFail($id);
        $record->load('employee');
        $record->employee_name = $record->employee?->full_name ?? 'N/A';
        $record->makeHidden('employee');

        return $record;
    }

    public function updateOne(int $id, array $data): EmployeeWiseDeduction
    {
        $record = EmployeeWiseDeduction::findOrFail($id);

        $record->update([
            'deduction_name' => $data['deduction_name'],
            'deduction_description' => $data['deduction_description'] ?? null,
            'amount' => $data['amount'],
            'date' => $data['date'],
            'status' => $data['status'],
        ]);

        return $this->getOneById($record->id);
    }

    public function deleteOne(int $id): bool
    {
        $record = EmployeeWiseDeduction::findOrFail($id);

        return $record->delete();
    }
}
