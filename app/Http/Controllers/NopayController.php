<?php



namespace App\Http\Controllers;

use App\Models\NoPayRecord;
use App\Models\employee;
use App\Models\leave_master;
use App\Models\roster;
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

    public function generateDailyNoPayRecords(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'company_id' => 'sometimes|exists:companies,id',
            'status' => 'sometimes|in:Pending,Approved,Rejected',
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
                    'reason' => 'No organization assignment'
                ];
                continue;
            }

            if ($employee->compensation && $employee->compensation->active_nopay === false) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'reason' => 'NoPay inactive in compensation'
                ];
                continue;
            }

            $dayOff = $org->day_off ?? null;
            $dayName = Carbon::parse($date)->format('l');

            if ($dayOff && strtolower($dayOff) === strtolower($dayName)) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'reason' => 'Employee day off'
                ];
                continue;
            }

            [$roster, $shift] = $this->resolveRosterAndShift($employee, $date);

            if (!$roster || !$shift) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'reason' => 'No roster/shift'
                ];
                continue;
            }

            if ($this->checkCompanyHoliday($employee, $date)) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'reason' => 'Company/department holiday'
                ];
                continue;
            }

            if ($this->hasApprovedFullDayLeave($employee->id, $date)) {
                $skipped[] = [
                    'employee_id' => $employee->id,
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
                    $allSkipped[] = [
                        'date' => $dateString,
                        'employee_id' => $employee->id,
                        'reason' => 'No organization assignment'
                    ];
                    continue;
                }

                if ($employee->compensation && $employee->compensation->active_nopay === false) {
                    $allSkipped[] = [
                        'date' => $dateString,
                        'employee_id' => $employee->id,
                        'reason' => 'NoPay inactive in compensation'
                    ];
                    continue;
                }

                $dayOff = $org->day_off ?? null;
                $dayName = $date->format('l');

                if ($dayOff && strtolower($dayOff) === strtolower($dayName)) {
                    $allSkipped[] = [
                        'date' => $dateString,
                        'employee_id' => $employee->id,
                        'reason' => 'Employee day off'
                    ];
                    continue;
                }

                [$roster, $shift] = $this->resolveRosterAndShift($employee, $dateString);

                if (!$roster || !$shift) {
                    $allSkipped[] = [
                        'date' => $dateString,
                        'employee_id' => $employee->id,
                        'reason' => 'No roster/shift'
                    ];
                    continue;
                }

                if ($this->checkCompanyHoliday($employee, $dateString)) {
                    $allSkipped[] = [
                        'date' => $dateString,
                        'employee_id' => $employee->id,
                        'reason' => 'Company/department holiday'
                    ];
                    continue;
                }

                if ($this->hasApprovedFullDayLeave($employee->id, $dateString)) {
                    $allSkipped[] = [
                        'date' => $dateString,
                        'employee_id' => $employee->id,
                        'reason' => 'Approved full-day leave'
                    ];
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
            'message' => count($allGenerated) . ' no-pay record(s) generated for ' . $startDate->format('F Y'),
            'records' => $allGenerated,
            'skipped' => $allSkipped,
        ]);
    }

    protected function buildNoPayForEmployeeDay($employee, string $date, $shift, string $status): array
    {
        $records = [];

        $shiftStart = Carbon::parse($date . ' ' . $shift->start_time);
        $shiftEnd = Carbon::parse($date . ' ' . $shift->end_time);

        if ($shiftEnd->lte($shiftStart)) {
            $shiftEnd->addDay();
        }

        $shiftHours = max($shiftStart->floatDiffInHours($shiftEnd), 0.01);

        $inCard = time_card::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->whereIn('status', ['IN', 'Late Coming'])
            ->whereNull('deleted_at')
            ->orderBy('time', 'asc')
            ->first();

        $outCard = time_card::where('employee_id', $employee->id)
            ->where(function ($q) use ($date) {
                $q->whereDate('date', $date)
                  ->orWhereDate('actual_date', $date);
            })
            ->whereIn('status', ['OUT', 'Early OUT'])
            ->whereNull('deleted_at')
            ->orderBy('date', 'desc')
            ->orderBy('time', 'desc')
            ->first();

        if (!$inCard && !$outCard) {
            $allFullDayRows = NoPayRecord::where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->where('type', 'FULL_DAY')
                ->orderByRaw("CASE WHEN status = 'Approved' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->get();

            if ($allFullDayRows->count() > 1) {
                $allFullDayRows->shift();
                foreach ($allFullDayRows as $extraRow) {
                    $extraRow->delete();
                }
            }

            $existing = NoPayRecord::where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->where('type', 'FULL_DAY')
                ->first();

            if ($existing) {
                $existing->update([
                    'no_pay_count' => 1,
                    'description' => 'Automatic full-day no-pay: no attendance, no approved leave, not holiday, not day off',
                    'processed_by' => Auth::id(),
                    'hours' => round($shiftHours, 2),
                    'minutes' => 0,
                    'start_time' => $shiftStart->format('H:i:s'),
                    'end_time' => $shiftEnd->format('H:i:s'),
                    'type' => 'FULL_DAY',
                ]);

                $records[] = $existing;
            } else {
                $records[] = NoPayRecord::create([
                    'employee_id' => $employee->id,
                    'date' => $date,
                    'no_pay_count' => 1,
                    'description' => 'Automatic full-day no-pay: no attendance, no approved leave, not holiday, not day off',
                    'status' => $status,
                    'processed_by' => Auth::id(),
                    'hours' => round($shiftHours, 2),
                    'minutes' => 0,
                    'start_time' => $shiftStart->format('H:i:s'),
                    'end_time' => $shiftEnd->format('H:i:s'),
                    'type' => 'FULL_DAY',
                ]);
            }

            return $records;
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
                    $gapHours = round($actualOut->floatDiffInHours($shiftEnd), 2);

                    if ($gapHours > 0) {
                        $records[] = $this->createOrUpdatePartialNoPay(
                            $employee->id,
                            $date,
                            'EARLY_OUT',
                            $gapHours,
                            $shiftHours,
                            $actualOut,
                            $shiftEnd,
                            $status,
                            'Automatic early-out no-pay: employee left before shift end without approved short/half-day leave'
                        );
                    }
                }
            }
        }

        return array_values(array_filter($records));
    }

    protected function createOrUpdatePartialNoPay(
        int $employeeId,
        string $date,
        string $type,
        float $gapHours,
        float $shiftHours,
        Carbon $from,
        Carbon $to,
        string $status,
        string $description
    ) {
        $minutes = (int) round($gapHours * 60);
        $hoursPart = floor($minutes / 60);
        $minutePart = $minutes % 60;
        $count = round($gapHours / $shiftHours, 4);

        $allRows = NoPayRecord::where('employee_id', $employeeId)
            ->whereDate('date', $date)
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
            ->whereDate('date', $date)
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
                $query->whereNull('leave_type')
                    ->orWhereNotIn('leave_type', [
                        'Short Leave',
                        'Half Day',
                        'Half-Day',
                        'Short',
                        'HALF_DAY',
                        'SHORT_LEAVE'
                    ]);
            })
            ->exists();
    }

    protected function hasApprovedPartialLeaveForWindow(
        int $employeeId,
        string $date,
        Carbon $from,
        Carbon $to,
        string $mode = 'EARLY_OUT'
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
                'short leave',
                'short_leave',
                'short',
                'half day',
                'half-day',
                'half_day',
                'halfday'
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
            ->whereDate('start_date', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $date);
            })
            ->exists();

        if ($companyHoliday) {
            return true;
        }

        if ($orgAssignment->department_id) {
            $deptHoliday = leaveCalendar::where('department_id', $orgAssignment->department_id)
                ->whereDate('start_date', '<=', $date)
                ->where(function ($query) use ($date) {
                    $query->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', $date);
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

    protected function getDisplayValue($record): string
    {
        if (($record->type ?? null) === 'FULL_DAY' || (float) ($record->no_pay_count ?? 0) === 1.0) {
            return 'Full Day';
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
use App\Models\roster;
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
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$search}%");
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

    public function generateDailyNoPayRecords(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'company_id' => 'sometimes|exists:companies,id',
            'status' => 'sometimes|in:Pending,Approved,Rejected',
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
                    'reason' => 'No organization assignment'
                ];
                continue;
            }

            if ($employee->compensation && $employee->compensation->active_nopay === false) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'reason' => 'NoPay inactive in compensation'
                ];
                continue;
            }

            // Sunday no-pay exclude එක අයින් කරලා තියෙනවා

            $dayOff = $org->day_off ?? null;
            $dayName = Carbon::parse($date)->format('l');

            if ($dayOff && strtolower($dayOff) === strtolower($dayName)) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'reason' => 'Employee day off'
                ];
                continue;
            }

            [$roster, $shift] = $this->resolveRosterAndShift($employee, $date);

            if (!$roster || !$shift) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'reason' => 'No roster/shift'
                ];
                continue;
            }

            if ($this->checkCompanyHoliday($employee, $date)) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'reason' => 'Company/department holiday'
                ];
                continue;
            }

            if ($this->hasApprovedFullDayLeave($employee->id, $date)) {
                $skipped[] = [
                    'employee_id' => $employee->id,
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

    protected function buildNoPayForEmployeeDay($employee, string $date, $shift, string $status): array
    {
        $records = [];

        $shiftStart = Carbon::parse($date . ' ' . $shift->start_time);
        $shiftEnd = Carbon::parse($date . ' ' . $shift->end_time);

        if ($shiftEnd->lte($shiftStart)) {
            $shiftEnd->addDay();
        }

        $shiftHours = max($shiftStart->floatDiffInHours($shiftEnd), 0.01);

        $inCard = time_card::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->whereIn('status', ['IN', 'Late Coming'])
            ->whereNull('deleted_at')
            ->orderBy('time', 'asc')
            ->first();

        $outCard = time_card::where('employee_id', $employee->id)
            ->where(function ($q) use ($date) {
                $q->whereDate('date', $date)
                  ->orWhereDate('actual_date', $date);
            })
            ->whereIn('status', ['OUT', 'Early OUT'])
            ->whereNull('deleted_at')
            ->orderBy('date', 'desc')
            ->orderBy('time', 'desc')
            ->first();

        // FULL DAY
        if (!$inCard && !$outCard) {
            $allFullDayRows = NoPayRecord::where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->where('type', 'FULL_DAY')
                ->orderByRaw("CASE WHEN status = 'Approved' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->get();

            if ($allFullDayRows->count() > 1) {
                $allFullDayRows->shift();
                foreach ($allFullDayRows as $extraRow) {
                    $extraRow->delete();
                }
            }

            $existing = NoPayRecord::where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->where('type', 'FULL_DAY')
                ->first();

            if ($existing) {
                $existing->update([
                    'no_pay_count' => 1,
                    'description' => 'Automatic full-day no-pay: no attendance, no approved leave, not holiday, not day off',
                    'processed_by' => Auth::id(),
                    'hours' => round($shiftHours, 2),
                    'minutes' => 0,
                    'start_time' => $shiftStart->format('H:i:s'),
                    'end_time' => $shiftEnd->format('H:i:s'),
                    'type' => 'FULL_DAY',
                ]);

                $records[] = $existing;
            } else {
                $records[] = NoPayRecord::create([
                    'employee_id' => $employee->id,
                    'date' => $date,
                    'no_pay_count' => 1,
                    'description' => 'Automatic full-day no-pay: no attendance, no approved leave, not holiday, not day off',
                    'status' => $status,
                    'processed_by' => Auth::id(),
                    'hours' => round($shiftHours, 2),
                    'minutes' => 0,
                    'start_time' => $shiftStart->format('H:i:s'),
                    'end_time' => $shiftEnd->format('H:i:s'),
                    'type' => 'FULL_DAY',
                ]);
            }

            return $records;
        }

        // LATE_IN no-pay නැහැ

        // EARLY OUT
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
                    $gapHours = round($actualOut->floatDiffInHours($shiftEnd), 2);

                    if ($gapHours > 0) {
                        $records[] = $this->createOrUpdatePartialNoPay(
                            $employee->id,
                            $date,
                            'EARLY_OUT',
                            $gapHours,
                            $shiftHours,
                            $actualOut,
                            $shiftEnd,
                            $status,
                            'Automatic early-out no-pay: employee left before shift end without approved short/half-day leave'
                        );
                    }
                }
            }
        }

        return array_values(array_filter($records));
    }

    protected function createOrUpdatePartialNoPay(
        int $employeeId,
        string $date,
        string $type,
        float $gapHours,
        float $shiftHours,
        Carbon $from,
        Carbon $to,
        string $status,
        string $description
    ) {
        $minutes = (int) round($gapHours * 60);
        $hoursPart = floor($minutes / 60);
        $minutePart = $minutes % 60;
        $count = round($gapHours / $shiftHours, 4);

        $allRows = NoPayRecord::where('employee_id', $employeeId)
            ->whereDate('date', $date)
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
            ->whereDate('date', $date)
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
                $query->whereNull('leave_type')
                    ->orWhereNotIn('leave_type', [
                        'Short Leave',
                        'Half Day',
                        'Half-Day',
                        'Short',
                        'HALF_DAY',
                        'SHORT_LEAVE'
                    ]);
            })
            ->exists();
    }

    protected function hasApprovedPartialLeaveForWindow(
        int $employeeId,
        string $date,
        Carbon $from,
        Carbon $to,
        string $mode = 'EARLY_OUT'
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
                'short leave',
                'short_leave',
                'short',
                'half day',
                'half-day',
                'half_day',
                'halfday'
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
            ->whereDate('start_date', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')
                      ->orWhereDate('end_date', '>=', $date);
            })
            ->exists();

        if ($companyHoliday) {
            return true;
        }

        if ($orgAssignment->department_id) {
            $deptHoliday = leaveCalendar::where('department_id', $orgAssignment->department_id)
                ->whereDate('start_date', '<=', $date)
                ->where(function ($query) use ($date) {
                    $query->whereNull('end_date')
                          ->orWhereDate('end_date', '>=', $date);
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

    protected function getDisplayValue($record): string
    {
        if (($record->type ?? null) === 'FULL_DAY' || (float) ($record->no_pay_count ?? 0) === 1.0) {
            return 'Full Day';
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
