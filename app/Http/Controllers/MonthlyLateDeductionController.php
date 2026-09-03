<?php

namespace App\Http\Controllers;

use App\Services\MonthlyLateDeductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MonthlyLateDeductionController extends Controller
{
    public function __construct(private MonthlyLateDeductionService $service)
    {
    }

    public function rules()
    {
        return response()->json([
            'rules' => $this->service->rulesMeta(),
            'thresholds' => [
                'free_minutes' => MonthlyLateDeductionService::FREE_MINUTES,
                'one_short_max_minutes' => MonthlyLateDeductionService::ONE_SHORT_MAX,
                'two_short_max_minutes' => MonthlyLateDeductionService::TWO_SHORT_MAX,
                'short_leave_days' => MonthlyLateDeductionService::SHORT_LEAVE_DAYS,
            ],
        ]);
    }

    public function preview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
            'company_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $this->service->preview(
            (int) $request->year,
            (int) $request->month,
            $request->filled('company_id') ? (int) $request->company_id : null,
            $request->search
        );

        return response()->json($data);
    }

    public function apply(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
            'company_id' => 'nullable|integer',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $result = $this->service->apply(
            (int) $request->year,
            (int) $request->month,
            $request->filled('company_id') ? (int) $request->company_id : null,
            $request->input('employee_ids', [])
        );

        return response()->json([
            'message' => 'Monthly late deductions applied successfully.',
            ...$result,
        ]);
    }

    public function employeeDetail(Request $request, int $employeeId)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $year = (int) $request->year;
        $month = (int) $request->month;
        $start = \Carbon\Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $calc = $this->service->calculateForEmployee($employeeId, $start, $end, $year);
        $employee = \App\Models\employee::with('organizationAssignment.company')->find($employeeId);

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        return response()->json([
            'employee_id' => $employeeId,
            'employee_no' => $employee->attendance_employee_no,
            'employee_name' => $employee->display_name
                ?? $employee->name_with_initials
                ?? $employee->full_name,
            'month' => $month,
            'year' => $year,
            'already_applied' => $this->service->isApplied($employeeId, $year, $month),
            ...$calc,
        ]);
    }
}
