<?php

namespace App\Http\Controllers;

use App\Models\shifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ShiftController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $shifts = shifts::all();
        return response()->json(['data' => $shifts]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shift_code' => 'required|string|max:50|alpha_dash|unique:shifts,shift_code',
            'shift_description' => 'required|string|max:255',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'midnight_roster' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $shift = shifts::create([
            'shift_code' => $request->shift_code,
            'shift_description' => $request->shift_description,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'midnight_roster' => $request->midnight_roster ?? false,
        ]);

        return response()->json([
            'message' => 'Shift created successfully',
            'data' => $shift
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $shift = shifts::find($id);
        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }
        return response()->json(['data' => $shift]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $shift = shifts::find($id);
        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'shift_code' => 'required|string|max:50|alpha_dash|unique:shifts,shift_code,'.$id,
            'shift_description' => 'required|string|max:255',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'midnight_roster' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $shift->update([
            'shift_code' => $request->shift_code,
            'shift_description' => $request->shift_description,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'midnight_roster' => $request->midnight_roster,
        ]);

        return response()->json([
            'message' => 'Shift updated successfully',
            'data' => $shift
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $shift = shifts::find($id);
        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }
        $shift->delete();
        return response()->json(['message' => 'Shift deleted successfully'], 200);
    }
}