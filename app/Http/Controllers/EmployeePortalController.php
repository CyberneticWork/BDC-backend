<?php

namespace App\Http\Controllers;

use App\Models\employee;
use App\Models\leave_master;
use App\Models\NoPayRecord;
use App\Models\over_time;
use App\Models\salary_process;
use App\Models\SalaryAdvanceRequest;
use App\Models\time_card;
use App\Services\CompanyProcessSettings;
use App\Services\LeaveNotificationService;
use App\Services\MedicalClaimService;
use App\Services\PendingPaymentService;
use App\Services\SalaryAdvanceService;
use App\Services\WeeklyOffService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class EmployeePortalController extends Controller
{
    public function linkedEmployee(Request $request): employee
    {
        return $this->requireEmployee($request);
    }

    private function requireEmployee(Request $request): employee
    {
        $user = $request->user();
        if (!$user || !$user->employee_id) {
            abort(403, 'No employee profile is linked to your login.');
        }

        $emp = employee::with(['organizationAssignment.department', 'organizationAssignment.designation'])
            ->find($user->employee_id);

        if (!$emp) {
            abort(404, 'Employee profile not found.');
        }

        return $emp;
    }

    public function home(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $month = (int) ($request->query('month') ?: now()->month);
        $year = (int) ($request->query('year') ?: now()->year);
        $from = Carbon::create($year, $month, 1)->startOfDay();
        $to = (clone $from)->endOfMonth();

        $attendanceCount = time_card::where('employee_id', $emp->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->distinct('date')
            ->count('date');

        $otHours = (float) over_time::where('employee_id', $emp->id)
            ->whereBetween('created_at', [$from, $to])
            ->sum('ot_hours');

        $nopayDays = (float) NoPayRecord::where('employee_id', $emp->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('no_pay_count');

        $lastSalary = salary_process::where('employee_id', $emp->id)
            ->whereIn('status', ['processed', 'issued', 'pending'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        $net = 0;
        if ($lastSalary) {
            $sb = is_string($lastSalary->salary_breakdown)
                ? json_decode($lastSalary->salary_breakdown, true)
                : ($lastSalary->salary_breakdown ?? []);
            $net = (float) ($sb['net_salary'] ?? $sb['net_pay'] ?? 0);
        }

        $pendingLeave = leave_master::where('employee_id', $emp->id)
            ->whereIn('status', [
                'Pending',
                'pending',
                'Pending_Covering',
                'Pending_Supervisor',
                'Supervisor_Approved',
                'SUPERVISOR_APPROVED',
            ])
            ->count();

        $advances = SalaryAdvanceRequest::where('employee_id', $emp->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $pendingAdvance = $advances->where('status', 'PENDING')->count();

        $dept = $emp->organizationAssignment->department->name ?? null;
        $desig = $emp->organizationAssignment->designation->name ?? null;
        $leaveWorkflow = CompanyProcessSettings::usesLeaveWorkflow($emp);

        $leaveBalances = [];
        try {
            $eligRequest = Request::create('/leave-eligibility', 'GET', [
                'employee_id' => $emp->id,
            ]);
            $eligResponse = app(LeaveMasterController::class)->getLeaveEligibility($eligRequest);
            $eligJson = json_decode($eligResponse->getContent(), true) ?: [];
            $leaveBalances = $eligJson['eligible_leaves'] ?? [];
        } catch (\Throwable $e) {
            $leaveBalances = [];
        }

        $coveringPending = 0;
        if ($leaveWorkflow && Schema::hasColumn('leave_masters', 'covering_employee_id')) {
            $coveringPending = leave_master::where('covering_employee_id', $emp->id)
                ->where('status', 'Pending_Covering')
                ->count();
        }

        return response()->json([
            'employee' => [
                'id' => $emp->id,
                'fullName' => $emp->full_name ?: $emp->name_with_initials,
                'employeeNo' => $emp->attendance_employee_no,
                'designation' => $desig,
                'department' => $dept,
            ],
            'month' => $month,
            'year' => $year,
            'attendanceCount' => $attendanceCount,
            'otHours' => round($otHours, 2),
            'nopayDays' => round($nopayDays, 2),
            'lastSalary' => $lastSalary ? [
                'id' => $lastSalary->id,
                'salaryMonth' => (int) $lastSalary->month,
                'salaryYear' => (int) $lastSalary->year,
                'netPay' => $net,
                'status' => $lastSalary->status,
            ] : null,
            'pendingLeave' => $pendingLeave,
            'pendingAdvance' => $pendingAdvance,
            'advances' => $advances,
            'leaveWorkflow' => $leaveWorkflow,
            'leaveBalances' => $leaveBalances,
            'coveringPending' => $coveringPending,
            'smsAvailable' => false,
            'weeklyOffEnabled' => CompanyProcessSettings::usesWeeklyOff($emp),
            'medicalClaimsEnabled' => CompanyProcessSettings::usesMedicalClaims($emp),
            'salaryAdvancePack' => CompanyProcessSettings::usesSalaryAdvancePack($emp),
            'weeklyOffBalance' => CompanyProcessSettings::usesWeeklyOff($emp) ? WeeklyOffService::balance($emp) : null,
            'medicalQuota' => CompanyProcessSettings::usesMedicalClaims($emp) ? MedicalClaimService::quota($emp) : null,
            'advanceQuota' => CompanyProcessSettings::usesSalaryAdvancePack($emp) ? SalaryAdvanceService::quota($emp) : null,
        ]);
    }

    public function attendance(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $month = (int) ($request->query('month') ?: now()->month);
        $year = (int) ($request->query('year') ?: now()->year);
        $from = Carbon::create($year, $month, 1)->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $rows = time_card::where('employee_id', $emp->id)
            ->whereBetween('date', [$from, $to])
            ->orderByDesc('date')
            ->orderBy('time')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'cardDate' => $r->date,
                'clockTime' => $r->time,
                'status' => $r->status,
                'workingHours' => $r->working_hours,
                'entry' => $r->entry,
            ]);

        return response()->json(['items' => $rows, 'month' => $month, 'year' => $year]);
    }

    public function overtime(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $month = (int) ($request->query('month') ?: now()->month);
        $year = (int) ($request->query('year') ?: now()->year);
        $from = Carbon::create($year, $month, 1)->startOfDay();
        $to = (clone $from)->endOfMonth();

        $rows = over_time::with('timeCard')
            ->where('employee_id', $emp->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'otDate' => optional($r->timeCard)->date ?: optional($r->created_at)->toDateString(),
                'hours' => (float) $r->ot_hours,
                'amount' => (float) $r->total_ot_amount,
                'otType' => 'OT',
                'status' => $r->status,
            ]);

        return response()->json(['items' => $rows, 'month' => $month, 'year' => $year]);
    }

    public function nopay(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $month = (int) ($request->query('month') ?: now()->month);
        $year = (int) ($request->query('year') ?: now()->year);
        $from = Carbon::create($year, $month, 1)->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $rows = NoPayRecord::where('employee_id', $emp->id)
            ->whereBetween('date', [$from, $to])
            ->orderByDesc('date')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'nopayDate' => $r->date,
                'nopayDays' => (float) $r->no_pay_count,
                'type' => $r->type,
                'status' => $r->status,
                'description' => $r->description,
            ]);

        return response()->json(['items' => $rows, 'month' => $month, 'year' => $year]);
    }

    public function salary(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $rows = salary_process::where('employee_id', $emp->id)
            ->whereIn('status', ['processed', 'issued', 'pending'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(24)
            ->get()
            ->map(function ($s) {
                $sb = is_string($s->salary_breakdown)
                    ? json_decode($s->salary_breakdown, true)
                    : ($s->salary_breakdown ?? []);
                return [
                    'id' => $s->id,
                    'salaryMonth' => (int) $s->month,
                    'salaryYear' => (int) $s->year,
                    'grossPay' => (float) ($sb['gross_salary'] ?? 0),
                    'netPay' => (float) ($sb['net_salary'] ?? $sb['net_pay'] ?? 0),
                    'status' => $s->status,
                    'breakdown' => $sb,
                ];
            });

        return response()->json(['items' => $rows]);
    }

    public function leaves(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $rows = leave_master::where('employee_id', $emp->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['items' => $rows]);
    }

    public function storeLeave(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $validator = Validator::make($request->all(), [
            'leave_type' => 'required|string|max:255',
            'leave_from' => 'required|date',
            'leave_to' => 'required|date|after_or_equal:leave_from',
            'reason' => 'required|string|max:1000',
            'day_type' => 'nullable|in:FULL,HALF,SHORT',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $dayType = strtoupper((string) $request->input('day_type', 'FULL'));
        $from = Carbon::parse($request->leave_from);
        $to = Carbon::parse($request->leave_to);
        $calendarDays = $from->diffInDays($to) + 1;
        $unit = 1.0;
        if ($dayType === 'HALF') {
            $unit = 0.5;
        } elseif ($dayType === 'SHORT') {
            $unit = 0.25;
        }
        $days = round($calendarDays * $unit, 4);

        if (CompanyProcessSettings::usesLeaveWorkflow($emp)) {
            $inner = Request::create('/leave-masters', 'POST', [
                'employee_id' => $emp->id,
                'reporting_date' => now()->toDateString(),
                'leave_type' => $request->leave_type,
                'leave_from' => $request->leave_from,
                'leave_to' => $request->leave_to,
                'leave_date' => $request->leave_from === $request->leave_to ? $request->leave_from : null,
                'reason' => $request->reason,
                'status' => 'Pending',
                'is_half_day' => $dayType === 'HALF',
                'is_short_leave' => $dayType === 'SHORT',
                'covering_employee_id' => $request->input('covering_employee_id'),
            ]);
            $inner->setUserResolver(fn () => $request->user());
            $response = app(LeaveMasterController::class)->store($inner);
            if ($response->getStatusCode() >= 400) {
                return $response;
            }

            return response()->json([
                'message' => 'Leave request submitted for covering approval',
                'data' => json_decode($response->getContent(), true),
            ], 201);
        }

        $leave = leave_master::create([
            'employee_id' => $emp->id,
            'reporting_date' => now()->toDateString(),
            'leave_type' => $request->leave_type,
            'leave_from' => $request->leave_from,
            'leave_to' => $request->leave_to,
            'leave_date' => $request->leave_from === $request->leave_to ? $request->leave_from : null,
            'leave_duration' => $days,
            'requested_days' => $days,
            'reason' => $request->reason,
            'status' => 'Pending',
            'is_half_day' => $dayType === 'HALF',
            'is_short_leave' => $dayType === 'SHORT',
            'over_limit' => 0,
        ]);

        return response()->json(['message' => 'Leave request submitted', 'data' => $leave], 201);
    }

    public function coveringColleagues(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $companyId = $emp->organizationAssignment->company_id ?? null;
        $query = employee::query()->where('id', '!=', $emp->id);
        if ($companyId) {
            $query->whereHas('organizationAssignment', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        }
        $items = $query->orderBy('full_name')->limit(400)->get([
            'id',
            'full_name',
            'name_with_initials',
            'attendance_employee_no',
        ]);

        return response()->json(['items' => $items]);
    }

    public function coveringLeaves(Request $request)
    {
        $emp = $this->requireEmployee($request);
        if (!Schema::hasColumn('leave_masters', 'covering_employee_id')) {
            return response()->json(['items' => []]);
        }
        $rows = leave_master::with('employee')
            ->where('covering_employee_id', $emp->id)
            ->where('status', 'Pending_Covering')
            ->orderByDesc('id')
            ->get();

        return response()->json(['items' => $rows]);
    }

    public function respondCovering(Request $request, $id)
    {
        $emp = $this->requireEmployee($request);
        $action = strtolower((string) $request->input('action'));
        if (!in_array($action, ['approve', 'reject'], true)) {
            return response()->json(['message' => 'Action must be approve or reject.'], 422);
        }
        if (!CompanyProcessSettings::usesLeaveWorkflow($emp)) {
            return response()->json(['message' => 'Covering workflow is not enabled for this company.'], 422);
        }

        $leave = leave_master::with('employee')->findOrFail($id);
        if ((int) $leave->covering_employee_id !== (int) $emp->id) {
            return response()->json(['message' => 'This covering request is not assigned to you.'], 403);
        }
        if ($leave->status !== 'Pending_Covering') {
            return response()->json(['message' => 'This covering request is no longer pending.'], 422);
        }

        $newStatus = $action === 'approve' ? 'Pending_Supervisor' : 'Rejected';
        $payload = ['status' => $newStatus];
        if (Schema::hasColumn('leave_masters', 'covering_status')) {
            $payload['covering_status'] = $action === 'approve' ? 'Approved' : 'Rejected';
        }
        if ($action === 'reject') {
            $payload['rejection_reason'] = $request->input('reason') ?: 'Rejected by covering person';
        }
        $leave->update($payload);
        LeaveNotificationService::statusChanged($leave->fresh(), $newStatus, $payload['rejection_reason'] ?? null);

        return response()->json(['message' => 'Covering response saved', 'data' => $leave->fresh()]);
    }

    public function myAdvances(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $rows = SalaryAdvanceRequest::where('employee_id', $emp->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['items' => $rows]);
    }

    public function storeAdvance(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'reason' => 'required|string|max:500',
            'needed_on' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $pending = SalaryAdvanceRequest::where('employee_id', $emp->id)
            ->where('status', 'PENDING')
            ->exists();

        if ($pending) {
            return response()->json(['message' => 'You already have a pending salary advance request.'], 422);
        }

        if (CompanyProcessSettings::usesSalaryAdvancePack($emp)) {
            $quota = SalaryAdvanceService::quota($emp);
            if ((float) $request->amount - $quota['available'] > 0.009) {
                return response()->json([
                    'message' => 'Advance exceeds available amount.',
                    'available' => $quota['available'],
                    'quota' => $quota,
                ], 422);
            }
        }

        $row = SalaryAdvanceRequest::create([
            'employee_id' => $emp->id,
            'amount' => $request->amount,
            'reason' => $request->reason,
            'needed_on' => $request->needed_on,
            'status' => 'PENDING',
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Advance request submitted', 'data' => $row], 201);
    }

    public function listAdvances(Request $request)
    {
        $user = $request->user();
        if ($user->role === 'employee') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $q = SalaryAdvanceRequest::with('employee:id,name_with_initials,full_name,attendance_employee_no')
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            $q->where('status', strtoupper($status));
        }

        return response()->json(['items' => $q->limit(200)->get()]);
    }

    public function reviewAdvance(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role === 'employee') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:APPROVE,REJECT',
            'note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $row = SalaryAdvanceRequest::findOrFail($id);
        $row->load('employee.organizationAssignment');
        if ($row->status !== 'PENDING') {
            return response()->json(['message' => 'This request has already been reviewed.'], 422);
        }

        $row->status = $request->action === 'APPROVE' ? 'APPROVED' : 'REJECTED';
        $row->review_note = $request->note;
        $row->reviewed_by = $user->id;
        $row->reviewed_at = now();
        $row->save();

        if ($row->employee && CompanyProcessSettings::usesSalaryAdvancePack($row->employee)) {
            if ($row->status === 'APPROVED') {
                PendingPaymentService::record($row->employee, 'salary_advance', $row->id, (float) $row->amount);
            }
            LeaveNotificationService::notifyEmployee(
                $row->employee_id,
                $row->status === 'APPROVED' ? 'Salary advance approved' : 'Salary advance rejected',
                $row->status === 'APPROVED'
                    ? 'Your salary advance was approved and sent to Pending Payments.'
                    : 'Your salary advance request was rejected.',
                ['type' => 'salary_advance', 'id' => $row->id, 'status' => $row->status]
            );
        }

        return response()->json(['message' => 'Advance request updated', 'data' => $row]);
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 401);
        }

        $user->password = Hash::make($request->new_password);
        if (isset($user->is_first_login)) {
            $user->is_first_login = false;
        }
        $user->save();

        return response()->json(['message' => 'Password changed successfully.']);
    }
}
