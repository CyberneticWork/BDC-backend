<?php

namespace App\Services;

use App\Models\EmployeeWiseAllowance;
use Illuminate\Support\Collection;

class EmployeeWiseAllowanceService
{
    public function getAll(): Collection
    {
        return EmployeeWiseAllowance::with('employee')->get()->map(function ($allowance) {
            $allowance->employee_name = $allowance->employee?->display_name ?? 'N/A';
            $allowance->makeHidden('employee');

            return $allowance;
        });
    }

    public function createOne(array $data): EmployeeWiseAllowance
    {
        $record = EmployeeWiseAllowance::create([
            'allowance_code' => $data['allowance_code'],
            'allowance_name' => $data['allowance_name'],
            'allowance_description' => $data['allowance_description'],
            'employee_id' => $data['employee_id'],
            'amount' => $data['amount'],
            'date' => $data['date']
        ]);

        return $this->getOneById($record->id);
    }

    public function getOneById(int $id): EmployeeWiseAllowance
    {
        $record = EmployeeWiseAllowance::findOrFail($id);
        $record->load('employee');
        $record->employee_name = $record->employee?->display_name ?? 'N/A';
        $record->makeHidden('employee');

        return $record;
    }

    public function updateOne(int $id, array $data): EmployeeWiseAllowance
    {
        $record = EmployeeWiseAllowance::findOrFail($id);

        $record->update([
            'allowance_name' => $data['allowance_name'],
            'allowance_description' => $data['allowance_description'],
            'amount' => $data['amount'],
            'date' => $data['date'],
            'status' => $data['status']
        ]);

        return $this->getOneById($record->id);
    }

    public function deleteOne(int $id): bool
    {
        $record = $this->getOneById($id);
        return $record->delete();
    }
}
