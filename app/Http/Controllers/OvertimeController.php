<?php

namespace App\Http\Controllers;

use App\Models\over_time;
use App\Models\time_card;
use Illuminate\Http\Request;

class OvertimeController extends Controller
{
    public function index()
    {
        $overtimes = over_time::with([
                'employee.compensation',
                'employee.organizationAssignment.company',
                'shift',
                'timeCard'
            ])
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($overtime) {
                $outTimeCard = $overtime->timeCard;

                $inTimeCard = null;
                if ($outTimeCard) {
                    if ($outTimeCard->actual_date) {
                        $inTimeCard = time_card::where('employee_id', $overtime->employee_id)
                            ->where('date', $outTimeCard->actual_date)
                            ->whereIn('status', ['IN', 'Late Coming'])
                            ->orderBy('time', 'desc')
                            ->first();
                    } else {
                        $inTimeCard = time_card::where('employee_id', $overtime->employee_id)
                            ->where('date', $outTimeCard->date)
                            ->whereIn('status', ['IN', 'Late Coming'])
                            ->where('time', '<=', $outTimeCard->time)
                            ->orderBy('time', 'desc')
                            ->first();
                    }
                }

                $shift = $overtime->shift;
                $company = $overtime->employee?->organizationAssignment?->company;

                $displayDate = ($outTimeCard && $outTimeCard->actual_date)
                    ? $outTimeCard->actual_date
                    : ($outTimeCard ? $outTimeCard->date : null);

                // 🔥 FALLBACK LOGIC: අලුත් Columns වලට Data සේව් වෙලා නැත්නම් පරණ එකෙන් ගන්නවා
                $h_shift_hours = (float) ($overtime->holiday_shift_hours ?? 0);
                $h_outside_hours = (float) ($overtime->holiday_outside_hours ?? 0);
                $h_ot_hours = (float) ($overtime->holiday_ot_hours ?? 0);

                if ($h_shift_hours == 0 && $h_outside_hours == 0 && $h_ot_hours > 0) {
                    $h_shift_hours = $h_ot_hours; // පැය ගාණ Shift එකට දානවා
                }

                $h_shift_amount = (float) ($overtime->holiday_shift_amount ?? 0);
                $h_outside_amount = (float) ($overtime->holiday_outside_amount ?? 0);
                $h_ot_amount = (float) ($overtime->holiday_ot_amount ?? 0);

                if ($h_shift_amount == 0 && $h_outside_amount == 0 && $h_ot_amount > 0) {
                    $h_shift_amount = $h_ot_amount; // සල්ලි ගාණ Shift එකට දානවා
                }

                return [
                    'id' => $overtime->id,
                    'employee_id' => $overtime->employee_id,
                    'employee_name' => $overtime->employee?->full_name,
                    'employee_no' => $overtime->employee?->attendance_employee_no,

                    'company_id' => $company ? $company->id : null,
                    'company_name' => $company ? $company->name : null,

                    'date' => $displayDate,
                    'in_date' => $inTimeCard ? $inTimeCard->date : null,
                    'out_date' => $outTimeCard ? $outTimeCard->date : null,
                    'actual_date' => $outTimeCard ? $outTimeCard->actual_date : null,
                    'is_cross_day' => ($outTimeCard && $outTimeCard->actual_date) ? true : false,

                    'in_time' => $inTimeCard ? $inTimeCard->time : null,
                    'out_time' => $outTimeCard ? $outTimeCard->time : null,

                    'shift_start' => $shift ? date('H:i', strtotime($shift->start_time)) : null,
                    'shift_end' => $shift ? date('H:i', strtotime($shift->end_time)) : null,

                    'working_hours' => $outTimeCard ? $outTimeCard->working_hours : null,

                    'morning_ot' => (float) ($overtime->morning_ot ?? 0),
                    'morning_ot_special' => (float) ($overtime->morning_ot_special ?? 0),
                    'evening_ot' => (float) ($overtime->afternoon_ot ?? 0),
                    'evening_ot_special' => (float) ($overtime->evening_ot_special ?? 0),

                    // 🔥 අප්ඩේට් කරපු Fallback අගයන් යැවීම
                    'holiday_shift_hours' => $h_shift_hours,
                    'holiday_outside_hours' => $h_outside_hours,
                    'holiday_ot_hours' => $h_ot_hours,
                    
                    'holiday_shift_amount' => $h_shift_amount,
                    'holiday_outside_amount' => $h_outside_amount,
                    'holiday_ot_amount' => $h_ot_amount,

                    'special_ot' => round(
                        (float) ($overtime->morning_ot_special ?? 0) +
                        (float) ($overtime->evening_ot_special ?? 0),
                        2
                    ),

                    'total_ot' => (float) ($overtime->ot_hours ?? 0),

                    'morning_ot_amount' => (float) ($overtime->morning_ot_amount ?? 0),
                    'morning_ot_special_amount' => (float) ($overtime->morning_ot_special_amount ?? 0),
                    'evening_ot_amount' => (float) ($overtime->evening_ot_amount ?? 0),
                    'evening_ot_special_amount' => (float) ($overtime->evening_ot_special_amount ?? 0),
                    'total_ot_amount' => (float) ($overtime->total_ot_amount ?? 0),

                    'ot_morning_rate' => $overtime->employee?->compensation?->ot_morning_rate,
                    'ot_morning_special_rate' => $overtime->employee?->compensation?->ot_morning_rate_special,
                    'ot_night_rate' => $overtime->employee?->compensation?->ot_night_rate,
                    'ot_night_special_rate' => $overtime->employee?->compensation?->ot_night_rate_special,

                    'status' => $overtime->status,
                    'created_at' => $overtime->created_at,
                ];
            });

        return response()->json($overtimes);
    }

    public function approve(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected,pending',
        ]);

        $overtime = over_time::whereNull('deleted_at')->findOrFail($id);
        $overtime->status = $request->status;
        $overtime->save();

        return response()->json([
            'message' => 'Overtime status updated successfully',
            'data' => $overtime,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $request->validate([
            'morning_ot' => 'required|numeric|min:0',
            'evening_ot' => 'required|numeric|min:0', 
            'holiday_shift_hours' => 'nullable|numeric|min:0',
            'holiday_outside_hours' => 'nullable|numeric|min:0',
        ]);

        try {
            $overtime = over_time::with('employee.compensation')->findOrFail($id);

            $overtime->morning_ot = $request->morning_ot;
            $overtime->afternoon_ot = $request->evening_ot; 
            
            $h_shift = (float)($request->holiday_shift_hours ?? 0);
            $h_outside = (float)($request->holiday_outside_hours ?? 0);
            
            // 🔥 අලුත් Columns (මේවා DB එකේ අනිවාර්යයෙන් තියෙන්න ඕනේ)
            $overtime->holiday_shift_hours = $h_shift;
            $overtime->holiday_outside_hours = $h_outside;
            $overtime->holiday_ot_hours = $h_shift + $h_outside;

            // 🔥 Null-safe Operator (?->) පාවිච්චි කිරීම
            $rates = $overtime->employee?->compensation;
            $mRate = (float)($rates?->ot_morning_rate ?? 0);
            $eRate = (float)($rates?->ot_night_rate ?? 0);

            $basicSalary = (float)($rates?->basic_salary ?? 0);
            $baseHourlyRate = $basicSalary / 240;
            
            $holidayShiftRate = round($baseHourlyRate * 1.5, 6);
            $holidayOutsideRate = round($baseHourlyRate * 2.0, 6);

            $overtime->morning_ot_amount = round($overtime->morning_ot * $mRate, 2);
            $overtime->evening_ot_amount = round($overtime->afternoon_ot * $eRate, 2);
            
            $shiftAmount = round($h_shift * $holidayShiftRate, 2);
            $outsideAmount = round($h_outside * $holidayOutsideRate, 2);
            
            $overtime->holiday_shift_amount = $shiftAmount;
            $overtime->holiday_outside_amount = $outsideAmount;
            $overtime->holiday_ot_amount = $shiftAmount + $outsideAmount;

            $overtime->ot_hours = $overtime->morning_ot + $overtime->afternoon_ot + ($overtime->morning_ot_special ?? 0) + ($overtime->evening_ot_special ?? 0);
            $overtime->total_ot_amount = $overtime->morning_ot_amount + $overtime->evening_ot_amount + ($overtime->morning_ot_special_amount ?? 0) + ($overtime->evening_ot_special_amount ?? 0);

            $overtime->save();

            return response()->json([
                'message' => 'Overtime updated successfully', 
                'data' => $overtime
            ], 200);

        } catch (\Exception $e) {
            // 🔥 Error එක හරියටම මොකක්ද කියලා Return කරනවා
            return response()->json([
                'message' => 'Error updating overtime: ' . $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }

    public function destroy(string $id) { }
    public function store(Request $request) { }
    public function show(string $id) { }
}


/*
namespace App\Http\Controllers;

use App\Models\over_time;
use App\Models\time_card;
use Illuminate\Http\Request;

class OvertimeController extends Controller
{
    public function index()
    {
        $overtimes = over_time::with([
                'employee.compensation',
                'employee.organizationAssignment.company',
                'shift',
                'timeCard'
            ])
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($overtime) {
                $outTimeCard = $overtime->timeCard;

                $inTimeCard = null;
                if ($outTimeCard) {
                    if ($outTimeCard->actual_date) {
                        $inTimeCard = time_card::where('employee_id', $overtime->employee_id)
                            ->where('date', $outTimeCard->actual_date)
                            ->whereIn('status', ['IN', 'Late Coming'])
                            ->orderBy('time', 'desc')
                            ->first();
                    } else {
                        $inTimeCard = time_card::where('employee_id', $overtime->employee_id)
                            ->where('date', $outTimeCard->date)
                            ->whereIn('status', ['IN', 'Late Coming'])
                            ->where('time', '<=', $outTimeCard->time)
                            ->orderBy('time', 'desc')
                            ->first();
                    }
                }

                $shift = $overtime->shift;
                $company = $overtime->employee->organizationAssignment->company ?? null;

                $displayDate = ($outTimeCard && $outTimeCard->actual_date)
                    ? $outTimeCard->actual_date
                    : ($outTimeCard ? $outTimeCard->date : null);

                return [
                    'id' => $overtime->id,
                    'employee_id' => $overtime->employee_id,
                    'employee_name' => $overtime->employee->full_name ?? null,
                    'employee_no' => $overtime->employee->attendance_employee_no ?? null,

                    'company_id' => $company ? $company->id : null,
                    'company_name' => $company ? $company->name : null,

                    'date' => $displayDate,
                    'in_date' => $inTimeCard ? $inTimeCard->date : null,
                    'out_date' => $outTimeCard ? $outTimeCard->date : null,
                    'actual_date' => $outTimeCard ? $outTimeCard->actual_date : null,
                    'is_cross_day' => ($outTimeCard && $outTimeCard->actual_date) ? true : false,

                    'in_time' => $inTimeCard ? $inTimeCard->time : null,
                    'out_time' => $outTimeCard ? $outTimeCard->time : null,

                    'shift_start' => $shift ? date('H:i', strtotime($shift->start_time)) : null,
                    'shift_end' => $shift ? date('H:i', strtotime($shift->end_time)) : null,

                    'working_hours' => $outTimeCard ? $outTimeCard->working_hours : null,

                    'morning_ot' => (float) ($overtime->morning_ot ?? 0),
                    'morning_ot_special' => (float) ($overtime->morning_ot_special ?? 0),
                    'evening_ot' => (float) ($overtime->afternoon_ot ?? 0),
                    'evening_ot_special' => (float) ($overtime->evening_ot_special ?? 0),

                    'holiday_ot_hours' => (float) ($overtime->holiday_ot_hours ?? 0),
                    'holiday_ot_amount' => (float) ($overtime->holiday_ot_amount ?? 0),

                    'special_ot' => round(
                        (float) ($overtime->morning_ot_special ?? 0) +
                        (float) ($overtime->evening_ot_special ?? 0),
                        2
                    ),

                    'total_ot' => (float) ($overtime->ot_hours ?? 0),

                    'morning_ot_amount' => (float) ($overtime->morning_ot_amount ?? 0),
                    'morning_ot_special_amount' => (float) ($overtime->morning_ot_special_amount ?? 0),
                    'evening_ot_amount' => (float) ($overtime->evening_ot_amount ?? 0),
                    'evening_ot_special_amount' => (float) ($overtime->evening_ot_special_amount ?? 0),
                    'total_ot_amount' => (float) ($overtime->total_ot_amount ?? 0),

                    'ot_morning_rate' => $overtime->employee->compensation->ot_morning_rate ?? null,
                    'ot_morning_special_rate' => $overtime->employee->compensation->ot_morning_rate_special ?? null,
                    'ot_night_rate' => $overtime->employee->compensation->ot_night_rate ?? null,
                    'ot_night_special_rate' => $overtime->employee->compensation->ot_night_rate_special ?? null,

                    'status' => $overtime->status,
                    'created_at' => $overtime->created_at,
                ];
            });

        return response()->json($overtimes);
    }

    public function approve(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected,pending',
        ]);

        $overtime = over_time::whereNull('deleted_at')->findOrFail($id);
        $overtime->status = $request->status;
        $overtime->save();

        return response()->json([
            'message' => 'Overtime status updated successfully',
            'data' => $overtime,
        ]);
    }

    public function store(Request $request)
    {
        //
    }

    public function show(string $id)
    {
        //
    }

   public function update(Request $request, string $id)
    {
        $request->validate([
            'morning_ot' => 'required|numeric|min:0',
            'evening_ot' => 'required|numeric|min:0', // DB එකේ මේක afternoon_ot
            'holiday_ot_hours' => 'required|numeric|min:0',
        ]);

        try {
            // OT Record එක සහ සේවකයාගේ Rates ටික ගන්නවා
            $overtime = over_time::with('employee.compensation')->findOrFail($id);

            // අලුත් පැය ගණන් සෙට් කරනවා
            $overtime->morning_ot = $request->morning_ot;
            $overtime->afternoon_ot = $request->evening_ot; 
            $overtime->holiday_ot_hours = $request->holiday_ot_hours;

            // සේවකයාගේ OT Rates ටික ගන්නවා
            $rates = $overtime->employee->compensation;
            $mRate = (float)($rates->ot_morning_rate ?? 0);
            $eRate = (float)($rates->ot_night_rate ?? 0);
            $holidayRate = (float)($rates->holiday_rate ?? $eRate); // Holiday Rate එකක් නැත්නම් Night Rate එක ගන්නවා

            // අලුත් ගණන් (Amounts) ටික Calculate කරනවා
            $overtime->morning_ot_amount = $overtime->morning_ot * $mRate;
            $overtime->evening_ot_amount = $overtime->afternoon_ot * $eRate;
            $overtime->holiday_ot_amount = $overtime->holiday_ot_hours * $holidayRate;

            // Total පැය ගණන සහ Total ගාණ හදනවා
            $overtime->ot_hours = $overtime->morning_ot + $overtime->afternoon_ot + ($overtime->morning_ot_special ?? 0) + ($overtime->evening_ot_special ?? 0);
            
            $overtime->total_ot_amount = $overtime->morning_ot_amount + $overtime->evening_ot_amount + ($overtime->morning_ot_special_amount ?? 0) + ($overtime->evening_ot_special_amount ?? 0);

            $overtime->save();

            return response()->json([
                'message' => 'Overtime updated successfully', 
                'data' => $overtime
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating overtime: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        //
    }
}

*/

