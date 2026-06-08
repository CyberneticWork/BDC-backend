<?php

namespace App\Http\Controllers;

use App\Models\EmployeeBonus;
use App\Models\bonuses;
use App\Models\employee;
use Illuminate\Http\Request;

class EmployeeBonusController extends Controller
{
    public function index(Request $request)
    {
        $bonusId = $request->query('bonus_id');
        $employeeId = $request->query('employee_id');

        $query = EmployeeBonus::with(['employee:id,full_name,attendance_employee_no', 'bonus:id,bonus_name,bonus_code,is_annual']);

        if ($bonusId) {
            $query->where('bonus_id', $bonusId);
        }
        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }

        return response()->json(['data' => $query->orderByDesc('id')->get()]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'bonus_id' => 'required|exists:bonuses,id',
            'custom_amount' => 'nullable|numeric|min:0',
            'month' => 'nullable|integer|between:1,12',
            'year' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        $bonus = bonuses::findOrFail($validated['bonus_id']);

        if ($bonus->is_annual) {
            $validated['month'] = null;
            $validated['year'] = $validated['year'] ?? (int) date('Y');
        }

        $assignment = EmployeeBonus::updateOrCreate(
            [
                'employee_id' => $validated['employee_id'],
                'bonus_id' => $validated['bonus_id'],
                'month' => $validated['month'] ?? null,
                'year' => $validated['year'] ?? null,
            ],
            [
                'custom_amount' => $validated['custom_amount'] ?? $bonus->amount,
                'is_active' => $validated['is_active'] ?? true,
            ]
        );

        return response()->json(['data' => $assignment->load('employee', 'bonus')], 201);
    }

    public function destroy($id)
    {
        $assignment = EmployeeBonus::findOrFail($id);
        $assignment->delete();

        return response()->json(['message' => 'Employee bonus assignment removed']);
    }
}
