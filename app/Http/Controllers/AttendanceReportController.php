<?php


namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\time_card;
use App\Models\employee;
use App\Models\leaveCalendar;
use Illuminate\Support\Facades\DB;

class AttendanceReportController extends Controller
{
   
/*
    public function index(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string',
        ]);

        $date = $validated['date'];
        $perPage = (int) ($validated['per_page'] ?? 15);
        $search = $validated['search'] ?? null;

        $statuses = ['IN', 'Late Coming', 'OUT', 'Early OUT'];

        $attendedEmployeeIds = time_card::select('employee_id')
            ->where('date', $date)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('employee_id');

        $query = time_card::with([
                'employee.organizationAssignment.company',
                'employee.organizationAssignment.department',
                'employee.organizationAssignment.subDepartment',
            ])
            ->select(
                'employee_id',
                DB::raw('MIN(CASE WHEN status IN ("IN", "Late Coming") THEN time END) as in_time'),
                DB::raw('MAX(CASE WHEN status IN ("OUT", "Early OUT") THEN time END) as out_time'),
                DB::raw('MIN(date) as date'),
                DB::raw('GROUP_CONCAT(DISTINCT status ORDER BY time ASC SEPARATOR ", ") as entries'),

                DB::raw('
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            CASE
                                WHEN status IN ("IN", "Late Coming")
                                THEN status
                                ELSE NULL
                            END
                            ORDER BY time ASC SEPARATOR ","
                        ),
                        ",",
                        1
                    ) as first_in_status
                '),

                DB::raw('
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            CASE
                                WHEN status IN ("OUT", "Early OUT")
                                THEN status
                                ELSE NULL
                            END
                            ORDER BY time DESC SEPARATOR ","
                        ),
                        ",",
                        1
                    ) as last_out_status
                ')
            )
            ->where('date', $date)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $attendedEmployeeIds)
            ->groupBy('employee_id');

        if ($search) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('nic', 'like', "%{$search}%")
                  ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        $results = $query->paginate($perPage);

        $results->getCollection()->transform(function ($record) {
            $employee = $record->employee;

            return [
                'id' => $record->employee_id,
                'empNo' => $employee->attendance_employee_no ?? null,
                'name' => $employee->full_name ?? null,
                'company' => $employee->organizationAssignment->company->name ?? null,
                'department' => $employee->organizationAssignment->department->name ?? null,
                'sub_department' => $employee->organizationAssignment->subDepartment->name ?? null,
                'date' => $record->date,
                'in_time' => $record->in_time ?? '-',
                'out_time' => $record->out_time ?? '-',
                'status' => 'Present',
                'in_label' => ($record->first_in_status ?? null) === 'Late Coming' ? 'Late Coming' : null,
                'out_label' => ($record->last_out_status ?? null) === 'Early OUT' ? 'Early OUT' : null,
                'entries' => $record->entries,
            ];
        });

        return response()->json($results);
    }
*/


public function index(Request $request)
{
    $validated = $request->validate([
        'date' => 'required|date',
        'per_page' => 'nullable|integer|min:1|max:100',
        'search' => 'nullable|string',
    ]);

    $date = $validated['date'];
    $perPage = (int) ($validated['per_page'] ?? 15);
    $search = $validated['search'] ?? null;

    $statuses = ['IN', 'Late Coming', 'OUT', 'Early OUT'];

    $attendedEmployeeIds = time_card::select('employee_id')
        ->where('date', $date)
        ->whereIn('status', $statuses)
        ->whereNull('deleted_at')
        ->distinct()
        ->pluck('employee_id');

    // Leave calendar entries for selected date
    $leaveEntries = \App\Models\leaveCalendar::whereDate('start_date', '<=', $date)
        ->where(function ($q) use ($date) {
            $q->whereNull('end_date')
              ->orWhereDate('end_date', '>=', $date);
        })
        ->get();

    $leaveMap = [];
    foreach ($leaveEntries as $leave) {
        $key = ($leave->company_id ?? 'null') . '-' . ($leave->department_id ?? 'null');
        $leaveMap[$key][] = $date;
    }

    $query = time_card::with([
            'employee.organizationAssignment.company',
            'employee.organizationAssignment.department',
            'employee.organizationAssignment.subDepartment',
        ])
        ->select(
            'employee_id',
            DB::raw('MIN(CASE WHEN status IN ("IN", "Late Coming") THEN time END) as in_time'),
            DB::raw('MAX(CASE WHEN status IN ("OUT", "Early OUT") THEN time END) as out_time'),
            DB::raw('MIN(date) as date'),
            DB::raw('GROUP_CONCAT(DISTINCT status ORDER BY time ASC SEPARATOR ", ") as entries'),

            DB::raw('
                SUBSTRING_INDEX(
                    GROUP_CONCAT(
                        CASE
                            WHEN status IN ("IN", "Late Coming")
                            THEN status
                            ELSE NULL
                        END
                        ORDER BY time ASC SEPARATOR ","
                    ),
                    ",",
                    1
                ) as first_in_status
            '),

            DB::raw('
                SUBSTRING_INDEX(
                    GROUP_CONCAT(
                        CASE
                            WHEN status IN ("OUT", "Early OUT")
                            THEN status
                            ELSE NULL
                        END
                        ORDER BY time DESC SEPARATOR ","
                    ),
                    ",",
                    1
                ) as last_out_status
            ')
        )
        ->where('date', $date)
        ->whereIn('status', $statuses)
        ->whereNull('deleted_at')
        ->whereIn('employee_id', $attendedEmployeeIds)
        ->groupBy('employee_id');

    if ($search) {
        $query->whereHas('employee', function ($q) use ($search) {
            $q->where('full_name', 'like', "%{$search}%")
              ->orWhere('nic', 'like', "%{$search}%")
              ->orWhere('attendance_employee_no', 'like', "%{$search}%");
        });
    }

    $results = $query->paginate($perPage);

    $results->getCollection()->transform(function ($record) use ($leaveMap, $date) {
        $employee = $record->employee;
        $org = $employee?->organizationAssignment;

        $companyId = $org->company_id ?? 'null';
        $departmentId = $org->department_id ?? 'null';

        $companyKey = $companyId . '-null';
        $departmentKey = $companyId . '-' . $departmentId;

        $holidayDates = array_merge(
            $leaveMap[$companyKey] ?? [],
            $leaveMap[$departmentKey] ?? []
        );

        $holidayDates = array_values(array_unique($holidayDates));
        $isLeaveDate = in_array($date, $holidayDates);

        return [
            'id' => $record->employee_id,
            'empNo' => $employee->attendance_employee_no ?? null,
            'name' => $employee->full_name ?? null,
            'company' => $org->company->name ?? null,
            'department' => $org->department->name ?? null,
            'sub_department' => $org->subDepartment->name ?? null,
            'date' => $record->date,
            'in_time' => $record->in_time ?? '-',
            'out_time' => $record->out_time ?? '-',
            'status' => 'Present',
            'in_label' => ($record->first_in_status ?? null) === 'Late Coming' ? 'Late Coming' : null,
            'out_label' => ($record->last_out_status ?? null) === 'Early OUT' ? 'Early OUT' : null,

            // NEW
            'is_leave_date' => $isLeaveDate,
            'date_label' => $isLeaveDate ? 'Holiday Worked' : null,

            'entries' => $record->entries,
        ];
    });

    return response()->json($results);
}


  
    /*
    public function monthly(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $month = $validated['month'];
        $search = $validated['search'] ?? null;
        $perPage = (int) ($validated['per_page'] ?? 15);

        [$year, $monthNumber] = explode('-', $month);

        $startDate = "{$year}-{$monthNumber}-01";
        $endDate = date('Y-m-t', strtotime($startDate));

        $attendanceStatuses = ['IN', 'Late Coming', 'OUT', 'Early OUT'];

        $query = employee::with([
            'organizationAssignment.company',
            'organizationAssignment.department',
            'organizationAssignment.subDepartment',
        ])->where('is_active', 1);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('nic', 'like', "%{$search}%")
                  ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        $employees = $query->paginate($perPage);

        $employees->getCollection()->transform(function ($employee) use ($startDate, $endDate, $attendanceStatuses) {
            $org = $employee->organizationAssignment;

            $companyId = $org->company_id ?? null;
            $departmentId = $org->department_id ?? null;

            $attendanceDates = time_card::where('employee_id', $employee->id)
                ->whereBetween('date', [$startDate, $endDate])
                ->whereIn('status', $attendanceStatuses)
                ->whereNull('deleted_at')
                ->select('date')
                ->distinct()
                ->pluck('date')
                ->toArray();

            $leaveEntries = leaveCalendar::where(function ($q) use ($companyId, $departmentId) {
                    $q->where(function ($q2) use ($companyId) {
                        $q2->where('company_id', $companyId)
                           ->whereNull('department_id');
                    });

                    if ($departmentId) {
                        $q->orWhere(function ($q3) use ($companyId, $departmentId) {
                            $q3->where('company_id', $companyId)
                               ->where('department_id', $departmentId);
                        });
                    }
                })
                ->whereDate('start_date', '<=', $endDate)
                ->where(function ($q) use ($startDate) {
                    $q->whereNull('end_date')
                      ->orWhereDate('end_date', '>=', $startDate);
                })
                ->get();

            $leaveDates = [];

            foreach ($leaveEntries as $leave) {
                $rangeStart = max($startDate, $leave->start_date);
                $rangeEnd = $leave->end_date ? min($endDate, $leave->end_date) : $leave->start_date;

                $current = strtotime($rangeStart);
                $last = strtotime($rangeEnd);

                while ($current <= $last) {
                    $leaveDates[] = date('Y-m-d', $current);
                    $current = strtotime('+1 day', $current);
                }
            }

            $leaveDates = array_values(array_unique($leaveDates));

            $presentDates = array_values(array_diff($attendanceDates, $leaveDates));
            sort($presentDates);

            return [
                'id' => $employee->id,
                'empNo' => $employee->attendance_employee_no ?? null,
                'name' => $employee->full_name ?? null,
                'company' => $org->company->name ?? null,
                'department' => $org->department->name ?? null,
                'sub_department' => $org->subDepartment->name ?? null,
                'month' => date('F Y', strtotime($startDate)),
                'present_dates' => $presentDates,
                'present_count' => count($presentDates),
            ];
        });

        return response()->json($employees);
    }
        */


public function monthly(Request $request)
{
    $validated = $request->validate([
        'month' => 'required|date_format:Y-m',
        'search' => 'nullable|string',
        'per_page' => 'nullable|integer|min:1|max:100',
    ]);

    $month = $validated['month'];
    $search = $validated['search'] ?? null;
    $perPage = (int) ($validated['per_page'] ?? 15);

    [$year, $monthNumber] = explode('-', $month);

    $startDate = "{$year}-{$monthNumber}-01";
    $endDate = date('Y-m-t', strtotime($startDate));

    $attendanceStatuses = ['IN', 'Late Coming', 'OUT', 'Early OUT'];

    // Load leave calendar entries for selected month
    $leaveEntries = \App\Models\leaveCalendar::whereDate('start_date', '<=', $endDate)
        ->where(function ($q) use ($startDate) {
            $q->whereNull('end_date')
              ->orWhereDate('end_date', '>=', $startDate);
        })
        ->get();

    $leaveMap = [];

    foreach ($leaveEntries as $leave) {
        $rangeStart = max($startDate, $leave->start_date);
        $rangeEnd = $leave->end_date ? min($endDate, $leave->end_date) : $leave->start_date;

        $current = strtotime($rangeStart);
        $last = strtotime($rangeEnd);

        while ($current <= $last) {
            $d = date('Y-m-d', $current);
            $key = ($leave->company_id ?? 'null') . '-' . ($leave->department_id ?? 'null');
            $leaveMap[$key][] = $d;
            $current = strtotime('+1 day', $current);
        }
    }

    foreach ($leaveMap as $k => $dates) {
        $leaveMap[$k] = array_values(array_unique($dates));
    }

    $query = time_card::with([
            'employee.organizationAssignment.company',
            'employee.organizationAssignment.department',
            'employee.organizationAssignment.subDepartment',
        ])
        ->select(
            'employee_id',
            'date',
            DB::raw('MIN(CASE WHEN status IN ("IN", "Late Coming") THEN time END) as in_time'),
            DB::raw('MAX(CASE WHEN status IN ("OUT", "Early OUT") THEN time END) as out_time'),

            DB::raw('
                SUBSTRING_INDEX(
                    GROUP_CONCAT(
                        CASE
                            WHEN status IN ("IN", "Late Coming")
                            THEN status
                            ELSE NULL
                        END
                        ORDER BY time ASC SEPARATOR ","
                    ),
                    ",",
                    1
                ) as first_in_status
            '),

            DB::raw('
                SUBSTRING_INDEX(
                    GROUP_CONCAT(
                        CASE
                            WHEN status IN ("OUT", "Early OUT")
                            THEN status
                            ELSE NULL
                        END
                        ORDER BY time DESC SEPARATOR ","
                    ),
                    ",",
                    1
                ) as last_out_status
            ')
        )
        ->whereBetween('date', [$startDate, $endDate])
        ->whereIn('status', $attendanceStatuses)
        ->whereNull('deleted_at')
        ->groupBy('employee_id', 'date')
        ->orderBy('date', 'asc');

    if ($search) {
        $query->whereHas('employee', function ($q) use ($search) {
            $q->where('full_name', 'like', "%{$search}%")
              ->orWhere('nic', 'like', "%{$search}%")
              ->orWhere('attendance_employee_no', 'like', "%{$search}%");
        });
    }

    $results = $query->paginate($perPage);

    $results->getCollection()->transform(function ($record) use ($leaveMap) {
        $employee = $record->employee;
        $org = $employee?->organizationAssignment;

        $companyId = $org->company_id ?? 'null';
        $departmentId = $org->department_id ?? 'null';

        $companyKey = $companyId . '-null';
        $departmentKey = $companyId . '-' . $departmentId;

        $holidayDates = array_merge(
            $leaveMap[$companyKey] ?? [],
            $leaveMap[$departmentKey] ?? []
        );

        $holidayDates = array_values(array_unique($holidayDates));

        $isLeaveDate = in_array($record->date, $holidayDates);

        return [
            'id' => $record->employee_id . '_' . $record->date,
            'employee_id' => $record->employee_id,
            'empNo' => $employee->attendance_employee_no ?? null,
            'name' => $employee->full_name ?? null,
            'company' => $org->company->name ?? null,
            'department' => $org->department->name ?? null,
            'sub_department' => $org->subDepartment->name ?? null,
            'date' => $record->date,
            'in_time' => $record->in_time ?? '-',
            'out_time' => $record->out_time ?? '-',
            'status' => 'Present',
            'in_label' => ($record->first_in_status ?? null) === 'Late Coming' ? 'Late Coming' : null,
            'out_label' => ($record->last_out_status ?? null) === 'Early OUT' ? 'Early OUT' : null,

            // NEW
            'is_leave_date' => $isLeaveDate,
            'date_label' => $isLeaveDate ? 'Holiday Worked' : null,
        ];
    });

    return response()->json($results);
}


}


//==================================================================

/*
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\time_card;
use Illuminate\Support\Facades\DB;

class AttendanceReportController extends Controller
{
    
    public function index(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string',
        ]);

        $date = $validated['date'];
        $perPage = (int) ($validated['per_page'] ?? 15);
        $search = $validated['search'] ?? null;

        $statuses = ['IN', 'Late Coming', 'OUT', 'Early OUT'];

        $attendedEmployeeIds = time_card::select('employee_id')
            ->where('date', $date)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('employee_id');

        $query = time_card::with([
                'employee.organizationAssignment.company',
                'employee.organizationAssignment.department',
                'employee.organizationAssignment.subDepartment',
            ])
            ->select(
                'employee_id',
                DB::raw('MIN(CASE WHEN status IN ("IN", "Late Coming") THEN time END) as in_time'),
                DB::raw('MAX(CASE WHEN status IN ("OUT", "Early OUT") THEN time END) as out_time'),
                DB::raw('MIN(date) as date'),
                DB::raw('GROUP_CONCAT(DISTINCT status ORDER BY time ASC SEPARATOR ", ") as entries'),

                DB::raw('
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            CASE
                                WHEN status IN ("IN", "Late Coming")
                                THEN status
                                ELSE NULL
                            END
                            ORDER BY time ASC SEPARATOR ","
                        ),
                        ",",
                        1
                    ) as first_in_status
                '),

                DB::raw('
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            CASE
                                WHEN status IN ("OUT", "Early OUT")
                                THEN status
                                ELSE NULL
                            END
                            ORDER BY time DESC SEPARATOR ","
                        ),
                        ",",
                        1
                    ) as last_out_status
                ')
            )
            ->where('date', $date)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $attendedEmployeeIds)
            ->groupBy('employee_id');

        if ($search) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('nic', 'like', "%{$search}%")
                  ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        $results = $query->paginate($perPage);

        $results->getCollection()->transform(function ($record) {
            $employee = $record->employee;

            return [
                'id' => $record->employee_id,
                'empNo' => $employee->attendance_employee_no ?? null,
                'name' => $employee->full_name ?? null,
                'company' => $employee->organizationAssignment->company->name ?? null,
                'department' => $employee->organizationAssignment->department->name ?? null,
                'sub_department' => $employee->organizationAssignment->subDepartment->name ?? null,
                'date' => $record->date,
                'in_time' => $record->in_time ?? '-',
                'out_time' => $record->out_time ?? '-',

                // Always keep status as Present
                'status' => 'Present',

                // Labels for UI
                'in_label' => ($record->first_in_status ?? null) === 'Late Coming' ? 'Late Coming' : null,
                'out_label' => ($record->last_out_status ?? null) === 'Early OUT' ? 'Early OUT' : null,

                'entries' => $record->entries,
            ];
        });

        return response()->json($results);
    }
}
*/
