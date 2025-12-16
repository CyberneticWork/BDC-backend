<?php

namespace App\Http\Controllers;

use App\Models\LeaveSetting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class LeaveSettingController extends Controller
{
    /**
     * Display a listing of leave settings
     */
    public function index(): JsonResponse
    {
        $settings = LeaveSetting::orderBy('employee_type')->get();
        return response()->json(['data' => $settings]);
    }

    /**
     * Store a newly created leave setting
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_type' => ['required', 'in:probation,permanent', 'unique:leave_settings,employee_type'],
            'annual_leave_days' => ['nullable', 'integer', 'min:0'],
            'number_of_quarters' => ['nullable', 'integer', 'min:1', 'max:12'],
            'quarters' => ['nullable', 'array'],
            'quarters.*.quarter_number' => ['required_with:quarters', 'integer'],
            'quarters.*.leave_days' => ['required_with:quarters', 'integer', 'min:0'],
            'quarters.*.name' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        // Validate based on employee type
        if ($request->employee_type === 'probation' && !$request->has('annual_leave_days')) {
            return response()->json([
                'message' => 'Annual leave days is required for probation employees'
            ], 422);
        }

        if ($request->employee_type === 'permanent') {
            if (!$request->has('number_of_quarters') || !$request->has('quarters')) {
                return response()->json([
                    'message' => 'Number of quarters and quarter details are required for permanent employees'
                ], 422);
            }
        }

        $setting = LeaveSetting::create($validated);

        return response()->json([
            'message' => 'Leave setting created successfully',
            'data' => $setting
        ], 201);
    }

    /**
     * Display the specified leave setting
     */
    public function show(LeaveSetting $leaveSetting): JsonResponse
    {
        return response()->json(['data' => $leaveSetting]);
    }

    /**
     * Get leave setting by employee type
     */
    public function getByType(string $type): JsonResponse
    {
        $setting = LeaveSetting::where('employee_type', $type)->first();
        
        if (!$setting) {
            return response()->json([
                'message' => 'Leave setting not found for this employee type'
            ], 404);
        }

        return response()->json(['data' => $setting]);
    }

    /**
     * Update the specified leave setting
     */
    public function update(Request $request, LeaveSetting $leaveSetting): JsonResponse
    {
        $validated = $request->validate([
            'employee_type' => [
                'sometimes',
                'in:probation,permanent',
                Rule::unique('leave_settings', 'employee_type')->ignore($leaveSetting->id)
            ],
            'annual_leave_days' => ['nullable', 'integer', 'min:0'],
            'number_of_quarters' => ['nullable', 'integer', 'min:1', 'max:12'],
            'quarters' => ['nullable', 'array'],
            'quarters.*.quarter_number' => ['required_with:quarters', 'integer'],
            'quarters.*.leave_days' => ['required_with:quarters', 'integer', 'min:0'],
            'quarters.*.name' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $leaveSetting->update($validated);

        return response()->json([
            'message' => 'Leave setting updated successfully',
            'data' => $leaveSetting->fresh()
        ]);
    }

    /**
     * Remove the specified leave setting
     */
    public function destroy(LeaveSetting $leaveSetting): JsonResponse
    {
        $leaveSetting->delete();

        return response()->json([
            'message' => 'Leave setting deleted successfully'
        ]);
    }

    /**
     * Get active leave settings summary
     */
    public function getActiveSummary(): JsonResponse
    {
        $settings = LeaveSetting::active()->get()->map(function ($setting) {
            return [
                'employee_type' => $setting->employee_type,
                'total_leave_days' => $setting->total_leave_days,
                'is_quarter_based' => $setting->employee_type === 'permanent',
                'number_of_quarters' => $setting->number_of_quarters,
            ];
        });

        return response()->json(['data' => $settings]);
    }
}