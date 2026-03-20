<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Roster;
use App\Models\time_card;
use App\Models\employee;
use App\Models\leave_master;
use App\Models\leaveCalendar;
use App\Models\NoPayRecord;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class AbsentReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'mode' => 'nullable|in:daily,monthly',
            'date' => 'nullable|date',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
            'session' => 'nullable|in:morning,afternoon',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $mode = $validated['mode'] ?? 'daily';
        $session = strtolower($validated['session'] ?? 'morning');
        $perPage = (int)($validated['per_page'] ?? 15);
        $search = $validated['search'] ?? null;
        $companyId = $validated['company_id'] ?? null;
        $departmentId = $validated['department_id'] ?? null;
        $currentPage = max((int)$request->query('page', 1), 1);

        if ($mode === 'daily' && empty($validated['date'])) {
            return response()->json([
                'message' => 'date is required for daily mode'
            ], 422);
        }

        if ($mode === 'monthly' && (empty($validated['month']) || empty($validated['year']))) {
            return response()->json([
                'message' => 'month and year are required for monthly mode'
            ], 422);
        }

        if ($mode === 'daily') {
            $rows = $this->getDailyRows(
                $validated['date'],
                $session,
                $search,
                $companyId,
                $departmentId
            );
        } else {
            $rows = $this->getMonthlyRows(
                (int)$validated['month'],
                (int)$validated['year'],
                $session,
                $search,
                $companyId,
                $departmentId
            );
        }

        $total = $rows->count();
        $lastPage = max((int)ceil($total / $perPage), 1);
        $offset = ($currentPage - 1) * $perPage;
        $pageRows = $rows->slice($offset, $perPage)->values();

        return response()->json([
            'current_page' => $currentPage,
            'data' => $pageRows,
            'from' => $total > 0 ? $offset + 1 : null,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'to' => $total > 0 ? min($offset + $perPage, $total) : null,
            'total' => $total,
        ]);
    }

    private function getMonthlyRows(
        int $month,
        int $year,
        string $session,
        ?string $search,
        $companyId,
        $departmentId
    ) {
        $today = Carbon::today();
        $monthStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $monthEnd = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        // future month ekak nam empty
        if ($monthStart->gt($today)) {
            return collect();
        }

        // current/future overlap month ekedi today wenakam witharai
        if ($monthEnd->gt($today)) {
            $monthEnd = $today->copy();
        }

        $period = CarbonPeriod::create($monthStart, $monthEnd);

        $rows = collect();

        foreach ($period as $day) {
            $date = $day->format('Y-m-d');

            $dailyRows = $this->getDailyRows(
                $date,
                $session,
                $search,
                $companyId,
                $departmentId
            );

            foreach ($dailyRows as $row) {
                $rows->push($row);
            }
        }

        return $rows->sortBy([
            ['date', 'desc'],
            ['name', 'asc'],
        ])->values();
    }

    private function getDailyRows(
        string $date,
        string $session,
        ?string $search,
        $companyId,
        $departmentId
    ) {
        $statuses = ['IN', 'OUT', 'Early OUT'];

        // 1) rostered employees on selected date
        $rosteredQuery = roster::query()
            ->whereNotNull('employee_id')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($date) {
                $q->where(function ($q2) use ($date) {
                    $q2->whereNull('date_from')->orWhere('date_from', '<=', $date);
                });
                $q->where(function ($q2) use ($date) {
                    $q2->whereNull('date_to')->orWhere('date_to', '>=', $date);
                });
            });

        if ($companyId) {
            $rosteredQuery->where('company_id', $companyId);
        }

        if ($departmentId) {
            $rosteredQuery->where('department_id', $departmentId);
        }

        $rosteredRows = $rosteredQuery
            ->get(['employee_id', 'company_id', 'department_id']);

        $rosteredEmployeeIds = $rosteredRows->pluck('employee_id')->unique()->values();

        if ($rosteredEmployeeIds->isEmpty()) {
            return collect();
        }

        // 2) attendance on selected date
        $attendedEmployeeIds = time_card::whereDate('date', $date)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('employee_id');

        // 3) approved / HR approved leaves covering date
        $approvedLeaves = leave_master::whereIn('status', ['Approved', 'HR_Approved'])
            ->whereIn('employee_id', $rosteredEmployeeIds)
            ->where(function ($q) use ($date) {
                $q->whereDate('leave_date', $date)
                    ->orWhere(function ($q2) use ($date) {
                        $q2->whereDate('leave_from', '<=', $date)
                            ->whereDate('leave_to', '>=', $date);
                    });
            })
            ->select(
                'employee_id',
                'status',
                'is_half_day',
                'period',
                'leave_date',
                'leave_from',
                'leave_to',
                'leave_duration',
                'leave_type'
            )
            ->get();

        // 4) session logic
        $fullOrRangeApprovedIds = $approvedLeaves
            ->filter(function ($lv) {
                return (!$lv->is_half_day) || (floatval($lv->leave_duration) >= 1.0);
            })
            ->pluck('employee_id')
            ->unique();

        $halfMorningLeaveIds = $approvedLeaves
            ->filter(function ($lv) {
                return ($lv->is_half_day || floatval($lv->leave_duration) == 0.5)
                    && strtolower((string)$lv->period) === 'morning';
            })
            ->pluck('employee_id')
            ->unique();

        $halfAfternoonLeaveIds = $approvedLeaves
            ->filter(function ($lv) {
                return ($lv->is_half_day || floatval($lv->leave_duration) == 0.5)
                    && strtolower((string)$lv->period) === 'afternoon';
            })
            ->pluck('employee_id')
            ->unique();

        // base absent = rostered - attended
        $absentEmployeeIds = collect($rosteredEmployeeIds)
            ->diff($attendedEmployeeIds)
            ->unique();

        // session specific exclusion only for relevant half-day
        if ($session === 'morning') {
            $absentEmployeeIds = $absentEmployeeIds
                ->diff($halfMorningLeaveIds)
                ->unique();
        } elseif ($session === 'afternoon') {
            $absentEmployeeIds = $absentEmployeeIds
                ->diff($halfAfternoonLeaveIds)
                ->unique();
        }

        // full/range approved leave aya result eken ain karanne naha
        // mokada table eke leave approval pennanna one
        // e aya result eke innawa, label ekama venas wenawa

        // 5) leave calendar holidays exclude
        $holidayEmployeeIds = collect();

        foreach ($rosteredRows as $row) {
            if ($this->isHolidayForRosterRow($row->company_id, $row->department_id, $date)) {
                $holidayEmployeeIds->push($row->employee_id);
            }
        }

        $absentEmployeeIds = $absentEmployeeIds
            ->diff($holidayEmployeeIds->unique())
            ->unique()
            ->values();

        if ($absentEmployeeIds->isEmpty()) {
            return collect();
        }

        // 6) approved no-pay records only
        $approvedNoPayRecords = NoPayRecord::whereIn('employee_id', $absentEmployeeIds)
            ->whereDate('date', $date)
            ->where('status', 'Approved')
            ->get()
            ->keyBy('employee_id');

        // 7) employee details
        $employeeQuery = employee::with([
                'organizationAssignment.company',
                'organizationAssignment.department',
                'organizationAssignment.subDepartment',
            ])
            ->whereIn('id', $absentEmployeeIds)
            ->where('is_active', 1);

        if ($search) {
            $employeeQuery->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('nic', 'like', "%{$search}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        $employees = $employeeQuery->get();

        $rows = $employees->map(function ($employee) use ($date, $approvedLeaves, $approvedNoPayRecords) {
            $leave = $approvedLeaves->firstWhere('employee_id', $employee->id);
            $approvedNoPay = $approvedNoPayRecords->get($employee->id);

            return [
                'id' => $employee->id . '_' . $date,
                'employee_id' => $employee->id,
                'empNo' => $employee->attendance_employee_no ?? null,
                'name' => $employee->full_name ?? null,
                'company' => $employee->organizationAssignment->company->name ?? null,
                'department' => $employee->organizationAssignment->department->name ?? null,
                'sub_department' => $employee->organizationAssignment->subDepartment->name ?? null,
                'date' => $date,
                'status' => 'Absent',
                'leave_approval' => $leave->status ?? 'No Leave',
                'leave_type' => $leave->leave_type ?? '-',
                'period' => $leave->period ?? '-',
                'nopay_status' => $approvedNoPay ? $approvedNoPay->status : 'No NoPay',
                'leave_or_nopay' => $leave
                    ? 'Approved Leave'
                    : ($approvedNoPay ? 'NoPay' : 'Absent'),
            ];
        });

        return $rows->sortBy([
            ['date', 'desc'],
            ['name', 'asc'],
        ])->values();
    }

    private function isHolidayForRosterRow($companyId, $departmentId, string $date): bool
    {
        $companyHoliday = false;
        $departmentHoliday = false;

        if ($companyId) {
            $companyHoliday = leaveCalendar::where('company_id', $companyId)
                ->whereDate('start_date', '<=', $date)
                ->where(function ($query) use ($date) {
                    $query->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', $date);
                })
                ->exists();
        }

        if ($departmentId) {
            $departmentHoliday = leaveCalendar::where('department_id', $departmentId)
                ->whereDate('start_date', '<=', $date)
                ->where(function ($query) use ($date) {
                    $query->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', $date);
                })
                ->exists();
        }

        return $companyHoliday || $departmentHoliday;
    }
}



/*
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\roster;
use App\Models\time_card;
use App\Models\employee;
use App\Models\leave_master;
use Illuminate\Support\Facades\DB;

class AbsentReportController extends Controller
{
    
    public function index(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'session' => 'nullable|in:morning,afternoon',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $date = $validated['date'];
        $session = strtolower($validated['session'] ?? 'morning');
        $perPage = (int)($validated['per_page'] ?? 15);
        $search = $validated['search'] ?? null;
        $companyId = $validated['company_id'] ?? null;
        $departmentId = $validated['department_id'] ?? null;

        $statuses = ['IN', 'OUT', 'Early OUT'];

        // Step 1: rostered employees active on the selected date
        $rosteredQuery = roster::query()
            ->whereNotNull('employee_id')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($date) {
                $q->where(function ($q2) use ($date) {
                    $q2->whereNull('date_from')->orWhere('date_from', '<=', $date);
                });
                $q->where(function ($q2) use ($date) {
                    $q2->whereNull('date_to')->orWhere('date_to', '>=', $date);
                });
            });

        if ($companyId) {
            $rosteredQuery->where('company_id', $companyId);
        }
        if ($departmentId) {
            $rosteredQuery->where('department_id', $departmentId);
        }

        $rosteredEmployeeIds = $rosteredQuery->pluck('employee_id')->unique();

        // Step 2: employees with any attendance entry on that date
        $attendedEmployeeIds = time_card::whereDate('date', $date)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('employee_id');

        // Step 3: Approved leaves covering the date
        // Full-day or multi-day leaves: exclude completely if the selected date lies within the approved range
        // Note: leave_duration is stored (e.g., 3.00 for 3 days), but we rely on the date range to decide coverage.
        $approvedCoveringLeave = leave_master::where('status', 'Approved')
            ->where(function ($q) use ($date) {
                $q->whereDate('leave_date', $date)
                  ->orWhere(function ($q2) use ($date) {
                      $q2->whereDate('leave_from', '<=', $date)
                         ->whereDate('leave_to', '>=', $date);
                  });
            })
            ->select('employee_id', 'is_half_day', 'period', 'leave_date', 'leave_from', 'leave_to', 'leave_duration')
            ->get();

        // Employees with approved full-day or ranged leave covering the date
        $fullOrRangeApprovedIds = $approvedCoveringLeave
            ->filter(function ($lv) {
                // Exclude half-day entries here; only full/range
                // Treat: is_half_day = false OR leave_duration >= 1
                return (!$lv->is_half_day) || (floatval($lv->leave_duration) >= 1.0);
            })
            ->pluck('employee_id')
            ->unique();

        // Half-day Morning covering selected date
        $halfMorningLeaveIds = $approvedCoveringLeave
            ->filter(function ($lv) {
                return ($lv->is_half_day || floatval($lv->leave_duration) == 0.5)
                    && (strtolower($lv->period) === 'morning');
            })
            ->pluck('employee_id')
            ->unique();

        // Half-day Afternoon covering selected date
        $halfAfternoonLeaveIds = $approvedCoveringLeave
            ->filter(function ($lv) {
                return ($lv->is_half_day || floatval($lv->leave_duration) == 0.5)
                    && (strtolower($lv->period) === 'afternoon');
            })
            ->pluck('employee_id')
            ->unique();

        // Step 4: compute absent set with session rules
        // Base excluded by leave depending on session
        $excludedByLeave = collect($fullOrRangeApprovedIds);

        if ($session === 'morning') {
            // Exclude full/range and morning half-day
            $excludedByLeave = $excludedByLeave->merge($halfMorningLeaveIds)->unique();
        } else { // afternoon
            // Exclude full/range and afternoon half-day
            $excludedByLeave = $excludedByLeave->merge($halfAfternoonLeaveIds)->unique();

            // Employees with morning half-day must have attendance to be present in afternoon.
            // If they have no attendance, include them in absent for afternoon (if rostered and not excluded already).
            $extraAbsent = collect($halfMorningLeaveIds)
                ->diff($attendedEmployeeIds)
                ->intersect($rosteredEmployeeIds)
                ->diff($excludedByLeave);

            $baseAbsentIds = collect($rosteredEmployeeIds)
                ->diff($attendedEmployeeIds)
                ->diff($excludedByLeave);

            $absentEmployeeIds = $baseAbsentIds->merge($extraAbsent)->unique();
        }

        if ($session !== 'afternoon') {
            // Morning case
            $absentEmployeeIds = collect($rosteredEmployeeIds)
                ->diff($attendedEmployeeIds)
                ->diff($excludedByLeave)
                ->unique();
        }

        // Step 5: build employee query and paginate
        $query = employee::with([
                'organizationAssignment.company',
                'organizationAssignment.department',
                'organizationAssignment.subDepartment',
            ])
            ->whereIn('id', $absentEmployeeIds)
            ->where('is_active', 1);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('nic', 'like', "%{$search}%")
                  ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        $results = $query->paginate($perPage);

        $results->getCollection()->transform(function ($employee) use ($date) {
            return [
                'id' => $employee->id,
                'empNo' => $employee->attendance_employee_no ?? null,
                'name' => $employee->full_name ?? null,
                'company' => $employee->organizationAssignment->company->name ?? null,
                'department' => $employee->organizationAssignment->department->name ?? null,
                'sub_department' => $employee->organizationAssignment->subDepartment->name ?? null,
                'date' => $date,
                'status' => 'Absent',
            ];
        });

        return response()->json($results);
    }
}
*/