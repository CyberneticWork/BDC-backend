<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\roster;
use App\Models\time_card;
use App\Models\employee;
use App\Models\leave_master;
use Illuminate\Support\Facades\DB;

class AbsentReportController extends Controller
{
    /**
     * GET /reports/time-cards/absent?date=YYYY-MM-DD&session=morning|afternoon&search=&page=&per_page=&company_id=&department_id=
     * Logic:
     * - Rostered employees on selected date
     * - Present if they have any IN/OUT/Early OUT record on date
     * - Exclude employees with Approved full-day leave covering the date
     * - Half-day handling:
     *    - If session=morning: exclude employees with Approved half-day leave (period=Morning) covering date
     *    - If session=afternoon: 
     *         - exclude employees with Approved half-day leave (period=Afternoon)
     *         - employees with half-day Morning leave must have attendance to be considered present in afternoon; if no attendance, mark as absent
     */
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
