<?php

namespace App\Http\Controllers;

use App\Models\time_card;
use App\Models\time_card_audit;
use App\Services\TimeCardAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TimeCardApprovalController extends Controller
{
    public function pending(Request $request)
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'approval_status' => 'nullable|in:Pending,Active,Approved,Rejected',
            'search' => 'nullable|string|max:100',
        ]);

        $query = time_card::with(['employee.organizationAssignment.company', 'employee.organizationAssignment.department'])
            ->whereNull('deleted_at');

        $approval = $validated['approval_status'] ?? 'Pending';
        $query->where('approval_status', $approval);

        if (Schema::hasColumn('time_cards', 'entry_source')) {
            $query->where(function ($q) {
                $q->whereIn('entry_source', ['manual', 'import'])
                    ->orWhereNull('entry_source');
            });
        }

        if (!empty($validated['from_date'])) {
            $query->whereDate('date', '>=', $validated['from_date']);
        }
        if (!empty($validated['to_date'])) {
            $query->whereDate('date', '<=', $validated['to_date']);
        }

        $this->applyEmployeeFilters($query, $validated);

        $rows = $query->orderByDesc('date')->orderByDesc('time')->get()->map(fn (time_card $card) => $this->mapCard($card));

        return response()->json(['data' => $rows]);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'approval_status' => 'required|in:Approved,Rejected,Pending,Active',
            'reason' => 'nullable|string|max:500',
        ]);

        $card = time_card::whereNull('deleted_at')->findOrFail($id);
        $old = TimeCardAuditService::snapshot($card);

        $status = $validated['approval_status'] === 'Approved' ? 'Active' : $validated['approval_status'];
        $card->approval_status = $status;
        $card->save();

        $action = $status === 'Rejected' ? 'rejected' : ($status === 'Pending' ? 'updated' : 'approved');
        TimeCardAuditService::log($card, $action, $validated['reason'] ?? null, $old, TimeCardAuditService::snapshot($card), $card->entry_source ?? 'manual');

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
            'reason' => 'nullable|string|max:500',
        ]);

        $status = $validated['approval_status'] === 'Approved' ? 'Active' : $validated['approval_status'];
        $action = $status === 'Rejected' ? 'rejected' : ($status === 'Pending' ? 'updated' : 'approved');

        $cards = time_card::whereIn('id', $validated['ids'])->whereNull('deleted_at')->get();
        foreach ($cards as $card) {
            $old = TimeCardAuditService::snapshot($card);
            $card->approval_status = $status;
            $card->save();
            TimeCardAuditService::log($card, $action, $validated['reason'] ?? null, $old, TimeCardAuditService::snapshot($card), $card->entry_source ?? 'manual');
        }

        return response()->json([
            'message' => 'Updated ' . $cards->count() . ' record(s)',
            'updated' => $cards->count(),
        ]);
    }

    public function audit(Request $request)
    {
        if (!Schema::hasTable('time_card_audits')) {
            return response()->json(['data' => [], 'month' => $request->input('month')]);
        }

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'action' => 'nullable|in:created,updated,adjusted,deleted,approved,rejected',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'search' => 'nullable|string|max:100',
        ]);

        [$from, $to] = $this->monthRange($validated['month']);

        $query = time_card_audit::with(['employee.organizationAssignment.company', 'employee.organizationAssignment.department', 'user'])
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to);

        if (!empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        if (!empty($validated['company_id']) || !empty($validated['department_id']) || !empty($validated['search'])) {
            $query->whereHas('employee', function ($q) use ($validated) {
                if (!empty($validated['search'])) {
                    $s = $validated['search'];
                    $q->where(function ($inner) use ($s) {
                        $inner->where('full_name', 'like', "%{$s}%")
                            ->orWhere('attendance_employee_no', 'like', "%{$s}%")
                            ->orWhere('nic', 'like', "%{$s}%");
                    });
                }
                if (!empty($validated['company_id']) || !empty($validated['department_id'])) {
                    $q->whereHas('organizationAssignment', function ($org) use ($validated) {
                        if (!empty($validated['company_id'])) {
                            $org->where('company_id', $validated['company_id']);
                        }
                        if (!empty($validated['department_id'])) {
                            $org->where('department_id', $validated['department_id']);
                        }
                    });
                }
            });
        }

        $rows = $query->orderByDesc('created_at')->get()->map(function (time_card_audit $row) {
            $emp = $row->employee;
            $org = $emp?->organizationAssignment;

            return [
                'id' => $row->id,
                'time_card_id' => $row->time_card_id,
                'employee_id' => $row->employee_id,
                'emp_no' => $emp?->attendance_employee_no,
                'employee_name' => $emp?->full_name,
                'company' => $org?->company?->name,
                'department' => $org?->department?->name,
                'entry_date' => optional($row->entry_date)->format('Y-m-d'),
                'action' => $row->action,
                'source' => $row->source,
                'reason' => $row->reason,
                'old_values' => $row->old_values,
                'new_values' => $row->new_values,
                'user_name' => $row->user?->name,
                'created_at' => optional($row->created_at)->toDateTimeString(),
            ];
        });

        return response()->json([
            'data' => $rows,
            'month' => $validated['month'],
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function deleted(Request $request)
    {
        $request->merge([
            'action' => 'deleted',
            'month' => $request->input('month', now()->format('Y-m')),
        ]);

        return $this->audit($request);
    }

    private function monthRange(string $month): array
    {
        $start = $month . '-01';
        $from = date('Y-m-d', strtotime($start));
        $to = date('Y-m-t', strtotime($start));

        return [$from, $to];
    }

    private function applyEmployeeFilters($query, array $validated): void
    {
        if (empty($validated['company_id']) && empty($validated['department_id']) && empty($validated['search'])) {
            return;
        }

        $query->whereHas('employee', function ($q) use ($validated) {
            if (!empty($validated['search'])) {
                $s = $validated['search'];
                $q->where(function ($inner) use ($s) {
                    $inner->where('full_name', 'like', "%{$s}%")
                        ->orWhere('attendance_employee_no', 'like', "%{$s}%")
                        ->orWhere('nic', 'like', "%{$s}%");
                });
            }
            if (!empty($validated['company_id']) || !empty($validated['department_id'])) {
                $q->whereHas('organizationAssignment', function ($org) use ($validated) {
                    if (!empty($validated['company_id'])) {
                        $org->where('company_id', $validated['company_id']);
                    }
                    if (!empty($validated['department_id'])) {
                        $org->where('department_id', $validated['department_id']);
                    }
                });
            }
        });
    }

    private function mapCard(time_card $card): array
    {
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
            'entry_source' => $card->entry_source ?? 'manual',
            'reason' => $card->reason,
        ];
    }
}
