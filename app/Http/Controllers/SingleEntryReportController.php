<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\time_card;

class SingleEntryReportController extends Controller
{
    /**
     * GET /reports/time-cards/single-entry?date=YYYY-MM-DD&search=&page=&per_page=
     * Returns time_card rows where an employee has exactly one IN/OUT/Early OUT entry for the given date.
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

        // Find employee_ids that have exactly one relevant entry on that date
        $singleEmployeeIds = time_card::select('employee_id')
            ->where('date', $date)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) = 1')
            ->pluck('employee_id');

        // Base query for the single entry rows
        $query = time_card::with([
                'employee.organizationAssignment.company',
                'employee.organizationAssignment.department',
                'employee.organizationAssignment.subDepartment',
            ])
            ->where('date', $date)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $singleEmployeeIds)
            ->orderBy('time', 'asc');

        // Optional search by employee fields
        if ($search) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('nic', 'like', "%{$search}%")
                  ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        $results = $query->paginate($perPage);

        // Shape the response consistently with other attendance endpoints
        $results->getCollection()->transform(function ($card) {
            return [
                'id' => $card->id,
                'empNo' => $card->employee->attendance_employee_no ?? null,
                'name' => $card->employee->full_name ?? null,
                'date' => $card->date,
                'time' => $card->time,
                'entry' => $card->entry,
                'status' => $card->status,
                'inOut' => $card->entry == 1 ? 'IN' : ($card->entry == 2 ? 'OUT' : ($card->entry == 0 ? 'Early OUT' : null)),
                'company' => $card->employee->organizationAssignment->company->name ?? null,
                'department' => $card->employee->organizationAssignment->department->name ?? null,
                'sub_department' => $card->employee->organizationAssignment->subDepartment->name ?? null,
            ];
        });

        return response()->json($results);
    }
}
