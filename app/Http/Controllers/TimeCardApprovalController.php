<?php

namespace App\Http\Controllers;

use App\Models\time_card;
use App\Models\time_card_audit;
use App\Services\ContractEmployeeScope;
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
            'approval_status' => 'nullable|in:Pending,Active,Approved,Rejected,HR_Approved',
            'search' => 'nullable|string|max:100',
        ]);

        $query = time_card::with(['employee.organizationAssignment.company', 'employee.organizationAssignment.department'])
            ->whereNull('deleted_at');

        $approval = $validated['approval_status'] ?? 'Pending';
        if ($approval === 'Approved') {
            $approval = 'Active';
        }
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
            'approval_status' => 'required|in:Approved,Rejected,Pending,Active,HR_Approved',
            'reason' => 'nullable|string|max:500',
        ]);

        $card = time_card::with('employee.organizationAssignment')->whereNull('deleted_at')->findOrFail($id);
        $old = TimeCardAuditService::snapshot($card);
        $current = (string) ($card->approval_status ?: 'Pending');
        $requested = (string) $validated['approval_status'];
        $chain = \App\Services\ApprovalChainService::enabledForEmployee($card->employee);
        $statuses = [
            'pending' => 'Pending',
            'intermediate' => 'HR_Approved',
            'final' => 'Active',
            'rejected' => 'Rejected',
        ];

        if ($requested === 'Pending') {
            $card->approval_status = 'Pending';
            $card->save();
            TimeCardAuditService::log($card, 'updated', $validated['reason'] ?? null, $old, TimeCardAuditService::snapshot($card), $card->entry_source ?? 'manual');
            if ($chain) {
                \App\Services\ApprovalPushService::timeCardAwaitingAdmin($card->employee, $card->id);
            }
            return response()->json(['message' => 'Approval status updated', 'data' => $card]);
        }

        $result = \App\Services\ApprovalChainService::resolveApproveTransition(
            $request->user(),
            $chain,
            $current,
            $requested === 'Approved' ? 'Active' : $requested,
            $statuses
        );
        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Not allowed'], 403);
        }

        $status = $result['status'];
        $card->approval_status = $status;
        $card->save();

        $action = $status === 'Rejected' ? 'rejected' : ($status === 'Pending' ? 'updated' : 'approved');
        TimeCardAuditService::log($card, $action, $validated['reason'] ?? null, $old, TimeCardAuditService::snapshot($card), $card->entry_source ?? 'manual');

        if ($chain && $status === 'HR_Approved') {
            \App\Services\ApprovalPushService::timeCardAwaitingSuperAdmin($card->employee, $card->id);
        }

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
            'approval_status' => 'required|in:Approved,Rejected,Pending,Active,HR_Approved',
            'reason' => 'nullable|string|max:500',
        ]);

        $cards = time_card::with('employee.organizationAssignment')
            ->whereIn('id', $validated['ids'])
            ->whereNull('deleted_at')
            ->get();
        $updated = 0;
        foreach ($cards as $card) {
            $old = TimeCardAuditService::snapshot($card);
            $current = (string) ($card->approval_status ?: 'Pending');
            $requested = (string) $validated['approval_status'];
            $chain = \App\Services\ApprovalChainService::enabledForEmployee($card->employee);
            $statuses = [
                'pending' => 'Pending',
                'intermediate' => 'HR_Approved',
                'final' => 'Active',
                'rejected' => 'Rejected',
            ];

            if ($requested === 'Pending') {
                $status = 'Pending';
            } else {
                $result = \App\Services\ApprovalChainService::resolveApproveTransition(
                    $request->user(),
                    $chain,
                    $current,
                    $requested === 'Approved' ? 'Active' : $requested,
                    $statuses
                );
                if (!$result['ok']) {
                    continue;
                }
                $status = $result['status'];
            }

            $card->approval_status = $status;
            $card->save();
            $action = $status === 'Rejected' ? 'rejected' : ($status === 'Pending' ? 'updated' : 'approved');
            TimeCardAuditService::log($card, $action, $validated['reason'] ?? null, $old, TimeCardAuditService::snapshot($card), $card->entry_source ?? 'manual');
            if ($chain && $status === 'HR_Approved') {
                \App\Services\ApprovalPushService::timeCardAwaitingSuperAdmin($card->employee, $card->id);
            }
            $updated++;
        }

        return response()->json([
            'message' => 'Updated ' . $updated . ' record(s)',
            'updated' => $updated,
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
        ContractEmployeeScope::excludeRelated($query);

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
