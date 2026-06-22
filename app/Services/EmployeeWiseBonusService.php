<?php

namespace App\Services;

use App\Models\EmployeeWiseBonus;
use Illuminate\Support\Collection;

class EmployeeWiseBonusService
{
    public function getAll(): Collection
    {
        return EmployeeWiseBonus::with('employee')->get()->map(function ($bonus) {
            $bonus->employee_name = $bonus->employee?->full_name ?? 'N/A';
            $bonus->makeHidden('employee');

            return $bonus;
        });
    }

    public function createOne(array $data): EmployeeWiseBonus
    {
        $record = EmployeeWiseBonus::create([
            'bonus_code' => $data['bonus_code'],
            'bonus_name' => $data['bonus_name'],
            'bonus_description' => $data['bonus_description'] ?? null,
            'employee_id' => $data['employee_id'],
            'amount' => $data['amount'],
            'date' => $data['date'],
            'is_annual' => $data['is_annual'] ?? false,
            'payment_months' => $data['payment_months'] ?? null,
        ]);

        return $this->getOneById($record->id);
    }

    public function getOneById(int $id): EmployeeWiseBonus
    {
        $record = EmployeeWiseBonus::findOrFail($id);
        $record->load('employee');
        $record->employee_name = $record->employee?->full_name ?? 'N/A';
        $record->makeHidden('employee');

        return $record;
    }

    public function updateOne(int $id, array $data): EmployeeWiseBonus
    {
        $record = EmployeeWiseBonus::findOrFail($id);

        $record->update([
            'bonus_name' => $data['bonus_name'],
            'bonus_description' => $data['bonus_description'] ?? null,
            'amount' => $data['amount'],
            'date' => $data['date'],
            'is_annual' => $data['is_annual'] ?? false,
            'payment_months' => $data['payment_months'] ?? null,
            'status' => $data['status'],
        ]);

        return $this->getOneById($record->id);
    }

    public function deleteOne(int $id): bool
    {
        $record = EmployeeWiseBonus::findOrFail($id);

        return $record->delete();
    }
}
