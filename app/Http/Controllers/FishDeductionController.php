<?php

namespace App\Http\Controllers;

use App\Models\company;
use App\Models\employee;
use App\Models\FishDeduction;
use App\Services\CompanyProcessSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FishDeductionController extends Controller
{
    /**
     * Display a listing of fish deductions with filters and company isolation.
     */
    public function index(Request $request)
    {
        $companyId = $request->query('company_id');
        $employeeId = $request->query('employee_id');
        $salaryYear = $request->query('salary_year') ?? $request->query('year');
        $salaryMonth = $request->query('salary_month') ?? $request->query('month');
        $applicableMonth = $request->query('applicable_month');
        $status = $request->query('status');
        $search = trim((string) $request->query('search', ''));

        // If no explicit company_id given, resolve from current authenticated user's assigned employee company
        if (!$companyId && $request->user()) {
            $userCompanyId = $request->user()->employee?->organizationAssignment?->company_id;
            if ($userCompanyId) {
                $companyId = $userCompanyId;
            }
        }

        // Validate feature toggle for the target company if filtered
        if ($companyId && !CompanyProcessSettings::usesFishDeduction($companyId)) {
            return response()->json([
                'data' => [],
                'feature_enabled' => false,
                'message' => 'Fish Deduction is not enabled for this company in Cybernetic Admin.',
                'summary' => [
                    'total_amount' => 0,
                    'total_count' => 0,
                    'unique_employees' => 0,
                ],
            ]);
        }

        if (!\Illuminate\Support\Facades\Schema::hasTable('fish_deductions')) {
            return response()->json([
                'data' => [],
                'feature_enabled' => true,
                'summary' => [
                    'total_amount' => 0,
                    'total_count' => 0,
                    'unique_employees' => 0,
                ],
            ]);
        }

        $query = FishDeduction::with([
            'employee:id,attendance_employee_no,full_name,name_with_initials',
            'employee.organizationAssignment:id,employee_id,company_id,department_id',
            'employee.organizationAssignment.company:id,name,company_code',
            'employee.organizationAssignment.department:id,name',
            'creator:id,name,email',
            'updater:id,name,email',
        ]);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }

        if ($applicableMonth) {
            $query->where('applicable_month', $applicableMonth);
        } elseif ($salaryYear && $salaryMonth) {
            $query->where('salary_year', (int) $salaryYear)
                  ->where('salary_month', (int) $salaryMonth);
        } elseif ($salaryYear) {
            $query->where('salary_year', (int) $salaryYear);
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhereHas('employee', function ($eq) use ($search) {
                      $eq->where('full_name', 'like', "%{$search}%")
                         ->orWhere('name_with_initials', 'like', "%{$search}%")
                         ->orWhere('attendance_employee_no', 'like', "%{$search}%");
                  });
            });
        }

        $records = $query->orderBy('deduction_date', 'desc')
                         ->orderBy('id', 'desc')
                         ->get();

        $totalAmount = (float) $records->sum('amount');
        $uniqueEmployees = $records->pluck('employee_id')->unique()->count();

        return response()->json([
            'data' => $records,
            'feature_enabled' => $companyId ? CompanyProcessSettings::usesFishDeduction($companyId) : true,
            'summary' => [
                'total_amount' => round($totalAmount, 2),
                'total_count' => $records->count(),
                'unique_employees' => $uniqueEmployees,
            ],
        ]);
    }

    /**
     * Store a newly created fish deduction record.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer|exists:employees,id',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'deduction_date' => 'required|date',
            'applicable_month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'description' => 'nullable|string|max:1000',
            'company_id' => 'nullable|integer|exists:companies,id',
        ], [
            'applicable_month.regex' => 'The applicable month must be in YYYY-MM format (e.g. 2026-10).',
            'amount.min' => 'The deduction amount must be greater than zero.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $employee = employee::with('organizationAssignment.company')->findOrFail($request->input('employee_id'));
        $resolvedCompanyId = $request->input('company_id')
            ?? $employee->organizationAssignment?->company_id;

        if (!$resolvedCompanyId) {
            return response()->json([
                'message' => 'The selected employee is not assigned to any company.',
            ], 422);
        }

        // Verify company-level feature toggle
        if (!CompanyProcessSettings::usesFishDeduction($resolvedCompanyId)) {
            return response()->json([
                'message' => 'Fish Deduction feature is disabled for this company in Cybernetic Admin.',
            ], 403);
        }

        [$year, $month] = explode('-', $request->input('applicable_month'));

        // Prevent accidental duplicate entry (same employee, same date, same amount, same month within 5 minutes)
        $duplicate = FishDeduction::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $resolvedCompanyId)
            ->where('amount', $request->input('amount'))
            ->where('deduction_date', $request->input('deduction_date'))
            ->where('applicable_month', $request->input('applicable_month'))
            ->where('created_at', '>=', Carbon::now()->subMinutes(5))
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' => 'An identical fish deduction record was just saved moments ago. Duplicate prevented.',
                'duplicate_id' => $duplicate->id,
            ], 409);
        }

        $record = FishDeduction::create([
            'company_id' => $resolvedCompanyId,
            'employee_id' => $employee->id,
            'amount' => $request->input('amount'),
            'deduction_date' => $request->input('deduction_date'),
            'applicable_month' => $request->input('applicable_month'),
            'salary_year' => (int) $year,
            'salary_month' => (int) $month,
            'description' => $request->input('description'),
            'status' => 'Active',
            'created_by' => $request->user()?->id,
        ]);

        $record->load([
            'employee:id,attendance_employee_no,full_name,name_with_initials',
            'employee.organizationAssignment.department:id,name',
            'company:id,name,company_code',
        ]);

        return response()->json([
            'message' => 'Fish deduction record saved successfully.',
            'data' => $record,
        ], 201);
    }

    /**
     * Display the specified fish deduction record.
     */
    public function show($id)
    {
        $record = FishDeduction::with([
            'employee:id,attendance_employee_no,full_name,name_with_initials',
            'employee.organizationAssignment.department:id,name',
            'company:id,name,company_code',
            'creator:id,name,email',
            'updater:id,name,email',
        ])->findOrFail($id);

        return response()->json(['data' => $record]);
    }

    /**
     * Update the specified fish deduction record.
     */
    public function update(Request $request, $id)
    {
        $record = FishDeduction::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'deduction_date' => 'required|date',
            'applicable_month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'description' => 'nullable|string|max:1000',
            'status' => 'nullable|in:Active,Processed,Cancelled',
        ], [
            'applicable_month.regex' => 'The applicable month must be in YYYY-MM format (e.g. 2026-10).',
            'amount.min' => 'The deduction amount must be greater than zero.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify company-level feature toggle
        if ($record->company_id && !CompanyProcessSettings::usesFishDeduction($record->company_id)) {
            return response()->json([
                'message' => 'Fish Deduction feature is disabled for this company in Cybernetic Admin.',
            ], 403);
        }

        [$year, $month] = explode('-', $request->input('applicable_month'));

        $record->update([
            'amount' => $request->input('amount'),
            'deduction_date' => $request->input('deduction_date'),
            'applicable_month' => $request->input('applicable_month'),
            'salary_year' => (int) $year,
            'salary_month' => (int) $month,
            'description' => $request->input('description'),
            'status' => $request->input('status', $record->status),
            'updated_by' => $request->user()?->id,
        ]);

        $record->load([
            'employee:id,attendance_employee_no,full_name,name_with_initials',
            'employee.organizationAssignment.department:id,name',
            'company:id,name,company_code',
            'updater:id,name,email',
        ]);

        return response()->json([
            'message' => 'Fish deduction record updated successfully.',
            'data' => $record,
        ]);
    }

    /**
     * Remove the specified fish deduction record from storage.
     */
    public function destroy(Request $request, $id)
    {
        $record = FishDeduction::findOrFail($id);

        if ($record->company_id && !CompanyProcessSettings::usesFishDeduction($record->company_id)) {
            return response()->json([
                'message' => 'Fish Deduction feature is disabled for this company in Cybernetic Admin.',
            ], 403);
        }

        $record->update(['updated_by' => $request->user()?->id]);
        $record->delete();

        return response()->json([
            'message' => 'Fish deduction record deleted successfully.',
        ]);
    }

    /**
     * Get monthly fish deduction summary for an employee or company.
     */
    public function monthlySummary(Request $request)
    {
        $companyId = $request->query('company_id');
        $employeeId = $request->query('employee_id');
        $year = (int) ($request->query('year') ?? date('Y'));
        $month = (int) ($request->query('month') ?? date('n'));

        $query = FishDeduction::query()
            ->where('salary_year', $year)
            ->where('salary_month', $month)
            ->where('status', '!=', 'Cancelled');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }

        $total = (float) $query->sum('amount');
        $records = $query->with('employee:id,attendance_employee_no,full_name')->get();

        return response()->json([
            'salary_year' => $year,
            'salary_month' => $month,
            'total_amount' => round($total, 2),
            'records_count' => $records->count(),
            'records' => $records,
        ]);
    }
}
