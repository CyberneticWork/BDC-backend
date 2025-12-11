<?php

namespace App\Http\Controllers;

use App\Models\shifts;
use App\Models\ShiftOvertimeRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ShiftOvertimeRateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $overtimeRates = ShiftOvertimeRate::with(['shift'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($rate) {
                return [
                    'id' => $rate->id,
                    'shift_id' => $rate->shift_id,
                    'shift_code' => $rate->shift->shift_code ?? null,
                    'shift_description' => $rate->shift->shift_description ?? null,
                    'shift_hours_per_day' => $rate->shift_hours_per_day,
                    'working_days_per_month' => $rate->working_days_per_month,
                    'ot_multiplier' => $rate->ot_multiplier,
                    'holiday_multiplier' => $rate->holiday_multiplier,
                    'ignore_hours_threshold' => $rate->ignore_hours_threshold,
                    'created_at' => $rate->created_at,
                ];
            });

        return response()->json([
            'data' => $overtimeRates,
            'message' => 'Shift overtime rates retrieved successfully'
        ], 200);
    }

    /**
     * Get all shifts for dropdown
     */
    public function getShifts()
    {
        $shifts = shifts::select('id', 'shift_code', 'shift_description', 'start_time', 'end_time')
            ->orderBy('shift_code')
            ->get();

        return response()->json([
            'data' => $shifts,
            'message' => 'Shifts retrieved successfully'
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shift_id' => [
                'required',
                'exists:shifts,id',
                function ($attribute, $value, $fail) {
                    // Check for existing non-soft-deleted records only
                    $exists = ShiftOvertimeRate::where('shift_id', $value)
                        ->whereNull('deleted_at')
                        ->exists();
                    
                    if ($exists) {
                        $fail('A shift overtime rate configuration already exists for this shift.');
                    }
                }
            ],
            'shift_hours_per_day' => 'required|numeric|min:1|max:24',
            'working_days_per_month' => 'required|numeric|min:1|max:31',
            'ot_multiplier' => 'required|numeric|min:1|max:5',
            'holiday_multiplier' => 'required|numeric|min:1|max:5',
            'ignore_hours_threshold' => 'nullable|array',
            'ignore_hours_threshold.hours' => 'nullable|numeric|min:0|max:24',
            'ignore_hours_threshold.minutes' => 'nullable|numeric|min:0|max:59'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Check if there's a soft-deleted record we can restore instead
            $softDeleted = ShiftOvertimeRate::withTrashed()
                ->where('shift_id', $request->shift_id)
                ->whereNotNull('deleted_at')
                ->first();

            if ($softDeleted) {
                // Restore and update the soft-deleted record
                $softDeleted->restore();
                $overtimeRate = $softDeleted;
                $overtimeRate->update([
                    'shift_hours_per_day' => $request->shift_hours_per_day,
                    'working_days_per_month' => $request->working_days_per_month,
                    'ot_multiplier' => $request->ot_multiplier,
                    'holiday_multiplier' => $request->holiday_multiplier,
                    'ignore_hours_threshold' => $request->ignore_hours_threshold
                ]);
            } else {
                // Create new record
                $overtimeRate = ShiftOvertimeRate::create([
                    'shift_id' => $request->shift_id,
                    'shift_hours_per_day' => $request->shift_hours_per_day,
                    'working_days_per_month' => $request->working_days_per_month,
                    'ot_multiplier' => $request->ot_multiplier,
                    'holiday_multiplier' => $request->holiday_multiplier,
                    'ignore_hours_threshold' => $request->ignore_hours_threshold
                ]);
            }

            DB::commit();

            $overtimeRate->load('shift');

            return response()->json([
                'data' => $overtimeRate,
                'message' => 'Shift overtime rate created successfully'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to create shift overtime rate: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $overtimeRate = ShiftOvertimeRate::with(['shift'])->find($id);

            if (!$overtimeRate) {
                return response()->json([
                    'message' => 'Shift overtime rate not found'
                ], 404);
            }

            return response()->json([
                'data' => $overtimeRate,
                'message' => 'Shift overtime rate retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve shift overtime rate: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $overtimeRate = ShiftOvertimeRate::find($id);

            if (!$overtimeRate) {
                return response()->json([
                    'message' => 'Shift overtime rate not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'shift_id' => [
                    'required',
                    'exists:shifts,id',
                    function ($attribute, $value, $fail) use ($id) {
                        // Check for existing non-soft-deleted records excluding current record
                        $exists = ShiftOvertimeRate::where('shift_id', $value)
                            ->where('id', '!=', $id)
                            ->whereNull('deleted_at')
                            ->exists();
                        
                        if ($exists) {
                            $fail('A shift overtime rate configuration already exists for this shift.');
                        }
                    }
                ],
                'shift_hours_per_day' => 'required|numeric|min:1|max:24',
                'working_days_per_month' => 'required|numeric|min:1|max:31',
                'ot_multiplier' => 'required|numeric|min:1|max:5',
                'holiday_multiplier' => 'required|numeric|min:1|max:5',
                'ignore_hours_threshold' => 'nullable|array',
                'ignore_hours_threshold.hours' => 'nullable|numeric|min:0|max:24',
                'ignore_hours_threshold.minutes' => 'nullable|numeric|min:0|max:59'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $overtimeRate->update([
                'shift_id' => $request->shift_id,
                'shift_hours_per_day' => $request->shift_hours_per_day,
                'working_days_per_month' => $request->working_days_per_month,
                'ot_multiplier' => $request->ot_multiplier,
                'holiday_multiplier' => $request->holiday_multiplier,
                'ignore_hours_threshold' => $request->ignore_hours_threshold
            ]);

            DB::commit();

            $overtimeRate->load('shift');

            return response()->json([
                'data' => $overtimeRate,
                'message' => 'Shift overtime rate updated successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to update shift overtime rate: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $overtimeRate = ShiftOvertimeRate::find($id);

            if (!$overtimeRate) {
                return response()->json([
                    'message' => 'Shift overtime rate not found'
                ], 404);
            }

            $overtimeRate->delete();

            return response()->json([
                'message' => 'Shift overtime rate deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete shift overtime rate: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get overtime rate by shift ID
     */
    public function getByShiftId($shiftId)
    {
        try {
            $shift = shifts::find($shiftId);
            if (!$shift) {
                return response()->json([
                    'message' => 'Shift not found'
                ], 404);
            }

            $overtimeRate = ShiftOvertimeRate::with(['shift'])
                ->where('shift_id', $shiftId)
                ->first();

            if (!$overtimeRate) {
                return response()->json([
                    'message' => 'Shift overtime rate not found for this shift'
                ], 404);
            }

            return response()->json([
                'data' => $overtimeRate,
                'message' => 'Shift overtime rate retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error in getByShiftId: ' . $e->getMessage(), [
                'shift_id' => $shiftId,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve shift overtime rate: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate rates for a specific employee and shift
     */
    public function calculateRates(Request $request, $shiftId)
    {
        $validator = Validator::make($request->all(), [
            'basic_salary' => 'required|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $overtimeRate = ShiftOvertimeRate::where('shift_id', $shiftId)->first();

            if (!$overtimeRate) {
                return response()->json([
                    'message' => 'Shift overtime rate configuration not found for this shift'
                ], 404);
            }

            $basicSalary = $request->basic_salary;
            $hourlyRate = $overtimeRate->calculateHourlyRate($basicSalary);
            $otRate = $overtimeRate->calculateOtRate($basicSalary);
            $holidayRate = $overtimeRate->calculateHolidayRate($basicSalary);

            return response()->json([
                'data' => [
                    'basic_salary' => $basicSalary,
                    'shift_hours_per_day' => $overtimeRate->shift_hours_per_day,
                    'working_days_per_month' => $overtimeRate->working_days_per_month,
                    'total_monthly_hours' => $overtimeRate->shift_hours_per_day * $overtimeRate->working_days_per_month,
                    'hourly_rate' => round($hourlyRate, 2),
                    'ot_multiplier' => $overtimeRate->ot_multiplier,
                    'ot_rate' => round($otRate, 2),
                    'holiday_multiplier' => $overtimeRate->holiday_multiplier,
                    'holiday_rate' => round($holidayRate, 2),
                    'ignore_hours_threshold' => $overtimeRate->ignore_hours_threshold,
                ],
                'message' => 'Rates calculated successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to calculate rates: ' . $e->getMessage()
            ], 500);
        }
    }
}
