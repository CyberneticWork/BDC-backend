<?php

namespace App\Http\Controllers;

use App\Models\bonuses;
use App\Models\departments;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use App\Imports\BonusesImport;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\BonusesTemplateExport;

class BonusesController extends Controller
{
    public function index()
    {
        $bonuses = bonuses::with(['company:id,name', 'department:id,name'])->get();
        return response()->json(['data' => $bonuses], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'bonus_code' => 'required|unique:bonuses,bonus_code',
            'bonus_name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
            'bonus_type' => 'nullable|in:fixed,variable',
            'company_id' => 'required|exists:companies,id',
            'amount' => 'nullable|numeric',
            'department_id' => [
                'nullable',
                'exists:departments,id',
                Rule::exists('departments', 'id')->where(function ($query) use ($request) {
                    $query->where('company_id', $request->company_id);
                })
            ],
            'fixed_date' => [
                'nullable',
                'date',
                Rule::requiredIf(function () use ($request) {
                    return $request->bonus_type === 'fixed';
                })
            ],
            'variable_from' => [
                'nullable',
                'date',
                Rule::requiredIf(function () use ($request) {
                    return $request->bonus_type === 'variable';
                }),
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->bonus_type === 'variable' && $request->variable_to && $value > $request->variable_to) {
                        $fail('The from date must be before the to date.');
                    }
                }
            ],
            'variable_to' => [
                'nullable',
                'date',
                Rule::requiredIf(function () use ($request) {
                    return $request->bonus_type === 'variable';
                }),
                'after_or_equal:variable_from'
            ]
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // default amount
        if (!isset($data['amount']) || $data['amount'] === null) {
            $data['amount'] = 0.00;
        }

        // dates based on type
        if (($data['bonus_type'] ?? null) === 'fixed') {
            $data['variable_from'] = null;
            $data['variable_to'] = null;
        } else {
            $data['fixed_date'] = null;
        }

        $bonus = bonuses::create($data);

        // (optional) return with relations
        $bonus = bonuses::with(['company:id,name', 'department:id,name'])->find($bonus->id);

        return response()->json(['data' => $bonus], 201);
    }

    public function show($id)
    {
        $bonus = bonuses::with(['company:id,name', 'department:id,name'])->find($id);

        if (!$bonus) {
            return response()->json(['message' => 'Bonus not found.'], 404);
        }

        return response()->json(['data' => $bonus], 200);
    }

    public function update(Request $request, $id)
    {
        $bonus = bonuses::find($id);

        if (!$bonus) {
            return response()->json(['message' => 'Bonus not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'bonus_code' => ['required', Rule::unique('bonuses')->ignore($bonus->id)],
            'bonus_name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
            'bonus_type' => 'required|in:fixed,variable',
            'company_id' => 'nullable|exists:companies,id',
            'amount' => 'required|numeric|min:0',
            'department_id' => [
                'nullable',
                'exists:departments,id',
                Rule::exists('departments', 'id')->where(function ($query) use ($request) {
                    $query->where('company_id', $request->company_id);
                })
            ],
            'fixed_date' => [
                'nullable',
                'date',
                Rule::requiredIf(function () use ($request) {
                    return $request->bonus_type === 'fixed';
                })
            ],
            'variable_from' => [
                'nullable',
                'date',
                Rule::requiredIf(function () use ($request) {
                    return $request->bonus_type === 'variable';
                }),
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->bonus_type === 'variable' && $request->variable_to && $value > $request->variable_to) {
                        $fail('The from date must be before the to date.');
                    }
                }
            ],
            'variable_to' => [
                'nullable',
                'date',
                Rule::requiredIf(function () use ($request) {
                    return $request->bonus_type === 'variable';
                }),
                'after_or_equal:variable_from'
            ]
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if ($data['bonus_type'] === 'fixed') {
            $data['variable_from'] = null;
            $data['variable_to'] = null;
        } else {
            $data['fixed_date'] = null;
        }

        $bonus->update($data);

        $bonus = bonuses::with(['company:id,name', 'department:id,name'])->find($bonus->id);

        return response()->json(['data' => $bonus], 200);
    }

    public function destroy($id)
    {
        $bonus = bonuses::find($id);

        if (!$bonus) {
            return response()->json(['message' => 'Bonus not found.'], 404);
        }

        $bonus->delete();
        return response()->json(['message' => 'Deleted successfully.'], 204);
    }

    public function getBonusesByCompanyOrDepartment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|exists:companies,id',
            'department_id' => 'nullable|integer|exists:departments,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $companyId = $request->input('company_id');
        $departmentId = $request->input('department_id');

        $query = bonuses::query()
            ->where('status', 'active')
            ->with(['company:id,name', 'department:id,name']);

        if (!is_null($companyId) && $companyId !== '') {
            $query->where('company_id', $companyId);
        }

        if (!is_null($departmentId) && $departmentId !== '') {
            $query->where('department_id', $departmentId);
        }

        $bonuses = $query->orderBy('id', 'desc')->get();

        return response()->json([
            'data' => $bonuses,
            'count' => $bonuses->count(),
        ], 200);
    }

    /**
     * Download Excel template for bonuses import
     */
    public function downloadTemplate()
    {
        return Excel::download(new BonusesTemplateExport(), 'bonuses_template.xlsx');
    }

    /**
     * Import bonuses from Excel
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls'
        ]);

        try {
            Excel::import(new BonusesImport(), $request->file('file'));
            return response()->json(['message' => 'Bonuses imported successfully'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error importing file',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}