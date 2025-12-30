<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\time_card;
use Illuminate\Support\Facades\DB;

class AttendanceReportController extends Controller
{
    /**
     * GET /reports/time-cards/attendance?date=YYYY-MM-DD&search=&page=&per_page=
     * Returns employees who have at least one attendance entry (IN/OUT/Early OUT) on the given date.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string',
        ]);

        $date = $validated['date'];
        $perPage = (int)($validated['per_page'] ?? 15);
        $search = $validated['search'] ?? null;

        $statuses = ['IN', 'OUT', 'Early OUT'];

        // Find employee_ids that have at least one attendance entry on that date
        $attendedEmployeeIds = time_card::select('employee_id')
            ->where('date', $date)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('employee_id');

        // Build query for attendance records
        $query = time_card::with([
                'employee.organizationAssignment.company',
                'employee.organizationAssignment.department',
                'employee.organizationAssignment.subDepartment',
            ])
            ->select('employee_id', 
                DB::raw('MIN(CASE WHEN status = "IN" THEN time END) as in_time'),
                DB::raw('MAX(CASE WHEN status IN ("OUT", "Early OUT") THEN time END) as out_time'),
                DB::raw('MIN(date) as date'),
                DB::raw('GROUP_CONCAT(DISTINCT status ORDER BY time ASC) as statuses')
            )
            ->where('date', $date)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $attendedEmployeeIds)
            ->groupBy('employee_id');

        // Optional search by employee fields
        if ($search) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('nic', 'like', "%{$search}%")
                  ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        $results = $query->paginate($perPage);

        // Transform results
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
                'entries' => $record->statuses,
            ];
        });

        return response()->json($results);
    }
}
