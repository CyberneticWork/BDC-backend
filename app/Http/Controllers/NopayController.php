<?php

namespace App\Http\Controllers;

use App\Models\NoPayRecord;
use App\Models\employee;
use App\Models\leave_master;
use App\Models\Roster;
use App\Models\shifts;
use App\Models\time_card;
use App\Models\leaveCalendar;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class NopayController extends Controller
{
    public function index(Request $request)
    {
        $query = NoPayRecord::with(['employee', 'processedBy'])
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        } else {
            if ($request->filled('month')) {
                $query->whereMonth('date', $request->month);
            }

            if ($request->filled('year')) {
                $query->whereYear('date', $request->year);
            }
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('company_id')) {
            $query->whereHas('employee.organizationAssignment', function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });
        }

        // Search Filter (දැන් Employee ID, Name සහ NIC වලින් සර්ච් කළ හැක)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($mainQuery) use ($search) {
                $mainQuery->where('description', 'like', "%{$search}%")
                    ->orWhereHas('employee', function ($q) use ($search) {
                        $q->where('full_name', 'like', "%{$search}%")
                            ->orWhere('attendance_employee_no', 'like', "%{$search}%")
                            ->orWhere('nic', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('page') && $request->filled('per_page')) {
            $records = $query->paginate((int) $request->per_page);

            $records->getCollection()->transform(function ($record) {
                $record->display_value = $this->getDisplayValue($record);
                return $record;
            });

            return response()->json($records);
        }

        $records = $query->get()->map(function ($record) {
            $record->display_value = $this->getDisplayValue($record);
            return $record;
        });

        return response()->json($records);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id'  => 'required|exists:employees,id',
            'date'         => 'required|date',
            'no_pay_count' => 'required|numeric|min:0.01|max:2',
            'description'  => 'required|string|max:500',
            'status'       => 'sometimes|in:Pending,Approved,Rejected',
            'hours'        => 'nullable|numeric|min:0',
            'minutes'      => 'nullable|integer|min:0|max:59',
            'start_time'   => 'nullable',
            'end_time'     => 'nullable',
            'type'         => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $record = NoPayRecord::create([
            'employee_id'  => $request->employee_id,
            'date'         => $request->date,
            'no_pay_count' => $request->no_pay_count,
            'description'  => $request->description,
            'status'       => $request->status ?? 'Pending',
            'processed_by' => Auth::id(),
            'hours'        => $request->hours,
            'minutes'      => $request->minutes,
            'start_time'   => $request->start_time,
            'end_time'     => $request->end_time,
            'type'         => $request->type,
        ]);

        $record->load(['employee', 'processedBy']);
        $record->display_value = $this->getDisplayValue($record);

        return response()->json($record, 201);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'no_pay_count' => 'sometimes|numeric|min:0.01|max:2',
            'description'  => 'sometimes|string|max:500',
            'status'       => 'sometimes|in:Pending,Approved,Rejected',
            'hours'        => 'sometimes|numeric|min:0',
            'minutes'      => 'sometimes|integer|min:0|max:59',
            'start_time'   => 'nullable',
            'end_time'     => 'nullable',
            'type'         => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $record = NoPayRecord::findOrFail($id);
        $record->update($request->all());
        $record->load(['employee', 'processedBy']);
        $record->display_value = $this->getDisplayValue($record);

        return response()->json($record);
    }

    public function bulkUpdateStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'exists:no_pay_records,id',
            'status' => 'required|in:Pending,Approved,Rejected',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $updatedCount = NoPayRecord::whereIn('id', $request->ids)
            ->update([
                'status' => $request->status,
                'processed_by' => Auth::id(),
            ]);

        return response()->json([
            'message' => 'Successfully updated ' . $updatedCount . ' records',
            'updated_count' => $updatedCount
        ]);
    }

    public function destroy($id)
    {
        $record = NoPayRecord::findOrFail($id);
        $record->delete();

        return response()->json(null, 204);
    }

    public function bulkDestroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'exists:no_pay_records,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $deletedCount = NoPayRecord::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => 'Successfully deleted ' . $deletedCount . ' records',
            'deleted_count' => $deletedCount
        ]);
    }

    // =====================================================================
    // GENERATE DAILY NO-PAY RECORDS
    // =====================================================================
    public function generateDailyNoPayRecords(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date|before:today', 
            'company_id' => 'sometimes|exists:companies,id',
            'status' => 'sometimes|in:Pending,Approved,Rejected',
        ], [
            'date.before' => 'Cannot generate no-pay for today or future dates. Please select yesterday or a past date.'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $date = $request->date;
        $status = $request->status ?? 'Pending';

        $employeesQuery = employee::where('is_active', '1');

        if ($request->filled('company_id')) {
            $companyId = $request->company_id;
            $employeesQuery->whereHas('organizationAssignment', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        }

        $employees = $employeesQuery->with(['organizationAssignment', 'compensation'])->get();

        $generatedRecords = [];
        $skipped = [];

        foreach ($employees as $employee) {
            $org = $employee->organizationAssignment;

            if (!$org) {
                $skipped[] = [
                    'employee_id' => $employee->id, 
                    'attendance_employee_no' => $employee->attendance_employee_no, 
                    'reason' => 'No organization assignment'
                ];
                continue;
            }

            // සේවකයා වැඩට බැඳුණු දිනයට කලින් දවස් වලට No Pay හදන්නේ නෑ
            if ($org->date_of_joining) {
                $joinDate = Carbon::parse($org->date_of_joining)->startOfDay();
                if (Carbon::parse($date)->lt($joinDate)) {
                    $skipped[] = [
                        'employee_id' => $employee->id, 
                        'attendance_employee_no' => $employee->attendance_employee_no, 
                        'reason' => 'Before date of joining'
                    ];
                    continue;
                }
            }

            if ($employee->compensation && $employee->compensation->active_nopay === false) {
                NoPayRecord::where('employee_id', $employee->id)->where('date', $date)->delete();
                $skipped[] = [
                    'employee_id' => $employee->id, 
                    'attendance_employee_no' => $employee->attendance_employee_no, 
                    'reason' => 'NoPay inactive in compensation'
                ];
                continue;
            }

            $dayOff = $org->day_off ?? null;
            $dayName = Carbon::parse($date)->format('l');

            if ($dayOff && strtolower($dayOff) === strtolower($dayName)) {
                NoPayRecord::where('employee_id', $employee->id)->where('date', $date)->delete();
                $skipped[] = [
                    'employee_id' => $employee->id, 
                    'attendance_employee_no' => $employee->attendance_employee_no, 
                    'reason' => 'Employee day off'
                ];
                continue;
            }

            [$roster, $shift] = $this->resolveRosterAndShift($employee, $date);

            if (!$roster || !$shift) {
                $skipped[] = [
                    'employee_id' => $employee->id, 
                    'attendance_employee_no' => $employee->attendance_employee_no, 
                    'reason' => 'No roster/shift'
                ];
                continue;
            }

            if ($this->checkCompanyHoliday($employee, $date)) {
                NoPayRecord::where('employee_id', $employee->id)->where('date', $date)->delete();
                $skipped[] = [
                    'employee_id' => $employee->id, 
                    'attendance_employee_no' => $employee->attendance_employee_no, 
                    'reason' => 'Company/department holiday'
                ];
                continue;
            }

            if ($this->hasApprovedFullDayLeave($employee->id, $date)) {
                NoPayRecord::where('employee_id', $employee->id)->where('date', $date)->delete();
                $skipped[] = [
                    'employee_id' => $employee->id, 
                    'attendance_employee_no' => $employee->attendance_employee_no, 
                    'reason' => 'Approved full-day leave'
                ];
                continue;
            }

            $dayResult = $this->buildNoPayForEmployeeDay($employee, $date, $shift, $status);

            if (!empty($dayResult)) {
                foreach ($dayResult as $record) {
                    $record->load(['employee', 'processedBy']);
                    $record->display_value = $this->getDisplayValue($record);
                    $generatedRecords[] = $record;
                }
            }
        }

        return response()->json([
            'message' => count($generatedRecords) . ' no-pay record(s) generated',
            'records' => $generatedRecords,
            'skipped' => $skipped,
        ]);
    }

    // =====================================================================
    // GENERATE MONTHLY NO-PAY RECORDS
    // =====================================================================
    public function generateMonthlyNoPayRecords(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000|max:2100',
            'company_id' => 'sometimes|exists:companies,id',
            'status' => 'sometimes|in:Pending,Approved,Rejected',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $month = (int) $request->month;
        $year = (int) $request->year;
        $status = $request->status ?? 'Pending';

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        $yesterday = Carbon::yesterday(); 

        if ($startDate->greaterThan($yesterday)) {
            return response()->json([
                'month' => ['Cannot generate no-pay for future months or current day.']
            ], 422);
        }

        if ($endDate->greaterThan($yesterday)) {
            $endDate = $yesterday;
        }

        $allGenerated = [];
        $allSkipped = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $employeesQuery = employee::where('is_active', '1');

            if ($request->filled('company_id')) {
                $companyId = $request->company_id;
                $employeesQuery->whereHas('organizationAssignment', function ($q) use ($companyId) {
                    $q->where('company_id', $companyId);
                });
            }

            $employees = $employeesQuery->with(['organizationAssignment', 'compensation'])->get();

            foreach ($employees as $employee) {
                $dateString = $date->format('Y-m-d');
                $org = $employee->organizationAssignment;

                if (!$org) {
                    $allSkipped[] = ['date' => $dateString, 'employee_id' => $employee->id, 'reason' => 'No organization assignment'];
                    continue;
                }

                // සේවකයා වැඩට බැඳුණු දිනයට කලින් දවස් වලට No Pay හදන්නේ නෑ
                if ($org->date_of_joining) {
                    $joinDate = Carbon::parse($org->date_of_joining)->startOfDay();
                    if ($date->lt($joinDate)) {
                        $allSkipped[] = ['date' => $dateString, 'employee_id' => $employee->id, 'reason' => 'Before date of joining'];
                        continue;
                    }
                }

                if ($employee->compensation && $employee->compensation->active_nopay === false) {
                    NoPayRecord::where('employee_id', $employee->id)->where('date', $dateString)->delete();
                    $allSkipped[] = ['date' => $dateString, 'employee_id' => $employee->id, 'reason' => 'NoPay inactive in compensation'];
                    continue;
                }

                $dayOff = $org->day_off ?? null;
                $dayName = $date->format('l');

                if ($dayOff && strtolower($dayOff) === strtolower($dayName)) {
                    NoPayRecord::where('employee_id', $employee->id)->where('date', $dateString)->delete();
                    $allSkipped[] = ['date' => $dateString, 'employee_id' => $employee->id, 'reason' => 'Employee day off'];
                    continue;
                }

                [$roster, $shift] = $this->resolveRosterAndShift($employee, $dateString);

                if (!$roster || !$shift) {
                    $allSkipped[] = ['date' => $dateString, 'employee_id' => $employee->id, 'reason' => 'No roster/shift'];
                    continue;
                }

                if ($this->checkCompanyHoliday($employee, $dateString)) {
                    NoPayRecord::where('employee_id', $employee->id)->where('date', $dateString)->delete();
                    $allSkipped[] = ['date' => $dateString, 'employee_id' => $employee->id, 'reason' => 'Company/department holiday'];
                    continue;
                }

                if ($this->hasApprovedFullDayLeave($employee->id, $dateString)) {
                    NoPayRecord::where('employee_id', $employee->id)->where('date', $dateString)->delete();
                    $allSkipped[] = ['date' => $dateString, 'employee_id' => $employee->id, 'reason' => 'Approved full-day leave'];
                    continue;
                }

                $dayResult = $this->buildNoPayForEmployeeDay($employee, $dateString, $shift, $status);

                if (!empty($dayResult)) {
                    foreach ($dayResult as $record) {
                        $record->load(['employee', 'processedBy']);
                        $record->display_value = $this->getDisplayValue($record);
                        $allGenerated[] = $record;
                    }
                }
            }
        }

        return response()->json([
            'message' => count($allGenerated) . ' no-pay record(s) generated for ' . $startDate->format('F Y') . ' (Up to ' . $endDate->format('Y-m-d') . ')',
            'records' => $allGenerated,
            'skipped' => $allSkipped,
        ]);
    }

    // =================================================================
    // GET NO PAY STATS (දැන් Search Filter එකත් එක්කම නිවැරදිව වැඩ කරයි)
    // =================================================================
    public function getNoPayStats(Request $request)
    {
        $query = NoPayRecord::query();

        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        } else {
            if ($request->filled('month')) {
                $query->whereMonth('date', $request->month);
            }

            if ($request->filled('year')) {
                $query->whereYear('date', $request->year);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('company_id')) {
            $query->whereHas('employee.organizationAssignment', function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });
        }

        // Stats වලටත් අනිවාර්යයෙන්ම Search Filter එක දාන්න ඕනේ!
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($mainQuery) use ($search) {
                $mainQuery->where('description', 'like', "%{$search}%")
                    ->orWhereHas('employee', function ($q) use ($search) {
                        $q->where('full_name', 'like', "%{$search}%")
                            ->orWhere('attendance_employee_no', 'like', "%{$search}%")
                            ->orWhere('nic', 'like', "%{$search}%");
                    });
            });
        }

        $records = $query->get()
            ->groupBy(function ($record) {
                return $record->employee_id . '|' . $record->date . '|' . ($record->type ?? 'NO_TYPE');
            })
            ->map(function ($group) {
                return $group->sortBy([
                    fn ($a, $b) => ($a->status === 'Approved' ? 0 : 1) <=> ($b->status === 'Approved' ? 0 : 1),
                    fn ($a, $b) => $b->id <=> $a->id,
                ])->first();
            });

        $totalRecords = $records->count();

        $totalDays = $records->sum(function ($record) {
            if ($record->type === 'FULL_DAY' || (float) $record->no_pay_count === 1.0) {
                return 1;
            }
            return (float) $record->no_pay_count;
        });

        $affectedEmployees = $records->pluck('employee_id')->unique()->count();

        return response()->json([
            'total_records' => $totalRecords,
            'total_days' => $totalDays,
            'affected_employees' => $affectedEmployees
        ]);
    }

    // =====================================================================
    // HELPER FUNCTIONS 
    // =====================================================================
    protected function buildNoPayForEmployeeDay($employee, string $date, $shift, string $status): array
    {
        $records = [];

        $shiftStart = Carbon::parse($date . ' ' . $shift->start_time);
        $shiftEnd = Carbon::parse($date . ' ' . $shift->end_time);

        if ($shiftEnd->lte($shiftStart)) {
            $shiftEnd->addDay();
        }

        $shiftHours = max($shiftStart->floatDiffInHours($shiftEnd), 0.01);

        $allCards = time_card::where('employee_id', $employee->id)
            ->where(function ($q) use ($date) {
                $q->where('date', $date)
                  ->orWhere('actual_date', $date);
            })
            ->whereNull('deleted_at')
            ->orderBy('time', 'asc')
            ->get();

        $inCard = $allCards->first(function ($card) {
            $st = strtolower(trim($card->status));
            return $card->entry == 1 || in_array($st, ['in', 'late coming', 'late_coming']);
        });

        $outCard = $allCards->sortByDesc('time')->first(function ($card) {
            $st = strtolower(trim($card->status));
            return in_array($card->entry, [0, 2]) || in_array($st, ['out', 'early out', 'early_out']);
        });

        if ($inCard || $outCard) {
            NoPayRecord::where('employee_id', $employee->id)
                ->where('date', $date)
                ->whereIn('type', ['FULL_DAY', 'PARTIAL_ABSENT'])
                ->delete();
        } 
        else {
            NoPayRecord::where('employee_id', $employee->id)
                ->where('date', $date)
                ->whereIn('type', ['LATE_IN', 'EARLY_OUT'])
                ->delete();

            $approvedLeaveDuration = $this->getApprovedLeaveDuration($employee->id, $date);
            $noPayAmount = 1 - $approvedLeaveDuration;

            if ($noPayAmount > 0) {
                $existing = NoPayRecord::where('employee_id', $employee->id)
                    ->where('date', $date)
                    ->whereIn('type', ['FULL_DAY', 'PARTIAL_ABSENT'])
                    ->first();

                $type = $noPayAmount == 1 ? 'FULL_DAY' : 'PARTIAL_ABSENT';
                
                $leaveTypeStr = "";
                if ($approvedLeaveDuration == 0.5) $leaveTypeStr = "Half Day (0.5 Days)";
                elseif ($approvedLeaveDuration == 0.25) $leaveTypeStr = "Short Leave (0.25 Days)";
                elseif ($approvedLeaveDuration == 0.75) $leaveTypeStr = "0.75 Days";

                $desc = $noPayAmount == 1 
                    ? 'Automatic full-day no-pay: no attendance, no approved leave.'
                    : "Automatic partial no-pay: no attendance, but had {$leaveTypeStr} approved leave.";

                $data = [
                    'no_pay_count' => $noPayAmount,
                    'description' => $desc,
                    'processed_by' => Auth::id(),
                    'hours' => round($shiftHours * $noPayAmount, 2),
                    'minutes' => 0,
                    'start_time' => $shiftStart->format('H:i:s'),
                    'end_time' => $shiftEnd->format('H:i:s'),
                    'type' => $type,
                ];

                if ($existing) {
                    $existing->update($data);
                    $records[] = $existing;
                } else {
                    $data['employee_id'] = $employee->id;
                    $data['date'] = $date;
                    $data['status'] = $status;
                    $records[] = NoPayRecord::create($data);
                }
            }

            return $records; 
        }

        if ($inCard) {
            $actualIn = Carbon::parse($inCard->date . ' ' . $inCard->time);
            
            if ($actualIn->gt($shiftStart)) {
                $lateMinutes = $shiftStart->diffInMinutes($actualIn);
                
                if ($lateMinutes > 30) {
                    if (!$this->hasApprovedPartialLeaveForWindow($employee->id, $date, $shiftStart, $actualIn, 'LATE_IN')) {
                        $roundedMinutes = (int) (round($lateMinutes / 30) * 30);

                        if ($roundedMinutes > 0) {
                            $gapHours = $roundedMinutes / 60;
                            $descHours = floor($roundedMinutes / 60);
                            $descMins = $roundedMinutes % 60;
                            
                            $records[] = $this->createOrUpdatePartialNoPay(
                                $employee->id, $date, 'LATE_IN', $gapHours, $shiftHours,
                                $shiftStart, $actualIn, $status,
                                "Automatic late-in no-pay: employee arrived late by {$descHours}h {$descMins}m"
                            );
                        }
                    }
                } else {
                    NoPayRecord::where('employee_id', $employee->id)
                        ->where('date', $date)
                        ->where('type', 'LATE_IN')
                        ->delete();
                }
            }
        }

        if ($outCard) {
            $actualOut = Carbon::parse($outCard->date . ' ' . $outCard->time);

            if ($outCard->actual_date && $outCard->actual_date !== $date) {
                $actualOut = Carbon::parse($outCard->actual_date . ' ' . $outCard->time);
            }

            if ($actualOut->lt($shiftStart)) {
                $actualOut->addDay();
            }

            if ($actualOut->lt($shiftEnd)) {
                if (!$this->hasApprovedPartialLeaveForWindow($employee->id, $date, $actualOut, $shiftEnd, 'EARLY_OUT')) {
                    $earlyMinutes = $actualOut->diffInMinutes($shiftEnd);
                    $roundedMinutes = (int) (round($earlyMinutes / 30) * 30);

                    if ($roundedMinutes > 0) {
                        $gapHours = $roundedMinutes / 60;
                        $descHours = floor($roundedMinutes / 60);
                        $descMins = $roundedMinutes % 60;

                        $records[] = $this->createOrUpdatePartialNoPay(
                            $employee->id, $date, 'EARLY_OUT', $gapHours, $shiftHours,
                            $actualOut, $shiftEnd, $status,
                            "Automatic early-out no-pay: employee left early by {$descHours}h {$descMins}m"
                        );
                    }
                }
            } else {
                NoPayRecord::where('employee_id', $employee->id)
                    ->where('date', $date)
                    ->where('type', 'EARLY_OUT')
                    ->delete();
            }
        }

        return array_values(array_filter($records));
    }

    protected function createOrUpdatePartialNoPay(
        int $employeeId, string $date, string $type, float $gapHours, float $shiftHours,
        Carbon $from, Carbon $to, string $status, string $description
    ) {
        $minutes = (int) round($gapHours * 60);
        $hoursPart = floor($minutes / 60);
        $minutePart = $minutes % 60;
        $count = round($gapHours / $shiftHours, 4);

        $allRows = NoPayRecord::where('employee_id', $employeeId)
            ->where('date', $date)
            ->where('type', $type)
            ->orderByRaw("CASE WHEN status = 'Approved' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        if ($allRows->count() > 1) {
            $allRows->shift();
            foreach ($allRows as $extraRow) {
                $extraRow->delete();
            }
        }

        $existing = NoPayRecord::where('employee_id', $employeeId)
            ->where('date', $date)
            ->where('type', $type)
            ->first();

        if ($existing) {
            $existing->update([
                'no_pay_count' => $count,
                'description' => $description,
                'processed_by' => Auth::id(),
                'hours' => $hoursPart,
                'minutes' => $minutePart,
                'start_time' => $from->format('H:i:s'),
                'end_time' => $to->format('H:i:s'),
                'type' => $type,
            ]);

            return $existing;
        }

        return NoPayRecord::create([
            'employee_id' => $employeeId,
            'date' => $date,
            'no_pay_count' => $count,
            'description' => $description,
            'status' => $status,
            'processed_by' => Auth::id(),
            'hours' => $hoursPart,
            'minutes' => $minutePart,
            'start_time' => $from->format('H:i:s'),
            'end_time' => $to->format('H:i:s'),
            'type' => $type,
        ]);
    }

    protected function resolveRosterAndShift(employee $employee, string $date): array
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

    protected function getApprovedLeaveDuration(int $employeeId, string $date): float
    {
        $leaves = leave_master::where('employee_id', $employeeId)
            ->where('status', 'Approved')
            ->where(function ($query) use ($date) {
                $query->whereDate('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->whereDate('leave_from', '<=', $date)
                          ->whereDate('leave_to', '>=', $date);
                    });
            })
            ->get();

        $totalDuration = 0;
        foreach ($leaves as $leave) {
            if ($leave->is_short_leave || (float)$leave->leave_duration == 0.25) {
                $totalDuration += 0.25;
            } elseif ($leave->is_half_day || (float)$leave->leave_duration == 0.5) {
                $totalDuration += 0.5;
            } else {
                $totalDuration += (float)($leave->leave_duration ?? 1);
            }
        }
        
        return min($totalDuration, 1);
    }

    protected function hasApprovedFullDayLeave(int $employeeId, string $date): bool
    {
        return leave_master::where('employee_id', $employeeId)
            ->where('status', 'Approved')
            ->where(function ($query) use ($date) {
                $query->whereDate('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->whereDate('leave_from', '<=', $date)
                          ->whereDate('leave_to', '>=', $date);
                    });
            })
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('is_half_day', 0)
                      ->where('is_short_leave', 0);
                })->orWhere('leave_duration', '>=', 1);
            })
            ->exists();
    }

    protected function hasApprovedPartialLeaveForWindow(
        int $employeeId, string $date, Carbon $from, Carbon $to, string $mode = 'EARLY_OUT'
    ): bool {
        $leaves = leave_master::where('employee_id', $employeeId)
            ->where('status', 'Approved')
            ->where(function ($query) use ($date) {
                $query->whereDate('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->whereDate('leave_from', '<=', $date)
                          ->whereDate('leave_to', '>=', $date);
                    });
            })
            ->get();

        foreach ($leaves as $leave) {
            if ($this->isPartialLeaveRecord($leave)) {
                return true;
            }
        }

        return false;
    }

    protected function isPartialLeaveRecord($leave): bool
    {
        $possibleValues = [
            strtolower((string) ($leave->leave_type ?? '')),
            strtolower((string) ($leave->leave_category ?? '')),
            strtolower((string) ($leave->duration_type ?? '')),
        ];

        foreach ($possibleValues as $value) {
            if (in_array($value, [
                'short leave', 'short_leave', 'short', 'half day', 'half-day', 'half_day', 'halfday'
            ])) {
                return true;
            }
        }

        if (isset($leave->is_short_leave) && (int) $leave->is_short_leave === 1) {
            return true;
        }

        if (isset($leave->is_half_day) && (int) $leave->is_half_day === 1) {
            return true;
        }

        return false;
    }

    protected function checkCompanyHoliday($employee, $date): bool
    {
        $orgAssignment = $employee->organizationAssignment;
        if (!$orgAssignment) {
            return false;
        }

        $companyHoliday = leaveCalendar::where('company_id', $orgAssignment->company_id)
            ->where(function ($query) use ($date) {
                $query->where(function ($q) use ($date) {
                    $q->whereNull('end_date')->whereDate('start_date', $date);
                })->orWhere(function ($q) use ($date) {
                    $q->whereNotNull('end_date')
                      ->whereDate('start_date', '<=', $date)
                      ->whereDate('end_date', '>=', $date);
                });
            })
            ->exists();

        if ($companyHoliday) {
            return true;
        }

        if ($orgAssignment->department_id) {
            $deptHoliday = leaveCalendar::where('department_id', $orgAssignment->department_id)
                ->where(function ($query) use ($date) {
                    $query->where(function ($q) use ($date) {
                        $q->whereNull('end_date')->whereDate('start_date', $date);
                    })->orWhere(function ($q) use ($date) {
                        $q->whereNotNull('end_date')
                          ->whereDate('start_date', '<=', $date)
                          ->whereDate('end_date', '>=', $date);
                    });
                })
                ->exists();

            if ($deptHoliday) {
                return true;
            }
        }

        return false;
    }

    protected function getDisplayValue($record): string
    {
        if ($record->type === 'FULL_DAY' || (float) $record->no_pay_count == 1.0) {
            return 'Full Day';
        }

        if ($record->type === 'PARTIAL_ABSENT') {
            return $record->no_pay_count . ' Days'; 
        }

        $hours = (int) ($record->hours ?? 0);
        $minutes = (int) ($record->minutes ?? 0);

        if ($hours > 0 && $minutes > 0) {
            return $hours . ' Hours ' . $minutes . ' Minutes';
        }

        if ($hours > 0) {
            return $hours . ' Hours';
        }

        if ($minutes > 0) {
            return $minutes . ' Minutes';
        }

        return (string) $record->no_pay_count;
    }
}


/*
namespace App\Http\Controllers;

use App\Models\NoPayRecord;
use App\Models\employee;
use App\Models\leave_master;
use App\Models\Roster;
use App\Models\shifts;
use App\Models\time_card;
use App\Models\leaveCalendar;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class NopayController extends Controller
{
    public function index(Request $request)
    {
        $query = NoPayRecord::with(['employee', 'processedBy'])
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        } else {
            if ($request->filled('month')) {
                $query->whereMonth('date', $request->month);
            }

            if ($request->filled('year')) {
                $query->whereYear('date', $request->year);
            }
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('company_id')) {
            $query->whereHas('employee.organizationAssignment', function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($mainQuery) use ($search) {
                $mainQuery->where('description', 'like', "%{$search}%")
                    ->orWhereHas('employee', function ($q) use ($search) {
                        $q->where('full_name', 'like', "%{$search}%")
                            ->orWhere('attendance_employee_no', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('page') && $request->filled('per_page')) {
            $records = $query->paginate((int) $request->per_page);

            $records->getCollection()->transform(function ($record) {
                $record->display_value = $this->getDisplayValue($record);
                return $record;
            });

            return response()->json($records);
        }

        $records = $query->get()->map(function ($record) {
            $record->display_value = $this->getDisplayValue($record);
            return $record;
        });

        return response()->json($records);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id'  => 'required|exists:employees,id',
            'date'         => 'required|date',
            'no_pay_count' => 'required|numeric|min:0.01|max:2',
            'description'  => 'required|string|max:500',
            'status'       => 'sometimes|in:Pending,Approved,Rejected',
            'hours'        => 'nullable|numeric|min:0',
            'minutes'      => 'nullable|integer|min:0|max:59',
            'start_time'   => 'nullable',
            'end_time'     => 'nullable',
            'type'         => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $record = NoPayRecord::create([
            'employee_id'  => $request->employee_id,
            'date'         => $request->date,
            'no_pay_count' => $request->no_pay_count,
            'description'  => $request->description,
            'status'       => $request->status ?? 'Pending',
            'processed_by' => Auth::id(),
            'hours'        => $request->hours,
            'minutes'      => $request->minutes,
            'start_time'   => $request->start_time,
            'end_time'     => $request->end_time,
            'type'         => $request->type,
        ]);

        $record->load(['employee', 'processedBy']);
        $record->display_value = $this->getDisplayValue($record);

        return response()->json($record, 201);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'no_pay_count' => 'sometimes|numeric|min:0.01|max:2',
            'description'  => 'sometimes|string|max:500',
            'status'       => 'sometimes|in:Pending,Approved,Rejected',
            'hours'        => 'sometimes|numeric|min:0',
            'minutes'      => 'sometimes|integer|min:0|max:59',
            'start_time'   => 'nullable',
            'end_time'     => 'nullable',
            'type'         => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $record = NoPayRecord::findOrFail($id);
        $record->update($request->all());
        $record->load(['employee', 'processedBy']);
        $record->display_value = $this->getDisplayValue($record);

        return response()->json($record);
    }

    public function bulkUpdateStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'exists:no_pay_records,id',
            'status' => 'required|in:Pending,Approved,Rejected',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $updatedCount = NoPayRecord::whereIn('id', $request->ids)
            ->update([
                'status' => $request->status,
                'processed_by' => Auth::id(),
            ]);

        return response()->json([
            'message' => 'Successfully updated ' . $updatedCount . ' records',
            'updated_count' => $updatedCount
        ]);
    }

    public function destroy($id)
    {
        $record = NoPayRecord::findOrFail($id);
        $record->delete();

        return response()->json(null, 204);
    }

    public function bulkDestroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'exists:no_pay_records,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $deletedCount = NoPayRecord::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => 'Successfully deleted ' . $deletedCount . ' records',
            'deleted_count' => $deletedCount
        ]);
    }

    // =====================================================================
    // GENERATE DAILY NO-PAY RECORDS
    // =====================================================================
    public function generateDailyNoPayRecords(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date|before:today', 
            'company_id' => 'sometimes|exists:companies,id',
            'status' => 'sometimes|in:Pending,Approved,Rejected',
        ], [
            'date.before' => 'Cannot generate no-pay for today or future dates. Please select yesterday or a past date.'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $date = $request->date;
        $status = $request->status ?? 'Pending';

        $employeesQuery = employee::where('is_active', '1');

        if ($request->filled('company_id')) {
            $companyId = $request->company_id;
            $employeesQuery->whereHas('organizationAssignment', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        }

        $employees = $employeesQuery->with(['organizationAssignment', 'compensation'])->get();

        $generatedRecords = [];
        $skipped = [];

        foreach ($employees as $employee) {
            $org = $employee->organizationAssignment;

            if (!$org) {
                //$skipped[] = ['employee_id' => $employee->id, 'reason' => 'No organization assignment'];

                $skipped[] = [
    'employee_id' => $employee->id, 
    'attendance_employee_no' => $employee->attendance_employee_no, // 
    'reason' => 'No organization assignment'
];
                continue;
            }

            if ($employee->compensation && $employee->compensation->active_nopay === false) {
                NoPayRecord::where('employee_id', $employee->id)->where('date', $date)->delete();
                //$skipped[] = ['employee_id' => $employee->id, 'reason' => 'NoPay inactive in compensation'];
                $skipped[] = [
    'employee_id' => $employee->id, 
    'attendance_employee_no' => $employee->attendance_employee_no, // අලුතින් එක් කළා
    'reason' => 'NoPay inactive in compensation'
];
                continue;
            }

            $dayOff = $org->day_off ?? null;
            $dayName = Carbon::parse($date)->format('l');

            if ($dayOff && strtolower($dayOff) === strtolower($dayName)) {
                NoPayRecord::where('employee_id', $employee->id)->where('date', $date)->delete();
                //$skipped[] = ['employee_id' => $employee->id, 'reason' => 'Employee day off'];
                $skipped[] = [
    'employee_id' => $employee->id, 
    'attendance_employee_no' => $employee->attendance_employee_no, // අලුතින් එක් කළා
    'reason' => 'Employee day off'
];
                continue;
            }

            [$roster, $shift] = $this->resolveRosterAndShift($employee, $date);

            if (!$roster || !$shift) {
                //$skipped[] = ['employee_id' => $employee->id, 'reason' => 'No roster/shift'];
                $skipped[] = [
    'employee_id' => $employee->id, 
    'attendance_employee_no' => $employee->attendance_employee_no, // 
    'reason' => 'No roster/shift'
];
                continue;
            }

            if ($this->checkCompanyHoliday($employee, $date)) {
                NoPayRecord::where('employee_id', $employee->id)->where('date', $date)->delete();
                //$skipped[] = ['employee_id' => $employee->id, 'reason' => 'Company/department holiday'];
                $skipped[] = [
    'employee_id' => $employee->id, 
    'attendance_employee_no' => $employee->attendance_employee_no, // 
    'reason' => 'Company/department holiday'
];
                continue;
            }

            if ($this->hasApprovedFullDayLeave($employee->id, $date)) {
                NoPayRecord::where('employee_id', $employee->id)->where('date', $date)->delete();
                //$skipped[] = ['employee_id' => $employee->id, 'reason' => 'Approved full-day leave'];

                $skipped[] = [
    'employee_id' => $employee->id, 
    'attendance_employee_no' => $employee->attendance_employee_no, // new
    'reason' => 'Approved full-day leave'
];
                continue;
            }

            $dayResult = $this->buildNoPayForEmployeeDay($employee, $date, $shift, $status);

            if (!empty($dayResult)) {
                foreach ($dayResult as $record) {
                    $record->load(['employee', 'processedBy']);
                    $record->display_value = $this->getDisplayValue($record);
                    $generatedRecords[] = $record;
                }
            }
        }

        return response()->json([
            'message' => count($generatedRecords) . ' no-pay record(s) generated',
            'records' => $generatedRecords,
            'skipped' => $skipped,
        ]);
    }

    // =====================================================================
    // GENERATE MONTHLY NO-PAY RECORDS
    // =====================================================================
    public function generateMonthlyNoPayRecords(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000|max:2100',
            'company_id' => 'sometimes|exists:companies,id',
            'status' => 'sometimes|in:Pending,Approved,Rejected',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $month = (int) $request->month;
        $year = (int) $request->year;
        $status = $request->status ?? 'Pending';

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        $yesterday = Carbon::yesterday(); 

        if ($startDate->greaterThan($yesterday)) {
            return response()->json([
                'month' => ['Cannot generate no-pay for future months or current day.']
            ], 422);
        }

        if ($endDate->greaterThan($yesterday)) {
            $endDate = $yesterday;
        }

        $allGenerated = [];
        $allSkipped = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $employeesQuery = employee::where('is_active', '1');

            if ($request->filled('company_id')) {
                $companyId = $request->company_id;
                $employeesQuery->whereHas('organizationAssignment', function ($q) use ($companyId) {
                    $q->where('company_id', $companyId);
                });
            }

            $employees = $employeesQuery->with(['organizationAssignment', 'compensation'])->get();

            foreach ($employees as $employee) {
                $dateString = $date->format('Y-m-d');
                $org = $employee->organizationAssignment;

                if (!$org) {
                    $allSkipped[] = ['date' => $dateString, 'employee_id' => $employee->id, 'reason' => 'No organization assignment'];
                    continue;
                }

                if ($employee->compensation && $employee->compensation->active_nopay === false) {
                    NoPayRecord::where('employee_id', $employee->id)->where('date', $dateString)->delete();
                    $allSkipped[] = ['date' => $dateString, 'employee_id' => $employee->id, 'reason' => 'NoPay inactive in compensation'];
                    continue;
                }

                $dayOff = $org->day_off ?? null;
                $dayName = $date->format('l');

                if ($dayOff && strtolower($dayOff) === strtolower($dayName)) {
                    NoPayRecord::where('employee_id', $employee->id)->where('date', $dateString)->delete();
                    $allSkipped[] = ['date' => $dateString, 'employee_id' => $employee->id, 'reason' => 'Employee day off'];
                    continue;
                }

                [$roster, $shift] = $this->resolveRosterAndShift($employee, $dateString);

                if (!$roster || !$shift) {
                    $allSkipped[] = ['date' => $dateString, 'employee_id' => $employee->id, 'reason' => 'No roster/shift'];
                    continue;
                }

                if ($this->checkCompanyHoliday($employee, $dateString)) {
                    NoPayRecord::where('employee_id', $employee->id)->where('date', $dateString)->delete();
                    $allSkipped[] = ['date' => $dateString, 'employee_id' => $employee->id, 'reason' => 'Company/department holiday'];
                    continue;
                }

                if ($this->hasApprovedFullDayLeave($employee->id, $dateString)) {
                    NoPayRecord::where('employee_id', $employee->id)->where('date', $dateString)->delete();
                    $allSkipped[] = ['date' => $dateString, 'employee_id' => $employee->id, 'reason' => 'Approved full-day leave'];
                    continue;
                }

                $dayResult = $this->buildNoPayForEmployeeDay($employee, $dateString, $shift, $status);

                if (!empty($dayResult)) {
                    foreach ($dayResult as $record) {
                        $record->load(['employee', 'processedBy']);
                        $record->display_value = $this->getDisplayValue($record);
                        $allGenerated[] = $record;
                    }
                }
            }
        }

        return response()->json([
            'message' => count($allGenerated) . ' no-pay record(s) generated for ' . $startDate->format('F Y') . ' (Up to ' . $endDate->format('Y-m-d') . ')',
            'records' => $allGenerated,
            'skipped' => $allSkipped,
        ]);
    }

    // =====================================================================
    // HELPER FUNCTIONS 
    // =====================================================================
    protected function buildNoPayForEmployeeDay($employee, string $date, $shift, string $status): array
    {
        $records = [];

        $shiftStart = Carbon::parse($date . ' ' . $shift->start_time);
        $shiftEnd = Carbon::parse($date . ' ' . $shift->end_time);

        if ($shiftEnd->lte($shiftStart)) {
            $shiftEnd->addDay();
        }

        $shiftHours = max($shiftStart->floatDiffInHours($shiftEnd), 0.01);

        $allCards = time_card::where('employee_id', $employee->id)
            ->where(function ($q) use ($date) {
                $q->where('date', $date)
                  ->orWhere('actual_date', $date);
            })
            ->whereNull('deleted_at')
            ->orderBy('time', 'asc')
            ->get();

        $inCard = $allCards->first(function ($card) {
            $st = strtolower(trim($card->status));
            return $card->entry == 1 || in_array($st, ['in', 'late coming', 'late_coming']);
        });

        $outCard = $allCards->sortByDesc('time')->first(function ($card) {
            $st = strtolower(trim($card->status));
            return in_array($card->entry, [0, 2]) || in_array($st, ['out', 'early out', 'early_out']);
        });

        // IN හෝ OUT එකක් හරි තියෙනවා නම්, පරණ Full Day No Pay එක මකා දමන්න
        if ($inCard || $outCard) {
            NoPayRecord::where('employee_id', $employee->id)
                ->where('date', $date)
                ->whereIn('type', ['FULL_DAY', 'PARTIAL_ABSENT'])
                ->delete();
        } 
        // සම්පූර්ණ දවසම absent නම් (IN එකකුත් නෑ, OUT එකකුත් නෑ)
        else {
            // Full Day Absent නම් පරණ Late In / Early Out මකන්න
            NoPayRecord::where('employee_id', $employee->id)
                ->where('date', $date)
                ->whereIn('type', ['LATE_IN', 'EARLY_OUT'])
                ->delete();

            // 1. එදා දවසට අනුමත වුණු නිවාඩු ප්‍රමාණය කොච්චරද කියලා බලනවා
            $approvedLeaveDuration = $this->getApprovedLeaveDuration($employee->id, $date);
            
            // 2. අනුමත නිවාඩුවක් තියෙනවා නම් ඒක 1 න් අඩු කරනවා (උදා: Half day නම් 1 - 0.5 = 0.5)
            $noPayAmount = 1 - $approvedLeaveDuration;

            // ඉතුරු No Pay වෙන්න ඕනේ ප්‍රමාණයක් තියෙනවා නම් විතරක් No Pay Record එක හදනවා
            if ($noPayAmount > 0) {
                $existing = NoPayRecord::where('employee_id', $employee->id)
                    ->where('date', $date)
                    ->whereIn('type', ['FULL_DAY', 'PARTIAL_ABSENT'])
                    ->first();

                // Full Day ද නැත්නම් Partial ද කියලා තීරණය කරනවා
                $type = $noPayAmount == 1 ? 'FULL_DAY' : 'PARTIAL_ABSENT';
                
                $leaveTypeStr = "";
                if ($approvedLeaveDuration == 0.5) $leaveTypeStr = "Half Day (0.5 Days)";
                elseif ($approvedLeaveDuration == 0.25) $leaveTypeStr = "Short Leave (0.25 Days)";
                elseif ($approvedLeaveDuration == 0.75) $leaveTypeStr = "0.75 Days";

                $desc = $noPayAmount == 1 
                    ? 'Automatic full-day no-pay: no attendance, no approved leave.'
                    : "Automatic partial no-pay: no attendance, but had {$leaveTypeStr} approved leave.";

                $data = [
                    'no_pay_count' => $noPayAmount, // 0.25, 0.5, 0.75 හෝ 1.0 විදිහට හරියටම සේව් වෙනවා
                    'description' => $desc,
                    'processed_by' => Auth::id(),
                    'hours' => round($shiftHours * $noPayAmount, 2),
                    'minutes' => 0,
                    'start_time' => $shiftStart->format('H:i:s'),
                    'end_time' => $shiftEnd->format('H:i:s'),
                    'type' => $type,
                ];

                if ($existing) {
                    $existing->update($data);
                    $records[] = $existing;
                } else {
                    $data['employee_id'] = $employee->id;
                    $data['date'] = $date;
                    $data['status'] = $status;
                    $records[] = NoPayRecord::create($data);
                }
            }

            return $records; 
        }

        // LATE IN (පරක්කු වෙලා ආවම)
        if ($inCard) {
            $actualIn = Carbon::parse($inCard->date . ' ' . $inCard->time);
            
            if ($actualIn->gt($shiftStart)) {
                $lateMinutes = $shiftStart->diffInMinutes($actualIn);
                
                if ($lateMinutes > 30) {
                    if (!$this->hasApprovedPartialLeaveForWindow($employee->id, $date, $shiftStart, $actualIn, 'LATE_IN')) {
                        $roundedMinutes = (int) (round($lateMinutes / 30) * 30);

                        if ($roundedMinutes > 0) {
                            $gapHours = $roundedMinutes / 60;
                            $descHours = floor($roundedMinutes / 60);
                            $descMins = $roundedMinutes % 60;
                            
                            $records[] = $this->createOrUpdatePartialNoPay(
                                $employee->id, $date, 'LATE_IN', $gapHours, $shiftHours,
                                $shiftStart, $actualIn, $status,
                                "Automatic late-in no-pay: employee arrived late by {$descHours}h {$descMins}m"
                            );
                        }
                    }
                } else {
                    NoPayRecord::where('employee_id', $employee->id)
                        ->where('date', $date)
                        ->where('type', 'LATE_IN')
                        ->delete();
                }
            }
        }

        // EARLY OUT (කලින් ගියාම)
        if ($outCard) {
            $actualOut = Carbon::parse($outCard->date . ' ' . $outCard->time);

            if ($outCard->actual_date && $outCard->actual_date !== $date) {
                $actualOut = Carbon::parse($outCard->actual_date . ' ' . $outCard->time);
            }

            if ($actualOut->lt($shiftStart)) {
                $actualOut->addDay();
            }

            if ($actualOut->lt($shiftEnd)) {
                if (!$this->hasApprovedPartialLeaveForWindow($employee->id, $date, $actualOut, $shiftEnd, 'EARLY_OUT')) {
                    $earlyMinutes = $actualOut->diffInMinutes($shiftEnd);
                    $roundedMinutes = (int) (round($earlyMinutes / 30) * 30);

                    if ($roundedMinutes > 0) {
                        $gapHours = $roundedMinutes / 60;
                        $descHours = floor($roundedMinutes / 60);
                        $descMins = $roundedMinutes % 60;

                        $records[] = $this->createOrUpdatePartialNoPay(
                            $employee->id, $date, 'EARLY_OUT', $gapHours, $shiftHours,
                            $actualOut, $shiftEnd, $status,
                            "Automatic early-out no-pay: employee left early by {$descHours}h {$descMins}m"
                        );
                    }
                }
            } else {
                NoPayRecord::where('employee_id', $employee->id)
                    ->where('date', $date)
                    ->where('type', 'EARLY_OUT')
                    ->delete();
            }
        }

        return array_values(array_filter($records));
    }

    protected function createOrUpdatePartialNoPay(
        int $employeeId, string $date, string $type, float $gapHours, float $shiftHours,
        Carbon $from, Carbon $to, string $status, string $description
    ) {
        $minutes = (int) round($gapHours * 60);
        $hoursPart = floor($minutes / 60);
        $minutePart = $minutes % 60;
        $count = round($gapHours / $shiftHours, 4);

        $allRows = NoPayRecord::where('employee_id', $employeeId)
            ->where('date', $date)
            ->where('type', $type)
            ->orderByRaw("CASE WHEN status = 'Approved' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        if ($allRows->count() > 1) {
            $allRows->shift();
            foreach ($allRows as $extraRow) {
                $extraRow->delete();
            }
        }

        $existing = NoPayRecord::where('employee_id', $employeeId)
            ->where('date', $date)
            ->where('type', $type)
            ->first();

        if ($existing) {
            $existing->update([
                'no_pay_count' => $count,
                'description' => $description,
                'processed_by' => Auth::id(),
                'hours' => $hoursPart,
                'minutes' => $minutePart,
                'start_time' => $from->format('H:i:s'),
                'end_time' => $to->format('H:i:s'),
                'type' => $type,
            ]);

            return $existing;
        }

        return NoPayRecord::create([
            'employee_id' => $employeeId,
            'date' => $date,
            'no_pay_count' => $count,
            'description' => $description,
            'status' => $status,
            'processed_by' => Auth::id(),
            'hours' => $hoursPart,
            'minutes' => $minutePart,
            'start_time' => $from->format('H:i:s'),
            'end_time' => $to->format('H:i:s'),
            'type' => $type,
        ]);
    }

    protected function resolveRosterAndShift(employee $employee, string $date): array
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

    // අලුතින් එකතු කළ Function එක (අනුමත නිවාඩු ප්‍රමාණය හරියටම ගණනය කරන්න)
    protected function getApprovedLeaveDuration(int $employeeId, string $date): float
    {
        $leaves = leave_master::where('employee_id', $employeeId)
            ->where('status', 'Approved')
            ->where(function ($query) use ($date) {
                $query->whereDate('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->whereDate('leave_from', '<=', $date)
                          ->whereDate('leave_to', '>=', $date);
                    });
            })
            ->get();

        $totalDuration = 0;
        foreach ($leaves as $leave) {
            if ($leave->is_short_leave || (float)$leave->leave_duration == 0.25) {
                $totalDuration += 0.25;
            } elseif ($leave->is_half_day || (float)$leave->leave_duration == 0.5) {
                $totalDuration += 0.5;
            } else {
                $totalDuration += (float)($leave->leave_duration ?? 1);
            }
        }
        
        return min($totalDuration, 1); // දවසකට උපරිම නිවාඩුව 1 යි
    }

    protected function hasApprovedFullDayLeave(int $employeeId, string $date): bool
    {
        return leave_master::where('employee_id', $employeeId)
            ->where('status', 'Approved')
            ->where(function ($query) use ($date) {
                $query->whereDate('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->whereDate('leave_from', '<=', $date)
                          ->whereDate('leave_to', '>=', $date);
                    });
            })
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('is_half_day', 0)
                      ->where('is_short_leave', 0);
                })->orWhere('leave_duration', '>=', 1);
            })
            ->exists();
    }

    protected function hasApprovedPartialLeaveForWindow(
        int $employeeId, string $date, Carbon $from, Carbon $to, string $mode = 'EARLY_OUT'
    ): bool {
        $leaves = leave_master::where('employee_id', $employeeId)
            ->where('status', 'Approved')
            ->where(function ($query) use ($date) {
                $query->whereDate('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->whereDate('leave_from', '<=', $date)
                          ->whereDate('leave_to', '>=', $date);
                    });
            })
            ->get();

        foreach ($leaves as $leave) {
            if ($this->isPartialLeaveRecord($leave)) {
                return true;
            }
        }

        return false;
    }

    protected function isPartialLeaveRecord($leave): bool
    {
        $possibleValues = [
            strtolower((string) ($leave->leave_type ?? '')),
            strtolower((string) ($leave->leave_category ?? '')),
            strtolower((string) ($leave->duration_type ?? '')),
        ];

        foreach ($possibleValues as $value) {
            if (in_array($value, [
                'short leave', 'short_leave', 'short', 'half day', 'half-day', 'half_day', 'halfday'
            ])) {
                return true;
            }
        }

        if (isset($leave->is_short_leave) && (int) $leave->is_short_leave === 1) {
            return true;
        }

        if (isset($leave->is_half_day) && (int) $leave->is_half_day === 1) {
            return true;
        }

        return false;
    }

    protected function checkCompanyHoliday($employee, $date): bool
    {
        $orgAssignment = $employee->organizationAssignment;
        if (!$orgAssignment) {
            return false;
        }

        $companyHoliday = leaveCalendar::where('company_id', $orgAssignment->company_id)
            ->where(function ($query) use ($date) {
                $query->where(function ($q) use ($date) {
                    $q->whereNull('end_date')->whereDate('start_date', $date);
                })->orWhere(function ($q) use ($date) {
                    $q->whereNotNull('end_date')
                      ->whereDate('start_date', '<=', $date)
                      ->whereDate('end_date', '>=', $date);
                });
            })
            ->exists();

        if ($companyHoliday) {
            return true;
        }

        if ($orgAssignment->department_id) {
            $deptHoliday = leaveCalendar::where('department_id', $orgAssignment->department_id)
                ->where(function ($query) use ($date) {
                    $query->where(function ($q) use ($date) {
                        $q->whereNull('end_date')->whereDate('start_date', $date);
                    })->orWhere(function ($q) use ($date) {
                        $q->whereNotNull('end_date')
                          ->whereDate('start_date', '<=', $date)
                          ->whereDate('end_date', '>=', $date);
                    });
                })
                ->exists();

            if ($deptHoliday) {
                return true;
            }
        }

        return false;
    }

    public function getNoPayStats(Request $request)
    {
        $query = NoPayRecord::query();

        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        } else {
            if ($request->filled('month')) {
                $query->whereMonth('date', $request->month);
            }

            if ($request->filled('year')) {
                $query->whereYear('date', $request->year);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('company_id')) {
            $query->whereHas('employee.organizationAssignment', function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });
        }

        $records = $query->get()
            ->groupBy(function ($record) {
                return $record->employee_id . '|' . $record->date . '|' . ($record->type ?? 'NO_TYPE');
            })
            ->map(function ($group) {
                return $group->sortBy([
                    fn ($a, $b) => ($a->status === 'Approved' ? 0 : 1) <=> ($b->status === 'Approved' ? 0 : 1),
                    fn ($a, $b) => $b->id <=> $a->id,
                ])->first();
            });

        $totalRecords = $records->count();

        $totalDays = $records->sum(function ($record) {
            if ($record->type === 'FULL_DAY' || (float) $record->no_pay_count === 1.0) {
                return 1;
            }
            return (float) $record->no_pay_count;
        });

        $affectedEmployees = $records->pluck('employee_id')->unique()->count();

        return response()->json([
            'total_records' => $totalRecords,
            'total_days' => $totalDays,
            'affected_employees' => $affectedEmployees
        ]);
    }

    // =================================================================
    // GET DISPLAY VALUE FOR FRONTEND
    // =================================================================
    protected function getDisplayValue($record): string
    {
        if ($record->type === 'FULL_DAY' || (float) $record->no_pay_count == 1.0) {
            return 'Full Day';
        }

        // අලුතින් එකතු කළ Partial Absent (Half Day / Short Leave) කොටස
        if ($record->type === 'PARTIAL_ABSENT') {
            return $record->no_pay_count . ' Days'; 
        }

        $hours = (int) ($record->hours ?? 0);
        $minutes = (int) ($record->minutes ?? 0);

        if ($hours > 0 && $minutes > 0) {
            return $hours . ' Hours ' . $minutes . ' Minutes';
        }

        if ($hours > 0) {
            return $hours . ' Hours';
        }

        if ($minutes > 0) {
            return $minutes . ' Minutes';
        }

        return (string) $record->no_pay_count;
    }
}

*/

