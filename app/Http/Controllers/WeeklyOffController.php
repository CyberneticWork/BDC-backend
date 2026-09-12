<?php

namespace App\Http\Controllers;

use App\Models\WeeklyOffEntry;
use App\Models\employee;
use App\Services\CompanyProcessSettings;
use App\Services\LeaveNotificationService;
use App\Services\WeeklyOffService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class WeeklyOffController extends Controller
{
    private function denyIfOff(employee $employee)
    {
        if (!CompanyProcessSettings::usesWeeklyOff($employee)) {
            abort(response()->json(['message' => 'Weekly off pack is not enabled for this company.'], 422));
        }
    }

    private function forbidEmployee(Request $request): void
    {
        if ($request->user()?->role === 'employee') {
            abort(response()->json(['message' => 'Forbidden'], 403));
        }
    }

    public function portalBalance(Request $request)
    {
        $emp = app(EmployeePortalController::class)->linkedEmployee($request);
        $this->denyIfOff($emp);
        return response()->json(WeeklyOffService::balance($emp));
    }

    public function portalIndex(Request $request)
    {
        $emp = app(EmployeePortalController::class)->linkedEmployee($request);
        $this->denyIfOff($emp);
        $mine = WeeklyOffEntry::where('employee_id', $emp->id)->orderByDesc('off_date')->limit(100)->get();
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to = $request->query('to', now()->endOfMonth()->toDateString());
        $schedule = WeeklyOffEntry::with('employee:id,full_name,name_with_initials,attendance_employee_no')
            ->where('status', 'Approved')
            ->whereBetween('off_date', [$from, $to])
            ->when($emp->organizationAssignment->company_id ?? null, function ($q, $companyId) {
                $q->where('company_id', $companyId);
            })
            ->orderBy('off_date')
            ->get();

        return response()->json([
            'balance' => WeeklyOffService::balance($emp),
            'items' => $mine,
            'schedule' => $schedule,
        ]);
    }

    public function portalStore(Request $request)
    {
        $emp = app(EmployeePortalController::class)->linkedEmployee($request);
        $this->denyIfOff($emp);
        $validator = Validator::make($request->all(), [
            'off_date' => 'required|date',
            'day_type' => 'nullable|in:FULL,HALF',
            'reason' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $days = strtoupper((string) $request->input('day_type', 'FULL')) === 'HALF' ? 0.5 : 1.0;
        $balance = WeeklyOffService::balance($emp, Carbon::parse($request->off_date));
        if ($days - $balance['available'] > 0.0001) {
            return response()->json([
                'message' => 'Weekly off request exceeds available balance.',
                'available' => $balance['available'],
            ], 422);
        }

        $exists = WeeklyOffEntry::where('employee_id', $emp->id)
            ->whereDate('off_date', $request->off_date)
            ->whereIn('status', ['Pending', 'Approved'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'A weekly off already exists for this date.'], 422);
        }

        $row = WeeklyOffEntry::create([
            'company_id' => $emp->organizationAssignment->company_id ?? null,
            'employee_id' => $emp->id,
            'off_date' => $request->off_date,
            'days' => $days,
            'source' => 'portal',
            'status' => 'Pending',
            'reason' => $request->reason,
        ]);

        return response()->json(['message' => 'Weekly off submitted', 'data' => $row], 201);
    }

    public function hrIndex(Request $request)
    {
        $this->forbidEmployee($request);
        if (!Schema::hasTable('weekly_off_entries')) {
            return response()->json(['items' => []]);
        }
        $q = WeeklyOffEntry::with('employee:id,full_name,name_with_initials,attendance_employee_no')
            ->orderByDesc('off_date');
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($from = $request->query('from')) {
            $q->whereDate('off_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('off_date', '<=', $to);
        }
        return response()->json(['items' => $q->limit(500)->get()]);
    }

    public function hrStore(Request $request)
    {
        $this->forbidEmployee($request);
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'off_date' => 'required|date',
            'days' => 'nullable|numeric|min:0.5|max:1',
            'reason' => 'nullable|string|max:1000',
            'publish' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $emp = employee::with(['organizationAssignment', 'employmentType'])->findOrFail($request->employee_id);
        $this->denyIfOff($emp);
        $days = (float) ($request->input('days', 1));
        $status = $request->boolean('publish') ? 'Approved' : 'Pending';
        $row = WeeklyOffEntry::create([
            'company_id' => $emp->organizationAssignment->company_id ?? null,
            'employee_id' => $emp->id,
            'off_date' => $request->off_date,
            'days' => $days > 0 ? $days : 1,
            'source' => 'schedule',
            'status' => $status,
            'reason' => $request->reason,
            'reviewed_by' => $status === 'Approved' ? $request->user()->id : null,
            'reviewed_at' => $status === 'Approved' ? now() : null,
        ]);
        if ($status === 'Approved') {
            LeaveNotificationService::notifyEmployee($emp->id, 'Weekly off published', "A weekly off on {$row->off_date->toDateString()} is now on the schedule.", [
                'type' => 'weekly_off',
                'id' => $row->id,
            ]);
        }
        return response()->json(['message' => 'Weekly off saved', 'data' => $row], 201);
    }

    public function hrReview(Request $request, $id)
    {
        $this->forbidEmployee($request);
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:APPROVE,REJECT',
            'note' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $row = WeeklyOffEntry::with('employee')->findOrFail($id);
        $this->denyIfOff($row->employee);
        if (!in_array($row->status, ['Pending'], true)) {
            return response()->json(['message' => 'This weekly off is already reviewed.'], 422);
        }
        if ($request->action === 'APPROVE') {
            $balance = WeeklyOffService::balance($row->employee, Carbon::parse($row->off_date));
            $withoutThis = $balance['available'] + (float) $row->days;
            if ((float) $row->days - $withoutThis > 0.0001 && $row->source === 'portal') {
                return response()->json(['message' => 'Employee does not have enough weekly off balance.'], 422);
            }
        }
        $row->status = $request->action === 'APPROVE' ? 'Approved' : 'Rejected';
        $row->review_note = $request->note;
        $row->reviewed_by = $request->user()->id;
        $row->reviewed_at = now();
        $row->save();
        LeaveNotificationService::notifyEmployee(
            $row->employee_id,
            $row->status === 'Approved' ? 'Weekly off approved' : 'Weekly off rejected',
            $row->status === 'Approved'
                ? "Your weekly off on {$row->off_date->toDateString()} was approved."
                : "Your weekly off on {$row->off_date->toDateString()} was rejected.",
            ['type' => 'weekly_off', 'id' => $row->id, 'status' => $row->status]
        );
        return response()->json(['message' => 'Weekly off updated', 'data' => $row]);
    }
}
