<?php
namespace App\Http\Controllers;

use App\Models\allowances;
use App\Models\departments;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use App\Imports\AllowancesImport;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\AllowancesTemplateExport;

class AllowancesController extends Controller
{
    public function index()
    {
        $allowances = allowances::with(['company:id,name', 'department:id,name'])->get();
        return response()->json(['data' => $allowances], 200);
    }

     public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'allowance_code' => 'required|unique:allowances,allowance_code',
            'allowance_name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
            'category' => 'nullable|in:travel,bonus,performance,health,other',
            'allowance_type' => 'nullable|in:fixed,variable',
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
                    return $request->allowance_type === 'fixed';
                })
            ],
            'variable_from' => [
                'nullable',
                'date',
                Rule::requiredIf(function () use ($request) {
                    return $request->allowance_type === 'variable';
                }),
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->allowance_type === 'variable' && $request->to_date && $value > $request->to_date) {
                        $fail('The from date must be before the to date.');
                    }
                }
            ],
            'variable_to' => [
                'nullable',
                'date',
                Rule::requiredIf(function () use ($request) {
                    return $request->allowance_type === 'variable';
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

        // Prepare data based on allowance type
        $data = $validator->validated();

        if ($data['amount'] == null) {
            $data['amount'] = 0.00;
        }
        if ($data['allowance_type'] === 'fixed') {
            $data['variable_from'] = null;
            $data['variable_to'] = null;
        } else {
            $data['fixed_date'] = null;
        }

        $allowance = allowances::create($data);
        return response()->json(['data' => $allowance], 201);
    }

    public function show($id)
    {
        $allowance = allowances::with(['company:id,name', 'department:id,name'])->find($id);

        if (!$allowance) {
            return response()->json(['message' => 'Allowance not found.'], 404);
        }

        return response()->json(['data' => $allowance], 200);
    }

    public function update(Request $request, $id)
    {
        $allowance = allowances::find($id);

        if (!$allowance) {
            return response()->json(['message' => 'Allowance not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'allowance_code' => ['required', Rule::unique('allowances')->ignore($allowance->id)],
            'allowance_name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
            'category' => 'nullable|in:travel,bonus,performance,health,other',
            'allowance_type' => 'required|in:fixed,variable',
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
                    return $request->allowance_type === 'fixed';
                })
            ],
            'variable_from' => [
                'nullable',
                'date',
                Rule::requiredIf(function () use ($request) {
                    return $request->allowance_type === 'variable';
                }),
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->allowance_type === 'variable' && $request->to_date && $value > $request->to_date) {
                        $fail('The from date must be before the to date.');
                    }
                }
            ],
            'variable_to' => [
                'nullable',
                'date',
                Rule::requiredIf(function () use ($request) {
                    return $request->allowance_type === 'variable';
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

        // Prepare data based on allowance type
        $data = $validator->validated();

        if ($data['allowance_type'] === 'fixed') {
            $data['variable_from'] = null;
            $data['variable_to'] = null;
        } else {
            $data['fixed_date'] = null;
        }

        $allowance->update($data);
        return response()->json(['data' => $allowance], 200);
    }

    public function destroy($id)
    {
        $allowance = allowances::find($id);

        if (!$allowance) {
            return response()->json(['message' => 'Allowance not found.'], 404);
        }

        $allowance->delete();
        return response()->json(['message' => 'Deleted successfully.'], 204);
    }

    public function getDepartmentsByCompany($companyId)
    {
        $departments = departments::where('company_id', $companyId)->get(['id', 'name']);

        if ($departments->isEmpty()) {
            return response()->json(['message' => 'No departments found for this company.'], 404);
        }

        return response()->json(['data' => $departments], 200);
    }

    public function getAllowancesByCompanyOrDepartment(Request $request)
    {
        $query = allowances::where('status', 'active');

        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        $allowances = $query->get();

        if ($allowances->isEmpty()) {
            return response()->json(['message' => 'No allowances found.'], 404);
        }

        return response()->json(['data' => $allowances], 200);
    }

    /**
     * Download Excel template for allowances import
     */
    public function downloadTemplate()
    {
        return Excel::download(new AllowancesTemplateExport(), 'allowances_template.xlsx');
    }

    /**
     * Import allowances from Excel
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls'
        ]);

        try {
            Excel::import(new AllowancesImport(), $request->file('file'));
            return response()->json(['message' => 'Allowances imported successfully'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error importing file',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}
