<?php

namespace App\Http\Controllers;

use App\Models\over_time;
use App\Models\time_card;
use App\Models\employee;
use App\Models\roster;
use App\Models\shifts;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;
use App\Models\absence;
use App\Exports\AttendanceTemplateExport;
use App\Models\leave_master;
use App\Services\Overtime\OvertimeCalculator;

// use Maatwebsite\Excel\Facades\Excel;

class TimeCardController extends Controller
{
    protected OvertimeCalculator $overtimeCalculator;

    public function __construct(OvertimeCalculator $overtimeCalculator)
    {
        $this->overtimeCalculator = $overtimeCalculator;
    }

    public function index(Request $request)
    {
        $cards = time_card::with(['employee.organizationAssignment.department'])
            ->whereNull('deleted_at') // exclude soft-deleted rows
            ->get()
            ->map(function ($card) {
                return [
                    'id' => $card->id, // <-- Add this line
                    'empNo' => $card->employee->attendance_employee_no ?? null,
                    'name' => $card->employee->full_name ?? null,
                    'fingerprintClock' => $card->fingerprint_clock?? null, // Update if you have this field
                    'time' => $card->time,
                    'date' => $card->date,
                    'entry' => $card->entry,
                    'inOut' => $card->entry == 1 ? 'IN' : ($card->entry == 2 ? 'OUT' : null),
                    'department' => $card->employee->organizationAssignment->department->name ?? null,
                    'status' => $card->status,
                ];
            });

        return response()->json($cards);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'time' => 'required',
            'date' => 'required|date',
            'entry' => 'required',
            'status' => 'required',
        ]);

        $employee = employee::find($validated['employee_id']);
        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $org = $employee->organizationAssignment;
        if (!$org) {
            return response()->json(['message' => 'Organization assignment not found'], 404);
        }

        [$roster, $shift] = $this->resolveRosterAndShift($employee, $validated['date']);

        if (!$roster) {
            return response()->json(['message' => 'No shift/roster assigned for this employee on this date'], 422);
        }

        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        try {
            $inputTime = Carbon::createFromFormat('H:i:s', $validated['time']);
        } catch (\Exception $e) {
            try {
                $inputTime = Carbon::createFromFormat('H:i', $validated['time']);
            } catch (\Exception $ex) {
                return response()->json(['message' => 'Invalid time format. Please use HH:mm or HH:mm:ss'], 422);
            }
        }

        $storeTime = $inputTime->format('H:i:s');
        $shiftEnd = Carbon::parse($shift->end_time);

        $entryType = (int) $validated['entry'];
        $status = strtoupper($validated['status']);
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        if ($status === 'OUT') {
            $lastInCard = null;
            $morningOutRecord = Carbon::parse($validated['time'])->hour < 12;

            if ($morningOutRecord) {
                $previousDayIN = time_card::where('employee_id', $employee->id)
                    ->where('status', 'IN')
                    ->where('date', '<', $validated['date'])
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('time_cards as tc')
                            ->whereRaw('tc.actual_date = time_cards.date')
                            ->where('tc.status', 'OUT');
                    })
                    ->orderBy('date', 'desc')
                    ->orderBy('time', 'desc')
                    ->first();

                if ($previousDayIN) {
                    $lastInCard = $previousDayIN;
                } else {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->where('date', $validated['date'])
                        ->where('status', 'IN')
                        ->where('time', '<', $validated['time'])
                        ->orderBy('time', 'desc')
                        ->first();

                    if (!$lastInCard) {
                        $lastInCard = time_card::where('employee_id', $employee->id)
                            ->where('status', 'IN')
                            ->where('date', '<', $validated['date'])
                            ->orderBy('date', 'desc')
                            ->orderBy('time', 'desc')
                            ->first();
                    }
                }
            } else {
                $lastInCard = time_card::where('employee_id', $employee->id)
                    ->where('date', $validated['date'])
                    ->where('status', 'IN')
                    ->orderBy('time', 'desc')
                    ->first();

                if (!$lastInCard) {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->where('status', 'IN')
                        ->where('date', '<', $validated['date'])
                        ->orderBy('date', 'desc')
                        ->orderBy('time', 'desc')
                        ->first();
                }
            }

            if ($lastInCard) {
                $pairedInCard = $lastInCard;
                $lastInDate = Carbon::parse($lastInCard->date);
                $currentDate = Carbon::parse($validated['date']);

                if ($lastInDate->eq($currentDate)) {
                    $inTime = Carbon::parse($lastInCard->time);
                    $outTime = $inputTime;
                    $working_hours = round($inTime->floatDiffInHours($outTime), 2);

                    if ($outTime->lt($shiftEnd)) {
                        $entryType = 0;
                        $status = 'Leave';
                        $pairedInCard = null;
                    } else {
                        $entryType = 2;
                        $status = 'OUT';
                    }
                } else {
                    $inDateTime = Carbon::parse($lastInCard->date . ' ' . $lastInCard->time);
                    $outDateTime = Carbon::parse($validated['date'] . ' ' . $validated['time']);
                    $working_hours = round($inDateTime->floatDiffInHours($outDateTime), 2);

                    $entryType = 2;
                    $status = 'OUT';
                    $actual_date = $lastInCard->date;
                }
            }
        } else {
            $entryType = 1;
            $status = 'IN';
            $working_hours = null;
        }

        $fingerprintClock = now();

        $duplicate = time_card::where('employee_id', $employee->id)
            ->where('date', $validated['date'])
            ->where('time', $storeTime)
            ->where('entry', $entryType)
            ->where('status', $status)
            ->whereNull('deleted_at')
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'Duplicate attendance record. This entry already exists.',
            ], 409);
        }

        $timeCard = time_card::create([
            'employee_id' => $employee->id,
            'time' => $storeTime,
            'date' => $validated['date'],
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'fingerprint_clock' => $fingerprintClock,
            'actual_date' => $actual_date,
        ]);

        if ($status === 'OUT' && $pairedInCard) {
            $referenceDate = $actual_date ?? $pairedInCard->date;
            $this->processOvertimeForOutPunch($employee, $timeCard, $pairedInCard, $shift, $referenceDate);
        }

        return response()->json($timeCard, 201);
    }

    public function attendance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'empno' => ['required','regex:/^[A-Za-z0-9_\-]+$/'], // removed integer constraint
            'date' => 'required|date',
            'time' => 'required|date_format:H:i:s',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rawEmpNo = trim($request->empno);

        // First attempt: attendance_employee_no exact
        $employee = employee::where('attendance_employee_no', $rawEmpNo)->first();

        // Fallback: if purely digits and not found, treat as internal ID
        if (!$employee && ctype_digit($rawEmpNo)) {
            $employee = employee::find((int)$rawEmpNo);
        }

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $org = $employee->organizationAssignment;
        if (!$org) {
            return response()->json(['message' => 'Organization assignment not found'], 404);
        }

        [$roster, $shift] = $this->resolveRosterAndShift($employee, $request->date);

        if (!$roster) {
            return response()->json(['message' => 'No roster assigned for this employee on this date'], 422);
        }

        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        $inputTime = Carbon::createFromFormat('H:i:s', $request->time);

        // Prevent duplicate/close attendance for this employee (±5 min)
        $recentCard = time_card::where('employee_id', $employee->id)
            ->where('date', $request->date)
            ->where(function ($q) use ($inputTime) {
                $q->whereBetween('time', [
                    $inputTime->copy()->subMinutes(5)->format('H:i:s'),
                    $inputTime->copy()->addMinutes(5)->format('H:i:s')
                ]);
            })
            ->orderBy('time', 'desc')
            ->first();

        if ($recentCard) {
            return response()->json([
                'message' => 'You cannot mark attendance within 5 minutes of your previous record.'
            ], 409);
        }

        // Find the last attendance record for this employee
        $lastCard = time_card::where('employee_id', $employee->id)
            ->orderBy('date', 'desc')
            ->orderBy('time', 'desc')
            ->first();

    $entryType = 1; // Default to IN
    $status = 'IN';
    $working_hours = null;
    $storeTime = $inputTime->format('H:i:s');
    $actual_date = null;
    $shiftEnd = Carbon::parse($shift->end_time);
    $pairedInCard = null;

        if ($lastCard && $lastCard->status === 'IN') {
            $pairedInCard = $lastCard;
            $lastInDate = Carbon::parse($lastCard->date);
            $currentDate = Carbon::parse($request->date);

            if ($lastInDate->eq($currentDate)) {
                // Same day: normal OUT/Leave logic
                $inTime = Carbon::parse($lastCard->time);
                $outTime = $inputTime;
                $working_hours = round($inTime->floatDiffInHours($outTime), 2);

                if ($outTime->lt($shiftEnd)) {
                    $entryType = 0; // Leave
                    $status = 'Leave';
                    $pairedInCard = null;
                } else {
                    $entryType = 2; // OUT
                    $status = 'OUT';
                }
            } else {
                // Different day: cross-day OUT
                $inDateTime = Carbon::parse($lastCard->date . ' ' . $lastCard->time);
                $outDateTime = Carbon::parse($request->date . ' ' . $request->time);
                $working_hours = round($inDateTime->floatDiffInHours($outDateTime), 2);

                $entryType = 2; // OUT
                $status = 'OUT';
                $actual_date = $lastCard->date; // Save the last IN's date to actual_date
            }
        } else {
            // No previous IN, or last was OUT/Leave: this is a new IN
            $entryType = 1;
            $status = 'IN';
            $working_hours = null;
        }

        $fingerprintClock = now();

        $duplicate = time_card::where('employee_id', $employee->id)
            ->where('date', $request->date)
            ->where('time', $storeTime)
            ->where('entry', $entryType)
            ->where('status', $status)
            ->whereNull('deleted_at')  // Add this line to exclude soft-deleted records
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'Duplicate attendance record. This entry already exists.',
            ], 409);
        }

        $timeCard = time_card::create([
            'employee_id' => $employee->id,
            'time' => $storeTime,
            'date' => $request->date,
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'fingerprint_clock' => $fingerprintClock,
            'actual_date' => $actual_date, // will be null unless cross-day OUT
        ]);

        if ($status === 'OUT' && $pairedInCard) {
            $referenceDate = $actual_date ?? $pairedInCard->date;
            $this->processOvertimeForOutPunch($employee, $timeCard, $pairedInCard, $shift, $referenceDate);
        }

        return response()->json([
            'message' => 'Attendance marked as ' . $status,
            'data' => $timeCard,
            'employee' => [
                'id' => $employee->id,
                'attendance_employee_no' => $employee->attendance_employee_no,
                'full_name' => $employee->full_name,
                'department' => $org->department_id,
                'sub_department' => $org->sub_department_id,
                'company' => $org->company_id,
            ],
            'shift' => [
                'id' => $shift->id,
                'shift_code' => $shift->shift_code,
                'shift_description' => $shift->shift_description,
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
            ],
            'roster' => $roster,
        ], 201);
    }
    public function searchByEmployee(Request $request)
    {
        $search = $request->query('q');
        if (!$search) {
            return response()->json(['message' => 'Search query is required'], 422);
        }

        // Find employee by NIC or EPF number
        $employee = employee::where('nic', $search)
            ->orWhere('attendance_employee_no', $search)
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        // Fetch time cards for this employee, ordered by date and time (latest first)
        $cards = time_card::with(['employee.organizationAssignment.department'])
            ->where('employee_id', $employee->id)
            ->whereNull('deleted_at') // exclude soft-deleted rows
            ->orderBy('date', 'desc')
            ->orderBy('time', 'desc')
            ->get()
            ->map(function ($card) {
                return [
                    'id' => $card->id,
                    'empNo' => $card->employee->attendance_employee_no ?? null,
                    'name' => $card->employee->full_name ?? null,
                    'fingerprintClock' => null,
                    'time' => $card->time,
                    'date' => $card->date,
                    'entry' => $card->entry,
                    'inOut' => $card->entry == 1 ? 'IN' : ($card->entry == 2 ? 'OUT' : null),
                    'department' => $card->employee->organizationAssignment->department->name ?? null,
                    'status' => $card->status,
                ];
            });

        return response()->json($cards);
    }
    // public function markAbsentees(Request $request)
    // {
    //     $date = $request->input('date');
    //     if (!$date) {
    //         return response()->json(['message' => 'Date is required'], 422);
    //     }

    //     // Get all rosters active on this date
    //     $rosters = roster::whereNull('deleted_at')
    //         ->where(function ($q) use ($date) {
    //             $q->whereNull('date_from')->orWhere('date_from', '<=', $date);
    //         })
    //         ->where(function ($q) use ($date) {
    //             $q->whereNull('date_to')->orWhere('date_to', '>=', $date);
    //         })
    //         ->get();

    //     $absentRecords = [];

    //     foreach ($rosters as $roster) {
    //         // Find employees for this roster
    //         if ($roster->employee_id) {
    //             $employees = employee::where('id', $roster->employee_id)->where('is_active', 1)->get();
    //         } else {
    //             $query = employee::where('is_active', 1);
    //             if ($roster->sub_department_id) {
    //                 $query->whereHas('organizationAssignment', function ($q) use ($roster) {
    //                     $q->where('sub_department_id', $roster->sub_department_id);
    //                 });
    //             } elseif ($roster->department_id) {
    //                 $query->whereHas('organizationAssignment', function ($q) use ($roster) {
    //                     $q->where('department_id', $roster->department_id);
    //                 });
    //             } elseif ($roster->company_id) {
    //                 $query->whereHas('organizationAssignment', function ($q) use ($roster) {
    //                     $q->where('company_id', $roster->company_id);
    //                 });
    //             }
    //             $employees = $query->get();
    //         }

    //         foreach ($employees as $employee) {
    //             // Check if both IN and OUT exist for this date
    //             $hasIn = time_card::where('employee_id', $employee->id)
    //                 ->where('date', $date)
    //                 ->where('entry', 1)
    //                 ->exists();
    //             $hasOut = time_card::where('employee_id', $employee->id)
    //                 ->where('date', $date)
    //                 ->where('entry', 2)
    //                 ->exists();

    //             if (!($hasIn && $hasOut)) {
    //                 // Mark as absent if not already marked
    //                 $alreadyAbsent = time_card::where('employee_id', $employee->id)
    //                     ->where('date', $date)
    //                     ->where('status', 'Absent')
    //                     ->exists();
    //                 if (!$alreadyAbsent) {
    //                     $absent = time_card::create([
    //                         'employee_id' => $employee->id,
    //                         'date' => $date,
    //                         'entry' => 0,
    //                         'status' => 'Absent',
    //                     ]);
    //                     $absentRecords[] = $absent;
    //                 }
    //             }
    //         }
    //     }

    //     // Fetch all absent records for the date, with related details
    //     $absentees = time_card::with([
    //             'employee.organizationAssignment.department',
    //             'employee.organizationAssignment.subDepartment',
    //             'employee.organizationAssignment.company'
    //         ])
    //         ->where('date', $date)
    //         ->where('status', 'Absent')
    //         ->get()
    //         ->map(function ($card) {
    //             return [
    //                 'id' => $card->id, // <-- Add this line
    //                 'empNo' => $card->employee->attendance_employee_no ?? null,
    //                 'name' => $card->employee->full_name ?? null,
    //                 'department' => $card->employee->organizationAssignment->department->name ?? null,
    //                 'sub_department' => $card->employee->organizationAssignment->subDepartment->name ?? null,
    //                 'company' => $card->employee->organizationAssignment->company->name ?? null,
    //                 'date' => $card->date,
    //                 'entry' => $card->entry,
    //                 'status' => $card->status,
    //             };
    //         });

    //     return response()->json([
    //         'message' => 'Absentees marked for date ' . $date,
    //         'absentees' => $absentees,
    //     ]);
    // }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'time' => 'required',
            'entry' => 'required|in:0,1,2',
            'status' => 'required|in:IN,OUT,Absent,Leave',
        ]);

        $timeCard = time_card::findOrFail($id);
        $employee = $timeCard->employee;
        
        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }
        
        $org = $employee->organizationAssignment;
        if (!$org) {
            return response()->json(['message' => 'Organization assignment not found'], 404);
        }

        [$roster, $shift] = $this->resolveRosterAndShift($employee, $validated['date']);

        if (!$roster) {
            return response()->json(['message' => 'No shift/roster assigned for this employee on this date'], 422);
        }

        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        // Parse time input (same logic as store)
        try {
            $inputTime = Carbon::createFromFormat('H:i:s', $validated['time']);
        } catch (\Exception $e) {
            // Try H:i format if H:i:s fails
            try {
                $inputTime = Carbon::createFromFormat('H:i', $validated['time']);
            } catch (\Exception $ex) {
                return response()->json(['message' => 'Invalid time format. Please use HH:mm or HH:mm:ss'], 422);
            }
        }
        $storeTime = $inputTime->format('H:i:s');
        $shiftEnd = Carbon::parse($shift->end_time);

        $entryType = (int)$validated['entry'];
        $status = strtoupper($validated['status']);
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        // Same logic as store function for calculating working hours and status
        if ($status === 'OUT') {
            $lastInCard = null;
            $morningOutRecord = Carbon::parse($validated['time'])->hour < 12;
            
            if ($morningOutRecord) {
                $previousDayIN = time_card::where('employee_id', $employee->id)
                    ->where('status', 'IN')
                    ->where('date', '<', $validated['date'])
                    ->whereNotExists(function($query) {
                        $query->select(DB::raw(1))
                              ->from('time_cards as tc')
                              ->whereRaw('tc.actual_date = time_cards.date')
                              ->where('tc.status', 'OUT');
                    })
                    ->orderBy('date', 'desc')
                    ->orderBy('time', 'desc')
                    ->first();
                    
                if ($previousDayIN) {
                    $lastInCard = $previousDayIN;
                } else {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->where('date', $validated['date'])
                        ->where('status', 'IN')
                        ->where('time', '<', $validated['time']) // Only IN records before this OUT time
                        ->where('id', '!=', $timeCard->id) // Exclude current record
                        ->orderBy('time', 'desc')
                        ->first();
                        
                    // If no same-day IN before this OUT time, look for any previous IN
                    if (!$lastInCard) {
                        $lastInCard = time_card::where('employee_id', $employee->id)
                            ->where('status', 'IN')
                            ->where('date', '<', $validated['date'])
                            ->orderBy('date', 'desc')
                            ->orderBy('time', 'desc')
                            ->first();
                    }
                }
            } else {
                $lastInCard = time_card::where('employee_id', $employee->id)
                    ->where('date', $validated['date'])
                    ->where('status', 'IN')
                    ->where('id', '!=', $timeCard->id) // Exclude current record
                    ->orderBy('time', 'desc')
                    ->first();
            
                if (!$lastInCard) {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->where('status', 'IN')
                        ->where('date', '<', $validated['date'])
                        ->orderBy('date', 'desc')
                        ->orderBy('time', 'desc')
                        ->first();
                }
            }

            if ($lastInCard) {
                $pairedInCard = $lastInCard;
                $lastInDate = Carbon::parse($lastInCard->date);
                $currentDate = Carbon::parse($validated['date']);

                if ($lastInDate->eq($currentDate)) {
                    // Same day: normal OUT/Leave logic
                    $inTime = Carbon::parse($lastInCard->time);
                    $outTime = $inputTime;
                    $working_hours = round($inTime->floatDiffInHours($outTime), 2);

                    if ($outTime->lt($shiftEnd)) {
                        $entryType = 0; // Leave
                        $status = 'Leave';
                        $pairedInCard = null;
                    } else {
                        $entryType = 2; // OUT
                        $status = 'OUT';
                    }
                } else {
                    // Different day: cross-day OUT
                    $inDateTime = Carbon::parse($lastInCard->date . ' ' . $lastInCard->time);
                    $outDateTime = Carbon::parse($validated['date'] . ' ' . $validated['time']);
                    $working_hours = round($inDateTime->floatDiffInHours($outDateTime), 2);

                    $entryType = 2; // OUT
                    $status = 'OUT';
                    $actual_date = $lastInCard->date;
                }
            }
        } else {
            $entryType = 1;
            $status = 'IN';
            $working_hours = null;
        }

        // Check for duplicates (excluding current record)
        $duplicate = time_card::where('employee_id', $employee->id)
            ->where('date', $validated['date'])
            ->where('time', $storeTime)
            ->where('entry', $entryType)
            ->where('status', $status)
            ->where('id', '!=', $timeCard->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'Duplicate attendance record. This entry already exists.',
            ], 409);
        }

        // Delete existing overtime record if it exists
        over_time::where('time_cards_id', $timeCard->id)->delete();

        // Update the time card
        $timeCard->update([
            'time' => $storeTime,
            'date' => $validated['date'],
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'actual_date' => $actual_date,
        ]);

        if ($status === 'OUT' && $pairedInCard) {
            $referenceDate = $actual_date ?? $pairedInCard->date;
            $this->processOvertimeForOutPunch($employee, $timeCard, $pairedInCard, $shift, $referenceDate);
        }

        return response()->json(['message' => 'Time card updated successfully', 'data' => $timeCard]);
    }

    public function destroy($id)
    {
        $timeCard = time_card::findOrFail($id);

        // Soft-delete associated overtime records by setting deleted_at
        \App\Models\over_time::where('time_cards_id', $timeCard->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        // Soft-delete the time card by setting deleted_at (do not hard delete)
        if (is_null($timeCard->deleted_at)) {
            $timeCard->deleted_at = now();
            $timeCard->save();
        }

        return response()->json(['message' => 'Time card soft-deleted successfully']);
    }

    private function resolveRosterAndShift(employee $employee, string $date): array
    {
        $org = $employee->organizationAssignment;
        if (!$org) {
            return [null, null];
        }

        $roster = roster::where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($date) {
                $q->whereNull('date_from')->orWhere('date_from', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('date_to')->orWhere('date_to', '>=', $date);
            })
            ->first();

        if (!$roster && $org->sub_department_id) {
            $roster = roster::where('sub_department_id', $org->sub_department_id)
                ->whereNull('employee_id')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($date) {
                    $q->where(function ($nested) use ($date) {
                        $nested->whereNull('date_from')->orWhere('date_from', '<=', $date);
                    });
                    $q->where(function ($nested) use ($date) {
                        $nested->whereNull('date_to')->orWhere('date_to', '>=', $date);
                    });
                })
                ->first();
        }

        if (!$roster && $org->department_id) {
            $roster = roster::where('department_id', $org->department_id)
                ->whereNull('employee_id')
                ->whereNull('sub_department_id')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($date) {
                    $q->where(function ($nested) use ($date) {
                        $nested->whereNull('date_from')->orWhere('date_from', '<=', $date);
                    });
                    $q->where(function ($nested) use ($date) {
                        $nested->whereNull('date_to')->orWhere('date_to', '>=', $date);
                    });
                })
                ->first();
        }

        if (!$roster && $org->company_id) {
            $roster = roster::where('company_id', $org->company_id)
                ->whereNull('employee_id')
                ->whereNull('sub_department_id')
                ->whereNull('department_id')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($date) {
                    $q->where(function ($nested) use ($date) {
                        $nested->whereNull('date_from')->orWhere('date_from', '<=', $date);
                    });
                    $q->where(function ($nested) use ($date) {
                        $nested->whereNull('date_to')->orWhere('date_to', '>=', $date);
                    });
                })
                ->first();
        }

        $shift = $roster ? shifts::find($roster->shift_code) : null;

        return [$roster, $shift];
    }

    private function processOvertimeForOutPunch(
        employee $employee,
        time_card $outCard,
        time_card $inCard,
        ?shifts $fallbackShift = null,
        ?string $referenceDate = null
    ): void {
        if (!$inCard) {
            return;
        }

        $targetDate = $referenceDate ?? $inCard->date;
        [, $resolvedShift] = $this->resolveRosterAndShift($employee, $targetDate);

        if (!$resolvedShift && $fallbackShift) {
            $resolvedShift = $fallbackShift;
        }

        if (!$resolvedShift) {
            return;
        }

        $clockIn = Carbon::parse($inCard->date . ' ' . $inCard->time);
        $clockOut = Carbon::parse($outCard->date . ' ' . $outCard->time);

        // NEW: determine holiday (company or department) for target date
        $isHoliday = $this->isHoliday($employee, $targetDate);

        // NEW: Holiday working hours logic
        if ($isHoliday) {
            $comp = $employee->compensation;
            if (!$comp || !$comp->ot_active) {
                return;
            }

            // Get total working hours from the OUT record
            $totalWorkingHours = (float) ($outCard->working_hours ?? 0);
            
            if ($totalWorkingHours <= 0) {
                return;
            }

            // Get shift overtime rate configuration
            $rateModel = \App\Models\ShiftOvertimeRate::where('shift_id', $resolvedShift->id)->whereNull('deleted_at')->first();
            if (!$rateModel) {
                return;
            }

            // Calculate holiday rate: Basic Salary ÷ Total Monthly Hours × Holiday Multiplier
            $basicSalary = (float) ($comp->basic_salary ?? 0);
            $shiftHoursPerDay = (float) ($rateModel->shift_hours_per_day ?? 0);
            $workingDaysPerMonth = (float) ($rateModel->working_days_per_month ?? 0);
            $totalMonthlyHours = $shiftHoursPerDay * $workingDaysPerMonth;
            
            if ($totalMonthlyHours <= 0) {
                return;
            }

            $baseHourlyRate = round($basicSalary / $totalMonthlyHours, 6);
            $holidayMultiplier = (float) ($rateModel->holiday_multiplier ?? 2.0);
            $holidayOtHourlyRate = round($baseHourlyRate * $holidayMultiplier, 6);
            $totalOtAmount = round($totalWorkingHours * $holidayOtHourlyRate, 2);

            // Create overtime record with all working hours as OT
            over_time::create([
                'employee_id' => $employee->id,
                'shift_code' => $resolvedShift->id,
                'time_cards_id' => $outCard->id,
                'ot_hours' => $totalWorkingHours,
                'morning_ot' => 0.0, // All hours treated as holiday OT, not categorized by time
                'afternoon_ot' => 0.0,
                'morning_ot_special' => 0.0,
                'evening_ot_special' => 0.0,
                'morning_ot_amount' => 0.0,
                'morning_ot_special_amount' => 0.0,
                'evening_ot_amount' => 0.0,
                'evening_ot_special_amount' => 0.0,
                'total_ot_amount' => $totalOtAmount,
                'holiday_ot_hours' => $totalWorkingHours, // NEW: Track holiday OT separately
                'holiday_ot_amount' => $totalOtAmount,   // NEW: Track holiday OT amount separately
                'status' => 'pending',
            ]);

            return;
        }

        // Regular day OT calculation (existing logic)
        $breakdown = $this->overtimeCalculator->calculate($employee, $resolvedShift, $clockIn, $clockOut, $isHoliday);
        $totalHours = $breakdown['hours']['total'] ?? 0;
        if ($totalHours <= 0) {
            return;
        }

        $comp = $employee->compensation;
        if (!$comp || !$comp->ot_active) {
            return;
        }

        $allowMorning = (bool) ($comp->ot_morning ?? false);
        $allowEvening = (bool) ($comp->ot_evening ?? false);
        $allowMorningSpecial = (bool) ($comp->ot_morning_special ?? false);
        $allowEveningSpecial = (bool) ($comp->ot_evening_special ?? false);

        $hours = $breakdown['hours'];
        if (!$allowMorning) {
            $hours['morning_regular'] = 0.0;
        }
        if (!$allowMorningSpecial) {
            $hours['morning_special'] = 0.0;
        }
        if (!$allowEvening) {
            $hours['evening_regular'] = 0.0;
        }
        if (!$allowEveningSpecial) {
            $hours['evening_special'] = 0.0;
        }

        $hours['total'] = round(
            ($hours['morning_regular'] + $hours['morning_special'] + $hours['evening_regular'] + $hours['evening_special']),
            2
        );

        if ($hours['total'] <= 0) {
            return;
        }

        // Recompute amounts using effective OT hourly rate from meta
        $effectiveRate = (float) ($breakdown['meta']['effective_ot_hourly_rate'] ?? 0);
        $amounts = [
            'morning_regular' => round($hours['morning_regular'] * $effectiveRate, 2),
            'morning_special' => round($hours['morning_special'] * $effectiveRate, 2),
            'evening_regular' => round($hours['evening_regular'] * $effectiveRate, 2),
            'evening_special' => round($hours['evening_special'] * $effectiveRate, 2),
        ];
        $amounts['total'] = round(array_sum($amounts), 2);

        over_time::create([
            'employee_id' => $employee->id,
            'shift_code' => $resolvedShift->id,
            'time_cards_id' => $outCard->id,
            'ot_hours' => $hours['total'],
            'morning_ot' => $hours['morning_regular'],
            'afternoon_ot' => $hours['evening_regular'],
            'morning_ot_special' => $hours['morning_special'],
            'evening_ot_special' => $hours['evening_special'],
            'morning_ot_amount' => $amounts['morning_regular'],
            'morning_ot_special_amount' => $amounts['morning_special'],
            'evening_ot_amount' => $amounts['evening_regular'],
            'evening_ot_special_amount' => $amounts['evening_special'],
            'total_ot_amount' => $amounts['total'],
            'holiday_ot_hours' => 0.0,   // NEW: No holiday OT for regular days
            'holiday_ot_amount' => 0.0,  // NEW: No holiday OT amount for regular days
            'status' => 'pending',
        ]);
    }

    // NEW helper to detect holiday similar to NopayController logic
    private function isHoliday(employee $employee, string $date): bool
    {
        $org = $employee->organizationAssignment;
        if (!$org) {
            return false;
        }

        // Treat Saturdays and Sundays as holidays
        $carbonDate = \Carbon\Carbon::parse($date);
        if (in_array($carbonDate->dayOfWeek, [\Carbon\Carbon::SATURDAY, \Carbon\Carbon::SUNDAY])) {
            return true;
        }

        // Company-level
        $companyHoliday = \App\Models\leaveCalendar::where('company_id', $org->company_id)
            ->whereDate('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date);
            })
            ->exists();

        if ($companyHoliday) {
            return true;
        }

        // Department-level
        if ($org->department_id) {
            $deptHoliday = \App\Models\leaveCalendar::where('department_id', $org->department_id)
                ->whereDate('start_date', '<=', $date)
                ->where(function ($q) use ($date) {
                    $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date);
                })
                ->exists();
            if ($deptHoliday) {
                return true;
            }
        }

        return false;
    }

    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
            'company_id' => 'required|exists:companies,id',
            'from_date' => 'required|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $companyId = $request->input('company_id');
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // Pass the UploadedFile object directly (same method used by AllowancesController)
        // This preserves original filename/extension so the package can detect type on Linux
        $uploaded = $request->file('file');
        $rows = Excel::toArray(new class implements \Maatwebsite\Excel\Concerns\ToArray {
            public function array(array $array)
            {
                // No-op: required by interface but not used because toArray returns data directly
            }
        }, $uploaded)[0];

        $results = [
            'imported' => 0,
            'absent' => 0,
            'errors' => [],
        ];

        \DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                if ($index === 0) continue; // skip header row

                $nic = trim($row[0]);
                $excelDate = trim($row[1]);
                $rawTime = trim($row[2]);
                $entry = trim($row[3]);
                $status = trim($row[4]);
                $reason = isset($row[5]) ? trim($row[5]) : null;

                // Normalize date from Excel (supports both Excel serial and string)
                try {
                    if (is_numeric($excelDate)) {
                        $unixDate = ($excelDate - 25569) * 86400;
                        $date = gmdate("Y-m-d", $unixDate);
                    } else {
                        $date = date("Y-m-d", strtotime($excelDate));
                    }
                } catch (\Exception $ex) {
                    $results['errors'][] = "Row $index: Invalid date format";
                    continue;
                }

                // Only process if date is within range
                if ($date < $fromDate) continue;
                if ($toDate && $date > $toDate) continue;

                // Parse time from Excel
                try {
                    if (is_numeric($rawTime)) {
                        $seconds = round($rawTime * 24 * 60 * 60);
                        $hours = floor($seconds / 3600);
                        $minutes = floor(($seconds % 3600) / 60);
                        $seconds = $seconds % 60;
                        $time = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                    } else {
                        $carbonTime = Carbon::parse($rawTime);
                        $time = $carbonTime->format('H:i:s');
                    }
                } catch (\Exception $ex) {
                    $results['errors'][] = "Invalid time format";
                    continue;
                }

                // Find employee by NIC and company
                $identifier = trim($nic);
                $employeeQuery = employee::where(function ($q) use ($identifier) {
                    // match NIC case-insensitive OR attendance_employee_no exact
                    $q->whereRaw('LOWER(nic) = ?', [strtolower($identifier)])
                      ->orWhere('attendance_employee_no', $identifier);
                });
                // keep company scoping if provided
                if (!empty($companyId)) {
                    $employeeQuery->whereHas('organizationAssignment', function ($q) use ($companyId) {
                        $q->where('company_id', $companyId);
                    });
                }
                $employee = $employeeQuery->first();

                if (!$employee) {
                    $results['errors'][] = "Employee not found for NIC/Attendance number in selected company";
                    continue;
                }

                [$roster, $shift] = $this->resolveRosterAndShift($employee, $date);

                if (!$roster) {
                    $results['errors'][] = "No shift/roster assigned for this employee on $date";
                    continue;
                }

                if (!$shift) {
                    $results['errors'][] = "Shift not found for roster";
                    continue;
                }

                // Attendance logic
                if (in_array(strtoupper($status), ['IN', 'OUT', 'LEAVE'])) {
                    // Use store logic for attendance
                    $entryType = (int)$entry;
                    $working_hours = null;
                    $actual_date = null;
                    $statusUpper = strtoupper($status);
                    $lastInCard = null;

                    if ($statusUpper === 'OUT') {
                        // Special handling for early morning OUT records (likely from previous day shift)
                        $morningOutRecord = Carbon::parse($time)->hour < 12;
                        
                        if ($morningOutRecord) {
                            // For early morning OUT records, first check for unpaired IN from previous day
                            $previousDayIN = time_card::where('employee_id', $employee->id)
                                ->where('status', 'IN')
                                ->where('date', '<', $date)
                                ->whereNotExists(function($query) {
                                    $query->select(DB::raw(1))
                                          ->from('time_cards as tc')
                                          ->whereRaw('tc.actual_date = time_cards.date')
                                          ->where('tc.status', 'OUT');
                                })
                                ->orderBy('date', 'desc')
                                ->orderBy('time', 'desc')
                                ->first();
                                
                            if ($previousDayIN) {
                                $lastInCard = $previousDayIN;
                            } else {
                                // If no unpaired IN from previous day, try same day
                                $lastInCard = time_card::where('employee_id', $employee->id)
                                    ->where('date', $date)
                                    ->where('status', 'IN')
                                    ->where('time', '<', $time) // Only IN records before this OUT time
                                    ->orderBy('time', 'desc')
                                    ->first();
                                    
                                // If no same-day IN before this OUT time, look for any previous IN
                                if (!$lastInCard) {
                                    $lastInCard = time_card::where('employee_id', $employee->id)
                                        ->where('status', 'IN')
                                        ->where('date', '<', $date)
                                        ->orderBy('date', 'desc')
                                        ->orderBy('time', 'desc')
                                        ->first();
                                }
                            }
                        } else {
                            // For afternoon/evening OUT records, use existing logic
                            // First try to find an IN record from the SAME date
                            $lastInCard = time_card::where('employee_id', $employee->id)
                                ->where('date', $date)
                                ->where('status', 'IN')
                                ->orderBy('time', 'desc')
                                ->first();
                            
                            // If no same-day IN record, look for the most recent IN record BEFORE this date
                            if (!$lastInCard) {
                                $lastInCard = time_card::where('employee_id', $employee->id)
                                    ->where('status', 'IN')
                                    ->where('date', '<', $date)
                                    ->orderBy('date', 'desc')
                                    ->orderBy('time', 'desc')
                                    ->first();
                            }
                        }

                        if ($lastInCard) {
                            $lastInDate = Carbon::parse($lastInCard->date);
                            $currentDate = Carbon::parse($date);

                            if ($lastInDate->eq($currentDate)) {
                                // Same day: normal OUT/Leave logic
                                $inTime = Carbon::parse($lastInCard->time);
                                $outTime = Carbon::parse($time);
                                $working_hours = round($inTime->floatDiffInHours($outTime), 2);

                                if ($outTime->lt(Carbon::parse($shift->end_time))) {
                                    $entryType = 0; // Leave
                                    $statusUpper = 'Leave';
                                } else {
                                    $entryType = 2; // OUT
                                    $statusUpper = 'OUT';
                                }
                            } else {
                                // Different day: cross-day OUT
                                $inDateTime = Carbon::parse($lastInCard->date . ' ' . $lastInCard->time);
                                $outDateTime = Carbon::parse($date . ' ' . $time);
                                $working_hours = round($inDateTime->floatDiffInHours($outDateTime), 2);

                                $entryType = 2; // OUT
                                $statusUpper = 'OUT';
                                $actual_date = $lastInCard->date;
                            }
                        }
                    } else {
                        $entryType = 1;
                        $statusUpper = 'IN';
                        $working_hours = null;
                    }

                    // Prevent duplicate time_card
                    $exists = time_card::where('employee_id', $employee->id)
                        ->where('date', $date)
                        ->where('time', $time)
                        ->where('entry', $entryType)
                        ->where('status', $statusUpper)
                        ->whereNull('deleted_at')
                        ->exists();

                    if (!$exists) {
                        $timeCard = time_card::create([
                            'employee_id' => $employee->id,
                            'time' => $time,
                            'date' => $date,
                            'working_hours' => $working_hours,
                            'entry' => $entryType,
                            'status' => $statusUpper,
                            'actual_date' => $actual_date,
                        ]);
                        $results['imported']++;
                        
                        if ($statusUpper === 'OUT' && $lastInCard) {
                            $referenceDate = $actual_date ?? $lastInCard->date;
                            $this->processOvertimeForOutPunch($employee, $timeCard, $lastInCard, $shift, $referenceDate);
                        }
                    }
                } elseif (strtoupper($status) === 'ABSENT') {
                    // Prevent duplicate absence
                    $exists = absence::where('employee_id', $employee->id)
                        ->where('date', $date)
                        ->exists();

                    if (!$exists) {
                        absence::create([
                            'employee_id' => $employee->id,
                            'date' => $date,
                            'reason' => $reason ?: 'not mentioned',
                        ]);
                        $results['absent']++;
                    }
                } else {
                    $results['errors'][] = "Unknown status";
                }
            }
            \DB::commit();
        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'message' => 'Import error',
                'errors' => array_unique($results['errors']),
                'exception' => $e->getMessage()
            ], 500);
        }

        // Remove duplicate error messages before returning
        $results['errors'] = array_unique($results['errors']);

        return response()->json($results);
    }
    public function fetchAbsentees(Request $request)
    {
        $date = $request->query('date');
        $search = $request->query('search', '');

        $query = absence::with(['employee'])
            ->when($date, function ($q) use ($date) {
                $q->where('date', $date);
            })
            ->when($search, function ($q) use ($search) {
                $q->whereHas('employee', function ($q2) use ($search) {
                    $q2->where('nic', 'like', "%$search%")
                       ->orWhere('attendance_employee_no', 'like', "%$search%");
                });
            });

        $absentees = $query->get()->map(function ($abs) {
            return [
                'id' => $abs->id,
                'employee_name' => $abs->employee->full_name ?? null,
                'date' => $abs->date,
                'reason' => $abs->reason,
            ];
        });

        return response()->json($absentees);
    }
    public function downloadTemplate()
    {
        return Excel::download(new AttendanceTemplateExport, 'attendance_template.xlsx');
    }
    public function getTodayStats()
{
    $today = now()->format('Y-m-d');
    
    // Get employees on leave today
    $onLeaveCount = leave_master::where('status', 'Approved')
        ->where(function($query) use ($today) {
            $query->where('leave_date', $today)
                ->orWhere(function($q) use ($today) {
                    $q->where('leave_from', '<=', $today)
                      ->where('leave_to', '>=', $today);
                });
        })
        ->distinct('employee_id')
        ->count('employee_id');

    // Get employees present today (have at least one IN record)
    $presentCount = time_card::where('date', $today)
        ->where('status', 'IN')
        ->distinct('employee_id')
        ->count('employee_id');

    return response()->json([
        'on_leave' => $onLeaveCount,
        'present' => $presentCount
    ]);
}

}
