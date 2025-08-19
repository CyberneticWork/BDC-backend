<?php

namespace App\Http\Controllers;

use App\Models\over_time;
use App\Models\time_card;
use App\Models\shifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OvertimeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $overtimes = over_time::with([
                'employee.compensation',
                'employee.organizationAssignment.company',
                'shift',
                'timeCard'
            ])
            ->get()
            ->map(function ($overtime) {
                // Get the OUT time card (the one that created this overtime record)
                $outTimeCard = $overtime->timeCard;
                
                // Find the corresponding IN time card
                $inTimeCard = null;
                if ($outTimeCard) {
                    // If we have actual_date, use that to find the IN record (cross-day scenario)
                    if ($outTimeCard->actual_date) {
                        $inTimeCard = time_card::where('employee_id', $overtime->employee_id)
                            ->where('date', $outTimeCard->actual_date)
                            ->where('status', 'IN')
                            ->orderBy('time', 'desc')
                            ->first();
                    } else {
                        // Same day scenario - find the IN record from the same date
                        $inTimeCard = time_card::where('employee_id', $overtime->employee_id)
                            ->where('date', $outTimeCard->date)
                            ->where('status', 'IN')
                            ->orderBy('time', 'desc')
                            ->first();
                    }
                }
                
                $shift = $overtime->shift;
                
                // Get company information
                $company = $overtime->employee->organizationAssignment->company ?? null;
                
                // For date display, prioritize the actual_date for cross-day scenarios
                // This is the date when the shift actually started (IN record date)
                $displayDate = ($outTimeCard && $outTimeCard->actual_date) 
                    ? $outTimeCard->actual_date 
                    : ($outTimeCard ? $outTimeCard->date : null);
                
                return [
                    'id' => $overtime->id,
                    'employee_id' => $overtime->employee_id,
                    'employee_name' => $overtime->employee->full_name ?? null,
                    'employee_no' => $overtime->employee->attendance_employee_no ?? null,
                    // Add company information for filtering
                    'company_id' => $company ? $company->id : null,
                    'company_name' => $company ? $company->name : null,
                    'date' => $displayDate, // Use the appropriate date for display
                    'in_date' => $inTimeCard ? $inTimeCard->date : null, // Keep the actual IN date 
                    'out_date' => $outTimeCard ? $outTimeCard->date : null, // Keep the actual OUT date
                    'actual_date' => $outTimeCard ? $outTimeCard->actual_date : null,
                    'is_cross_day' => ($outTimeCard && $outTimeCard->actual_date) ? true : false,
                    'in_time' => $inTimeCard ? $inTimeCard->time : null,
                    'out_time' => $outTimeCard ? $outTimeCard->time : null,
                    'shift_start' => $shift ? date('H:i', strtotime($shift->start_time)) : null,
                    'shift_end' => $shift ? date('H:i', strtotime($shift->end_time)) : null,
                    'working_hours' => $outTimeCard ? $outTimeCard->working_hours : null,
                    'morning_ot' => $overtime->morning_ot,
                    'evening_ot' => $overtime->afternoon_ot,
                    'total_ot' => $overtime->ot_hours,
                    'ot_morning_rate' => $overtime->employee->compensation->ot_morning_rate ?? null,
                    'ot_night_rate' => $overtime->employee->compensation->ot_night_rate ?? null,
                    'status' => $overtime->status,
                    'created_at' => $overtime->created_at,
                ];
            });
        return response()->json($overtimes);
    }

    public function approve(Request $request, $id)
    {
        $overtime = over_time::findOrFail($id);
        if ($request->status == 'approved') {
            $overtime->status = 'approved';
        } else if ($request->status == 'rejected') {
            $overtime->status = 'rejected';
        } else if ($request->status == 'pending') {
            $overtime->status = 'pending';
        } else {
            return response()->json(['message' => 'Invalid status'], 400);
        }
        $overtime->save();
        return response()->json(['message' => 'Overtime approved successfully']);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
