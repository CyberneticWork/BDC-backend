<?php

namespace App\Http\Controllers;

use App\Models\LeaveSetting;
use App\Models\LeaveSettingQuarter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeaveSettingController extends Controller
{
    /**
     * Display a listing of leave settings
     */
    public function index(): JsonResponse
    {
        $settings = LeaveSetting::with(['quarters.leaveTypes'])
            ->orderBy('employee_type')
            ->get();
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
            'quarters.*.quarter_number' => ['required_with:quarters', 'integer', 'min:1'],
            'quarters.*.name' => ['nullable', 'string', 'max:150'],
            'quarters.*.start_month' => ['required_with:quarters', 'integer', 'between:1,12'],
            'quarters.*.end_month' => ['required_with:quarters', 'integer', 'between:1,12'],
            'quarters.*.leave_days' => ['nullable', 'integer', 'min:0'],
            'quarters.*.leave_types' => ['nullable', 'array'],
            'quarters.*.leave_types.*.type' => ['nullable', 'string', 'max:50'],
            'quarters.*.leave_types.*.name' => ['required_with:quarters.*.leave_types', 'string', 'max:150'],
            'quarters.*.leave_types.*.days' => ['required_with:quarters.*.leave_types', 'integer', 'min:0'],
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
            if (!$request->has('number_of_quarters') || empty($validated['quarters'])) {
                return response()->json([
                    'message' => 'Number of quarters and quarter details are required for permanent employees'
                ], 422);
            }
        }

        $quarters = $validated['quarters'] ?? [];

        foreach ($quarters as $quarter) {
            if (($quarter['start_month'] ?? 1) > ($quarter['end_month'] ?? 12)) {
                return response()->json([
                    'message' => 'Quarter end month must be greater than or equal to the start month.'
                ], 422);
            }
        }

        if ($request->employee_type === 'permanent' && $request->filled('number_of_quarters')) {
            if ((int) $request->number_of_quarters !== count($quarters)) {
                return response()->json([
                    'message' => 'Number of quarters must match the quarters provided.'
                ], 422);
            }
        }

        $normalizedQuarters = $this->normalizeQuarters($quarters);
        $settingData = Arr::except($validated, ['quarters']);

        $setting = DB::transaction(function () use ($settingData, $normalizedQuarters) {
            $setting = LeaveSetting::create($settingData);

            foreach ($normalizedQuarters as $quarterData) {
                $leaveTypes = $quarterData['leave_types'] ?? [];
                $quarter = $setting->quarters()->create(Arr::only($quarterData, [
                    'quarter_number',
                    'name',
                    'start_month',
                    'end_month',
                    'leave_days',
                ]));

                foreach ($leaveTypes as $leaveType) {
                    $quarter->leaveTypes()->create([
                        'type_key' => $leaveType['type'],
                        'name' => $leaveType['name'],
                        'days' => $leaveType['days'],
                    ]);
                }
            }

            return $setting->fresh(['quarters.leaveTypes']);
        });

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
        return response()->json([
            'data' => $leaveSetting->load('quarters.leaveTypes')
        ]);
    }

    /**
     * Get leave setting by employee type
     */
    public function getByType(string $type): JsonResponse
    {
        $setting = LeaveSetting::with(['quarters.leaveTypes'])
            ->where('employee_type', $type)
            ->first();
        
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
            'quarters.*.id' => ['nullable', 'integer', 'exists:leave_setting_quarters,id'],
            'quarters.*.quarter_number' => ['required_with:quarters', 'integer', 'min:1'],
            'quarters.*.name' => ['nullable', 'string', 'max:150'],
            'quarters.*.start_month' => ['required_with:quarters', 'integer', 'between:1,12'],
            'quarters.*.end_month' => ['required_with:quarters', 'integer', 'between:1,12'],
            'quarters.*.leave_days' => ['nullable', 'integer', 'min:0'],
            'quarters.*.leave_types' => ['nullable', 'array'],
            'quarters.*.leave_types.*.id' => ['nullable', 'integer', 'exists:leave_setting_quarter_leave_types,id'],
            'quarters.*.leave_types.*.type' => ['nullable', 'string', 'max:50'],
            'quarters.*.leave_types.*.name' => ['required_with:quarters.*.leave_types', 'string', 'max:150'],
            'quarters.*.leave_types.*.days' => ['required_with:quarters.*.leave_types', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $quarters = $validated['quarters'] ?? null;
        $targetType = $validated['employee_type'] ?? $leaveSetting->employee_type;

        if ($targetType === 'probation') {
            $annual = $validated['annual_leave_days'] ?? $leaveSetting->annual_leave_days;
            if ($annual === null) {
                return response()->json([
                    'message' => 'Annual leave days is required for probation employees'
                ], 422);
            }
        }

        if ($targetType === 'permanent') {
            $quarterCount = $validated['number_of_quarters'] ?? $leaveSetting->number_of_quarters;
            if ($quarterCount && is_array($quarters)) {
                if (empty($quarters)) {
                    return response()->json([
                        'message' => 'Quarter details are required for permanent employees'
                    ], 422);
                }
                if ((int) $quarterCount !== count($quarters)) {
                    return response()->json([
                        'message' => 'Number of quarters must match the quarters provided.'
                    ], 422);
                }
            }

            if ($quarterCount === null && $quarters !== null) {
                return response()->json([
                    'message' => 'Number of quarters is required when providing quarter details.'
                ], 422);
            }
        }

        if (is_array($quarters)) {
            foreach ($quarters as $quarter) {
                if (($quarter['start_month'] ?? 1) > ($quarter['end_month'] ?? 12)) {
                    return response()->json([
                        'message' => 'Quarter end month must be greater than or equal to the start month.'
                    ], 422);
                }
            }
        }

        $normalizedQuarters = is_array($quarters) ? $this->normalizeQuarters($quarters) : null;
        $settingData = Arr::except($validated, ['quarters']);

        $updated = DB::transaction(function () use ($leaveSetting, $settingData, $normalizedQuarters) {
            $leaveSetting->update($settingData);

            if (is_array($normalizedQuarters)) {
                $this->syncQuarters($leaveSetting, $normalizedQuarters);
            }

            return $leaveSetting->fresh(['quarters.leaveTypes']);
        });

        return response()->json([
            'message' => 'Leave setting updated successfully',
            'data' => $updated
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
        $settings = LeaveSetting::active()
            ->with('quarters')
            ->get()
            ->map(function ($setting) {
            return [
                'employee_type' => $setting->employee_type,
                'total_leave_days' => $setting->total_leave_days,
                'is_quarter_based' => $setting->employee_type === 'permanent',
                'number_of_quarters' => $setting->number_of_quarters,
            ];
        });

        return response()->json(['data' => $settings]);
    }

    private function normalizeQuarters(array $quarters): array
    {
        return collect($quarters)->map(function ($quarter) {
            $startMonth = (int) ($quarter['start_month'] ?? 1);
            $endMonth = (int) ($quarter['end_month'] ?? $startMonth);
            $leaveTypes = collect($quarter['leave_types'] ?? [])->map(function ($leaveType) {
                return [
                    'id' => $leaveType['id'] ?? null,
                    'type' => $leaveType['type'] ?? null,
                    'name' => $leaveType['name'] ?? ($leaveType['type'] ?? 'Custom'),
                    'days' => (int) ($leaveType['days'] ?? 0),
                ];
            })->toArray();

            $leaveDays = $quarter['leave_days'] ?? array_sum(array_column($leaveTypes, 'days'));

            return [
                'id' => $quarter['id'] ?? null,
                'quarter_number' => (int) ($quarter['quarter_number'] ?? 0),
                'name' => $quarter['name'] ?? $this->formatMonthRange($startMonth, $endMonth),
                'start_month' => $startMonth,
                'end_month' => $endMonth,
                'leave_days' => (int) $leaveDays,
                'leave_types' => $leaveTypes,
            ];
        })->toArray();
    }

    private function syncQuarters(LeaveSetting $leaveSetting, array $quarters): void
    {
        if (empty($quarters)) {
            $leaveSetting->quarters()->delete();
            return;
        }

        $quarterIds = collect($quarters)->pluck('id')->filter()->all();

        if (!empty($quarterIds)) {
            $leaveSetting->quarters()
                ->whereNotIn('id', $quarterIds)
                ->delete();
        } else {
            $leaveSetting->quarters()->delete();
        }

        foreach ($quarters as $quarterData) {
            $leaveTypes = $quarterData['leave_types'] ?? [];
            if (!empty($quarterData['id'])) {
                $quarter = $leaveSetting->quarters()->whereKey($quarterData['id'])->first();
                if (!$quarter) {
                    continue;
                }

                $quarter->update(Arr::only($quarterData, [
                    'quarter_number',
                    'name',
                    'start_month',
                    'end_month',
                    'leave_days',
                ]));
            } else {
                $quarter = $leaveSetting->quarters()->create(Arr::only($quarterData, [
                    'quarter_number',
                    'name',
                    'start_month',
                    'end_month',
                    'leave_days',
                ]));
            }

            $this->syncQuarterLeaveTypes($quarter, $leaveTypes);
        }
    }

    private function syncQuarterLeaveTypes(LeaveSettingQuarter $quarter, array $leaveTypes): void
    {
        if (empty($leaveTypes)) {
            $quarter->leaveTypes()->delete();
            $quarter->update(['leave_days' => 0]);
            return;
        }

        $typeIds = collect($leaveTypes)->pluck('id')->filter()->all();

        if (!empty($typeIds)) {
            $quarter->leaveTypes()
                ->whereNotIn('id', $typeIds)
                ->delete();
        } else {
            $quarter->leaveTypes()->delete();
        }

        foreach ($leaveTypes as $leaveTypeData) {
            $payload = [
                'type_key' => $leaveTypeData['type'],
                'name' => $leaveTypeData['name'],
                'days' => $leaveTypeData['days'],
            ];

            if (!empty($leaveTypeData['id'])) {
                $existing = $quarter->leaveTypes()->whereKey($leaveTypeData['id'])->first();
                if ($existing) {
                    $existing->update($payload);
                    continue;
                }
            }

            $quarter->leaveTypes()->create($payload);
        }

        $quarter->update([
            'leave_days' => array_sum(array_column($leaveTypes, 'days')),
        ]);
    }

    private function formatMonthRange(int $startMonth, int $endMonth): string
    {
        $months = [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ];

        $start = $months[$startMonth] ?? 'Month '.$startMonth;
        $end = $months[$endMonth] ?? 'Month '.$endMonth;

        return $startMonth === $endMonth ? $start : $start.' to '.$end;
    }
}