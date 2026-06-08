<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\time_card;
use App\Models\employee;
use App\Models\leaveCalendar;
use App\Models\leave_master;
use Illuminate\Support\Facades\DB;

class AttendanceReportController extends Controller
{
    private function getLatePolicyAction(int $lateIndex): string
    {
        if ($lateIndex <= 3) {
            return 'No Deduction';
        }

        if ($lateIndex <= 5) {
            return 'Short Leave';
        }

        return 'Half Day';
    }

    private function getLateMinutes(?string $inTime, string $officeStart = '09:00:00'): int
    {
        if (!$inTime) {
            return 0;
        }

        $in = strtotime($inTime);
        $start = strtotime($officeStart);

        if ($in <= $start) {
            return 0;
        }

        return (int) floor(($in - $start) / 60);
    }

    private function getLeaveInfoForDate($employeeId, $date): array
    {
        $leave = leave_master::where('employee_id', $employeeId)
            ->where(function ($query) use ($date) {
                $query->whereDate('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->whereDate('leave_from', '<=', $date)
                          ->whereDate('leave_to', '>=', $date);
                    });
            })
            ->orderByRaw("
                CASE
                    WHEN status = 'Approved' THEN 1
                    WHEN status = 'HR_Approved' THEN 2
                    WHEN status = 'Pending' THEN 3
                    WHEN status = 'Rejected' THEN 4
                    ELSE 5
                END
            ")
            ->first();

        if (!$leave) {
            return [
                'has_leave_request' => false,
                'leave_type' => null,
                'leave_status' => null,
                'is_half_day_leave' => false,
                'leave_period' => null,
                'admin_approved_leave' => false,
            ];
        }

        return [
            'has_leave_request' => true,
            'leave_type' => $leave->leave_type,
            'leave_status' => $leave->status,
            'is_half_day_leave' => (bool) $leave->is_half_day,
            'leave_period' => $leave->period,
            'admin_approved_leave' => in_array($leave->status, ['Approved', 'HR_Approved']),
        ];
    }

    public function updateApprovalStatus(Request $request, $employeeId, $date)
    {
        $validated = $request->validate([
            'approval_status' => 'required|in:Pending,Active,Rejected,Approved,Cancelled',
        ]);

        $updated = time_card::where('employee_id', $employeeId)
            ->where('date', $date)
            ->whereNull('deleted_at')
            ->update([
                'approval_status' => $validated['approval_status']
            ]);

        if ($updated === 0) {
            return response()->json([
                'message' => 'Attendance record not found'
            ], 404);
        }

        return response()->json([
            'message' => 'Approval status updated successfully'
        ], 200);
    }

    /**
     * Daily attendance report
     */
    public function index(Request $request)
    {
        $date = $request->input('date');
        $perPage = (int) ($request->input('per_page', 15));
        $search = $request->input('search');
        $company_id = $request->input('company_id');
        $department_id = $request->input('department_id');
        
        // 
        $holiday_worked = $request->boolean('holiday_worked');
        $employee_category = $request->input('employee_category');

        if (!$date) {
            return response()->json(['message' => 'Date is required'], 400);
        }

        // 1. Company, Department සහ Search වලට ගැලපෙන සේවකයින්ගේ ID සෙවීම
        $employeeQuery = employee::query();

        if ($company_id || $department_id) {
            $employeeQuery->whereHas('organizationAssignment', function ($q) use ($company_id, $department_id) {
                if ($company_id) {
                    $q->where('company_id', $company_id);
                }
                if ($department_id) {
                    $q->where('department_id', $department_id);
                }
            });
        }

        if ($search) {
            $employeeQuery->where(function($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('nic', 'like', "%{$search}%")
                  ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        $filteredEmployeeIds = $employeeQuery->pluck('id')->toArray();

        // ගැලපෙන සේවකයින් කිසිවෙක් නැත්නම් හිස් දත්ත යවන්න
        if (empty($filteredEmployeeIds)) {
            return response()->json([
                'data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0
            ]);
        }

        $statuses = ['IN', 'Late Coming', 'OUT', 'Early OUT'];

        $leaveEntries = leaveCalendar::whereDate('start_date', '<=', $date)
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

        // 2. ගැලපෙන සේවකයින්ගේ Attendance දත්ත ගැනීම
        $query = time_card::with([
                'employee.organizationAssignment.company',
                'employee.organizationAssignment.department',
                'employee.organizationAssignment.subDepartment',
                'employee.rosters' => function($q) use ($date) {
                    $q->whereDate('date_from', '<=', $date)
                      ->orderBy('date_from', 'desc');
                },
                'employee.rosters.shift'
            ])
            ->select(
                'employee_id',
                DB::raw('MIN(CASE WHEN status IN ("IN", "Late Coming") THEN time END) as in_time'),
                DB::raw('MAX(CASE WHEN status IN ("OUT", "Early OUT") THEN time END) as out_time'),
                DB::raw('MIN(date) as date'),
                DB::raw('MAX(approval_status) as approval_status'),
                DB::raw('GROUP_CONCAT(DISTINCT status ORDER BY time ASC SEPARATOR ", ") as entries'),
                DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN status IN ("IN", "Late Coming") THEN status ELSE NULL END ORDER BY time ASC SEPARATOR ","),",",1) as first_in_status'),
                DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN status IN ("OUT", "Early OUT") THEN status ELSE NULL END ORDER BY time DESC SEPARATOR ","),",",1) as last_out_status')
            )
            ->where('date', $date)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $filteredEmployeeIds) // Filter කරපු ID ටික පමණි
            ->groupBy('employee_id');

        if ($holiday_worked) {
            $results = $query->get();
        } else {
            $results = $query->paginate($perPage);
        }

        $collection = $holiday_worked ? $results : $results->getCollection();

        $collection->transform(function ($record) use ($leaveMap, $date) {
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

            $activeRoster = $employee->rosters->first();
            $shiftStartTime = $activeRoster && $activeRoster->shift ? $activeRoster->shift->start_time : '09:00:00';

            $isLate = ($record->first_in_status ?? null) === 'Late Coming';
            $lateMinutes = $isLate ? $this->getLateMinutes($record->in_time, $shiftStartTime) : 0;
            $isGracePeriodLate = $isLate && ($lateMinutes > 0) && ($lateMinutes <= 30);
            
            $leaveInfo = $this->getLeaveInfoForDate($record->employee_id, $record->date);

            return [
                'id' => $record->employee_id,
                'employee_id' => $record->employee_id,
                'empNo' => $employee->attendance_employee_no ?? null,
                'name' => $employee->full_name ?? null,
                'company' => $org->company->name ?? null,
                'department' => $org->department->name ?? null,
                'sub_department' => $org->subDepartment->name ?? null,
                'date' => $record->date,
                'in_time' => $record->in_time ?? '-',
                'out_time' => $record->out_time ?? '-',
                'shift_start' => $shiftStartTime,
                'status' => 'Present',
                'in_label' => $isLate ? 'Late Coming' : null,
                'out_label' => ($record->last_out_status ?? null) === 'Early OUT' ? 'Early OUT' : null,
                'is_leave_date' => $isLeaveDate,
                'date_label' => $isLeaveDate ? 'Holiday Worked' : null,
                'is_late' => $isLate && !$isGracePeriodLate, 
                'late_minutes' => $lateMinutes,
                'is_grace_period_late' => $isGracePeriodLate, 
                'approval_status' => $record->approval_status ?? 'Pending',
                'entries' => $record->entries,
                'has_leave_request' => $leaveInfo['has_leave_request'],
                'leave_type' => $leaveInfo['leave_type'],
                'leave_status' => $leaveInfo['leave_status'],
                'is_half_day_leave' => $leaveInfo['is_half_day_leave'],
                'leave_period' => $leaveInfo['leave_period'],
                'admin_approved_leave' => $leaveInfo['admin_approved_leave'],
                'late_with_leave' => $isLate && $leaveInfo['has_leave_request'],
                'late_without_leave' => $isLate && !$leaveInfo['has_leave_request'],
            ];
        });

        // Holiday Worked නම් ඒවා පමණක් තෝරමු
        if ($holiday_worked) {
            $filteredCollection = $collection->filter(function ($item) {
                return $item['is_leave_date'] === true;
            })->values();

            $page = $request->input('page', 1);
            $total = $filteredCollection->count();
            $items = $filteredCollection->slice(($page - 1) * $perPage, $perPage)->values();

            return response()->json([
                'data' => $items,
                'current_page' => (int)$page,
                'last_page' => $perPage > 0 ? ceil($total / $perPage) : 1,
                'per_page' => $perPage,
                'total' => $total,
            ]);
        }

        return response()->json($results);
    }

    //========================================
   //doble mark finger print
   //=========================================


   // (Intermediate Movements) 
    public function getIntermediateMovements($employeeId, $date)
    {
        $punches = time_card::where('employee_id', $employeeId)
            ->where('date', $date)
            ->whereNull('deleted_at')
            ->orderBy('time', 'asc')
            ->get();

        // IN එකයි OUT එකයි විතරක් නම් (Punches 2යි නම්) මැද ගමන් නෑ
        if ($punches->count() <= 2) {
            return response()->json(['data' => []]); 
        }

        // පළවෙනි එකයි අන්තිම එකයි අයින් කරලා මැද ටික විතරක් ගන්නවා
        $middlePunches = $punches->slice(1, $punches->count() - 2)->values();
        $movements = [];

        for ($i = 0; $i < $middlePunches->count(); $i++) {
            $current = $middlePunches[$i];
            
            // එළියට ගිය වෙලාවක් නම්
            if (in_array(strtoupper($current->status), ['OUT', 'EARLY OUT'])) {
                $next = $middlePunches[$i + 1] ?? null;
                
                // ඊළඟට ආපහු ඇතුළට ආපු වෙලාව
                if ($next && in_array(strtoupper($next->status), ['IN', 'LATE COMING'])) {
                    $durationMins = round((strtotime($next->time) - strtotime($current->time)) / 60);

                    $movements[] = [
                        'out_id' => $current->id,
                        'in_id' => $next->id,
                        'out_time' => $current->time,
                        'in_time' => $next->time,
                        'duration_mins' => $durationMins,
                        'reason' => $current->reason,
                        'status' => $current->break_status ?? 'Pending',
                    ];
                    $i++; // Pair එකක් හැදුන නිසා ඊළඟ එක Skip කරනවා
                }
            }
        }

        return response()->json(['data' => $movements]);
    }

    //  (Approve / Reject) Save 
    public function updateMovementStatus(Request $request)
    {
        $outId = $request->out_id;
        
        time_card::where('id', $outId)->update([
            'break_status' => $request->status, // 'Approved' or 'Rejected'
            'reason' => $request->reason
        ]);

        return response()->json(['message' => 'Status updated successfully!']);
    }



    /**
     * Date range attendance report (from_date to to_date)
     */
    public function dateRange(Request $request)
    {
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');
        $perPage = (int) ($request->input('per_page', 15));
        $search = $request->input('search');
        $company_id = $request->input('company_id');
        $department_id = $request->input('department_id');
        $employee_category = $request->input('employee_category');
        $holiday_worked = $request->boolean('holiday_worked');

        if (!$fromDate || !$toDate) {
            return response()->json(['message' => 'from_date and to_date are required'], 400);
        }

        if (strtotime($fromDate) > strtotime($toDate)) {
            return response()->json(['message' => 'from_date must be before or equal to to_date'], 400);
        }

        $employeeQuery = employee::query();

        if ($company_id || $department_id) {
            $employeeQuery->whereHas('organizationAssignment', function ($q) use ($company_id, $department_id) {
                if ($company_id) {
                    $q->where('company_id', $company_id);
                }
                if ($department_id) {
                    $q->where('department_id', $department_id);
                }
            });
        }

        if ($employee_category) {
            $employeeQuery->whereHas('compensation', function ($q) use ($employee_category) {
                $q->where('employee_category', $employee_category);
            });
        }

        if ($search) {
            $employeeQuery->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('nic', 'like', "%{$search}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        $filteredEmployeeIds = $employeeQuery->pluck('id')->toArray();

        if (empty($filteredEmployeeIds)) {
            return response()->json([
                'data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0,
            ]);
        }

        $statuses = ['IN', 'Late Coming', 'OUT', 'Early OUT'];

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
                DB::raw('MAX(approval_status) as approval_status'),
                DB::raw('GROUP_CONCAT(DISTINCT status ORDER BY time ASC SEPARATOR ", ") as entries'),
                DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN status IN ("IN", "Late Coming") THEN status ELSE NULL END ORDER BY time ASC SEPARATOR ","),",",1) as first_in_status'),
                DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN status IN ("OUT", "Early OUT") THEN status ELSE NULL END ORDER BY time DESC SEPARATOR ","),",",1) as last_out_status')
            )
            ->whereBetween('date', [$fromDate, $toDate])
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $filteredEmployeeIds)
            ->groupBy('employee_id', 'date')
            ->orderBy('date', 'desc')
            ->orderBy('employee_id');

        $results = $query->paginate($perPage);

        $results->getCollection()->transform(function ($record) {
            $employee = $record->employee;
            $org = $employee?->organizationAssignment;
            $isLate = ($record->first_in_status ?? null) === 'Late Coming';

            return [
                'id' => $record->employee_id . '-' . $record->date,
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
                'in_label' => $isLate ? 'Late Coming' : null,
                'out_label' => ($record->last_out_status ?? null) === 'Early OUT' ? 'Early OUT' : null,
                'approval_status' => $record->approval_status ?? 'Pending',
                'entries' => $record->entries,
            ];
        });

        return response()->json($results);
    }

    public function monthly(Request $request)
    {
        $month = $request->input('month');
        $perPage = (int) ($request->input('per_page', 15));
        $search = $request->input('search');
        $company_id = $request->input('company_id');
        $department_id = $request->input('department_id');
        $employee_category = $request->input('employee_category');
        
        // වඩාත් නිවැරදිව boolean අගය ලබා ගැනීම
        $holiday_worked = $request->boolean('holiday_worked');

        if (!$month) {
            return response()->json(['message' => 'Month is required'], 400);
        }

        [$year, $monthNumber] = explode('-', $month);
        $startDate = "{$year}-{$monthNumber}-01";
        $endDate = date('Y-m-t', strtotime($startDate));

        // 1. Company, Department සහ Search වලට ගැලපෙන සේවකයින්ගේ ID සෙවීම
        $employeeQuery = employee::query();

        if ($company_id || $department_id) {
            $employeeQuery->whereHas('organizationAssignment', function ($q) use ($company_id, $department_id) {
                if ($company_id) {
                    $q->where('company_id', $company_id);
                }
                if ($department_id) {
                    $q->where('department_id', $department_id);
                }
            });
        }
        
        if ($employee_category) {
            $employeeQuery->whereHas('compensation', function ($q) use ($employee_category) {
                $q->where('employee_category', $employee_category);
            });
        }

        if ($search) {
            $employeeQuery->where(function($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('nic', 'like', "%{$search}%")
                  ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        $filteredEmployeeIds = $employeeQuery->pluck('id')->toArray();

        // ගැලපෙන සේවකයින් කිසිවෙක් නැත්නම් හිස් දත්ත යවන්න
        if (empty($filteredEmployeeIds)) {
            return response()->json([
                'data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0, 'summary' => []
            ]);
        }

        $attendanceStatuses = ['IN', 'Late Coming', 'OUT', 'Early OUT'];

        $leaveEntries = leaveCalendar::whereDate('start_date', '<=', $endDate)
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

        // 2. ගැලපෙන සේවකයින්ගේ Attendance දත්ත ගැනීම
        $query = time_card::with([
                'employee.organizationAssignment.company',
                'employee.organizationAssignment.department',
                'employee.organizationAssignment.subDepartment',
                'employee.rosters' => function($q) use ($endDate) {
                    $q->whereDate('date_from', '<=', $endDate)
                      ->orderBy('date_from', 'desc');
                },
                'employee.rosters.shift'
            ])
            ->select(
                'employee_id',
                'date',
                DB::raw('MIN(CASE WHEN status IN ("IN", "Late Coming") THEN time END) as in_time'),
                DB::raw('MAX(CASE WHEN status IN ("OUT", "Early OUT") THEN time END) as out_time'),
                DB::raw('MAX(approval_status) as approval_status'),
                DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN status IN ("IN", "Late Coming") THEN status ELSE NULL END ORDER BY time ASC SEPARATOR ","),",",1) as first_in_status'),
                DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN status IN ("OUT", "Early OUT") THEN status ELSE NULL END ORDER BY time DESC SEPARATOR ","),",",1) as last_out_status')
            )
            ->whereBetween('date', [$startDate, $endDate])
            ->whereIn('status', $attendanceStatuses)
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $filteredEmployeeIds) // Filter කරපු ID ටික පමණි
            ->groupBy('employee_id', 'date')
            ->orderBy('employee_id')
            ->orderBy('date', 'asc');

        if ($holiday_worked) {
            $results = $query->get();
            $collection = $results;
        } else {
            $results = $query->paginate($perPage);
            $collection = $results->getCollection();
        }

        $grouped = $collection->groupBy('employee_id');
        $finalRows = collect();

        $summary = [
            'total_late_days' => 0,
            'approved_late_days' => 0,
            'pending_late_days' => 0,
            'rejected_late_days' => 0,
            'no_deduction_count' => 0,
            'short_leave_count' => 0,
            'half_day_count' => 0,
            'late_with_leave_count' => 0,
            'late_without_leave_count' => 0,
            'approved_leave_late_count' => 0,
        ];

        foreach ($grouped as $employeeId => $records) {
            $records = $records->sortBy('date')->values();
            $lateCounter = 0;

            $monthlyLateCount = $records->filter(function ($r) {
                $isLate = ($r->first_in_status ?? null) === 'Late Coming';
                if ($isLate) {
                    $activeRoster = $r->employee->rosters->first();
                    $shiftStartTime = $activeRoster && $activeRoster->shift ? $activeRoster->shift->start_time : '09:00:00';
                    $mins = $this->getLateMinutes($r->in_time, $shiftStartTime);
                    return $mins > 30;
                }
                return false;
            })->count();

            foreach ($records as $record) {
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

                // Holiday Worked නම් ඒවා පමණක් තෝරමු
                if ($holiday_worked && !$isLeaveDate) {
                    continue;
                }

                $isLate = ($record->first_in_status ?? null) === 'Late Coming';

                $lateIndex = null;
                $latePolicyAction = null;
                $lateMinutes = 0;
                $isGracePeriodLate = false;

                $activeRoster = $employee->rosters->first();
                $shiftStartTime = $activeRoster && $activeRoster->shift ? $activeRoster->shift->start_time : '09:00:00';

                if ($isLate) {
                    $lateMinutes = $this->getLateMinutes($record->in_time, $shiftStartTime);
                    
                    if ($lateMinutes > 0 && $lateMinutes <= 30) {
                        $isGracePeriodLate = true;
                    } else if ($lateMinutes > 30) {
                        $lateCounter++;
                        $lateIndex = $lateCounter;
                        $latePolicyAction = $this->getLatePolicyAction($lateIndex);
                    }
                }

                $leaveInfo = $this->getLeaveInfoForDate($record->employee_id, $record->date);

                if ($isLate && !$isGracePeriodLate && $lateMinutes > 30) { 
                    $summary['total_late_days']++;

                    $statusValue = $record->approval_status ?? 'Pending';

                    if (in_array($statusValue, ['Active', 'Approved'])) {
                        $summary['approved_late_days']++;
                    } elseif (in_array($statusValue, ['Rejected', 'Cancelled'])) {
                        $summary['rejected_late_days']++;
                    } else {
                        $summary['pending_late_days']++;
                    }

                    if ($latePolicyAction === 'No Deduction') {
                        $summary['no_deduction_count']++;
                    } elseif ($latePolicyAction === 'Short Leave') {
                        $summary['short_leave_count']++;
                    } elseif ($latePolicyAction === 'Half Day') {
                        $summary['half_day_count']++;
                    }

                    if ($leaveInfo['has_leave_request']) {
                        $summary['late_with_leave_count']++;
                    } else {
                        $summary['late_without_leave_count']++;
                    }

                    if ($leaveInfo['admin_approved_leave']) {
                        $summary['approved_leave_late_count']++;
                    }
                }

                $finalRows->push([
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
                    'shift_start' => $shiftStartTime,
                    'status' => 'Present',
                    'in_label' => $isLate ? 'Late Coming' : null,
                    'out_label' => ($record->last_out_status ?? null) === 'Early OUT' ? 'Early OUT' : null,
                    'is_leave_date' => $isLeaveDate,
                    'date_label' => $isLeaveDate ? 'Holiday Worked' : null,
                    'is_late' => $isLate && !$isGracePeriodLate && $lateMinutes > 30,
                    'late_minutes' => $lateMinutes,
                    'is_grace_period_late' => $isGracePeriodLate,
                    'late_day_number' => $lateIndex,
                    'late_policy_action' => $latePolicyAction,
                    'monthly_late_count' => $monthlyLateCount,
                    'approval_status' => $record->approval_status ?? 'Pending',
                    'has_leave_request' => $leaveInfo['has_leave_request'],
                    'leave_type' => $leaveInfo['leave_type'],
                    'leave_status' => $leaveInfo['leave_status'],
                    'is_half_day_leave' => $leaveInfo['is_half_day_leave'],
                    'leave_period' => $leaveInfo['leave_period'],
                    'admin_approved_leave' => $leaveInfo['admin_approved_leave'],
                    'late_with_leave' => $isLate && $leaveInfo['has_leave_request'],
                    'late_without_leave' => $isLate && !$leaveInfo['has_leave_request'],
                ]);
            }
        }

        if ($holiday_worked) {
            $page = $request->input('page', 1);
            $total = $finalRows->count();
            $items = $finalRows->slice(($page - 1) * $perPage, $perPage)->values();

            return response()->json([
                'data' => $items,
                'current_page' => (int)$page,
                'last_page' => $perPage > 0 ? ceil($total / $perPage) : 1,
                'per_page' => $perPage,
                'total' => $total,
                'summary' => $summary,
            ]);
        }

        $results->setCollection($finalRows->values());

        return response()->json([
            'data' => $results->items(),
            'current_page' => $results->currentPage(),
            'last_page' => $results->lastPage(),
            'per_page' => $results->perPage(),
            'total' => $results->total(),
            'summary' => $summary,
        ]);
    }
}


/*
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\time_card;
use App\Models\employee;
use App\Models\leaveCalendar;
use App\Models\leave_master;
use Illuminate\Support\Facades\DB;

class AttendanceReportController extends Controller
{
    
    private function getLatePolicyAction(int $lateIndex): string
    {
        if ($lateIndex <= 3) {
            return 'No Deduction';
        }

        if ($lateIndex <= 5) {
            return 'Short Leave';
        }

        return 'Half Day';
    }

    
    private function getLateMinutes(?string $inTime, string $officeStart = '09:00:00'): int
    {
        if (!$inTime) {
            return 0;
        }

        $in = strtotime($inTime);
        $start = strtotime($officeStart);

        if ($in <= $start) {
            return 0;
        }

        return (int) floor(($in - $start) / 60);
    }

   
    private function getLeaveInfoForDate($employeeId, $date): array
    {
        $leave = leave_master::where('employee_id', $employeeId)
            ->where(function ($query) use ($date) {
                $query->whereDate('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->whereDate('leave_from', '<=', $date)
                          ->whereDate('leave_to', '>=', $date);
                    });
            })
            ->orderByRaw("
                CASE
                    WHEN status = 'Approved' THEN 1
                    WHEN status = 'HR_Approved' THEN 2
                    WHEN status = 'Pending' THEN 3
                    WHEN status = 'Rejected' THEN 4
                    ELSE 5
                END
            ")
            ->first();

        if (!$leave) {
            return [
                'has_leave_request' => false,
                'leave_type' => null,
                'leave_status' => null,
                'is_half_day_leave' => false,
                'leave_period' => null,
                'admin_approved_leave' => false,
            ];
        }

        return [
            'has_leave_request' => true,
            'leave_type' => $leave->leave_type,
            'leave_status' => $leave->status,
            'is_half_day_leave' => (bool) $leave->is_half_day,
            'leave_period' => $leave->period,
            'admin_approved_leave' => in_array($leave->status, ['Approved', 'HR_Approved']),
        ];
    }

   
    public function updateApprovalStatus(Request $request, $employeeId, $date)
    {
        $validated = $request->validate([
            'approval_status' => 'required|in:Pending,Active,Rejected,Approved,Cancelled',
        ]);

        $updated = time_card::where('employee_id', $employeeId)
            ->where('date', $date)
            ->whereNull('deleted_at')
            ->update([
                'approval_status' => $validated['approval_status']
            ]);

        if ($updated === 0) {
            return response()->json([
                'message' => 'Attendance record not found'
            ], 404);
        }

        return response()->json([
            'message' => 'Approval status updated successfully'
        ], 200);
    }

    
 
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
        $leaveEntries = leaveCalendar::whereDate('start_date', '<=', $date)
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
                // සේවකයාගේ Roster එක සහ ඒකට අදාල Shift එක ගෙන්වා ගැනීම
                'employee.rosters' => function($q) use ($date) {
                    $q->whereDate('date_from', '<=', $date)
                      ->orderBy('date_from', 'desc');
                },
                'employee.rosters.shift'
            ])
            ->select(
                'employee_id',
                DB::raw('MIN(CASE WHEN status IN ("IN", "Late Coming") THEN time END) as in_time'),
                DB::raw('MAX(CASE WHEN status IN ("OUT", "Early OUT") THEN time END) as out_time'),
                DB::raw('MIN(date) as date'),
                DB::raw('MAX(approval_status) as approval_status'),
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

            // Roster Start Time එක ලබා ගැනීම (Default: 09:00:00)
            $activeRoster = $employee->rosters->first();
            $shiftStartTime = $activeRoster && $activeRoster->shift ? $activeRoster->shift->start_time : '09:00:00';

            $isLate = ($record->first_in_status ?? null) === 'Late Coming';
            // Fixed time එක වෙනුවට Shift Start Time එක භාවිතා කිරීම
            $lateMinutes = $isLate ? $this->getLateMinutes($record->in_time, $shiftStartTime) : 0;
            
            // New logic: Check if late is within 30 min grace period (from Roster start time)
            $isGracePeriodLate = $isLate && ($lateMinutes > 0) && ($lateMinutes <= 30);
            
            $leaveInfo = $this->getLeaveInfoForDate($record->employee_id, $record->date);

            return [
                'id' => $record->employee_id,
                'employee_id' => $record->employee_id,
                'empNo' => $employee->attendance_employee_no ?? null,
                'name' => $employee->full_name ?? null,
                'company' => $org->company->name ?? null,
                'department' => $org->department->name ?? null,
                'sub_department' => $org->subDepartment->name ?? null,
                'date' => $record->date,
                'in_time' => $record->in_time ?? '-',
                'out_time' => $record->out_time ?? '-',
                'shift_start' => $shiftStartTime, // Optional: Frontend එකට යවන්න පුළුවන්
                'status' => 'Present',
                'in_label' => $isLate ? 'Late Coming' : null,
                'out_label' => ($record->last_out_status ?? null) === 'Early OUT' ? 'Early OUT' : null,

                'is_leave_date' => $isLeaveDate,
                'date_label' => $isLeaveDate ? 'Holiday Worked' : null,

                'is_late' => $isLate && !$isGracePeriodLate, // Only true if > 30 mins from Roster time
                'late_minutes' => $lateMinutes,
                'is_grace_period_late' => $isGracePeriodLate, // Send to frontend for button

                'approval_status' => $record->approval_status ?? 'Pending',
                'entries' => $record->entries,

                // Leave related fields
                'has_leave_request' => $leaveInfo['has_leave_request'],
                'leave_type' => $leaveInfo['leave_type'],
                'leave_status' => $leaveInfo['leave_status'],
                'is_half_day_leave' => $leaveInfo['is_half_day_leave'],
                'leave_period' => $leaveInfo['leave_period'],
                'admin_approved_leave' => $leaveInfo['admin_approved_leave'],
                'late_with_leave' => $isLate && $leaveInfo['has_leave_request'],
                'late_without_leave' => $isLate && !$leaveInfo['has_leave_request'],
            ];
        });

        return response()->json($results);
    }

   
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
        $leaveEntries = leaveCalendar::whereDate('start_date', '<=', $endDate)
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
                // සේවකයාගේ Roster එක ගෙන්වා ගැනීම
                'employee.rosters' => function($q) use ($endDate) {
                    $q->whereDate('date_from', '<=', $endDate)
                      ->orderBy('date_from', 'desc');
                },
                'employee.rosters.shift'
            ])
            ->select(
                'employee_id',
                'date',
                DB::raw('MIN(CASE WHEN status IN ("IN", "Late Coming") THEN time END) as in_time'),
                DB::raw('MAX(CASE WHEN status IN ("OUT", "Early OUT") THEN time END) as out_time'),
                DB::raw('MAX(approval_status) as approval_status'),
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
            ->orderBy('employee_id')
            ->orderBy('date', 'asc');

        if ($search) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('nic', 'like', "%{$search}%")
                  ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        $results = $query->paginate($perPage);
        $collection = $results->getCollection();

        $grouped = $collection->groupBy('employee_id');
        $finalRows = collect();

        $summary = [
            'total_late_days' => 0,
            'approved_late_days' => 0,
            'pending_late_days' => 0,
            'rejected_late_days' => 0,
            'no_deduction_count' => 0,
            'short_leave_count' => 0,
            'half_day_count' => 0,
            'late_with_leave_count' => 0,
            'late_without_leave_count' => 0,
            'approved_leave_late_count' => 0,
        ];

        foreach ($grouped as $employeeId => $records) {
            $records = $records->sortBy('date')->values();
            $lateCounter = 0;

            // Monthly late count (Only > 30 mins)
            $monthlyLateCount = $records->filter(function ($r) {
                $isLate = ($r->first_in_status ?? null) === 'Late Coming';
                if ($isLate) {
                    $activeRoster = $r->employee->rosters->first();
                    $shiftStartTime = $activeRoster && $activeRoster->shift ? $activeRoster->shift->start_time : '09:00:00';
                    $mins = $this->getLateMinutes($r->in_time, $shiftStartTime);
                    return $mins > 30;
                }
                return false;
            })->count();

            foreach ($records as $record) {
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

                $isLate = ($record->first_in_status ?? null) === 'Late Coming';

                $lateIndex = null;
                $latePolicyAction = null;
                $lateMinutes = 0;
                $isGracePeriodLate = false;

                // Roster Start Time එක ලබා ගැනීම (Default: 09:00:00)
                $activeRoster = $employee->rosters->first();
                $shiftStartTime = $activeRoster && $activeRoster->shift ? $activeRoster->shift->start_time : '09:00:00';

                if ($isLate) {
                    $lateMinutes = $this->getLateMinutes($record->in_time, $shiftStartTime);
                    
                    if ($lateMinutes > 0 && $lateMinutes <= 30) {
                        // 0-30 min late
                        $isGracePeriodLate = true;
                    } else if ($lateMinutes > 30) {
                        // >30 min late
                        $lateCounter++;
                        $lateIndex = $lateCounter;
                        $latePolicyAction = $this->getLatePolicyAction($lateIndex);
                    }
                }

                $leaveInfo = $this->getLeaveInfoForDate($record->employee_id, $record->date);

                if ($isLate && !$isGracePeriodLate && $lateMinutes > 30) { 
                    $summary['total_late_days']++;

                    $statusValue = $record->approval_status ?? 'Pending';

                    if (in_array($statusValue, ['Active', 'Approved'])) {
                        $summary['approved_late_days']++;
                    } elseif (in_array($statusValue, ['Rejected', 'Cancelled'])) {
                        $summary['rejected_late_days']++;
                    } else {
                        $summary['pending_late_days']++;
                    }

                    if ($latePolicyAction === 'No Deduction') {
                        $summary['no_deduction_count']++;
                    } elseif ($latePolicyAction === 'Short Leave') {
                        $summary['short_leave_count']++;
                    } elseif ($latePolicyAction === 'Half Day') {
                        $summary['half_day_count']++;
                    }

                    if ($leaveInfo['has_leave_request']) {
                        $summary['late_with_leave_count']++;
                    } else {
                        $summary['late_without_leave_count']++;
                    }

                    if ($leaveInfo['admin_approved_leave']) {
                        $summary['approved_leave_late_count']++;
                    }
                }

                $finalRows->push([
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
                    'shift_start' => $shiftStartTime,
                    'status' => 'Present',
                    'in_label' => $isLate ? 'Late Coming' : null,
                    'out_label' => ($record->last_out_status ?? null) === 'Early OUT' ? 'Early OUT' : null,

                    'is_leave_date' => $isLeaveDate,
                    'date_label' => $isLeaveDate ? 'Holiday Worked' : null,

                    'is_late' => $isLate && !$isGracePeriodLate && $lateMinutes > 30,
                    'late_minutes' => $lateMinutes,
                    'is_grace_period_late' => $isGracePeriodLate, // Send to frontend
                    
                    'late_day_number' => $lateIndex,
                    'late_policy_action' => $latePolicyAction,
                    'monthly_late_count' => $monthlyLateCount,

                    'approval_status' => $record->approval_status ?? 'Pending',

                    // Leave related fields
                    'has_leave_request' => $leaveInfo['has_leave_request'],
                    'leave_type' => $leaveInfo['leave_type'],
                    'leave_status' => $leaveInfo['leave_status'],
                    'is_half_day_leave' => $leaveInfo['is_half_day_leave'],
                    'leave_period' => $leaveInfo['leave_period'],
                    'admin_approved_leave' => $leaveInfo['admin_approved_leave'],
                    'late_with_leave' => $isLate && $leaveInfo['has_leave_request'],
                    'late_without_leave' => $isLate && !$leaveInfo['has_leave_request'],
                ]);
            }
        }

        $results->setCollection($finalRows->values());

        return response()->json([
            'data' => $results->items(),
            'current_page' => $results->currentPage(),
            'last_page' => $results->lastPage(),
            'per_page' => $results->perPage(),
            'total' => $results->total(),
            'summary' => $summary,
        ]);
    }
}

*/
