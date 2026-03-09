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
        //
    }

    public function destroy(string $id)
    {
        //
    }
}

/*
namespace App\Http\Controllers;

use App\Models\over_time;
use App\Models\time_card;
use App\Models\shifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

                'morning_ot' => $overtime->morning_ot,
                'morning_ot_special' => $overtime->morning_ot_special,
                'evening_ot' => $overtime->afternoon_ot,
                'evening_ot_special' => $overtime->evening_ot_special,

                'holiday_ot_hours' => (float) ($overtime->holiday_ot_hours ?? 0),
                 'holiday_ot_amount' => (float) ($overtime->holiday_ot_amount ?? 0),

                'special_ot' => round(
                    (float) ($overtime->morning_ot_special ?? 0) +
                    (float) ($overtime->evening_ot_special ?? 0),
                    2
                ),

                'total_ot' => $overtime->ot_hours,

                'morning_ot_amount' => $overtime->morning_ot_amount,
                'morning_ot_special_amount' => $overtime->morning_ot_special_amount,
                'evening_ot_amount' => $overtime->evening_ot_amount,
                'evening_ot_special_amount' => $overtime->evening_ot_special_amount,
                'total_ot_amount' => $overtime->total_ot_amount,

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
        //
    }

    
    public function destroy(string $id)
    {
        //
    }
}
*/