<?php

namespace App\Http\Controllers;

use App\Exports\AttendanceTemplateExport;
use App\Models\absence;
use App\Models\employee;
use App\Models\leave_master;
use App\Models\over_time;
use App\Models\Roster;
use App\Models\shifts;
use App\Models\time_card;
use App\Services\Overtime\OvertimeCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

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
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($card) {
                return [
                    'id' => $card->id,
                    'empNo' => $card->employee->attendance_employee_no ?? null,
                    'name' => $card->employee->full_name ?? null,
                    'fingerprintClock' => $card->fingerprint_clock ?? null,
                    'time' => $card->time,
                    'date' => $card->date,
                    'entry' => $card->entry,
                    'inOut' => $card->entry == 1
                        ? 'IN'
                        : ($card->entry == 2 ? 'OUT' : ($card->entry == 0 ? 'Early OUT' : null)),
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

        $entryType = (int) $validated['entry'];
        $status = strtoupper($validated['status']);
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        if ($status === 'OUT' || $status === 'EARLY OUT') {
            $lastInCard = null;
            $morningOutRecord = Carbon::parse($storeTime)->hour < 12;

            if ($morningOutRecord) {
                $previousDayIN = time_card::where('employee_id', $employee->id)
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->where('date', '<', $validated['date'])
                    ->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('time_cards as tc')
                            ->whereRaw('tc.employee_id = time_cards.employee_id')
                            ->whereIn('tc.status', ['OUT', 'Early OUT'])
                            ->where(function ($x) {
                                $x->whereRaw('tc.actual_date = time_cards.date')
                                    ->orWhere(function ($y) {
                                        $y->whereNull('tc.actual_date')
                                            ->whereRaw('tc.date = time_cards.date');
                                    });
                            });
                    })
                    ->orderBy('date', 'desc')
                    ->orderBy('time', 'desc')
                    ->first();

                if ($previousDayIN) {
                    $lastInCard = $previousDayIN;
                } else {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->where('date', $validated['date'])
                        ->whereIn('status', ['IN', 'Late Coming'])
                        ->where('time', '<', $storeTime)
                        ->orderBy('time', 'desc')
                        ->first();

                    if (!$lastInCard) {
                        $lastInCard = time_card::where('employee_id', $employee->id)
                            ->whereIn('status', ['IN', 'Late Coming'])
                            ->where('date', '<', $validated['date'])
                            ->orderBy('date', 'desc')
                            ->orderBy('time', 'desc')
                            ->first();
                    }
                }
            } else {
                $lastInCard = time_card::where('employee_id', $employee->id)
                    ->where('date', $validated['date'])
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->orderBy('time', 'desc')
                    ->first();

                if (!$lastInCard) {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->whereIn('status', ['IN', 'Late Coming'])
                        ->where('date', '<', $validated['date'])
                        ->orderBy('date', 'desc')
                        ->orderBy('time', 'desc')
                        ->first();
                }
            }

            if ($lastInCard) {
                $pairedInCard = $lastInCard;

                $inDate = $lastInCard->date;
                $outDate = $validated['date'];

                $inDateTime = Carbon::parse($inDate . ' ' . $lastInCard->time);
                $outDateTime = Carbon::parse($outDate . ' ' . $storeTime);

                $working_hours = round($inDateTime->floatDiffInHours($outDateTime), 2);

                $shiftStartDT = Carbon::parse($inDate . ' ' . $shift->start_time);
                $shiftEndDT = Carbon::parse($inDate . ' ' . $shift->end_time);
                if ($shiftEndDT->lte($shiftStartDT)) {
                    $shiftEndDT->addDay();
                }

                $actual_date = ($inDate !== $outDate) ? $inDate : null;

                if ($outDateTime->lt($shiftEndDT)) {
                    $entryType = 0;
                    $status = 'Early OUT';
                } else {
                    $entryType = 2;
                    $status = 'OUT';
                }
            }
        } else {
            $entryType = 1;
            $working_hours = null;
            $status = $this->resolveInStatus($validated['date'], $storeTime, $shift);
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

        if (in_array($status, ['OUT', 'Early OUT']) && $pairedInCard) {
            $referenceDate = $actual_date ?? $pairedInCard->date;
            $this->processOvertimeForOutPunch($employee, $timeCard, $pairedInCard, $shift, $referenceDate);
        }

        return response()->json($timeCard, 201);
    }

    public function attendance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'empno' => ['required', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'date' => 'required|date',
            'time' => 'required|date_format:H:i:s',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rawEmpNo = trim($request->empno);

        $employee = employee::where('attendance_employee_no', $rawEmpNo)->first();

        if (!$employee && ctype_digit($rawEmpNo)) {
            $employee = employee::find((int) $rawEmpNo);
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
        $storeTime = $inputTime->format('H:i:s');

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

        $lastCard = time_card::where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->orderBy('date', 'desc')
            ->orderBy('time', 'desc')
            ->first();

        $entryType = 1;
        $status = 'IN';
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        if ($lastCard && in_array($lastCard->status, ['IN', 'Late Coming'])) {
            $pairedInCard = $lastCard;
            $lastInDate = Carbon::parse($lastCard->date);
            $currentDate = Carbon::parse($request->date);

            if ($lastInDate->eq($currentDate)) {
                $inTime = Carbon::parse($lastCard->time);
                $outTime = $inputTime;
                $working_hours = round($inTime->floatDiffInHours($outTime), 2);

                $shiftStartDT = Carbon::parse($request->date . ' ' . $shift->start_time);
                $shiftEndDT = Carbon::parse($request->date . ' ' . $shift->end_time);
                if ($shiftEndDT->lte($shiftStartDT)) {
                    $shiftEndDT->addDay();
                }

                $currentPunchDT = Carbon::parse($request->date . ' ' . $storeTime);

                if ($currentPunchDT->lt($shiftEndDT)) {
                    $entryType = 0;
                    $status = 'Early OUT';
                } else {
                    $entryType = 2;
                    $status = 'OUT';
                }
            } else {
                $inDateTime = Carbon::parse($lastCard->date . ' ' . $lastCard->time);
                $outDateTime = Carbon::parse($request->date . ' ' . $request->time);
                $working_hours = round($inDateTime->floatDiffInHours($outDateTime), 2);

                $entryType = 2;
                $status = 'OUT';
                $actual_date = $lastCard->date;
            }
        } else {
            $entryType = 1;
            $working_hours = null;
            $status = $this->resolveInStatus($request->date, $storeTime, $shift);
        }

        $fingerprintClock = now();

        $duplicate = time_card::where('employee_id', $employee->id)
            ->where('date', $request->date)
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
            'date' => $request->date,
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'fingerprint_clock' => $fingerprintClock,
            'actual_date' => $actual_date,
        ]);

        if (in_array($status, ['OUT', 'Early OUT']) && $pairedInCard) {
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

        $employee = employee::where('nic', $search)
            ->orWhere('attendance_employee_no', $search)
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $cards = time_card::with(['employee.organizationAssignment.department'])
            ->where('employee_id', $employee->id)
            ->whereNull('deleted_at')
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
                    'inOut' => $card->entry == 1
                        ? 'IN'
                        : ($card->entry == 2 ? 'OUT' : ($card->entry == 0 ? 'Early OUT' : null)),
                    'department' => $card->employee->organizationAssignment->department->name ?? null,
                    'status' => $card->status,
                ];
            });

        return response()->json($cards);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'time' => 'required',
            'entry' => 'required|in:0,1,2',
            'status' => 'required|in:IN,Late Coming,OUT,Absent,Early OUT',
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

        $entryType = (int) $validated['entry'];
        $requestedStatus = $validated['status'];
        $status = strtoupper($requestedStatus);
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        if (in_array($requestedStatus, ['OUT', 'Early OUT'])) {
            $lastInCard = null;
            $morningOutRecord = Carbon::parse($storeTime)->hour < 12;

            if ($morningOutRecord) {
                $previousDayIN = time_card::where('employee_id', $employee->id)
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->where('date', '<', $validated['date'])
                    ->where('id', '!=', $timeCard->id)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('time_cards as tc')
                            ->whereRaw('tc.actual_date = time_cards.date')
                            ->whereIn('tc.status', ['OUT', 'Early OUT']);
                    })
                    ->orderBy('date', 'desc')
                    ->orderBy('time', 'desc')
                    ->first();

                if ($previousDayIN) {
                    $lastInCard = $previousDayIN;
                } else {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->where('date', $validated['date'])
                        ->whereIn('status', ['IN', 'Late Coming'])
                        ->where('time', '<', $storeTime)
                        ->where('id', '!=', $timeCard->id)
                        ->orderBy('time', 'desc')
                        ->first();

                    if (!$lastInCard) {
                        $lastInCard = time_card::where('employee_id', $employee->id)
                            ->whereIn('status', ['IN', 'Late Coming'])
                            ->where('date', '<', $validated['date'])
                            ->where('id', '!=', $timeCard->id)
                            ->orderBy('date', 'desc')
                            ->orderBy('time', 'desc')
                            ->first();
                    }
                }
            } else {
                $lastInCard = time_card::where('employee_id', $employee->id)
                    ->where('date', $validated['date'])
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->where('id', '!=', $timeCard->id)
                    ->orderBy('time', 'desc')
                    ->first();

                if (!$lastInCard) {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->whereIn('status', ['IN', 'Late Coming'])
                        ->where('date', '<', $validated['date'])
                        ->where('id', '!=', $timeCard->id)
                        ->orderBy('date', 'desc')
                        ->orderBy('time', 'desc')
                        ->first();
                }
            }

            if ($lastInCard) {
                $pairedInCard = $lastInCard;

                $inDate = $lastInCard->date;
                $outDate = $validated['date'];

                $inDateTime = Carbon::parse($inDate . ' ' . $lastInCard->time);
                $outDateTime = Carbon::parse($outDate . ' ' . $storeTime);

                $working_hours = round($inDateTime->floatDiffInHours($outDateTime), 2);

                $shiftStartDT = Carbon::parse($inDate . ' ' . $shift->start_time);
                $shiftEndDT = Carbon::parse($inDate . ' ' . $shift->end_time);
                if ($shiftEndDT->lte($shiftStartDT)) {
                    $shiftEndDT->addDay();
                }

                $actual_date = ($inDate !== $outDate) ? $inDate : null;

                if ($outDateTime->lt($shiftEndDT)) {
                    $entryType = 0;
                    $status = 'Early OUT';
                } else {
                    $entryType = 2;
                    $status = 'OUT';
                }
            }
        } elseif (in_array($requestedStatus, ['IN', 'Late Coming'])) {
            $entryType = 1;
            $working_hours = null;
            $actual_date = null;
            $status = $this->resolveInStatus($validated['date'], $storeTime, $shift);
        } else {
            $entryType = 0;
            $status = 'ABSENT';
        }

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

        over_time::where('time_cards_id', $timeCard->id)->delete();

        $timeCard->update([
            'time' => $storeTime,
            'date' => $validated['date'],
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'actual_date' => $actual_date,
        ]);

        if (in_array($status, ['OUT', 'Early OUT']) && $pairedInCard) {
            $referenceDate = $actual_date ?? $pairedInCard->date;
            $this->processOvertimeForOutPunch($employee, $timeCard, $pairedInCard, $shift, $referenceDate);
        }

        return response()->json([
            'message' => 'Time card updated successfully',
            'data' => $timeCard
        ]);
    }

    public function destroy($id)
    {
        $timeCard = time_card::findOrFail($id);

        over_time::where('time_cards_id', $timeCard->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

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

    private function resolveInStatus(string $date, string $time, ?shifts $shift, int $graceMinutes = 0): string
    {
        if (!$shift) {
            return 'IN';
        }

        $shiftStartDateTime = Carbon::parse($date . ' ' . $shift->start_time);
        $punchDateTime = Carbon::parse($date . ' ' . $time);

        return $punchDateTime->gt($shiftStartDateTime->copy()->addMinutes($graceMinutes))
            ? 'Late Coming'
            : 'IN';
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

    $comp = $employee->compensation;
    if (!$comp || !$comp->ot_active) {
        return;
    }

    $clockIn = Carbon::parse($inCard->date . ' ' . $inCard->time);
    $clockOut = Carbon::parse($outCard->date . ' ' . $outCard->time);

    if ($clockOut->lte($clockIn)) {
        return;
    }

    $isHoliday = $this->isHoliday($employee, $targetDate);

    $breakdown = $this->overtimeCalculator->calculate(
        $employee,
        $resolvedShift,
        $clockIn,
        $clockOut,
        $isHoliday
    );

    $effectiveRate = (float) ($breakdown['meta']['effective_ot_hourly_rate'] ?? 0);

    $morningRate = (float) ($comp->ot_morning_rate ?? 0);
    $nightRate = (float) ($comp->ot_night_rate ?? 0);
    $morningSpecialRate = (float) ($comp->ot_morning_rate_special ?? $morningRate);
    $nightSpecialRate = (float) ($comp->ot_night_rate_special ?? $nightRate);

    // =========================
    // HOLIDAY DAY
    // =========================
    if ($isHoliday) {
        $holidayHours = round($clockIn->floatDiffInHours($clockOut), 2);

        if ($holidayHours <= 0) {
            return;
        }

        // shift_overtime_rates තිබුණොත් effectiveRate use වෙයි.
        // නැත්නම් compensation fallback rate use වෙයි.
        $fallbackHolidayRate = $nightRate > 0 ? $nightRate : $morningRate;
        $holidayRateToUse = $effectiveRate > 0 ? $effectiveRate : $fallbackHolidayRate;
        $holidayAmount = round($holidayHours * $holidayRateToUse, 2);

        // old rows remove කරලා fresh holiday row create කරන්න
        over_time::where('time_cards_id', $outCard->id)->delete();

        over_time::create([
            'employee_id' => $employee->id,
            'shift_code' => $resolvedShift->id,
            'time_cards_id' => $outCard->id,

            'ot_hours' => $holidayHours,

            'morning_ot' => 0.0,
            'afternoon_ot' => 0.0,
            'morning_ot_special' => 0.0,
            'evening_ot_special' => 0.0,

            'morning_ot_amount' => 0.0,
            'morning_ot_special_amount' => 0.0,
            'evening_ot_amount' => 0.0,
            'evening_ot_special_amount' => 0.0,

            'holiday_ot_hours' => $holidayHours,
            'holiday_ot_amount' => $holidayAmount,
            'total_ot_amount' => $holidayAmount,

            'status' => 'pending',
        ]);

        return;
    }

    // =========================
    // NORMAL DAY
    // =========================
    $hours = array_merge([
        'morning_regular' => 0.0,
        'morning_special' => 0.0,
        'evening_regular' => 0.0,
        'evening_special' => 0.0,
        'total' => 0.0,
    ], $breakdown['hours'] ?? []);

    $allowMorning = (bool) ($comp->ot_morning ?? false);
    $allowEvening = (bool) ($comp->ot_evening ?? false);
    $allowMorningSpecial = (bool) ($comp->ot_morning_special ?? false);
    $allowEveningSpecial = (bool) ($comp->ot_evening_special ?? false);

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
        $hours['morning_regular']
        + $hours['morning_special']
        + $hours['evening_regular']
        + $hours['evening_special'],
        2
    );

    if ($hours['total'] <= 0) {
        return;
    }

    if ($effectiveRate > 0) {
        $amounts = [
            'morning_regular' => round($hours['morning_regular'] * $effectiveRate, 2),
            'morning_special' => round($hours['morning_special'] * $effectiveRate, 2),
            'evening_regular' => round($hours['evening_regular'] * $effectiveRate, 2),
            'evening_special' => round($hours['evening_special'] * $effectiveRate, 2),
        ];
    } else {
        $amounts = [
            'morning_regular' => round($hours['morning_regular'] * $morningRate, 2),
            'morning_special' => round($hours['morning_special'] * $morningSpecialRate, 2),
            'evening_regular' => round($hours['evening_regular'] * $nightRate, 2),
            'evening_special' => round($hours['evening_special'] * $nightSpecialRate, 2),
        ];
    }

    $amounts['total'] = round(array_sum($amounts), 2);

    over_time::where('time_cards_id', $outCard->id)->delete();

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

        'holiday_ot_hours' => 0.0,
        'holiday_ot_amount' => 0.0,
        'total_ot_amount' => $amounts['total'],

        'status' => 'pending',
    ]);
}




private function isHoliday(employee $employee, string $date): bool
{
    $org = $employee->organizationAssignment;
    if (!$org) {
        return false;
    }

    return \App\Models\leaveCalendar::where('company_id', $org->company_id)
        ->where(function ($query) use ($org) {
            $query->where(function ($q) {
                // company-wide holiday
                $q->whereNull('department_id');
            });

            if (!empty($org->department_id)) {
                $query->orWhere(function ($q) use ($org) {
                    // department-specific holiday
                    $q->where('department_id', $org->department_id);
                });
            }
        })
        ->where(function ($query) use ($date) {
            $query->where(function ($q) use ($date) {
                // single-day holiday
                $q->whereNull('end_date')
                  ->whereDate('start_date', $date);
            })->orWhere(function ($q) use ($date) {
                // date-range holiday
                $q->whereNotNull('end_date')
                  ->whereDate('start_date', '<=', $date)
                  ->whereDate('end_date', '>=', $date);
            });
        })
        ->exists();
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

        $uploaded = $request->file('file');
        $rows = Excel::toArray(new class implements \Maatwebsite\Excel\Concerns\ToArray {
            public function array(array $array)
            {
            }
        }, $uploaded)[0];

        $results = [
            'imported' => 0,
            'absent' => 0,
            'errors' => [],
        ];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                if ($index === 0) {
                    continue;
                }

                $nic = trim($row[0] ?? '');
                $excelDate = trim($row[1] ?? '');
                $rawTime = trim($row[2] ?? '');
                $entry = trim($row[3] ?? '');
                $status = trim($row[4] ?? '');
                $reason = isset($row[5]) ? trim($row[5]) : null;

                try {
                    if (is_numeric($excelDate)) {
                        $unixDate = ($excelDate - 25569) * 86400;
                        $date = gmdate('Y-m-d', $unixDate);
                    } else {
                        $date = date('Y-m-d', strtotime($excelDate));
                    }
                } catch (\Exception $ex) {
                    $results['errors'][] = "Row $index: Invalid date format";
                    continue;
                }

                if ($date < $fromDate) {
                    continue;
                }
                if ($toDate && $date > $toDate) {
                    continue;
                }

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
                    $results['errors'][] = "Row $index: Invalid time format";
                    continue;
                }

                $identifier = trim($nic);
                $employeeQuery = employee::where(function ($q) use ($identifier) {
                    $q->whereRaw('LOWER(nic) = ?', [strtolower($identifier)])
                        ->orWhere('attendance_employee_no', $identifier);
                });

                if (!empty($companyId)) {
                    $employeeQuery->whereHas('organizationAssignment', function ($q) use ($companyId) {
                        $q->where('company_id', $companyId);
                    });
                }

                $employee = $employeeQuery->first();

                if (!$employee) {
                    $results['errors'][] = "Row $index: Employee not found for NIC/Attendance number in selected company";
                    continue;
                }

                [$roster, $shift] = $this->resolveRosterAndShift($employee, $date);

                if (!$roster) {
                    $results['errors'][] = "Row $index: No shift/roster assigned for this employee on $date";
                    continue;
                }

                if (!$shift) {
                    $results['errors'][] = "Row $index: Shift not found for roster";
                    continue;
                }

                $statusUpper = strtoupper($status);

                if (in_array($statusUpper, ['IN', 'OUT', 'EARLY OUT', 'LATE COMING'])) {
                    $entryType = (int) $entry;
                    $working_hours = null;
                    $actual_date = null;
                    $lastInCard = null;
                    $finalStatus = $statusUpper;

                    if (in_array($statusUpper, ['OUT', 'EARLY OUT'])) {
                        $morningOutRecord = Carbon::parse($time)->hour < 12;

                        if ($morningOutRecord) {
                            $previousDayIN = time_card::where('employee_id', $employee->id)
                                ->whereIn('status', ['IN', 'Late Coming'])
                                ->where('date', '<', $date)
                                ->whereNotExists(function ($query) {
                                    $query->select(DB::raw(1))
                                        ->from('time_cards as tc')
                                        ->whereRaw('tc.actual_date = time_cards.date')
                                        ->whereIn('tc.status', ['OUT', 'Early OUT']);
                                })
                                ->orderBy('date', 'desc')
                                ->orderBy('time', 'desc')
                                ->first();

                            if ($previousDayIN) {
                                $lastInCard = $previousDayIN;
                            } else {
                                $lastInCard = time_card::where('employee_id', $employee->id)
                                    ->where('date', $date)
                                    ->whereIn('status', ['IN', 'Late Coming'])
                                    ->where('time', '<', $time)
                                    ->orderBy('time', 'desc')
                                    ->first();

                                if (!$lastInCard) {
                                    $lastInCard = time_card::where('employee_id', $employee->id)
                                        ->whereIn('status', ['IN', 'Late Coming'])
                                        ->where('date', '<', $date)
                                        ->orderBy('date', 'desc')
                                        ->orderBy('time', 'desc')
                                        ->first();
                                }
                            }
                        } else {
                            $lastInCard = time_card::where('employee_id', $employee->id)
                                ->where('date', $date)
                                ->whereIn('status', ['IN', 'Late Coming'])
                                ->orderBy('time', 'desc')
                                ->first();

                            if (!$lastInCard) {
                                $lastInCard = time_card::where('employee_id', $employee->id)
                                    ->whereIn('status', ['IN', 'Late Coming'])
                                    ->where('date', '<', $date)
                                    ->orderBy('date', 'desc')
                                    ->orderBy('time', 'desc')
                                    ->first();
                            }
                        }

                        if ($lastInCard) {
                            $inDate = $lastInCard->date;
                            $outDate = $date;

                            $inDateTime = Carbon::parse($inDate . ' ' . $lastInCard->time);
                            $outDateTime = Carbon::parse($outDate . ' ' . $time);
                            $working_hours = round($inDateTime->floatDiffInHours($outDateTime), 2);

                            $shiftStartDT = Carbon::parse($inDate . ' ' . $shift->start_time);
                            $shiftEndDT = Carbon::parse($inDate . ' ' . $shift->end_time);
                            if ($shiftEndDT->lte($shiftStartDT)) {
                                $shiftEndDT->addDay();
                            }

                            $actual_date = ($inDate !== $outDate) ? $inDate : null;

                            if ($outDateTime->lt($shiftEndDT)) {
                                $entryType = 0;
                                $finalStatus = 'Early OUT';
                            } else {
                                $entryType = 2;
                                $finalStatus = 'OUT';
                            }
                        }
                    } else {
                        $entryType = 1;
                        $working_hours = null;
                        $finalStatus = $this->resolveInStatus($date, $time, $shift);
                    }

                    $exists = time_card::where('employee_id', $employee->id)
                        ->where('date', $date)
                        ->where('time', $time)
                        ->where('entry', $entryType)
                        ->where('status', $finalStatus)
                        ->whereNull('deleted_at')
                        ->exists();

                    if (!$exists) {
                        $timeCard = time_card::create([
                            'employee_id' => $employee->id,
                            'time' => $time,
                            'date' => $date,
                            'working_hours' => $working_hours,
                            'entry' => $entryType,
                            'status' => $finalStatus,
                            'actual_date' => $actual_date,
                        ]);

                        $results['imported']++;

                        if (in_array($finalStatus, ['OUT', 'Early OUT']) && $lastInCard) {
                            $referenceDate = $actual_date ?? $lastInCard->date;
                            $this->processOvertimeForOutPunch($employee, $timeCard, $lastInCard, $shift, $referenceDate);
                        }
                    }
                } elseif ($statusUpper === 'ABSENT') {
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
                    $results['errors'][] = "Row $index: Unknown status";
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Import error',
                'errors' => array_unique($results['errors']),
                'exception' => $e->getMessage()
            ], 500);
        }

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

        $onLeaveCount = leave_master::where('status', 'Approved')
            ->where(function ($query) use ($today) {
                $query->where('leave_date', $today)
                    ->orWhere(function ($q) use ($today) {
                        $q->where('leave_from', '<=', $today)
                            ->where('leave_to', '>=', $today);
                    });
            })
            ->distinct('employee_id')
            ->count('employee_id');

        $presentCount = time_card::where('date', $today)
            ->whereIn('status', ['IN', 'Late Coming'])
            ->distinct('employee_id')
            ->count('employee_id');

        return response()->json([
            'on_leave' => $onLeaveCount,
            'present' => $presentCount
        ]);
    }
}



/*
namespace App\Http\Controllers;

use App\Exports\AttendanceTemplateExport;
use App\Models\absence;
use App\Models\employee;
use App\Models\leave_master;
use App\Models\over_time;
use App\Models\roster;
use App\Models\shifts;
use App\Models\time_card;
use App\Services\Overtime\OvertimeCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

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
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($card) {
                return [
                    'id' => $card->id,
                    'empNo' => $card->employee->attendance_employee_no ?? null,
                    'name' => $card->employee->full_name ?? null,
                    'fingerprintClock' => $card->fingerprint_clock ?? null,
                    'time' => $card->time,
                    'date' => $card->date,
                    'entry' => $card->entry,
                    'inOut' => $card->entry == 1
                        ? 'IN'
                        : ($card->entry == 2 ? 'OUT' : ($card->entry == 0 ? 'Early OUT' : null)),
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

        $entryType = (int) $validated['entry'];
        $status = strtoupper($validated['status']);
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        if ($status === 'OUT' || $status === 'EARLY OUT') {
            $lastInCard = null;
            $morningOutRecord = Carbon::parse($storeTime)->hour < 12;

            if ($morningOutRecord) {
                $previousDayIN = time_card::where('employee_id', $employee->id)
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->where('date', '<', $validated['date'])
                    ->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('time_cards as tc')
                            ->whereRaw('tc.employee_id = time_cards.employee_id')
                            ->whereIn('tc.status', ['OUT', 'Early OUT'])
                            ->where(function ($x) {
                                $x->whereRaw('tc.actual_date = time_cards.date')
                                    ->orWhere(function ($y) {
                                        $y->whereNull('tc.actual_date')
                                            ->whereRaw('tc.date = time_cards.date');
                                    });
                            });
                    })
                    ->orderBy('date', 'desc')
                    ->orderBy('time', 'desc')
                    ->first();

                if ($previousDayIN) {
                    $lastInCard = $previousDayIN;
                } else {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->where('date', $validated['date'])
                        ->whereIn('status', ['IN', 'Late Coming'])
                        ->where('time', '<', $storeTime)
                        ->orderBy('time', 'desc')
                        ->first();

                    if (!$lastInCard) {
                        $lastInCard = time_card::where('employee_id', $employee->id)
                            ->whereIn('status', ['IN', 'Late Coming'])
                            ->where('date', '<', $validated['date'])
                            ->orderBy('date', 'desc')
                            ->orderBy('time', 'desc')
                            ->first();
                    }
                }
            } else {
                $lastInCard = time_card::where('employee_id', $employee->id)
                    ->where('date', $validated['date'])
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->orderBy('time', 'desc')
                    ->first();

                if (!$lastInCard) {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->whereIn('status', ['IN', 'Late Coming'])
                        ->where('date', '<', $validated['date'])
                        ->orderBy('date', 'desc')
                        ->orderBy('time', 'desc')
                        ->first();
                }
            }

            if ($lastInCard) {
                $pairedInCard = $lastInCard;

                $inDate = $lastInCard->date;
                $outDate = $validated['date'];

                $inDateTime = Carbon::parse($inDate . ' ' . $lastInCard->time);
                $outDateTime = Carbon::parse($outDate . ' ' . $storeTime);

                $working_hours = round($inDateTime->floatDiffInHours($outDateTime), 2);

                $shiftStartDT = Carbon::parse($inDate . ' ' . $shift->start_time);
                $shiftEndDT = Carbon::parse($inDate . ' ' . $shift->end_time);
                if ($shiftEndDT->lte($shiftStartDT)) {
                    $shiftEndDT->addDay();
                }

                $actual_date = ($inDate !== $outDate) ? $inDate : null;

                if ($outDateTime->lt($shiftEndDT)) {
                    $entryType = 0;
                    $status = 'Early OUT';
                } else {
                    $entryType = 2;
                    $status = 'OUT';
                }
            }
        } else {
            $entryType = 1;
            $working_hours = null;
            $status = $this->resolveInStatus($validated['date'], $storeTime, $shift);
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

        if (in_array($status, ['OUT', 'Early OUT']) && $pairedInCard) {
            $referenceDate = $actual_date ?? $pairedInCard->date;
            $this->processOvertimeForOutPunch($employee, $timeCard, $pairedInCard, $shift, $referenceDate);
        }

        return response()->json($timeCard, 201);
    }

    public function attendance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'empno' => ['required', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'date' => 'required|date',
            'time' => 'required|date_format:H:i:s',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rawEmpNo = trim($request->empno);

        $employee = employee::where('attendance_employee_no', $rawEmpNo)->first();

        if (!$employee && ctype_digit($rawEmpNo)) {
            $employee = employee::find((int) $rawEmpNo);
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
        $storeTime = $inputTime->format('H:i:s');

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

        $lastCard = time_card::where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->orderBy('date', 'desc')
            ->orderBy('time', 'desc')
            ->first();

        $entryType = 1;
        $status = 'IN';
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        if ($lastCard && in_array($lastCard->status, ['IN', 'Late Coming'])) {
            $pairedInCard = $lastCard;
            $lastInDate = Carbon::parse($lastCard->date);
            $currentDate = Carbon::parse($request->date);

            if ($lastInDate->eq($currentDate)) {
                $inTime = Carbon::parse($lastCard->time);
                $outTime = $inputTime;
                $working_hours = round($inTime->floatDiffInHours($outTime), 2);

                $shiftStartDT = Carbon::parse($request->date . ' ' . $shift->start_time);
                $shiftEndDT = Carbon::parse($request->date . ' ' . $shift->end_time);
                if ($shiftEndDT->lte($shiftStartDT)) {
                    $shiftEndDT->addDay();
                }

                $currentPunchDT = Carbon::parse($request->date . ' ' . $storeTime);

                if ($currentPunchDT->lt($shiftEndDT)) {
                    $entryType = 0;
                    $status = 'Early OUT';
                } else {
                    $entryType = 2;
                    $status = 'OUT';
                }
            } else {
                $inDateTime = Carbon::parse($lastCard->date . ' ' . $lastCard->time);
                $outDateTime = Carbon::parse($request->date . ' ' . $request->time);
                $working_hours = round($inDateTime->floatDiffInHours($outDateTime), 2);

                $entryType = 2;
                $status = 'OUT';
                $actual_date = $lastCard->date;
            }
        } else {
            $entryType = 1;
            $working_hours = null;
            $status = $this->resolveInStatus($request->date, $storeTime, $shift);
        }

        $fingerprintClock = now();

        $duplicate = time_card::where('employee_id', $employee->id)
            ->where('date', $request->date)
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
            'date' => $request->date,
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'fingerprint_clock' => $fingerprintClock,
            'actual_date' => $actual_date,
        ]);

        if (in_array($status, ['OUT', 'Early OUT']) && $pairedInCard) {
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

        $employee = employee::where('nic', $search)
            ->orWhere('attendance_employee_no', $search)
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $cards = time_card::with(['employee.organizationAssignment.department'])
            ->where('employee_id', $employee->id)
            ->whereNull('deleted_at')
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
                    'inOut' => $card->entry == 1
                        ? 'IN'
                        : ($card->entry == 2 ? 'OUT' : ($card->entry == 0 ? 'Early OUT' : null)),
                    'department' => $card->employee->organizationAssignment->department->name ?? null,
                    'status' => $card->status,
                ];
            });

        return response()->json($cards);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'time' => 'required',
            'entry' => 'required|in:0,1,2',
            'status' => 'required|in:IN,Late Coming,OUT,Absent,Early OUT',
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

        $entryType = (int) $validated['entry'];
        $requestedStatus = $validated['status'];
        $status = strtoupper($requestedStatus);
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        if (in_array($requestedStatus, ['OUT', 'Early OUT'])) {
            $lastInCard = null;
            $morningOutRecord = Carbon::parse($storeTime)->hour < 12;

            if ($morningOutRecord) {
                $previousDayIN = time_card::where('employee_id', $employee->id)
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->where('date', '<', $validated['date'])
                    ->where('id', '!=', $timeCard->id)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('time_cards as tc')
                            ->whereRaw('tc.actual_date = time_cards.date')
                            ->whereIn('tc.status', ['OUT', 'Early OUT']);
                    })
                    ->orderBy('date', 'desc')
                    ->orderBy('time', 'desc')
                    ->first();

                if ($previousDayIN) {
                    $lastInCard = $previousDayIN;
                } else {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->where('date', $validated['date'])
                        ->whereIn('status', ['IN', 'Late Coming'])
                        ->where('time', '<', $storeTime)
                        ->where('id', '!=', $timeCard->id)
                        ->orderBy('time', 'desc')
                        ->first();

                    if (!$lastInCard) {
                        $lastInCard = time_card::where('employee_id', $employee->id)
                            ->whereIn('status', ['IN', 'Late Coming'])
                            ->where('date', '<', $validated['date'])
                            ->where('id', '!=', $timeCard->id)
                            ->orderBy('date', 'desc')
                            ->orderBy('time', 'desc')
                            ->first();
                    }
                }
            } else {
                $lastInCard = time_card::where('employee_id', $employee->id)
                    ->where('date', $validated['date'])
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->where('id', '!=', $timeCard->id)
                    ->orderBy('time', 'desc')
                    ->first();

                if (!$lastInCard) {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->whereIn('status', ['IN', 'Late Coming'])
                        ->where('date', '<', $validated['date'])
                        ->where('id', '!=', $timeCard->id)
                        ->orderBy('date', 'desc')
                        ->orderBy('time', 'desc')
                        ->first();
                }
            }

            if ($lastInCard) {
                $pairedInCard = $lastInCard;

                $inDate = $lastInCard->date;
                $outDate = $validated['date'];

                $inDateTime = Carbon::parse($inDate . ' ' . $lastInCard->time);
                $outDateTime = Carbon::parse($outDate . ' ' . $storeTime);

                $working_hours = round($inDateTime->floatDiffInHours($outDateTime), 2);

                $shiftStartDT = Carbon::parse($inDate . ' ' . $shift->start_time);
                $shiftEndDT = Carbon::parse($inDate . ' ' . $shift->end_time);
                if ($shiftEndDT->lte($shiftStartDT)) {
                    $shiftEndDT->addDay();
                }

                $actual_date = ($inDate !== $outDate) ? $inDate : null;

                if ($outDateTime->lt($shiftEndDT)) {
                    $entryType = 0;
                    $status = 'Early OUT';
                } else {
                    $entryType = 2;
                    $status = 'OUT';
                }
            }
        } elseif (in_array($requestedStatus, ['IN', 'Late Coming'])) {
            $entryType = 1;
            $working_hours = null;
            $actual_date = null;
            $status = $this->resolveInStatus($validated['date'], $storeTime, $shift);
        } else {
            $entryType = 0;
            $status = 'ABSENT';
        }

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

        over_time::where('time_cards_id', $timeCard->id)->delete();

        $timeCard->update([
            'time' => $storeTime,
            'date' => $validated['date'],
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'actual_date' => $actual_date,
        ]);

        if (in_array($status, ['OUT', 'Early OUT']) && $pairedInCard) {
            $referenceDate = $actual_date ?? $pairedInCard->date;
            $this->processOvertimeForOutPunch($employee, $timeCard, $pairedInCard, $shift, $referenceDate);
        }

        return response()->json([
            'message' => 'Time card updated successfully',
            'data' => $timeCard
        ]);
    }

    public function destroy($id)
    {
        $timeCard = time_card::findOrFail($id);

        over_time::where('time_cards_id', $timeCard->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

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

    private function resolveInStatus(string $date, string $time, ?shifts $shift, int $graceMinutes = 10): string
    {
        if (!$shift) {
            return 'IN';
        }

        $shiftStartDateTime = Carbon::parse($date . ' ' . $shift->start_time);
        $punchDateTime = Carbon::parse($date . ' ' . $time);

        return $punchDateTime->gt($shiftStartDateTime->copy()->addMinutes($graceMinutes))
            ? 'Late Coming'
            : 'IN';
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

    $comp = $employee->compensation;
    if (!$comp || !$comp->ot_active) {
        return;
    }

    $clockIn = Carbon::parse($inCard->date . ' ' . $inCard->time);
    $clockOut = Carbon::parse($outCard->date . ' ' . $outCard->time);

    if ($clockOut->lte($clockIn)) {
        return;
    }

    $isHoliday = $this->isHoliday($employee, $targetDate);

    $breakdown = $this->overtimeCalculator->calculate(
        $employee,
        $resolvedShift,
        $clockIn,
        $clockOut,
        $isHoliday
    );

    $effectiveRate = (float) ($breakdown['meta']['effective_ot_hourly_rate'] ?? 0);

    $morningRate = (float) ($comp->ot_morning_rate ?? 0);
    $nightRate = (float) ($comp->ot_night_rate ?? 0);
    $morningSpecialRate = (float) ($comp->ot_morning_rate_special ?? $morningRate);
    $nightSpecialRate = (float) ($comp->ot_night_rate_special ?? $nightRate);

    // =========================
    // HOLIDAY DAY
    // =========================
    if ($isHoliday) {
        $holidayHours = round($clockIn->floatDiffInHours($clockOut), 2);

        if ($holidayHours <= 0) {
            return;
        }

        // shift_overtime_rates තිබුණොත් effectiveRate use වෙයි.
        // නැත්නම් compensation fallback rate use වෙයි.
        $fallbackHolidayRate = $nightRate > 0 ? $nightRate : $morningRate;
        $holidayRateToUse = $effectiveRate > 0 ? $effectiveRate : $fallbackHolidayRate;
        $holidayAmount = round($holidayHours * $holidayRateToUse, 2);

        // old rows remove කරලා fresh holiday row create කරන්න
        over_time::where('time_cards_id', $outCard->id)->delete();

        over_time::create([
            'employee_id' => $employee->id,
            'shift_code' => $resolvedShift->id,
            'time_cards_id' => $outCard->id,

            'ot_hours' => $holidayHours,

            'morning_ot' => 0.0,
            'afternoon_ot' => 0.0,
            'morning_ot_special' => 0.0,
            'evening_ot_special' => 0.0,

            'morning_ot_amount' => 0.0,
            'morning_ot_special_amount' => 0.0,
            'evening_ot_amount' => 0.0,
            'evening_ot_special_amount' => 0.0,

            'holiday_ot_hours' => $holidayHours,
            'holiday_ot_amount' => $holidayAmount,
            'total_ot_amount' => $holidayAmount,

            'status' => 'pending',
        ]);

        return;
    }

    // =========================
    // NORMAL DAY
    // =========================
    $hours = array_merge([
        'morning_regular' => 0.0,
        'morning_special' => 0.0,
        'evening_regular' => 0.0,
        'evening_special' => 0.0,
        'total' => 0.0,
    ], $breakdown['hours'] ?? []);

    $allowMorning = (bool) ($comp->ot_morning ?? false);
    $allowEvening = (bool) ($comp->ot_evening ?? false);
    $allowMorningSpecial = (bool) ($comp->ot_morning_special ?? false);
    $allowEveningSpecial = (bool) ($comp->ot_evening_special ?? false);

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
        $hours['morning_regular']
        + $hours['morning_special']
        + $hours['evening_regular']
        + $hours['evening_special'],
        2
    );

    if ($hours['total'] <= 0) {
        return;
    }

    if ($effectiveRate > 0) {
        $amounts = [
            'morning_regular' => round($hours['morning_regular'] * $effectiveRate, 2),
            'morning_special' => round($hours['morning_special'] * $effectiveRate, 2),
            'evening_regular' => round($hours['evening_regular'] * $effectiveRate, 2),
            'evening_special' => round($hours['evening_special'] * $effectiveRate, 2),
        ];
    } else {
        $amounts = [
            'morning_regular' => round($hours['morning_regular'] * $morningRate, 2),
            'morning_special' => round($hours['morning_special'] * $morningSpecialRate, 2),
            'evening_regular' => round($hours['evening_regular'] * $nightRate, 2),
            'evening_special' => round($hours['evening_special'] * $nightSpecialRate, 2),
        ];
    }

    $amounts['total'] = round(array_sum($amounts), 2);

    over_time::where('time_cards_id', $outCard->id)->delete();

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

        'holiday_ot_hours' => 0.0,
        'holiday_ot_amount' => 0.0,
        'total_ot_amount' => $amounts['total'],

        'status' => 'pending',
    ]);
}




private function isHoliday(employee $employee, string $date): bool
{
    $org = $employee->organizationAssignment;
    if (!$org) {
        return false;
    }

    return \App\Models\leaveCalendar::where('company_id', $org->company_id)
        ->where(function ($query) use ($org) {
            $query->where(function ($q) {
                // company-wide holiday
                $q->whereNull('department_id');
            });

            if (!empty($org->department_id)) {
                $query->orWhere(function ($q) use ($org) {
                    // department-specific holiday
                    $q->where('department_id', $org->department_id);
                });
            }
        })
        ->where(function ($query) use ($date) {
            $query->where(function ($q) use ($date) {
                // single-day holiday
                $q->whereNull('end_date')
                  ->whereDate('start_date', $date);
            })->orWhere(function ($q) use ($date) {
                // date-range holiday
                $q->whereNotNull('end_date')
                  ->whereDate('start_date', '<=', $date)
                  ->whereDate('end_date', '>=', $date);
            });
        })
        ->exists();
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

        $uploaded = $request->file('file');
        $rows = Excel::toArray(new class implements \Maatwebsite\Excel\Concerns\ToArray {
            public function array(array $array)
            {
            }
        }, $uploaded)[0];

        $results = [
            'imported' => 0,
            'absent' => 0,
            'errors' => [],
        ];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                if ($index === 0) {
                    continue;
                }

                $nic = trim($row[0] ?? '');
                $excelDate = trim($row[1] ?? '');
                $rawTime = trim($row[2] ?? '');
                $entry = trim($row[3] ?? '');
                $status = trim($row[4] ?? '');
                $reason = isset($row[5]) ? trim($row[5]) : null;

                try {
                    if (is_numeric($excelDate)) {
                        $unixDate = ($excelDate - 25569) * 86400;
                        $date = gmdate('Y-m-d', $unixDate);
                    } else {
                        $date = date('Y-m-d', strtotime($excelDate));
                    }
                } catch (\Exception $ex) {
                    $results['errors'][] = "Row $index: Invalid date format";
                    continue;
                }

                if ($date < $fromDate) {
                    continue;
                }
                if ($toDate && $date > $toDate) {
                    continue;
                }

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
                    $results['errors'][] = "Row $index: Invalid time format";
                    continue;
                }

                $identifier = trim($nic);
                $employeeQuery = employee::where(function ($q) use ($identifier) {
                    $q->whereRaw('LOWER(nic) = ?', [strtolower($identifier)])
                        ->orWhere('attendance_employee_no', $identifier);
                });

                if (!empty($companyId)) {
                    $employeeQuery->whereHas('organizationAssignment', function ($q) use ($companyId) {
                        $q->where('company_id', $companyId);
                    });
                }

                $employee = $employeeQuery->first();

                if (!$employee) {
                    $results['errors'][] = "Row $index: Employee not found for NIC/Attendance number in selected company";
                    continue;
                }

                [$roster, $shift] = $this->resolveRosterAndShift($employee, $date);

                if (!$roster) {
                    $results['errors'][] = "Row $index: No shift/roster assigned for this employee on $date";
                    continue;
                }

                if (!$shift) {
                    $results['errors'][] = "Row $index: Shift not found for roster";
                    continue;
                }

                $statusUpper = strtoupper($status);

                if (in_array($statusUpper, ['IN', 'OUT', 'EARLY OUT', 'LATE COMING'])) {
                    $entryType = (int) $entry;
                    $working_hours = null;
                    $actual_date = null;
                    $lastInCard = null;
                    $finalStatus = $statusUpper;

                    if (in_array($statusUpper, ['OUT', 'EARLY OUT'])) {
                        $morningOutRecord = Carbon::parse($time)->hour < 12;

                        if ($morningOutRecord) {
                            $previousDayIN = time_card::where('employee_id', $employee->id)
                                ->whereIn('status', ['IN', 'Late Coming'])
                                ->where('date', '<', $date)
                                ->whereNotExists(function ($query) {
                                    $query->select(DB::raw(1))
                                        ->from('time_cards as tc')
                                        ->whereRaw('tc.actual_date = time_cards.date')
                                        ->whereIn('tc.status', ['OUT', 'Early OUT']);
                                })
                                ->orderBy('date', 'desc')
                                ->orderBy('time', 'desc')
                                ->first();

                            if ($previousDayIN) {
                                $lastInCard = $previousDayIN;
                            } else {
                                $lastInCard = time_card::where('employee_id', $employee->id)
                                    ->where('date', $date)
                                    ->whereIn('status', ['IN', 'Late Coming'])
                                    ->where('time', '<', $time)
                                    ->orderBy('time', 'desc')
                                    ->first();

                                if (!$lastInCard) {
                                    $lastInCard = time_card::where('employee_id', $employee->id)
                                        ->whereIn('status', ['IN', 'Late Coming'])
                                        ->where('date', '<', $date)
                                        ->orderBy('date', 'desc')
                                        ->orderBy('time', 'desc')
                                        ->first();
                                }
                            }
                        } else {
                            $lastInCard = time_card::where('employee_id', $employee->id)
                                ->where('date', $date)
                                ->whereIn('status', ['IN', 'Late Coming'])
                                ->orderBy('time', 'desc')
                                ->first();

                            if (!$lastInCard) {
                                $lastInCard = time_card::where('employee_id', $employee->id)
                                    ->whereIn('status', ['IN', 'Late Coming'])
                                    ->where('date', '<', $date)
                                    ->orderBy('date', 'desc')
                                    ->orderBy('time', 'desc')
                                    ->first();
                            }
                        }

                        if ($lastInCard) {
                            $inDate = $lastInCard->date;
                            $outDate = $date;

                            $inDateTime = Carbon::parse($inDate . ' ' . $lastInCard->time);
                            $outDateTime = Carbon::parse($outDate . ' ' . $time);
                            $working_hours = round($inDateTime->floatDiffInHours($outDateTime), 2);

                            $shiftStartDT = Carbon::parse($inDate . ' ' . $shift->start_time);
                            $shiftEndDT = Carbon::parse($inDate . ' ' . $shift->end_time);
                            if ($shiftEndDT->lte($shiftStartDT)) {
                                $shiftEndDT->addDay();
                            }

                            $actual_date = ($inDate !== $outDate) ? $inDate : null;

                            if ($outDateTime->lt($shiftEndDT)) {
                                $entryType = 0;
                                $finalStatus = 'Early OUT';
                            } else {
                                $entryType = 2;
                                $finalStatus = 'OUT';
                            }
                        }
                    } else {
                        $entryType = 1;
                        $working_hours = null;
                        $finalStatus = $this->resolveInStatus($date, $time, $shift);
                    }

                    $exists = time_card::where('employee_id', $employee->id)
                        ->where('date', $date)
                        ->where('time', $time)
                        ->where('entry', $entryType)
                        ->where('status', $finalStatus)
                        ->whereNull('deleted_at')
                        ->exists();

                    if (!$exists) {
                        $timeCard = time_card::create([
                            'employee_id' => $employee->id,
                            'time' => $time,
                            'date' => $date,
                            'working_hours' => $working_hours,
                            'entry' => $entryType,
                            'status' => $finalStatus,
                            'actual_date' => $actual_date,
                        ]);

                        $results['imported']++;

                        if (in_array($finalStatus, ['OUT', 'Early OUT']) && $lastInCard) {
                            $referenceDate = $actual_date ?? $lastInCard->date;
                            $this->processOvertimeForOutPunch($employee, $timeCard, $lastInCard, $shift, $referenceDate);
                        }
                    }
                } elseif ($statusUpper === 'ABSENT') {
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
                    $results['errors'][] = "Row $index: Unknown status";
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Import error',
                'errors' => array_unique($results['errors']),
                'exception' => $e->getMessage()
            ], 500);
        }

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

        $onLeaveCount = leave_master::where('status', 'Approved')
            ->where(function ($query) use ($today) {
                $query->where('leave_date', $today)
                    ->orWhere(function ($q) use ($today) {
                        $q->where('leave_from', '<=', $today)
                            ->where('leave_to', '>=', $today);
                    });
            })
            ->distinct('employee_id')
            ->count('employee_id');

        $presentCount = time_card::where('date', $today)
            ->whereIn('status', ['IN', 'Late Coming'])
            ->distinct('employee_id')
            ->count('employee_id');

        return response()->json([
            'on_leave' => $onLeaveCount,
            'present' => $presentCount
        ]);
    }
}

*/
