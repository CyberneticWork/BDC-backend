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
                    'normal_hours_rate' => $rate->normal_hours_rate,
                    'ot_rate' => $rate->ot_rate,
                    'holiday_rate' => $rate->holiday_rate,
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
            'shift_id' => 'required|exists:shifts,id|unique:shift_overtime_rates,shift_id',
            'normal_hours_rate' => 'required|numeric|min:0|max:9999.99',
            'ot_rate' => 'required|numeric|min:0|max:9999.99',
            'holiday_rate' => 'required|numeric|min:0|max:9999.99',
            'ignore_hours_threshold' => 'required|numeric|min:0|max:99.99'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $overtimeRate = ShiftOvertimeRate::create([
                'shift_id' => $request->shift_id,
                'normal_hours_rate' => $request->normal_hours_rate,
                'ot_rate' => $request->ot_rate,
                'holiday_rate' => $request->holiday_rate,
                'ignore_hours_threshold' => $request->ignore_hours_threshold
            ]);

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
                'shift_id' => 'required|exists:shifts,id|unique:shift_overtime_rates,shift_id,' . $id,
                'normal_hours_rate' => 'required|numeric|min:0|max:9999.99',
                'ot_rate' => 'required|numeric|min:0|max:9999.99',
                'holiday_rate' => 'required|numeric|min:0|max:9999.99',
                'ignore_hours_threshold' => 'required|numeric|min:0|max:99.99'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $overtimeRate->update([
                'shift_id' => $request->shift_id,
                'normal_hours_rate' => $request->normal_hours_rate,
                'ot_rate' => $request->ot_rate,
                'holiday_rate' => $request->holiday_rate,
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
            // Validate shift exists first
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
}
