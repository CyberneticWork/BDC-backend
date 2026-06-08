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
            'category' => 'nullable|in:travel,bonus,monthly_bonus,performance,health,other',
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
            'category' => 'nullable|in:travel,bonus,monthly_bonus,performance,health,other',
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


    // =========================================================
    // MONTHLY ALLOWANCES REPORT 
    // =========================================================
    public function getMonthlyEmployeeAllowances(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $company_id = $request->query('company_id');
        $department_id = $request->query('department_id');
        $search = $request->query('search');

        if (!$month || !$year) {
            return response()->json(['message' => 'Month and Year are required'], 400);
        }

        // employee_allowances table 
        $query = \App\Models\employee_allowances::with([
            'employee.organizationAssignment.company',
            'employee.organizationAssignment.department',
            'allowance'
        ])
        ->where('month', $month)
        ->where('year', $year)
        ->where('is_active', 1);

        if ($company_id || $department_id) {
            $query->whereHas('employee.organizationAssignment', function($q) use($company_id, $department_id) {
                if ($company_id) $q->where('company_id', $company_id);
                if ($department_id) $q->where('department_id', $department_id);
            });
        }

        if ($search) {
            $query->where(function($mainQ) use($search) {
                $mainQ->whereHas('employee', function($q) use($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhere('attendance_employee_no', 'like', "%{$search}%");
                })->orWhereHas('allowance', function($q) use($search) {
                    $q->where('allowance_name', 'like', "%{$search}%");
                });
            });
        }

        $records = $query->orderBy('id', 'desc')->get();

        $data = $records->map(function($record) {
            $emp = $record->employee;
            $org = $emp ? $emp->organizationAssignment : null;
            $alw = $record->allowance;

            return [
                'id' => $record->id,
                'emp_no' => $emp ? $emp->attendance_employee_no : '-',
                'emp_name' => $emp ? $emp->full_name : '-',
                'company' => ($org && $org->company) ? $org->company->name : '-',
                'department' => ($org && $org->department) ? $org->department->name : '-',
                'allowance_code' => $alw ? $alw->allowance_code : '-',
                'allowance_name' => $alw ? $alw->allowance_name : '-',
                'amount' => $record->custom_amount ?? ($alw ? $alw->amount : 0),
                'month' => $record->month,
                'year' => $record->year
            ];
        });

        return response()->json(['data' => $data], 200);
    }




    /*
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
    */

public function getAllowancesByCompanyOrDepartment(Request $request)
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

    $query = allowances::query()
        ->where('status', 'active')
        ->with(['company:id,name', 'department:id,name']);

    // ✅ if company_id given
    if (!is_null($companyId) && $companyId !== '') {
        $query->where('company_id', $companyId);
    }

    // ✅ if department_id given
    if (!is_null($departmentId) && $departmentId !== '') {
        $query->where('department_id', $departmentId);
    }

    $allowances = $query->orderBy('id', 'desc')->get();

    // ✅ Always return 200 (frontend එකට easy)
    return response()->json([
        'data' => $allowances,
        'count' => $allowances->count(),
    ], 200);
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
