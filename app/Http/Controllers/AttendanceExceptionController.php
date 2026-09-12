<?php

namespace App\Http\Controllers;

use App\Models\employee;
use App\Models\Roster;
use App\Models\shifts;
use App\Models\time_card;
use App\Services\TimeCardAuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceExceptionController extends Controller
{
    /**
     * List Late Coming / Early OUT punches awaiting (or filtered) approval.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'status' => 'nullable|in:Late Coming,Early OUT',
            'approval_status' => 'nullable|in:Pending,Approved,Rejected,Active',
            'search' => 'nullable|string|max:100',
        ]);

        $query = time_card::with(['employee.organizationAssignment.company', 'employee.organizationAssignment.department'])
            ->whereNull('deleted_at')
            ->whereIn('status', ['Late Coming', 'Early OUT']);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $approval = $validated['approval_status'] ?? 'Pending';
        $query->where('approval_status', $approval);

        if (!empty($validated['from_date'])) {
            $query->whereDate('date', '>=', $validated['from_date']);
        }
        if (!empty($validated['to_date'])) {
            $query->whereDate('date', '<=', $validated['to_date']);
        }

        if (!empty($validated['company_id']) || !empty($validated['department_id']) || !empty($validated['search'])) {
            $query->whereHas('employee', function ($q) use ($validated) {
                if (!empty($validated['search'])) {
                    $s = $validated['search'];
                    $q->where(function ($x) use ($s) {
                        $x->where('full_name', 'like', "%{$s}%")
                            ->orWhere('attendance_employee_no', 'like', "%{$s}%")
                            ->orWhere('nic', 'like', "%{$s}%");
                    });
                }
                if (!empty($validated['company_id']) || !empty($validated['department_id'])) {
                    $q->whereHas('organizationAssignment', function ($oa) use ($validated) {
                        if (!empty($validated['company_id'])) {
                            $oa->where('company_id', $validated['company_id']);
                        }
                        if (!empty($validated['department_id'])) {
                            $oa->where('department_id', $validated['department_id']);
                        }
                    });
                }
            });
        }

        $rows = $query->orderByDesc('date')->orderByDesc('time')->get()->map(function (time_card $card) {
            $emp = $card->employee;
            $org = $emp?->organizationAssignment;

            return [
                'id' => $card->id,
                'employee_id' => $card->employee_id,
                'emp_no' => $emp?->attendance_employee_no,
                'employee_name' => $emp?->full_name,
                'company' => $org?->company?->name,
                'department' => $org?->department?->name,
                'date' => $card->date,
                'time' => $card->time,
                'status' => $card->status,
                'entry' => $card->entry,
                'approval_status' => $card->approval_status,
                'reason' => $card->reason,
            ];
        });

        return response()->json(['data' => $rows]);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'approval_status' => 'required|in:Approved,Rejected,Pending,Active',
            'reason' => 'nullable|string|max:255',
        ]);

        $card = time_card::whereNull('deleted_at')->findOrFail($id);

        if (!in_array($card->status, ['Late Coming', 'Early OUT'], true)) {
            return response()->json([
                'message' => 'Only Late Coming or Early OUT records can be approved/rejected here.',
            ], 422);
        }

        $card->approval_status = $validated['approval_status'];
        if (array_key_exists('reason', $validated)) {
            $card->reason = $validated['reason'];
        }
        $card->save();

        $action = $validated['approval_status'] === 'Rejected' ? 'rejected' : 'approved';
        TimeCardAuditService::log($card, $action, $validated['reason'] ?? null, null, TimeCardAuditService::snapshot($card), $card->entry_source ?? 'device');

        return response()->json([
            'message' => 'Approval status updated',
            'data' => $card,
        ]);
    }

    public function bulkUpdateStatus(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:time_cards,id',
            'approval_status' => 'required|in:Approved,Rejected,Pending,Active',
            'reason' => 'nullable|string|max:255',
        ]);

        $updated = time_card::whereIn('id', $validated['ids'])
            ->whereIn('status', ['Late Coming', 'Early OUT'])
            ->whereNull('deleted_at')
            ->update([
                'approval_status' => $validated['approval_status'],
                'reason' => $validated['reason'] ?? null,
            ]);

        return response()->json([
            'message' => "Updated {$updated} record(s)",
            'updated' => $updated,
        ]);
    }
}
