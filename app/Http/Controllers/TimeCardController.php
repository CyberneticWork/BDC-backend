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

// use Maatwebsite\Excel\Facades\Excel;

class TimeCardController extends Controller
{
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

        // Find roster for this employee for the given date (fallback logic)
        $roster = roster::where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($validated) {
                $q->whereNull('date_from')->orWhere('date_from', '<=', $validated['date']);
            })
            ->where(function ($q) use ($validated) {
                $q->whereNull('date_to')->orWhere('date_to', '>=', $validated['date']);
            })
            ->first();
        if (!$roster && $org->sub_department_id) {
            $roster = roster::where('sub_department_id', $org->sub_department_id)
                ->whereNull('employee_id')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($validated) {
                    $q->where(function ($q2) use ($validated) {
                        $q2->whereNull('date_from')->orWhere('date_from', '<=', $validated['date']);
                    });
                    $q->where(function ($q2) use ($validated) {
                        $q2->whereNull('date_to')->orWhere('date_to', '>=', $validated['date']);
                    });
                })
                ->first();
        }
        if (!$roster && $org->department_id) {
            $roster = roster::where('department_id', $org->department_id)
                ->whereNull('employee_id')
                ->whereNull('sub_department_id')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($validated) {
                    $q->where(function ($q2) use ($validated) {
                        $q2->whereNull('date_from')->orWhere('date_from', '<=', $validated['date']);
                    });
                    $q->where(function ($q2) use ($validated) {
                        $q2->whereNull('date_to')->orWhere('date_to', '>=', $validated['date']);
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
                ->where(function ($q) use ($validated) {
                    $q->where(function ($q2) use ($validated) {
                        $q2->whereNull('date_from')->orWhere('date_from', '<=', $validated['date']);
                    });
                    $q->where(function ($q2) use ($validated) {
                        $q2->whereNull('date_to')->orWhere('date_to', '>=', $validated['date']);
                    });
                })
                ->first();
        }

        if (!$roster) {
            return response()->json(['message' => 'No shift/roster assigned for this employee on this date'], 422);
        }

        $shift = shifts::find($roster->shift_code);
        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

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

        if ($status === 'OUT') {
            // Special handling for early morning OUT records (likely from previous day shift)
            $morningOutRecord = Carbon::parse($validated['time'])->hour < 12;
            
            if ($morningOutRecord) {
                // For early morning OUT records, first check for unpaired IN from previous day
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
                    // If no unpaired IN from previous day, try same day
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->where('date', $validated['date'])
                        ->where('status', 'IN')
                        ->where('time', '<', $validated['time']) // Only IN records before this OUT time
                        ->orderBy('time', 'desc')
                        ->first();
                        
                    // If no same-day IN before this OUT time, look for any previous IN
                    if (!$lastInCard) {
                        $lastInCard = time_card::where('employee_id', $employee->id)
                            ->where('status', 'IN')
                            ->where(function($query) use ($validated) {
                                $query->where('date', '<', $validated['date'])
                                      ->orWhere(function($q) use ($validated) {
                                          $q->where('date', $validated['date'])
                                            ->where('time', '<', $validated['time']);
                                      });
                            })
                            ->orderBy('date', 'desc')
                            ->orderBy('time', 'desc')
                            ->first();
                    }
                }
            } else {
                // For afternoon/evening OUT records, use existing logic
                // First try to find an IN record from the SAME date
                $lastInCard = time_card::where('employee_id', $employee->id)
                    ->where('date', $validated['date'])
                    ->where('status', 'IN')
                    ->orderBy('time', 'desc')
                    ->first();
                
                // If no same-day IN record, look for the most recent IN record BEFORE this date
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

        $fingerprintClock = now();

        $duplicate = time_card::where('employee_id', $employee->id)
            ->where('date', $validated['date'])
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
            'date' => $validated['date'],
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'fingerprint_clock' => $fingerprintClock,
            'actual_date' => $actual_date,
        ]);

        if ($status == "OUT") {
            // Get the appropriate shift code - make sure we get the correct one
            $shift_code = null;
            
            // First try to get shift from the roster for this specific date
            if ($actual_date) {
                $dateToCheck = $actual_date;
            } else {
                $dateToCheck = $validated['date'];
            }
            
            // Get roster for the relevant date
            $employeeRoster = roster::where('employee_id', $employee->id)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($dateToCheck) {
                    $q->whereNull('date_from')->orWhere('date_from', '<=', $dateToCheck);
                })
                ->where(function ($q) use ($dateToCheck) {
                    $q->whereNull('date_to')->orWhere('date_to', '>=', $dateToCheck);
                })
                ->first();
            
            if ($employeeRoster) {
                $shift_code = $employeeRoster->shift_code;
            } else {
                // Fallback to first roster if specific date roster not found
                $shift_code = $employee->rosters()->first()->shift_code ?? null;
            }
            
            $shift = shifts::find($shift_code);
            
            // Determine if this is a cross-day scenario
            if ($actual_date) {
                // Cross-day scenario - get the last IN record to calculate OT properly
                $lastInRecord = time_card::where('employee_id', $employee->id)
                    ->where('date', $actual_date)
                    ->where('status', 'IN')
                    ->orderBy('time', 'desc')
                    ->first();
                
                $morning_ot = 0;
                $afternoon_ot = 0;
                $ot_time = 0;
                
                if ($lastInRecord && $shift) {
                    // Calculate morning OT if employee clocked in before shift start time
                    $inTimeOnly = Carbon::parse($lastInRecord->time)->format('H:i:s');
                    $inDateTime = Carbon::parse($actual_date . ' ' . $inTimeOnly);
                    $shiftStartTime = Carbon::parse($shift->start_time)->format('H:i:s');
                    $shiftStartDateTime = Carbon::parse($actual_date . ' ' . $shiftStartTime);
                    
                    if ($inDateTime->lt($shiftStartDateTime)) {
                        // Use abs() to ensure positive OT hours
                        $morning_ot = abs(round($inDateTime->floatDiffInHours($shiftStartDateTime), 2));
                    }
                    
                    // Calculate afternoon OT (from shift end time till actual checkout)
                    $shiftTimeOnly = Carbon::parse($shift->end_time)->format('H:i:s');
                    $shiftEndDateTime = Carbon::parse($actual_date . ' ' . $shiftTimeOnly);
                    $checkoutDateTime = Carbon::parse($validated['date'] . ' ' . $validated['time']);
                    
                    // Ensure we're getting a positive value for afternoon OT
                    if ($checkoutDateTime->gt($shiftEndDateTime)) {
                        $afternoon_ot = abs(round($checkoutDateTime->floatDiffInHours($shiftEndDateTime), 2));
                    }
                    
                    // Calculate total OT as sum of morning and afternoon OT
                    $ot_time = $morning_ot + $afternoon_ot;
                }
                
                // Only create OT record if minimum thresholds are met (60 minutes = 1 hour)
                if ($morning_ot >= 1.0 || $afternoon_ot >= 1.0) {
                    $over_time = over_time::create([
                        'employee_id' => $validated['employee_id'],
                        'shift_code' => $shift_code,
                        'time_cards_id' => $timeCard->id,
                        'ot_hours' => $ot_time,
                        'morning_ot' => $morning_ot,
                        'afternoon_ot' => $afternoon_ot,
                        'status' => 'pending'
                    ]);
                }
            } else {
                // Same day scenario - regular OT calculation
                // Find the last IN record for this employee on the same day
                $lastInRecord = time_card::where('employee_id', $employee->id)
                    ->where('date', $validated['date'])
                    ->where('status', 'IN')
                    ->orderBy('time', 'desc')
                    ->first();
                
                $morning_ot = 0;
                $afternoon_ot = 0;
                $ot_time = 0;
                
                if ($lastInRecord && $shift) {
                    // Calculate morning OT if employee clocked in before shift start time
                    $inTimeOnly = Carbon::parse($lastInRecord->time)->format('H:i:s');
                    $inDateTime = Carbon::parse($validated['date'] . ' ' . $inTimeOnly);
                    $shiftStartTime = Carbon::parse($shift->start_time)->format('H:i:s');
                    $shiftStartDateTime = Carbon::parse($validated['date'] . ' ' . $shiftStartTime);
                    
                    if ($inDateTime->lt($shiftStartDateTime)) {
                        // Use abs() to ensure positive OT hours
                        $morning_ot = abs(round($inDateTime->floatDiffInHours($shiftStartDateTime), 2));
                    }
                    
                    // Calculate afternoon OT
                    $shiftTimeOnly = Carbon::parse($shift->end_time)->format('H:i:s');
                    $shiftEndDateTime = Carbon::parse($validated['date'] . ' ' . $shiftTimeOnly);
                    $checkoutDateTime = Carbon::parse($validated['date'] . ' ' . $validated['time']);
                    
                    // Ensure we're getting a positive value for afternoon OT
                    if ($checkoutDateTime->gt($shiftEndDateTime)) {
                        $afternoon_ot = abs(round($checkoutDateTime->floatDiffInHours($shiftEndDateTime), 2));
                    }
                    
                    // Calculate total OT as sum of morning and afternoon OT
                    $ot_time = $morning_ot + $afternoon_ot;
                }
                
                // Only create OT record if minimum thresholds are met (60 minutes = 1 hour)
                if ($morning_ot >= 1.0 || $afternoon_ot >= 1.0) {
                    $over_time = over_time::create([
                        'employee_id' => $validated['employee_id'],
                        'shift_code' => $shift_code,
                        'time_cards_id' => $timeCard->id,
                        'ot_hours' => $ot_time,
                        'morning_ot' => $morning_ot,
                        'afternoon_ot' => $afternoon_ot,
                        'status' => 'pending'
                    ]);
                }
            }
        }

        return response()->json($timeCard, 201);
    }

    public function attendance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'empno' => 'required|integer',
            'date' => 'required|date',
            'time' => 'required|date_format:H:i:s',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee = employee::where('attendance_employee_no', $request->empno)->first();
        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $org = $employee->organizationAssignment;
        if (!$org) {
            return response()->json(['message' => 'Organization assignment not found'], 404);
        }

        // Find roster for this employee for the given date (fallback logic)
        $roster = roster::where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($request) {
                $q->whereNull('date_from')->orWhere('date_from', '<=', $request->date);
            })
            ->where(function ($q) use ($request) {
                $q->whereNull('date_to')->orWhere('date_to', '>=', $request->date);
            })
            ->first();
        if (!$roster && $org->sub_department_id) {
            $roster = roster::where('sub_department_id', $org->sub_department_id)
                ->whereNull('employee_id')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($request) {
                    $q->where(function ($q2) use ($request) {
                        $q2->whereNull('date_from')->orWhere('date_from', '<=', $request->date);
                    });
                    $q->where(function ($q2) use ($request) {
                        $q2->whereNull('date_to')->orWhere('date_to', '>=', $request->date);
                    });
                })
                ->first();
        }
        if (!$roster && $org->department_id) {
            $roster = roster::where('department_id', $org->department_id)
                ->whereNull('employee_id')
                ->whereNull('sub_department_id')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($request) {
                    $q->where(function ($q2) use ($request) {
                        $q2->whereNull('date_from')->orWhere('date_from', '<=', $request->date);
                    });
                    $q->where(function ($q2) use ($request) {
                        $q2->whereNull('date_to')->orWhere('date_to', '>=', $request->date);
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
                ->where(function ($q) use ($request) {
                    $q->where(function ($q2) use ($request) {
                        $q2->whereNull('date_from')->orWhere('date_from', '<=', $request->date);
                    });
                    $q->where(function ($q2) use ($request) {
                        $q2->whereNull('date_to')->orWhere('date_to', '>=', $request->date);
                    });
                })
                ->first();
        }

        if (!$roster) {
            return response()->json(['message' => 'No roster assigned for this employee on this date'], 422);
        }

        $shift = shifts::find($roster->shift_code);
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

        if ($lastCard && $lastCard->status === 'IN') {
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

        // START OF NEW OT CALCULATION CODE
        if ($status == "OUT") {
            // Get the appropriate shift code - make sure we get the correct one
            $shift_code = null;
            
            // First try to get shift from the roster for this specific date
            if ($actual_date) {
                $dateToCheck = $actual_date;
            } else {
                $dateToCheck = $request->date;
            }
            
            // Get roster for the relevant date
            $employeeRoster = roster::where('employee_id', $employee->id)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($dateToCheck) {
                    $q->whereNull('date_from')->orWhere('date_from', '<=', $dateToCheck);
                })
                ->where(function ($q) use ($dateToCheck) {
                    $q->whereNull('date_to')->orWhere('date_to', '>=', $dateToCheck);
                })
                ->first();
            
            if ($employeeRoster) {
                $shift_code = $employeeRoster->shift_code;
            } else {
                // Fallback to first roster if specific date roster not found
                $shift_code = $employee->rosters()->first()->shift_code ?? null;
            }
            
            $shift = shifts::find($shift_code);
            
            // Determine if this is a cross-day scenario
            if ($actual_date) {
                // Cross-day scenario - get the last IN record to calculate OT properly
                $lastInRecord = time_card::where('employee_id', $employee->id)
                    ->where('date', $actual_date)
                    ->where('status', 'IN')
                    ->orderBy('time', 'desc')
                    ->first();
                
                $morning_ot = 0;
                $afternoon_ot = 0;
                $ot_time = 0;
                
                if ($lastInRecord && $shift) {
                    // Calculate morning OT if employee clocked in before shift start time
                    $inTimeOnly = Carbon::parse($lastInRecord->time)->format('H:i:s');
                    $inDateTime = Carbon::parse($actual_date . ' ' . $inTimeOnly);
                    $shiftStartTime = Carbon::parse($shift->start_time)->format('H:i:s');
                    $shiftStartDateTime = Carbon::parse($actual_date . ' ' . $shiftStartTime);
                    
                    if ($inDateTime->lt($shiftStartDateTime)) {
                        // Use abs() to ensure positive OT hours
                        $morning_ot = abs(round($inDateTime->floatDiffInHours($shiftStartDateTime), 2));
                    }
                    
                    // Calculate afternoon OT (from shift end time till actual checkout)
                    $shiftTimeOnly = Carbon::parse($shift->end_time)->format('H:i:s');
                    $shiftEndDateTime = Carbon::parse($actual_date . ' ' . $shiftTimeOnly);
                    $checkoutDateTime = Carbon::parse($request->date . ' ' . $request->time);
                    
                    // Ensure we're getting a positive value for afternoon OT
                    if ($checkoutDateTime->gt($shiftEndDateTime)) {
                        $afternoon_ot = abs(round($checkoutDateTime->floatDiffInHours($shiftEndDateTime), 2));
                    }
                    
                    // Calculate total OT as sum of morning and afternoon OT
                    $ot_time = $morning_ot + $afternoon_ot;
                }
                
                // Only create OT record if minimum thresholds are met (60 minutes = 1 hour)
                if ($morning_ot >= 1.0 || $afternoon_ot >= 1.0) {
                    $over_time = over_time::create([
                        'employee_id' => $employee->id,
                        'shift_code' => $shift_code,
                        'time_cards_id' => $timeCard->id,
                        'ot_hours' => $ot_time,
                        'morning_ot' => $morning_ot,
                        'afternoon_ot' => $afternoon_ot,
                        'status' => 'pending'
                    ]);
                }
            } else {
                // Same day scenario - regular OT calculation
                // Find the last IN record for this employee on the same day
                $lastInRecord = time_card::where('employee_id', $employee->id)
                    ->where('date', $request->date)
                    ->where('status', 'IN')
                    ->orderBy('time', 'desc')
                    ->first();
                
                $morning_ot = 0;
                $afternoon_ot = 0;
                $ot_time = 0;
                
                if ($lastInRecord && $shift) {
                    // Calculate morning OT if employee clocked in before shift start time
                    $inTimeOnly = Carbon::parse($lastInRecord->time)->format('H:i:s');
                    $inDateTime = Carbon::parse($request->date . ' ' . $inTimeOnly);
                    $shiftStartTime = Carbon::parse($shift->start_time)->format('H:i:s');
                    $shiftStartDateTime = Carbon::parse($request->date . ' ' . $shiftStartTime);
                    
                    if ($inDateTime->lt($shiftStartDateTime)) {
                        // Use abs() to ensure positive OT hours
                        $morning_ot = abs(round($inDateTime->floatDiffInHours($shiftStartDateTime), 2));
                    }
                    
                    // Calculate afternoon OT
                    $shiftTimeOnly = Carbon::parse($shift->end_time)->format('H:i:s');
                    $shiftEndDateTime = Carbon::parse($request->date . ' ' . $shiftTimeOnly);
                    $checkoutDateTime = Carbon::parse($request->date . ' ' . $request->time);
                    
                    // Ensure we're getting a positive value for afternoon OT
                    if ($checkoutDateTime->gt($shiftEndDateTime)) {
                        $afternoon_ot = abs(round($checkoutDateTime->floatDiffInHours($shiftEndDateTime), 2));
                    }
                    
                    // Calculate total OT as sum of morning and afternoon OT
                    $ot_time = $morning_ot + $afternoon_ot;
                }
                
                // Only create OT record if minimum thresholds are met (60 minutes = 1 hour)
                if ($morning_ot >= 1.0 || $afternoon_ot >= 1.0) {
                    $over_time = over_time::create([
                        'employee_id' => $employee->id,
                        'shift_code' => $shift_code,
                        'time_cards_id' => $timeCard->id,
                        'ot_hours' => $ot_time,
                        'morning_ot' => $morning_ot,
                        'afternoon_ot' => $afternoon_ot,
                        'status' => 'pending'
                    ]);
                }
            }
        }
        // END OF NEW OT CALCULATION CODE

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

        // Find roster for this employee for the given date (same logic as store)
        $roster = roster::where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($validated) {
                $q->whereNull('date_from')->orWhere('date_from', '<=', $validated['date']);
            })
            ->where(function ($q) use ($validated) {
                $q->whereNull('date_to')->orWhere('date_to', '>=', $validated['date']);
            })
            ->first();
        if (!$roster && $org->sub_department_id) {
            $roster = roster::where('sub_department_id', $org->sub_department_id)
                ->whereNull('employee_id')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($validated) {
                    $q->where(function ($q2) use ($validated) {
                        $q2->whereNull('date_from')->orWhere('date_from', '<=', $validated['date']);
                    });
                    $q->where(function ($q2) use ($validated) {
                        $q2->whereNull('date_to')->orWhere('date_to', '>=', $validated['date']);
                    });
                })
                ->first();
        }
        if (!$roster && $org->department_id) {
            $roster = roster::where('department_id', $org->department_id)
                ->whereNull('employee_id')
                ->whereNull('sub_department_id')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($validated) {
                    $q->where(function ($q2) use ($validated) {
                        $q2->whereNull('date_from')->orWhere('date_from', '<=', $validated['date']);
                    });
                    $q->where(function ($q2) use ($validated) {
                        $q2->whereNull('date_to')->orWhere('date_to', '>=', $validated['date']);
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
                ->where(function ($q) use ($validated) {
                    $q->where(function ($q2) use ($validated) {
                        $q2->whereNull('date_from')->orWhere('date_from', '<=', $validated['date']);
                    });
                    $q->where(function ($q2) use ($validated) {
                        $q2->whereNull('date_to')->orWhere('date_to', '>=', $validated['date']);
                    });
                })
                ->first();
        }

        if (!$roster) {
            return response()->json(['message' => 'No shift/roster assigned for this employee on this date'], 422);
        }

        $shift = shifts::find($roster->shift_code);
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

        // Same logic as store function for calculating working hours and status
        if ($status === 'OUT') {
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

        // Recalculate overtime if this is an OUT record (same logic as store)
        if ($status == "OUT") {
            $shift_code = null;
            
            if ($actual_date) {
                $dateToCheck = $actual_date;
            } else {
                $dateToCheck = $validated['date'];
            }
            
            $employeeRoster = roster::where('employee_id', $employee->id)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($dateToCheck) {
                    $q->whereNull('date_from')->orWhere('date_from', '<=', $dateToCheck);
                })
                ->where(function ($q) use ($dateToCheck) {
                    $q->whereNull('date_to')->orWhere('date_to', '>=', $dateToCheck);
                })
                ->first();
            
            if ($employeeRoster) {
                $shift_code = $employeeRoster->shift_code;
            } else {
                $shift_code = $employee->rosters()->first()->shift_code ?? null;
            }
            
            $shift = shifts::find($shift_code);
            
            if ($actual_date) {
                // Cross-day scenario
                $lastInRecord = time_card::where('employee_id', $employee->id)
                    ->where('date', $actual_date)
                    ->where('status', 'IN')
                    ->orderBy('time', 'desc')
                    ->first();
                
                $morning_ot = 0;
                $afternoon_ot = 0;
                $ot_time = 0;
                
                if ($lastInRecord && $shift) {
                    $inTimeOnly = Carbon::parse($lastInRecord->time)->format('H:i:s');
                    $inDateTime = Carbon::parse($actual_date . ' ' . $inTimeOnly);
                    $shiftStartTime = Carbon::parse($shift->start_time)->format('H:i:s');
                    $shiftStartDateTime = Carbon::parse($actual_date . ' ' . $shiftStartTime);
                    
                    if ($inDateTime->lt($shiftStartDateTime)) {
                        $morning_ot = abs(round($inDateTime->floatDiffInHours($shiftStartDateTime), 2));
                    }
                    
                    $shiftTimeOnly = Carbon::parse($shift->end_time)->format('H:i:s');
                    $shiftEndDateTime = Carbon::parse($actual_date . ' ' . $shiftTimeOnly);
                    $checkoutDateTime = Carbon::parse($validated['date'] . ' ' . $validated['time']);
                    
                    if ($checkoutDateTime->gt($shiftEndDateTime)) {
                        $afternoon_ot = abs(round($checkoutDateTime->floatDiffInHours($shiftEndDateTime), 2));
                    }
                    
                    $ot_time = $morning_ot + $afternoon_ot;
                }
                
                if ($morning_ot >= 1.0 || $afternoon_ot >= 1.0) {
                    over_time::create([
                        'employee_id' => $employee->id,
                        'shift_code' => $shift_code,
                        'time_cards_id' => $timeCard->id,
                        'ot_hours' => $ot_time,
                        'morning_ot' => $morning_ot,
                        'afternoon_ot' => $afternoon_ot,
                        'status' => 'pending'
                    ]);
                }
            } else {
                // Same day scenario
                $lastInRecord = time_card::where('employee_id', $employee->id)
                    ->where('date', $validated['date'])
                    ->where('status', 'IN')
                    ->where('id', '!=', $timeCard->id) // Exclude current record
                    ->orderBy('time', 'desc')
                    ->first();
            
                $morning_ot = 0;
                $afternoon_ot = 0;
                $ot_time = 0;
                
                if ($lastInRecord && $shift) {
                    $inTimeOnly = Carbon::parse($lastInRecord->time)->format('H:i:s');
                    $inDateTime = Carbon::parse($validated['date'] . ' ' . $inTimeOnly);
                    $shiftStartTime = Carbon::parse($shift->start_time)->format('H:i:s');
                    $shiftStartDateTime = Carbon::parse($validated['date'] . ' ' . $shiftStartTime);
                    
                    if ($inDateTime->lt($shiftStartDateTime)) {
                        $morning_ot = abs(round($inDateTime->floatDiffInHours($shiftStartDateTime), 2));
                    }
                    
                    $shiftTimeOnly = Carbon::parse($shift->end_time)->format('H:i:s');
                    $shiftEndDateTime = Carbon::parse($validated['date'] . ' ' . $shiftTimeOnly);
                    $checkoutDateTime = Carbon::parse($validated['date'] . ' ' . $validated['time']);
                    
                    if ($checkoutDateTime->gt($shiftEndDateTime)) {
                        $afternoon_ot = abs(round($checkoutDateTime->floatDiffInHours($shiftEndDateTime), 2));
                    }
                    
                    $ot_time = $morning_ot + $afternoon_ot;
                }
                
                if ($morning_ot >= 1.0 || $afternoon_ot >= 1.0) {
                    over_time::create([
                        'employee_id' => $employee->id,
                        'shift_code' => $shift_code,
                        'time_cards_id' => $timeCard->id,
                        'ot_hours' => $ot_time,
                        'morning_ot' => $morning_ot,
                        'afternoon_ot' => $afternoon_ot,
                        'status' => 'pending'
                    ]);
                }
            }
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
        $rows = Excel::toArray([], $uploaded)[0];

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

                // Check roster/shift assignment for this employee and date
                $org = $employee->organizationAssignment;
                $roster = roster::where('employee_id', $employee->id)
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($date) {
                        $q->whereNull('date_from')->orWhere('date_from', '<=', $date);
                    })
                    ->where(function ($q) use ($date) {
                        $q->whereNull('date_to')->orWhere('date_to', '>=', $date);
                    })
                    ->first();
                if (!$roster && $org) {
                    if ($org->sub_department_id) {
                        $roster = roster::where('sub_department_id', $org->sub_department_id)
                            ->whereNull('employee_id')
                            ->whereNull('deleted_at')
                            ->where(function ($q) use ($date) {
                                $q->whereNull('date_from')->orWhere('date_from', '<=', $date);
                            })
                            ->where(function ($q) use ($date) {
                                $q->whereNull('date_to')->orWhere('date_to', '>=', $date);
                            })
                            ->first();
                    }
                    if (!$roster && $org->department_id) {
                        $roster = roster::where('department_id', $org->department_id)
                            ->whereNull('employee_id')
                            ->whereNull('sub_department_id')
                            ->whereNull('deleted_at')
                            ->where(function ($q) use ($date) {
                                $q->whereNull('date_from')->orWhere('date_from', '<=', $date);
                            })
                            ->where(function ($q) use ($date) {
                                $q->whereNull('date_to')->orWhere('date_to', '>=', $date);
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
                                $q->whereNull('date_from')->orWhere('date_from', '<=', $date);
                            })
                            ->where(function ($q) use ($date) {
                                $q->whereNull('date_to')->orWhere('date_to', '>=', $date);
                            })
                            ->first();
                    }
                }
                if (!$roster) {
                    $results['errors'][] = "No shift/roster assigned for this employee on $date";
                    continue;
                }

                // Get shift for roster
                $shift = shifts::find($roster->shift_code);
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
                                        ->where(function($query) use ($date, $time) {
                                            $query->where('date', '<', $date)
                                                  ->orWhere(function($q) use ($date, $time) {
                                                      $q->where('date', $date)
                                                        ->where('time', '<', $time);
                                                  });
                                        })
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
                        
                        // START OF NEW OT CALCULATION CODE
                        // Only calculate OT for OUT records
                        if ($statusUpper === 'OUT') {
                            // Get the appropriate shift code
                            $shift_code = null;
                            
                            // First try to get shift from the roster for this specific date
                            if ($actual_date) {
                                $dateToCheck = $actual_date;
                            } else {
                                $dateToCheck = $date;
                            }
                            
                            // Get roster for the relevant date
                            $employeeRoster = roster::where('employee_id', $employee->id)
                                ->whereNull('deleted_at')
                                ->where(function ($q) use ($dateToCheck) {
                                    $q->whereNull('date_from')->orWhere('date_from', '<=', $dateToCheck);
                                })
                                ->where(function ($q) use ($dateToCheck) {
                                    $q->whereNull('date_to')->orWhere('date_to', '>=', $dateToCheck);
                                })
                                ->first();
                            
                            if ($employeeRoster) {
                                $shift_code = $employeeRoster->shift_code;
                            } else {
                                // Fallback to first roster if specific date roster not found
                                $shift_code = $employee->rosters()->first()->shift_code ?? null;
                            }
                            
                            $shift = shifts::find($shift_code);
                            
                            // Determine if this is a cross-day scenario
                            if ($actual_date) {
                                // Cross-day scenario - get the last IN record to calculate OT properly
                                $lastInRecord = time_card::where('employee_id', $employee->id)
                                    ->where('date', $actual_date)
                                    ->where('status', 'IN')
                                    ->orderBy('time', 'desc')
                                    ->first();
                                
                                $morning_ot = 0;
                                $afternoon_ot = 0;
                                $ot_time = 0;
                                
                                if ($lastInRecord && $shift) {
                                    // Calculate morning OT if employee clocked in before shift start time
                                    $inTimeOnly = Carbon::parse($lastInRecord->time)->format('H:i:s');
                                    $inDateTime = Carbon::parse($actual_date . ' ' . $inTimeOnly);
                                    $shiftStartTime = Carbon::parse($shift->start_time)->format('H:i:s');
                                    $shiftStartDateTime = Carbon::parse($actual_date . ' ' . $shiftStartTime);
                                    
                                    if ($inDateTime->lt($shiftStartDateTime)) {
                                        // Use abs() to ensure positive OT hours
                                        $morning_ot = abs(round($inDateTime->floatDiffInHours($shiftStartDateTime), 2));
                                    }
                                    
                                    // Calculate afternoon OT (from shift end time till actual checkout)
                                    $shiftTimeOnly = Carbon::parse($shift->end_time)->format('H:i:s');
                                    $shiftEndDateTime = Carbon::parse($actual_date . ' ' . $shiftTimeOnly);
                                    $checkoutDateTime = Carbon::parse($date . ' ' . $time);
                                    
                                    // Ensure we're getting a positive value for afternoon OT
                                    if ($checkoutDateTime->gt($shiftEndDateTime)) {
                                        $afternoon_ot = abs(round($checkoutDateTime->floatDiffInHours($shiftEndDateTime), 2));
                                    }
                                    
                                    // Calculate total OT as sum of morning and afternoon OT
                                    $ot_time = $morning_ot + $afternoon_ot;
                                }
                                
                                // Only create OT record if minimum thresholds are met (60 minutes = 1 hour)
                                if ($morning_ot >= 1.0 || $afternoon_ot >= 1.0) {
                                    $over_time = over_time::create([
                                        'employee_id' => $employee->id,
                                        'shift_code' => $shift_code,
                                        'time_cards_id' => $timeCard->id,
                                        'ot_hours' => $ot_time,
                                        'morning_ot' => $morning_ot,
                                        'afternoon_ot' => $afternoon_ot,
                                        'status' => 'pending'
                                    ]);
                                }
                            } else {
                                // Same day scenario - regular OT calculation
                                // Find the last IN record for this employee on the same day
                                $lastInRecord = time_card::where('employee_id', $employee->id)
                                    ->where('date', $date)
                                    ->where('status', 'IN')
                                    ->orderBy('time', 'desc')
                                    ->first();
                                
                                $morning_ot = 0;
                                $afternoon_ot = 0;
                                $ot_time = 0;
                                
                                if ($lastInRecord && $shift) {
                                    // Calculate morning OT if employee clocked in before shift start time
                                    $inTimeOnly = Carbon::parse($lastInRecord->time)->format('H:i:s');
                                    $inDateTime = Carbon::parse($date . ' ' . $inTimeOnly);
                                    $shiftStartTime = Carbon::parse($shift->start_time)->format('H:i:s');
                                    $shiftStartDateTime = Carbon::parse($date . ' ' . $shiftStartTime);
                                    
                                    if ($inDateTime->lt($shiftStartDateTime)) {
                                        // Use abs() to ensure positive OT hours
                                        $morning_ot = abs(round($inDateTime->floatDiffInHours($shiftStartDateTime), 2));
                                    }
                                    
                                    // Calculate afternoon OT
                                    $shiftTimeOnly = Carbon::parse($shift->end_time)->format('H:i:s');
                                    $shiftEndDateTime = Carbon::parse($date . ' ' . $shiftTimeOnly);
                                    $checkoutDateTime = Carbon::parse($date . ' ' . $time);
                                    
                                    // Ensure we're getting a positive value for afternoon OT
                                    if ($checkoutDateTime->gt($shiftEndDateTime)) {
                                        $afternoon_ot = abs(round($checkoutDateTime->floatDiffInHours($shiftEndDateTime), 2));
                                    }
                                    
                                    // Calculate total OT as sum of morning and afternoon OT
                                    $ot_time = $morning_ot + $afternoon_ot;
                                }
                                
                                // Only create OT record if minimum thresholds are met (60 minutes = 1 hour)
                                if ($morning_ot >= 1.0 || $afternoon_ot >= 1.0) {
                                    $over_time = over_time::create([
                                        'employee_id' => $employee->id,
                                        'shift_code' => $shift_code,
                                        'time_cards_id' => $timeCard->id,
                                        'ot_hours' => $ot_time,
                                        'morning_ot' => $morning_ot,
                                        'afternoon_ot' => $afternoon_ot,
                                        'status' => 'pending'
                                    ]);
                                }
                            }
                        }
                        // END OF NEW OT CALCULATION CODE
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
