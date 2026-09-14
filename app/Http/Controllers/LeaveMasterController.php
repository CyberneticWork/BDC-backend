<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\leave_master;
use App\Models\employee;
use App\Models\LeaveSetting;
use App\Models\NoPayRecord;
use App\Services\CompanyProcessSettings;
use App\Services\LeaveNotificationService;
use Illuminate\Support\Facades\Validator;
use App\Mail\LeaveApprovedMail;
use App\Mail\LeaveRejectedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class LeaveMasterController extends Controller
{
    /**
     * Shop & Office Act earning/accrual leave calculation is enabled from this date onward.
     * Before this date, only HR manual employee_leave_balances are used as actual balances.
     */
    private const ACT_ACCRUAL_START_DATE = '2026-12-31';

    private function isActAccrualEnabled(Carbon $asOfDate): bool
    {
        return $asOfDate->copy()->startOfDay()
            ->gte(Carbon::parse(self::ACT_ACCRUAL_START_DATE)->startOfDay());
    }

    public function index()
    {
        $leaveMasters = leave_master::with(['employee', 'coveringEmployee'])->get();
        return response()->json($leaveMasters);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'reporting_date' => 'required|date',
            'leave_type' => 'required|string|max:255',
            'leave_date' => 'nullable|date',
            'leave_from' => 'nullable|date',
            'leave_to' => 'nullable|date|after_or_equal:leave_from',
            'period' => 'nullable|string|max:255',
            'is_half_day' => 'nullable|boolean',
            'is_short_leave' => 'nullable|boolean',
            'short_leave_slot' => 'nullable|string|max:255',
            'cancel_from' => 'nullable|date',
            'cancel_to' => 'nullable|date|after_or_equal:cancel_from',
            'reason' => 'nullable|string|max:1000',
            'status' => 'required|in:Pending,Pending_Covering,Pending_Supervisor,Approved,HR_Approved,Rejected',
            'covering_employee_id' => 'nullable|exists:employees,id',
            'force_continue' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $duplicateCheck = $this->checkForDuplicateLeave($request);
        if ($duplicateCheck) {
            return response()->json([
                'message' => $duplicateCheck,
                'duplicate_found' => true
            ], 422);
        }

        // 🔥 මෙතනදී employmentType එකත් එක්කම Employee ව ගන්නවා
        $employee = employee::with(['organizationAssignment', 'employmentType'])->findOrFail($request->employee_id);
        $orgAssignment = $employee->organizationAssignment;

        $leaveType = (string) $request->leave_type;
        $isMedicalLeave = CompanyProcessSettings::isMedicalLeaveType($leaveType);
        if ($isMedicalLeave && !CompanyProcessSettings::usesMedicalLeave($employee)) {
            return response()->json([
                'message' => 'Medical leave is not enabled for this company.',
            ], 422);
        }
        if ($isMedicalLeave) {
            $leaveType = 'Medical Leave';
            $request->merge(['leave_type' => $leaveType]);
        }

        $isHalfDay = filter_var($request->is_half_day, FILTER_VALIDATE_BOOLEAN);
        $isShortLeave = filter_var($request->is_short_leave, FILTER_VALIDATE_BOOLEAN);

        $requestedDurationInDays = $this->computeRequestedLeaveDays($request, $isHalfDay, $isShortLeave);

        $overLimitInfo = null;

        if ($orgAssignment && $orgAssignment->probationary_period) {
            $requestDate = $request->filled('leave_date')
                ? Carbon::parse($request->leave_date)
                : ($request->filled('leave_from') ? Carbon::parse($request->leave_from) : now());

            $leaveBalance = $this->calculateProbationaryLeaveBalance($request->employee_id, $requestDate);
            $requestedHalfDays = $requestedDurationInDays * 2;

            if ($requestedDurationInDays > 0.5) {
                if (!$request->boolean('force_continue')) {
                    return response()->json([
                        'message' => 'Employees in probation period can only take half-day or short leaves at a time',
                        'limit_exceeded' => true,
                        'continue_allowed' => true
                    ], 422);
                }

                if ($leaveBalance['available_half_days'] < 1) {
                    if (!$request->boolean('force_continue')) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' . $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }
                    $overLimitInfo = ['reason' => 'probation_no_balance', 'amount' => $requestedDurationInDays];
                } else {
                    $validHalfDays = min($leaveBalance['available_half_days'], $requestedHalfDays);
                    $overLimitHalfDays = $requestedHalfDays - $validHalfDays;
                    $overLimitDuration = $overLimitHalfDays / 2;

                    if ($overLimitDuration > 0) {
                        $overLimitInfo = ['reason' => 'probation_partial_balance', 'amount' => $overLimitDuration];
                    }
                }
            } else {
                if ($leaveBalance['available_half_days'] < ceil($requestedHalfDays)) {
                    if (!$request->boolean('force_continue')) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' . $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }
                    $overLimitInfo = ['reason' => 'probation_no_balance', 'amount' => $requestedDurationInDays];
                }
            }
        }

        // Shop & Office Act / HR balance: block if request exceeds entitlement
        $asOfDate = $request->filled('leave_date')
            ? Carbon::parse($request->leave_date)
            : ($request->filled('leave_from') ? Carbon::parse($request->leave_from) : Carbon::now());

        $leaveType = (string) $request->leave_type;
        $isMedicalLeave = CompanyProcessSettings::isMedicalLeaveType($leaveType);
        $entitlementCheck = $this->validateLeaveEntitlement(
            $employee,
            $orgAssignment,
            $leaveType,
            (float) $requestedDurationInDays,
            $asOfDate
        );

        $usesLeaveWorkflow = CompanyProcessSettings::usesLeaveWorkflow($employee);

        $combinedSplit = null;
        $nopayPreviewDays = 0.0;
        $medicalPlan = null;
        if ($isMedicalLeave) {
            $medicalPlan = $this->planMedicalLeaveDeduction($employee, $orgAssignment, (float) $requestedDurationInDays, $asOfDate);
            $nopayPreviewDays = (float) ($medicalPlan['nopay_days'] ?? 0);
            if ($nopayPreviewDays > 0 && !$overLimitInfo) {
                $overLimitInfo = [
                    'reason' => 'balance_shortfall_nopay_preview',
                    'amount' => $nopayPreviewDays,
                ];
            }
        } elseif ($entitlementCheck !== null) {
            // If selected type alone is short, try Annual + Casual combined (e.g. 0.5 + 0.5 = 1 day)
            $combinedSplit = $this->planAnnualCasualCombinedSplit(
                $employee,
                $orgAssignment,
                $leaveType,
                (float) $requestedDurationInDays,
                $asOfDate
            );

            if ($combinedSplit === null) {
                // Allow apply even when balance is short; shortfall becomes NoPay on HR approve.
                // For Annual/Casual, NoPay is only the amount beyond COMBINED Annual+Casual.
                $available = (float) ($entitlementCheck['available_days'] ?? 0);
                $normalizedType = strtolower(trim((string) $leaveType));
                if (str_contains($normalizedType, 'annual') || str_contains($normalizedType, 'casual')) {
                    $pair = $this->getAnnualCasualAvailability($employee, $orgAssignment, $asOfDate);
                    $combined = round(
                        (float) ($pair['Annual Leave'] ?? 0) + (float) ($pair['Casual Leave'] ?? 0),
                        4
                    );
                    if ($combined > $available) {
                        $available = $combined;
                    }
                }
                $nopayPreviewDays = max(0, round((float) $requestedDurationInDays - $available, 4));
                if ($nopayPreviewDays > 0 && !$overLimitInfo) {
                    $overLimitInfo = [
                        'reason' => 'balance_shortfall_nopay_preview',
                        'amount' => $nopayPreviewDays,
                    ];
                }
            }
        }

        $normalizedType = strtolower(trim((string) $leaveType));
        $isNoPayType = str_contains($normalizedType, 'no pay') || str_contains($normalizedType, 'nopay');
        $combinedNopay = 0.0;
        if (is_array($combinedSplit)) {
            foreach ($combinedSplit as $part) {
                $combinedNopay += (float) ($part['nopay_days'] ?? 0);
            }
        }
        if ($usesLeaveWorkflow && !$isNoPayType && ($nopayPreviewDays > 0.0001 || $combinedNopay > 0.0001)) {
            return response()->json([
                'message' => 'Leave request exceeds available leave balance.',
                'requested_days' => $requestedDurationInDays,
                'shortfall_days' => max($nopayPreviewDays, $combinedNopay),
            ], 422);
        }

        $data = $request->all();
        $data['is_half_day'] = $isHalfDay;
        $data['is_short_leave'] = $isShortLeave;
        $data['leave_duration'] = $requestedDurationInDays;
        $data['requested_days'] = $requestedDurationInDays;
        $data['period'] = $request->input('period');
        $data['short_leave_slot'] = $request->input('short_leave_slot');
        if ($isMedicalLeave && Schema::hasColumn('leave_masters', 'requires_evidence')) {
            $data['leave_type'] = 'Medical Leave';
            $data['requires_evidence'] = true;
            if ($request->filled('evidence_path')) {
                $data['evidence_path'] = $request->input('evidence_path');
                $data['evidence_name'] = $request->input('evidence_name');
            }
            if (is_array($medicalPlan)) {
                $data['medical_casual_days'] = $medicalPlan['casual_days'];
                $data['medical_annual_days'] = $medicalPlan['annual_days'];
                $data['leave_balance_days'] = $medicalPlan['balance_days'];
                $data['nopay_days'] = $medicalPlan['nopay_days'];
                $data['over_limit'] = $medicalPlan['nopay_days'];
            }
        }

        // =========================================================================
        // 🔥 NEW LOGIC: EMPLOYMENT STATUS එක අනුව STATUS එක වෙනස් කිරීම 🔥
        // =========================================================================
        if ($usesLeaveWorkflow && in_array((string) $request->status, ['Pending', 'Pending_Covering'], true)) {
            $coveringId = (int) $request->input('covering_employee_id');
            if ($coveringId < 1) {
                return response()->json(['message' => 'Covering person is required.'], 422);
            }
            if ($coveringId === (int) $employee->id) {
                return response()->json(['message' => 'Covering person cannot be the same employee.'], 422);
            }
            $cover = employee::with('organizationAssignment')->find($coveringId);
            $empCompany = (int) ($employee->organizationAssignment->company_id ?? 0);
            $coverCompany = (int) ($cover?->organizationAssignment?->company_id ?? 0);
            if (!$cover || ($empCompany && $coverCompany && $empCompany !== $coverCompany)) {
                return response()->json(['message' => 'Covering person must be from the same company.'], 422);
            }
            $data['status'] = 'Pending_Covering';
            if (Schema::hasColumn('leave_masters', 'covering_employee_id')) {
                $data['covering_employee_id'] = $coveringId;
                $data['covering_status'] = 'Pending';
            }
        } elseif ($request->status === 'Pending') {
            $employmentType = strtolower($employee->employmentType->name ?? '');

            // Employment Status එක 'Training' නම් මුලින්ම Supervisor ගාවට යනවා
            if (str_contains($employmentType, 'training') || str_contains($employmentType, 'trainee')) {
                $data['status'] = 'Pending_Supervisor';
            } else {
                // අනිත් හැමෝම (Permanent, Contract etc.) කෙලින්ම Leave Approval එකට (Pending) යනවා
                $data['status'] = 'Pending';
            }
        }

        if (!$usesLeaveWorkflow) {
            unset($data['covering_employee_id'], $data['covering_status']);
        } elseif (!Schema::hasColumn('leave_masters', 'covering_employee_id')) {
            unset($data['covering_employee_id'], $data['covering_status']);
        }
        // =========================================================================

        if ($overLimitInfo) {
            if ($overLimitInfo['reason'] === 'probation_full_day') {
                $data['leave_duration'] = $requestedDurationInDays / 2;
            } elseif ($overLimitInfo['reason'] === 'probation_monthly_limit' || $overLimitInfo['reason'] === 'probation_no_balance') {
                $data['leave_duration'] = 0;
            } elseif ($overLimitInfo['reason'] === 'probation_partial_balance') {
                $data['leave_duration'] = max(0, $requestedDurationInDays - $overLimitInfo['amount']);
            }
            $data['over_limit'] = $overLimitInfo['amount'];
            if ($overLimitInfo['reason'] === 'balance_shortfall_nopay_preview') {
                $availableForLeave = max(0, round((float) $requestedDurationInDays - (float) $overLimitInfo['amount'], 4));
                $data['leave_balance_days'] = $availableForLeave;
                $data['nopay_days'] = (float) $overLimitInfo['amount'];
                $data['nopay_applied'] = false;
            }
        } else {
            $data['over_limit'] = 0;
            $data['leave_balance_days'] = $requestedDurationInDays;
            $data['nopay_days'] = 0;
            $data['nopay_applied'] = false;
        }

        // Combined Annual + Casual: create one leave row per type (e.g. 0.5 Annual + 0.5 Casual)
        // May also include a NoPay shortfall row when combined still < requested.
        if ($combinedSplit !== null) {
            $created = $this->createCombinedLeaveRecords($data, $combinedSplit);
            $nopayTotal = 0.0;
            foreach ($combinedSplit as $part) {
                $nopayTotal += (float) ($part['nopay_days'] ?? 0);
            }
            $balanceTotal = 0.0;
            foreach ($combinedSplit as $part) {
                $balanceTotal += (float) ($part['balance_days'] ?? $part['days'] ?? 0);
            }
            foreach ($created as $row) {
                LeaveNotificationService::leaveSubmitted($row);
            }
            return response()->json([
                'message' => $nopayTotal > 0.0001
                    ? 'Leave applied using combined Annual and Casual balances; remainder will be NoPay on HR approve.'
                    : 'Leave applied using combined Annual and Casual balances.',
                'combined_balance' => true,
                'split' => $combinedSplit,
                'leaves' => $created,
                'leave' => $created[0] ?? null,
                'nopay_preview_days' => round($nopayTotal, 4),
                'leave_balance_days' => round($balanceTotal, 4),
            ], 201);
        }

        $leaveMaster = leave_master::create($data);
        LeaveNotificationService::leaveSubmitted($leaveMaster);
        $payload = $leaveMaster->toArray();
        if ($nopayPreviewDays > 0) {
            $payload['nopay_preview_days'] = $nopayPreviewDays;
            $payload['message'] = 'Leave submitted. Shortfall will be treated as NoPay when HR approves.';
        }
        return response()->json($payload, 201);
    }

    public function show(string $id)
    {
        $leaveMaster = leave_master::where("employee_id", $id)->get();
        return response()->json($leaveMaster);
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'sometimes|exists:employees,id',
            'reporting_date' => 'sometimes|date',
            'leave_type' => 'sometimes|string|max:255',
            'leave_date' => 'nullable|date',
            'leave_from' => 'sometimes|date',
            'leave_to' => 'sometimes|date|after_or_equal:leave_from',
            'period' => 'nullable|string|max:255',
            'is_half_day' => 'nullable|boolean',
            'is_short_leave' => 'nullable|boolean',
            'short_leave_slot' => 'nullable|string|max:255',
            'cancel_from' => 'nullable|date',
            'cancel_to' => 'nullable|date|after_or_equal:cancel_from',
            'reason' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:Pending,Pending_Supervisor,Approved,HR_Approved,Rejected',
            'force_continue' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $leaveMaster = leave_master::findOrFail($id);

        $duplicateCheck = $this->checkForDuplicateLeave($request, $id);
        if ($duplicateCheck) {
            return response()->json([
                'message' => $duplicateCheck,
                'duplicate_found' => true
            ], 422);
        }

        $employee = employee::with(['organizationAssignment', 'employmentType'])->findOrFail($leaveMaster->employee_id);
        $orgAssignment = $employee->organizationAssignment;

        $isHalfDay = filter_var($request->is_half_day, FILTER_VALIDATE_BOOLEAN);
        $isShortLeave = filter_var($request->is_short_leave, FILTER_VALIDATE_BOOLEAN);

        $requestedDurationInDays = $this->computeRequestedLeaveDays($request, $isHalfDay, $isShortLeave);

        $overLimitInfo = null;

        if ($orgAssignment && $orgAssignment->probationary_period) {
            $requestDate = $request->filled('leave_date')
                ? Carbon::parse($request->leave_date)
                : ($request->filled('leave_from') ? Carbon::parse($request->leave_from) : now());

            $leaveBalance = $this->calculateProbationaryLeaveBalance($request->employee_id, $requestDate);

            $requestedHalfDays = $requestedDurationInDays * 2;

            if ($requestedDurationInDays > 0.5) {
                if (!$request->boolean('force_continue')) {
                    return response()->json([
                        'message' => 'Employees in probation period can only take half-day or short leaves at a time',
                        'limit_exceeded' => true,
                        'continue_allowed' => true
                    ], 422);
                }

                if ($leaveBalance['available_half_days'] < 1) {
                    if (!$request->boolean('force_continue')) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' . $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }
                    $overLimitInfo = ['reason' => 'probation_no_balance', 'amount' => $requestedDurationInDays];
                } else {
                    $validHalfDays = min($leaveBalance['available_half_days'], $requestedHalfDays);
                    $overLimitHalfDays = $requestedHalfDays - $validHalfDays;
                    $overLimitDuration = $overLimitHalfDays / 2;

                    if ($overLimitDuration > 0) {
                        $overLimitInfo = ['reason' => 'probation_partial_balance', 'amount' => $overLimitDuration];
                    }
                }
            } else {
                if ($leaveBalance['available_half_days'] < ceil($requestedHalfDays)) {
                    if (!$request->boolean('force_continue')) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' . $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }
                    $overLimitInfo = ['reason' => 'probation_no_balance', 'amount' => $requestedDurationInDays];
                }
            }
        }

        $data = $request->all();
        $data['is_half_day'] = $isHalfDay;
        $data['is_short_leave'] = $isShortLeave;
        $data['leave_duration'] = $requestedDurationInDays;
        $data['period'] = $request->input('period');
        $data['short_leave_slot'] = $request->input('short_leave_slot');

        if ($overLimitInfo) {
            if ($overLimitInfo['reason'] === 'probation_full_day') {
                $data['leave_duration'] = $requestedDurationInDays / 2;
            } elseif ($overLimitInfo['reason'] === 'probation_monthly_limit' || $overLimitInfo['reason'] === 'probation_no_balance') {
                $data['leave_duration'] = 0;
            } elseif ($overLimitInfo['reason'] === 'probation_partial_balance') {
                $data['leave_duration'] = max(0, $requestedDurationInDays - $overLimitInfo['amount']);
            }
            $data['over_limit'] = $overLimitInfo['amount'];
        } else {
            $data['over_limit'] = 0;
        }

        $leaveMaster->update($data);
        return response()->json($leaveMaster);
    }

    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:Pending,Pending_Covering,Pending_Supervisor,Approved,HR_Approved,Rejected',
            'rejection_reason' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $leaveMaster = leave_master::with('employee.organizationAssignment', 'employee.contactDetail')->findOrFail($id);
        $oldStatus = $leaveMaster->status;
        $newStatus = $request->status;
        $usesLeaveWorkflow = CompanyProcessSettings::usesLeaveWorkflow($leaveMaster->employee);

        if ($oldStatus === 'Pending_Covering' && $newStatus !== 'Rejected') {
            return response()->json([
                'message' => 'This leave is waiting for covering-person approval.',
            ], 422);
        }

        if ($usesLeaveWorkflow && $oldStatus === 'Pending_Supervisor' && $newStatus === 'Pending') {
            $newStatus = 'Approved';
        }

        $wasApproved = in_array($oldStatus, ['Approved', 'HR_Approved'], true);
        $willApprove = in_array($newStatus, ['Approved', 'HR_Approved'], true);

        if ($willApprove && !$wasApproved
            && Schema::hasColumn('leave_masters', 'requires_evidence')
            && $leaveMaster->requires_evidence
            && empty($leaveMaster->evidence_path)
        ) {
            return response()->json([
                'message' => 'Medical evidence is required before HR can approve this leave.',
            ], 422);
        }

        if ($willApprove && !$wasApproved) {
            $this->applyLeaveBalanceAndNopayOnApprove($leaveMaster);
            $leaveMaster->refresh();
        }

        if (!$willApprove && $wasApproved && $leaveMaster->nopay_applied && $leaveMaster->nopay_record_id) {
            $this->revokeLeaveNopay($leaveMaster);
            $leaveMaster->refresh();
        }

        $leaveMaster->update([
            'status' => $newStatus,
            'rejection_reason' => $request->rejection_reason
        ]);

        if ($oldStatus !== $newStatus) {
            $this->sendStatusEmail($leaveMaster, $newStatus, $request->rejection_reason);
            LeaveNotificationService::statusChanged($leaveMaster, $newStatus, $request->rejection_reason);
        }

        return response()->json([
            'message' => 'Leave status updated successfully',
            'leave' => $leaveMaster->fresh(),
            'nopay_days' => (float) ($leaveMaster->nopay_days ?? 0),
            'leave_balance_days' => $leaveMaster->leave_balance_days,
            'nopay_applied' => (bool) ($leaveMaster->nopay_applied ?? false),
        ]);
    }

    private function sendStatusEmail($leave, $status, $rejectionReason = null)
    {
        $employee = $leave->employee;

        if (!$employee->contactDetail || !$employee->contactDetail->email) {
            Log::warning('Cannot send email notification: Employee contact details missing');
            return;
        }

        try {
            if ($status === 'Approved' || $status === 'HR_Approved') {
                Mail::to($employee->contactDetail->email)->send(new LeaveApprovedMail($leave, $employee));
            } elseif ($status === 'Rejected') {
                Mail::to($employee->contactDetail->email)->send(new LeaveRejectedMail($leave, $employee, $rejectionReason));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send leave status email', ['error' => $e->getMessage()]);
        }
    }

    public function destroy(string $id)
    {
        $leaveMaster = leave_master::findOrFail($id);
        $leaveMaster->delete();
        return response()->json(null, 204);
    }

    public function getLeaveRecordCountsByEmployee($employeeId)
    {
        $leaveCounts = leave_master::where('employee_id', $employeeId)
            ->selectRaw('
            leave_type,
            SUM(CASE WHEN (is_half_day = 0 AND is_short_leave = 0) AND status != "Rejected" THEN COALESCE(leave_duration, 1) ELSE 0 END) as approved_full_days,
            SUM(CASE WHEN is_half_day = 1 AND status != "Rejected" THEN COALESCE(leave_duration, 0.5) ELSE 0 END) as approved_half_days,
            SUM(CASE WHEN is_short_leave = 1 AND status != "Rejected" THEN COALESCE(leave_duration, 0.25) ELSE 0 END) as approved_short_leaves,
            SUM(CASE WHEN (is_half_day = 0 AND is_short_leave = 0) AND status = "Rejected" THEN COALESCE(leave_duration, 1) ELSE 0 END) as rejected_full_days,
            SUM(CASE WHEN is_half_day = 1 AND status = "Rejected" THEN COALESCE(leave_duration, 0.5) ELSE 0 END) as rejected_half_days,
            SUM(CASE WHEN is_short_leave = 1 AND status = "Rejected" THEN COALESCE(leave_duration, 0.25) ELSE 0 END) as rejected_short_leaves
        ')
            ->groupBy('leave_type')
            ->get();

        return response()->json($leaveCounts);
    }

    // =========================================================================
    // API ENDPOINTS FOR FRONTEND (SUPERVISOR / MAIN / HR)
    // =========================================================================

    /*
    public function getSupervisorPendingLeaves()
    {
        // Trainee අය දාපුවා පෙන්වන එක (Supervisor Leave Approval පේජ් එකට)
        $pendingLeaves = leave_master::with('employee')->where('status', 'Pending_Supervisor')->get();
        return response()->json($pendingLeaves);
    }
    */


    public function getSupervisorLeaves()
    {
        // Trainee history stays as today. Covering-workflow leaves also appear at Pending_Supervisor.
        $leaves = leave_master::with(['employee.employmentType', 'coveringEmployee'])
            ->where(function ($q) {
                $q->whereHas('employee.employmentType', function ($query) {
                $query->where('name', 'LIKE', '%training%')
                    ->orWhere('name', 'LIKE', '%trainee%');
                })->orWhere('status', 'Pending_Supervisor');
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($leaves);
    }

    public function getPendingLeaveRecords()
    {
        // අනිත් ඔක්කොම අය දාපුවා සහ Supervisor Approve කරපුවා පෙන්වන එක (Leave Approval පේජ් එකට)
        $pendingLeaves = leave_master::with(['employee', 'coveringEmployee'])->where('status', 'Pending')->get();
        return response()->json($pendingLeaves);
    }

    // =========================================================================

    public function getApprovedLeaveRecords()
    {
        $approvedLeaves = leave_master::with(['employee', 'coveringEmployee'])->where('status', 'Approved')->get();
        return response()->json($approvedLeaves);
    }

    public function getHRApprovedLeaveRecords()
    {
        $hrApprovedLeaves = leave_master::with(['employee', 'coveringEmployee'])->where('status', 'HR_Approved')->get();
        return response()->json($hrApprovedLeaves);
    }

    public function getRejectedLeaveRecords()
    {
        $rejectedLeaves = leave_master::with(['employee', 'coveringEmployee'])->where('status', 'Rejected')->get();
        return response()->json($rejectedLeaves);
    }

    public function getApprovedLeavesByDate(Request $request)
    {
        $date = $request->query('date');
        if (!$date) return response()->json(['message' => 'Date is required'], 422);

        $leaveCount = leave_master::where('status', 'Approved')
            ->where(function ($query) use ($date) {
                $query->where('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->where('leave_from', '<=', $date)->where('leave_to', '>=', $date);
                    });
            })->count();

        return response()->json($leaveCount);
    }

    private function calculateProbationaryLeaveBalance($employeeId, $requestDate = null)
    {
        $requestDate = $requestDate ?? now();
        $currentYear = $requestDate->year;

        $monthsElapsed = min($requestDate->month, 12);
        $maxAccruedLeaves = min($monthsElapsed, 7);

        // Pending_Supervisor ඒවත් Balance එකෙන් අඩු වෙන්න ඕනේ
        $usedLeaves = leave_master::where('employee_id', $employeeId)
            ->where('status', '!=', 'Rejected')
            ->whereYear('reporting_date', $currentYear)
            ->get();

        $usedHalfDays = 0;
        foreach ($usedLeaves as $leave) {
            if ($leave->is_short_leave) {
                $usedHalfDays += 0.5;
            } elseif ($leave->is_half_day) {
                $usedHalfDays += 1;
            } else {
                $usedHalfDays += ($leave->leave_duration * 2);
            }
        }

        $availableHalfDays = max(0, $maxAccruedLeaves - $usedHalfDays);

        return [
            'available_half_days' => $availableHalfDays,
            'used_half_days' => $usedHalfDays,
            'max_accrued' => $maxAccruedLeaves
        ];
    }

    private function checkForDuplicateLeave($request, $excludeId = null)
    {
        $employeeId = $request->employee_id;

        if ($request->leave_date) {
            $newDuration = $this->resolveRequestLeaveDuration($request);
            $existingDuration = $this->getUsedLeaveDurationOnDate(
                $employeeId,
                $request->leave_date,
                $excludeId
            );

            // Allow multiple same-day leaves (e.g. 0.5 Annual + 0.5 Casual) up to 1 full day
            if ($existingDuration + $newDuration > 1.0001) {
                return "You already have "
                    . rtrim(rtrim(number_format($existingDuration, 2), '0'), '.')
                    . " day(s) of leave on {$request->leave_date}. "
                    . "Cannot add another "
                    . rtrim(rtrim(number_format($newDuration, 2), '0'), '.')
                    . " day(s) (max 1 day per date).";
            }

            if ($existingDuration <= 0) {
                // no conflict
            }
        }

        if ($request->leave_from && $request->leave_to) {
            // For ranges, keep strict overlap check unless it's a same-day range handled above
            if ($request->leave_from !== $request->leave_to || !$request->leave_date) {
            $query = leave_master::where('employee_id', $employeeId)
                ->where('status', '!=', 'Rejected')
                ->where(function ($query) use ($request) {
                    $query->where(function ($q) use ($request) {
                        $q->whereBetween('leave_date', [$request->leave_from, $request->leave_to]);
                    })->orWhere(function ($q) use ($request) {
                        $q->where(function ($subQ) use ($request) {
                            $subQ->where('leave_from', '<=', $request->leave_to)
                                ->where('leave_to', '>=', $request->leave_from);
                        });
                    });
                });

                if ($excludeId) {
                    $query->where('id', '!=', $excludeId);
                }
            $existing = $query->first();

            if ($existing) {
                    // Same calendar day range with room under 1 day is allowed via leave_date path
                    if ($request->leave_from === $request->leave_to) {
                        $newDuration = $this->resolveRequestLeaveDuration($request);
                        $existingDuration = $this->getUsedLeaveDurationOnDate(
                            $employeeId,
                            $request->leave_from,
                            $excludeId
                        );
                        if ($existingDuration + $newDuration <= 1.0001) {
                            return null;
                        }
                    }

                    $existingDateStr = $existing->leave_date
                        ? $existing->leave_date
                        : "{$existing->leave_from} to {$existing->leave_to}";
                return "Your requested leave period overlaps with an existing leave: {$existingDateStr} ({$existing->status})";
                }
            }
        }

        return null;
    }

    private function computeRequestedLeaveDays($request, ?bool $isHalfDay = null, ?bool $isShortLeave = null): float
    {
        $isHalfDay = $isHalfDay ?? filter_var($request->is_half_day, FILTER_VALIDATE_BOOLEAN);
        $isShortLeave = $isShortLeave ?? filter_var($request->is_short_leave, FILTER_VALIDATE_BOOLEAN);

        $calendarDays = 0.0;
        if ($request->filled('leave_from') && $request->filled('leave_to')) {
            $from = new \DateTime($request->leave_from);
            $to = new \DateTime($request->leave_to);
            $calendarDays = (float) ($from->diff($to)->days + 1);
        } elseif ($request->filled('leave_date')) {
            $calendarDays = 1.0;
        }

        $unit = 1.0;
        if ($isShortLeave) {
            $unit = 0.25;
        } elseif ($isHalfDay) {
            $unit = 0.5;
        }

        $computed = round(max(0, $calendarDays) * $unit, 4);

        // Prefer explicit leave_duration when it matches calendar×unit (or when calendar missing)
        if ($request->filled('leave_duration')) {
            $provided = round((float) $request->leave_duration, 4);
            if ($calendarDays <= 0 || abs($provided - $computed) < 0.0001 || $computed <= 0) {
                return max(0, $provided);
            }
        }

        return $computed > 0 ? $computed : 1.0;
    }

    private function resolveRequestLeaveDuration($request): float
    {
        return $this->computeRequestedLeaveDays($request);
    }

    private function getUsedLeaveDurationOnDate($employeeId, string $date, $excludeId = null): float
    {
        $query = leave_master::where('employee_id', $employeeId)
            ->where('status', '!=', 'Rejected')
            ->where(function ($q) use ($date) {
                $q->whereDate('leave_date', $date)
                    ->orWhere(function ($sub) use ($date) {
                        $sub->whereDate('leave_from', '<=', $date)
                            ->whereDate('leave_to', '>=', $date);
                    });
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $total = 0.0;
        foreach ($query->get() as $leave) {
            // For multi-day half/short leaves, charge the per-day unit on each overlapped date
            if ($leave->is_short_leave) {
                $total += 0.25;
            } elseif ($leave->is_half_day) {
                $total += 0.5;
            } elseif ($leave->leave_from && $leave->leave_to && $leave->leave_from !== $leave->leave_to) {
                $total += 1.0;
            } else {
                $total += (float) ($leave->leave_duration ?? 1);
            }
        }

        return round($total, 4);
    }

    public function getLeaveEligibility(Request $request)
    {
        $empNumber = $request->query('emp_number');
        $employeeId = $request->query('employee_id');
        $requestedDateStr = $request->query('date');

        if (!$empNumber && !$employeeId) {
            return response()->json(['message' => 'Either emp_number or employee_id is required'], 422);
        }

        if ($empNumber) {
            $employee = employee::with('organizationAssignment')->where('attendance_employee_no', $empNumber)->first();
        } else {
            $employee = employee::with('organizationAssignment')->find($employeeId);
        }

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $orgAssignment = $employee->organizationAssignment;
        $targetDate = $requestedDateStr ? Carbon::parse($requestedDateStr) : Carbon::now();
        $currentYear = (int) $targetDate->year;

        $eligibleLeaves = [];

        // Optional HR override: employee-wise balances for the year (if configured).
        $manualBalances = \App\Models\EmployeeLeaveBalance::where('employee_id', $employee->id)
            ->where('year', $currentYear)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get();

        $law = [
            'employment_year' => null,
            'is_first_year' => false,
            'is_second_year' => false,
            'completed_months_first_year' => 0,
            'casual_days' => 0,
            'annual_days' => 0,
            'casual_note' => '',
            'annual_note' => '',
            'law_reference' => 'Shop and Office Employees Act No. 19 of 1954 (Sri Lanka)',
        ];

        if ($orgAssignment && $orgAssignment->date_of_joining) {
            $joinDate = Carbon::parse($orgAssignment->date_of_joining);
            $calculator = app(\App\Services\ShopAndOfficeLeaveCalculator::class);
            $law = $calculator->calculate($joinDate, $targetDate);
        } elseif ($manualBalances->isEmpty() && $this->isActAccrualEnabled($targetDate)) {
            return response()->json([
                'message' => 'Date of Joining is not set for this employee. Cannot calculate leaves under Shop & Office Act.',
            ], 400);
        }

        if ($manualBalances->isNotEmpty()) {
            foreach ($manualBalances as $balance) {
                $used = $this->getUsedLeaveDays($employee->id, $balance->leave_type, $currentYear);
                $total = (float) $balance->entitled_days;
                $eligibleLeaves[] = [
                    'leave_type' => $balance->leave_type,
                    'total_days' => $total,
                    'used_days' => $used,
                    'available_days' => max(0, $total - $used),
                    'is_half_day_only' => false,
                    'source' => 'employee_balance',
                    'note' => $balance->notes ?: 'Employee-wise leave balance (HR override)',
                ];
            }

            return response()->json([
                'employee_id' => $employee->id,
                'emp_number' => $employee->attendance_employee_no,
                'employee_name' => $employee->display_name ?? $employee->name_with_initials ?? $employee->full_name,
                'join_date' => $orgAssignment->date_of_joining ?? null,
                'as_of_date' => $targetDate->toDateString(),
                'calendar_year' => $currentYear,
                'employment_year' => $law['employment_year'],
                'is_first_year' => $law['is_first_year'],
                'is_second_year' => $law['is_second_year'],
                'balance_source' => 'employee_leave_balances',
                'act_accrual_enabled' => $this->isActAccrualEnabled($targetDate),
                'act_accrual_starts_on' => self::ACT_ACCRUAL_START_DATE,
                'law_reference' => $law['law_reference'],
                'eligible_leaves' => $eligibleLeaves,
            ]);
        }

        // Before Act accrual start date: manual balances only (no Act auto-earn for this year).
        if (!$this->isActAccrualEnabled($targetDate)) {
            return response()->json([
                'employee_id' => $employee->id,
                'emp_number' => $employee->attendance_employee_no,
                'employee_name' => $employee->display_name ?? $employee->name_with_initials ?? $employee->full_name,
                'join_date' => $orgAssignment->date_of_joining ?? null,
                'as_of_date' => $targetDate->toDateString(),
                'calendar_year' => $currentYear,
                'employment_year' => $law['employment_year'],
                'is_first_year' => $law['is_first_year'],
                'is_second_year' => $law['is_second_year'],
                'balance_source' => 'manual_only_until_act_start',
                'act_accrual_enabled' => false,
                'act_accrual_starts_on' => self::ACT_ACCRUAL_START_DATE,
                'law_reference' => $law['law_reference'],
                'eligible_leaves' => [],
                'message' => 'Shop & Office Act earning leave starts from '
                    . self::ACT_ACCRUAL_START_DATE
                    . '. For this year, use HR-entered leave balances only. Leave can still be applied; shortfall becomes NoPay on HR approve.',
            ]);
        }

        // Default: Shop & Office Act statutory calculation only (Annual + Casual).
            $usedCasual = $this->getUsedLeaveDays($employee->id, 'Casual Leave', $currentYear);
            $eligibleLeaves[] = [
                'leave_type' => 'Casual Leave',
            'total_days' => $law['casual_days'],
                'used_days' => $usedCasual,
            'available_days' => max(0, $law['casual_days'] - $usedCasual),
                'is_half_day_only' => false,
            'source' => 'shop_and_office_act',
            'note' => $law['casual_note'],
            ];

            $usedAnnual = $this->getUsedLeaveDays($employee->id, 'Annual Leave', $currentYear);
            $eligibleLeaves[] = [
                'leave_type' => 'Annual Leave',
            'total_days' => $law['annual_days'],
                'used_days' => $usedAnnual,
            'available_days' => max(0, $law['annual_days'] - $usedAnnual),
                'is_half_day_only' => false,
            'source' => 'shop_and_office_act',
            'note' => $law['annual_note'],
            ];

        return response()->json([
            'employee_id' => $employee->id,
            'emp_number' => $employee->attendance_employee_no,
            'employee_name' => $employee->display_name ?? $employee->name_with_initials ?? $employee->full_name,
            'join_date' => $orgAssignment->date_of_joining ?? null,
            'as_of_date' => $targetDate->toDateString(),
            'calendar_year' => $currentYear,
            'employment_year' => $law['employment_year'],
            'is_first_year' => $law['is_first_year'],
            'is_second_year' => $law['is_second_year'],
            'completed_months_first_year' => $law['completed_months_first_year'],
            'balance_source' => 'shop_and_office_act',
            'act_accrual_enabled' => true,
            'act_accrual_starts_on' => self::ACT_ACCRUAL_START_DATE,
            'law_reference' => $law['law_reference'],
            'eligible_leaves' => $eligibleLeaves,
        ]);
    }

    private function getUsedLeaveDays($employeeId, $leaveType, $year, ?int $excludeLeaveId = null)
    {
        $aliases = [$leaveType];
        $normalized = strtolower(trim((string) $leaveType));

        if (str_contains($normalized, 'casual')) {
            $aliases = array_unique(array_merge($aliases, ['Casual Leave', 'Casual', 'casual leave', 'CASUAL LEAVE']));
        } elseif (str_contains($normalized, 'annual')) {
            $aliases = array_unique(array_merge($aliases, ['Annual Leave', 'Annual', 'annual leave', 'ANNUAL LEAVE']));
        }

        $leavesQuery = leave_master::where('employee_id', $employeeId)
            ->where(function ($q) use ($aliases) {
                foreach ($aliases as $alias) {
                    $q->orWhereRaw('LOWER(leave_type) = ?', [strtolower($alias)]);
                }
            })
            ->whereIn('status', ['Approved', 'HR_Approved', 'Pending', 'Pending_Supervisor', 'Pending_Covering'])
            ->where(function ($query) use ($year) {
                $query->whereYear('leave_date', $year)
                    ->orWhereYear('leave_from', $year);
            });

        if ($excludeLeaveId) {
            $leavesQuery->where('id', '!=', $excludeLeaveId);
        }

        $leaves = $leavesQuery->get();

        $totalDays = 0;
        foreach ($leaves as $leave) {
            // Count only the leave-balance portion (exclude NoPay shortfall).
            if ($leave->leave_balance_days !== null) {
                $totalDays += (float) $leave->leave_balance_days;
                continue;
            }

            $days = 0.0;
            if ($leave->is_short_leave) {
                $days = 0.25;
            } elseif ($leave->is_half_day) {
                $days = 0.5;
            } else {
                $days = (float) ($leave->leave_duration ?? 1);
            }

            $nopayPreview = (float) ($leave->nopay_days ?? 0);
            if ($nopayPreview <= 0 && (float) ($leave->over_limit ?? 0) > 0) {
                $nopayPreview = (float) $leave->over_limit;
            }
            if (!$leave->nopay_applied && $nopayPreview > 0) {
                $days = max(0, round($days - $nopayPreview, 4));
            }

            $totalDays += $days;
        }

        if (Schema::hasColumn('leave_masters', 'medical_casual_days')
            && (str_contains($normalized, 'casual') || str_contains($normalized, 'annual'))
        ) {
            $medicalQuery = leave_master::where('employee_id', $employeeId)
                ->whereRaw('LOWER(leave_type) like ?', ['%medical%'])
                ->whereIn('status', ['Approved', 'HR_Approved', 'Pending', 'Pending_Supervisor', 'Pending_Covering'])
                ->where(function ($query) use ($year) {
                    $query->whereYear('leave_date', $year)
                        ->orWhereYear('leave_from', $year);
                });
            if ($excludeLeaveId) {
                $medicalQuery->where('id', '!=', $excludeLeaveId);
            }
            $field = str_contains($normalized, 'casual') ? 'medical_casual_days' : 'medical_annual_days';
            $totalDays += (float) $medicalQuery->sum($field);
        }

        return round($totalDays, 2);
    }

    /**
     * Soft-check leave entitlement. Shortfall is allowed (NoPay on HR approve).
     * Returns warning payload when short, or null when fully covered.
     */
    private function validateLeaveEntitlement($employee, $orgAssignment, string $leaveType, float $requestedDays, Carbon $asOfDate): ?array
    {
        if ($requestedDays <= 0) {
            return null;
        }

        $year = (int) $asOfDate->year;
        $normalized = strtolower(trim($leaveType));
        $law = [
            'law_reference' => 'Shop and Office Employees Act No. 19 of 1954 (Sri Lanka)',
            'employment_year' => null,
            'casual_days' => 0,
            'annual_days' => 0,
            'casual_note' => '',
            'annual_note' => '',
        ];

        if ($orgAssignment && $orgAssignment->date_of_joining) {
            $joinDate = Carbon::parse($orgAssignment->date_of_joining);
            $calculator = app(\App\Services\ShopAndOfficeLeaveCalculator::class);
            $law = $calculator->calculate($joinDate, $asOfDate);
        }

        $manualBalances = \App\Models\EmployeeLeaveBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get();

        $entitled = null;
        $note = '';
        $source = 'shop_and_office_act';
        $displayType = $leaveType;

        if ($manualBalances->isNotEmpty()) {
            $match = $manualBalances->first(function ($row) use ($normalized) {
                return strtolower(trim((string) $row->leave_type)) === $normalized;
            });

            if (!$match) {
                // Type not in manual list → treat as 0 balance (full NoPay on approve)
                $entitled = 0.0;
                $note = 'Leave type not in HR leave balances for this year; shortfall will be NoPay on HR approve.';
                $source = 'employee_leave_balances';
            } else {
                $entitled = (float) $match->entitled_days;
                $note = $match->notes ?: 'Employee-wise leave balance (HR override)';
                $source = 'employee_leave_balances';
                $displayType = $match->leave_type;
            }
        } elseif (!$this->isActAccrualEnabled($asOfDate)) {
            $entitled = 0.0;
            $note = 'Act earning leave starts from ' . self::ACT_ACCRUAL_START_DATE
                . '. No HR leave balance set for this year; shortfall will be NoPay on HR approve.';
            $source = 'manual_only_until_act_start';
        } elseif (str_contains($normalized, 'casual')) {
            $entitled = (float) $law['casual_days'];
            $note = $law['casual_note'];
            $displayType = 'Casual Leave';
        } elseif (str_contains($normalized, 'annual')) {
            $entitled = (float) $law['annual_days'];
            $note = $law['annual_note'];
            $displayType = 'Annual Leave';
        } else {
            $entitled = 0.0;
            $note = 'Non-statutory leave type; shortfall will be NoPay on HR approve.';
            $source = 'nopay_fallback';
        }

        $used = $this->getUsedLeaveDays($employee->id, $displayType, $year);
        $available = max(0, round($entitled - $used, 2));

        if ($requestedDays <= $available + 0.0001) {
            return null;
        }

        $combinedAnnualCasual = null;
        $coverFromBalance = $available;
        if (str_contains(strtolower($displayType), 'annual') || str_contains(strtolower($displayType), 'casual')) {
            $pair = $this->getAnnualCasualAvailability($employee, $orgAssignment, $asOfDate);
            $combinedAnnualCasual = [
                'annual_available' => $pair['Annual Leave'] ?? 0,
                'casual_available' => $pair['Casual Leave'] ?? 0,
                'combined_available' => round(($pair['Annual Leave'] ?? 0) + ($pair['Casual Leave'] ?? 0), 4),
            ];
            // Shortfall / NoPay is beyond combined Annual + Casual, not selected type alone
            $coverFromBalance = max($available, (float) $combinedAnnualCasual['combined_available']);
        }

        $nopayPreview = max(0, round($requestedDays - $coverFromBalance, 4));
        $reason = $note;
        if ($entitled <= 0) {
            $reason = $note ?: "No {$displayType} balance available. Full request will be NoPay on HR approve.";
        } elseif ($available <= 0) {
            $reason = "All {$displayType} for {$year} is already used ({$used}/{$entitled} days). Shortfall {$nopayPreview} day(s) will be NoPay on HR approve. {$note}";
        } else {
            $reason = "Requested {$requestedDays} day(s); {$coverFromBalance} day(s) from leave balance"
                . ($combinedAnnualCasual ? " (Annual + Casual)" : " ({$displayType})")
                . ", {$nopayPreview} day(s) as NoPay on HR approve. {$note}";
        }

        if ($combinedAnnualCasual) {
            $reason .= " Combined Annual ({$combinedAnnualCasual['annual_available']}) + Casual ({$combinedAnnualCasual['casual_available']}) = {$combinedAnnualCasual['combined_available']} day(s).";
        }

        return [
            'message' => "Leave balance short for {$displayType}. You can still submit; shortfall becomes NoPay when HR approves.",
            'reason' => trim($reason),
            'entitlement_exceeded' => true,
            'limit_exceeded' => true,
            'continue_allowed' => true,
            'nopay_preview_days' => $nopayPreview,
            'leave_type' => $displayType,
            'requested_days' => $requestedDays,
            'entitled_days' => $entitled,
            'used_days' => $used,
            'available_days' => $available,
            'cover_from_balance_days' => $coverFromBalance,
            'combined_annual_casual' => $combinedAnnualCasual,
            'balance_source' => $source,
            'law_reference' => $law['law_reference'],
            'employment_year' => $law['employment_year'] ?? null,
            'calendar_year' => $year,
            'act_accrual_enabled' => $this->isActAccrualEnabled($asOfDate),
            'act_accrual_starts_on' => self::ACT_ACCRUAL_START_DATE,
        ];
    }

    /**
     * Medical leave uses Casual first, then Annual, then NoPay.
     */
    private function planMedicalLeaveDeduction($employee, $orgAssignment, float $requestedDays, Carbon $asOfDate): array
    {
        $pair = $this->getAnnualCasualAvailability($employee, $orgAssignment, $asOfDate);
        $casualAvail = max(0, (float) ($pair['Casual Leave'] ?? 0));
        $annualAvail = max(0, (float) ($pair['Annual Leave'] ?? 0));
        $fromCasual = min($requestedDays, $casualAvail);
        $remaining = round($requestedDays - $fromCasual, 4);
        $fromAnnual = min($remaining, $annualAvail);
        $nopay = round($remaining - $fromAnnual, 4);

        return [
            'casual_days' => round($fromCasual, 4),
            'annual_days' => round($fromAnnual, 4),
            'balance_days' => round($fromCasual + $fromAnnual, 4),
            'nopay_days' => max(0, $nopay),
        ];
    }

    /**
     * Available Annual / Casual days for an employee (HR balances or Shop & Office Act).
     */
    private function getAnnualCasualAvailability($employee, $orgAssignment, Carbon $asOfDate): array
    {
        $year = (int) $asOfDate->year;
        $result = [
            'Annual Leave' => 0.0,
            'Casual Leave' => 0.0,
        ];

        $manualBalances = \App\Models\EmployeeLeaveBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get();

        if ($manualBalances->isNotEmpty()) {
            foreach ($manualBalances as $balance) {
                $type = (string) $balance->leave_type;
                $normalized = strtolower(trim($type));
                if (!str_contains($normalized, 'annual') && !str_contains($normalized, 'casual')) {
                    continue;
                }
                $key = str_contains($normalized, 'annual') ? 'Annual Leave' : 'Casual Leave';
                $used = $this->getUsedLeaveDays($employee->id, $type, $year);
                $result[$key] = max(0, round((float) $balance->entitled_days - $used, 4));
            }
            return $result;
        }

        if (!$this->isActAccrualEnabled($asOfDate)) {
            return $result;
        }

        if (!$orgAssignment || !$orgAssignment->date_of_joining) {
            return $result;
        }

        $joinDate = Carbon::parse($orgAssignment->date_of_joining);
        $law = app(\App\Services\ShopAndOfficeLeaveCalculator::class)->calculate($joinDate, $asOfDate);

        $usedAnnual = $this->getUsedLeaveDays($employee->id, 'Annual Leave', $year);
        $usedCasual = $this->getUsedLeaveDays($employee->id, 'Casual Leave', $year);

        $result['Annual Leave'] = max(0, round((float) $law['annual_days'] - $usedAnnual, 4));
        $result['Casual Leave'] = max(0, round((float) $law['casual_days'] - $usedCasual, 4));

        return $result;
    }

    /**
     * When one leave type is short, plan a split across Annual + Casual
     * (e.g. need 1 day, have 0.5 Annual + 0.5 Casual).
     */
    private function planAnnualCasualCombinedSplit(
        $employee,
        $orgAssignment,
        string $preferredType,
        float $requestedDays,
        Carbon $asOfDate
    ): ?array {
        if ($requestedDays <= 0) {
            return null;
        }

        $normalized = strtolower(trim($preferredType));
        $isAnnual = str_contains($normalized, 'annual');
        $isCasual = str_contains($normalized, 'casual');
        if (!$isAnnual && !$isCasual) {
            return null;
        }

        $preferred = $isAnnual ? 'Annual Leave' : 'Casual Leave';
        $other = $isAnnual ? 'Casual Leave' : 'Annual Leave';

        $availability = $this->getAnnualCasualAvailability($employee, $orgAssignment, $asOfDate);
        $preferredAvail = max(0, (float) ($availability[$preferred] ?? 0));
        $otherAvail = max(0, (float) ($availability[$other] ?? 0));

        // Preferred type alone already enough — no split needed
        if ($requestedDays <= $preferredAvail + 0.0001) {
            return null;
        }

        // Other type has nothing to contribute — keep single-record NoPay path
        if ($otherAvail <= 0.0001) {
            return null;
        }

        $fromPreferred = min($requestedDays, $preferredAvail);
        $remaining = round($requestedDays - $fromPreferred, 4);
        $fromOther = min($remaining, $otherAvail);
        $remaining = round($remaining - $fromOther, 4);

        $plan = [];
        if ($fromPreferred > 0.0001) {
            $plan[] = [
                'leave_type' => $preferred,
                'days' => round($fromPreferred, 4),
                'balance_days' => round($fromPreferred, 4),
                'nopay_days' => 0.0,
            ];
        }
        if ($fromOther > 0.0001) {
            $plan[] = [
                'leave_type' => $other,
                'days' => round($fromOther, 4),
                'balance_days' => round($fromOther, 4),
                'nopay_days' => 0.0,
            ];
        }
        // Remainder beyond combined Annual + Casual → NoPay row (same preferred type)
        if ($remaining > 0.0001) {
            $plan[] = [
                'leave_type' => $preferred,
                'days' => round($remaining, 4),
                'balance_days' => 0.0,
                'nopay_days' => round($remaining, 4),
            ];
        }

        // Need at least preferred+other, or other+nopay style multi-part
        return count($plan) > 1 ? $plan : null;
    }

    /**
     * Create leave_master rows for a combined Annual/Casual split.
     */
    private function createCombinedLeaveRecords(array $baseData, array $splitPlan): array
    {
        $created = [];
        $anchorDate = $baseData['leave_date']
            ?? $baseData['leave_from']
            ?? now()->toDateString();
        $hasRange = !empty($baseData['leave_from']) && !empty($baseData['leave_to'])
            && $baseData['leave_from'] !== $baseData['leave_to'];

        foreach ($splitPlan as $index => $part) {
            $days = (float) $part['days'];
            $balanceDays = array_key_exists('balance_days', $part)
                ? (float) $part['balance_days']
                : $days;
            $nopayDays = array_key_exists('nopay_days', $part)
                ? (float) $part['nopay_days']
                : 0.0;

            $row = $baseData;
            $row['leave_type'] = $part['leave_type'];
            $row['leave_duration'] = $balanceDays > 0.0001 ? $balanceDays : 0;
            $row['requested_days'] = $days;
            $row['leave_balance_days'] = $balanceDays;
            $row['nopay_days'] = $nopayDays;
            $row['nopay_applied'] = false;
            $row['over_limit'] = $nopayDays;

            // Keep original range on the first balance row; siblings use anchor date only
            // so multi-day overlap usage is not triple-counted.
            if ($index === 0 && $hasRange) {
                $row['leave_from'] = $baseData['leave_from'];
                $row['leave_to'] = $baseData['leave_to'];
                $row['leave_date'] = $baseData['leave_date'] ?? null;
                $row['is_half_day'] = (bool) ($baseData['is_half_day'] ?? false);
                $row['is_short_leave'] = (bool) ($baseData['is_short_leave'] ?? false);
                $row['period'] = $baseData['period'] ?? null;
                $row['short_leave_slot'] = $baseData['short_leave_slot'] ?? null;
            } else {
                $row['leave_date'] = $anchorDate;
                $row['leave_from'] = null;
                $row['leave_to'] = null;

                if ($nopayDays > 0.0001 && $balanceDays <= 0.0001) {
                    $row['is_short_leave'] = false;
                    $row['is_half_day'] = false;
                    $row['period'] = null;
                    $row['short_leave_slot'] = null;
                } elseif (abs($days - 0.25) < 0.001) {
                    $row['is_short_leave'] = true;
                    $row['is_half_day'] = false;
                    $row['short_leave_slot'] = $row['short_leave_slot'] ?? 'slot1';
                    $row['period'] = null;
                } elseif (abs($days - 0.5) < 0.001) {
                    $row['is_short_leave'] = false;
                    $row['is_half_day'] = true;
                    $row['period'] = $row['period'] ?? 'Morning';
                    $row['short_leave_slot'] = null;
                } else {
                    $row['is_short_leave'] = false;
                    $row['is_half_day'] = false;
                    $row['period'] = null;
                    $row['short_leave_slot'] = null;
                }
            }

            $reason = trim((string) ($baseData['reason'] ?? ''));
            $splitNote = $nopayDays > 0.0001 && $balanceDays <= 0.0001
                ? "Combined balance shortfall (NoPay) #" . ($index + 1)
                    . ": {$part['leave_type']} {$days} day(s)"
                : "Combined balance split #" . ($index + 1)
                    . ": {$part['leave_type']} {$days} day(s)";
            $row['reason'] = $reason !== ''
                ? "{$reason} | {$splitNote}"
                : $splitNote;

            $created[] = leave_master::create($row);
        }

        return $created;
    }

    /**
     * On HR/manager approve: deduct available leave balance; remainder becomes NoPay.
     * Example: request 1 day, balance 0.5 → leave 0.5 + NoPay 0.5.
     */
    private function applyLeaveBalanceAndNopayOnApprove(leave_master $leave): void
    {
        if ($leave->nopay_applied) {
            return;
        }

        $employee = $leave->employee ?: employee::with('organizationAssignment')->find($leave->employee_id);
        if (!$employee) {
            return;
        }
        if (!$employee->relationLoaded('organizationAssignment')) {
            $employee->load('organizationAssignment');
        }
        $orgAssignment = $employee->organizationAssignment;

        $asOfDate = $leave->leave_date
            ? Carbon::parse($leave->leave_date)
            : ($leave->leave_from ? Carbon::parse($leave->leave_from) : Carbon::now());

        $requested = (float) ($leave->requested_days
            ?? $leave->leave_duration
            ?? ($leave->is_short_leave ? 0.25 : ($leave->is_half_day ? 0.5 : 1)));

        if (CompanyProcessSettings::isMedicalLeaveType((string) $leave->leave_type)) {
            $casualAvail = $this->getAvailableDaysForLeaveType($employee, $orgAssignment, 'Casual Leave', $asOfDate, (int) $leave->id);
            $annualAvail = $this->getAvailableDaysForLeaveType($employee, $orgAssignment, 'Annual Leave', $asOfDate, (int) $leave->id);
            $fromCasual = min($requested, max(0, $casualAvail));
            $remaining = round($requested - $fromCasual, 4);
            $fromAnnual = min($remaining, max(0, $annualAvail));
            $fromBalance = round($fromCasual + $fromAnnual, 4);
            $nopayDays = max(0, round($requested - $fromBalance, 4));
            $updates = [
                'requested_days' => $requested,
                'leave_balance_days' => $fromBalance,
                'leave_duration' => $fromBalance > 0 ? $fromBalance : 0,
                'nopay_days' => $nopayDays,
                'over_limit' => $nopayDays,
                'nopay_applied' => $nopayDays > 0.0001,
            ];
            if (Schema::hasColumn('leave_masters', 'medical_casual_days')) {
                $updates['medical_casual_days'] = round($fromCasual, 4);
                $updates['medical_annual_days'] = round($fromAnnual, 4);
            }
            $nopayRecordId = null;
            if ($nopayDays > 0.0001) {
                $record = NoPayRecord::create([
                    'employee_id' => $leave->employee_id,
                    'date' => $asOfDate->toDateString(),
                    'no_pay_count' => $nopayDays,
                    'description' => "Auto NoPay from medical leave shortfall | Leave #{$leave->id} | "
                        . "Requested {$requested} day(s), Casual {$fromCasual}, Annual {$fromAnnual}, NoPay {$nopayDays}",
                    'status' => 'Approved',
                    'processed_by' => Auth::id(),
                    'type' => 'LEAVE_SHORTFALL',
                    'hours' => null,
                    'minutes' => null,
                ]);
                $nopayRecordId = $record->id;
                $updates['nopay_record_id'] = $nopayRecordId;
            }
            $leave->update($updates);
            return;
        }

        // Pre-marked combined-split NoPay shortfall row — do not take from leave balance
        $premarkedNopayOnly = ((float) ($leave->leave_balance_days ?? 0) <= 0.0001)
            && ((float) ($leave->nopay_days ?? 0) > 0.0001);

        if ($premarkedNopayOnly) {
            $fromBalance = 0.0;
            $nopayDays = max(0, round($requested, 4));
        } else {
            $available = $this->getAvailableDaysForLeaveType(
                $employee,
                $orgAssignment,
                (string) $leave->leave_type,
                $asOfDate,
                (int) $leave->id
            );

            $normalized = strtolower(trim((string) $leave->leave_type));
            $isAnnualOrCasual = str_contains($normalized, 'annual') || str_contains($normalized, 'casual');

            $fromPreferred = max(0, min($requested, $available));
            $fromOther = 0.0;
            $otherType = null;

            // Use the other of Annual/Casual when this type alone is short
            if ($isAnnualOrCasual && ($requested - $fromPreferred) > 0.0001) {
                $otherType = str_contains($normalized, 'annual') ? 'Casual Leave' : 'Annual Leave';
                $otherAvail = $this->getAvailableDaysForLeaveType(
                    $employee,
                    $orgAssignment,
                    $otherType,
                    $asOfDate,
                    null
                );
                $fromOther = max(0, min(round($requested - $fromPreferred, 4), $otherAvail));
            }

            $fromBalance = round($fromPreferred + $fromOther, 4);
            $nopayDays = max(0, round($requested - $fromBalance, 4));

            // Persist other-type usage as its own approved leave row
            if ($fromOther > 0.0001 && $otherType) {
                leave_master::create([
                    'employee_id' => $leave->employee_id,
                    'reporting_date' => $leave->reporting_date ?? now()->toDateString(),
                    'leave_type' => $otherType,
                    'leave_date' => $leave->leave_date,
                    'leave_from' => $leave->leave_from,
                    'leave_to' => $leave->leave_to,
                    'leave_duration' => $fromOther,
                    'requested_days' => $fromOther,
                    'leave_balance_days' => $fromOther,
                    'nopay_days' => 0,
                    'nopay_applied' => false,
                    'over_limit' => 0,
                    'is_half_day' => false,
                    'is_short_leave' => false,
                    'period' => null,
                    'short_leave_slot' => null,
                    'reason' => trim((string) ($leave->reason ?? ''))
                        . (trim((string) ($leave->reason ?? '')) !== '' ? ' | ' : '')
                        . "Auto-split from leave #{$leave->id}: {$otherType} {$fromOther} day(s)",
                    'status' => $leave->status === 'HR_Approved' ? 'HR_Approved' : 'Approved',
                ]);
            }

            // This leave row only consumes its own type balance
            $fromBalance = $fromPreferred;
            // Total covered for nopay calc already used $fromPreferred+$fromOther above
            $nopayDays = max(0, round($requested - $fromPreferred - $fromOther, 4));
        }

        $updates = [
            'requested_days' => $requested,
            'leave_balance_days' => $fromBalance,
            'leave_duration' => $fromBalance > 0 ? $fromBalance : 0,
            'nopay_days' => $nopayDays,
            'over_limit' => $nopayDays,
            'nopay_applied' => $nopayDays > 0.0001,
        ];

        // Keep original full/half/short flags from the leave request (do not rewrite from balance amount)

        $nopayRecordId = null;
        if ($nopayDays > 0.0001) {
            $leaveDate = $asOfDate->toDateString();
            $type = 'LEAVE_SHORTFALL';
            $record = NoPayRecord::create([
                'employee_id' => $leave->employee_id,
                'date' => $leaveDate,
                'no_pay_count' => $nopayDays,
                'description' => "Auto NoPay from leave shortfall | Leave #{$leave->id} | "
                    . "Requested {$requested} day(s), leave balance used {$fromBalance}"
                    . ($premarkedNopayOnly ? '' : ' (+ paired Annual/Casual if any)')
                    . ", NoPay {$nopayDays}",
                'status' => 'Approved',
                'processed_by' => Auth::id(),
                'type' => $type,
                'hours' => null,
                'minutes' => null,
            ]);
            $nopayRecordId = $record->id;
            $updates['nopay_record_id'] = $nopayRecordId;
        }

        $leave->update($updates);
    }

    private function revokeLeaveNopay(leave_master $leave): void
    {
        if ($leave->nopay_record_id) {
            NoPayRecord::where('id', $leave->nopay_record_id)->delete();
        }
        $leave->update([
            'nopay_applied' => false,
            'nopay_record_id' => null,
            // Keep nopay_days / over_limit as preview if leave goes back to pending
        ]);
    }

    /**
     * Available days for a leave type, excluding one leave id (the one being approved).
     */
    private function getAvailableDaysForLeaveType(
        $employee,
        $orgAssignment,
        string $leaveType,
        Carbon $asOfDate,
        ?int $excludeLeaveId = null
    ): float {
        $year = (int) $asOfDate->year;
        $normalized = strtolower(trim($leaveType));

        $manualBalances = \App\Models\EmployeeLeaveBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get();

        $entitled = 0.0;
        $displayType = $leaveType;

        if ($manualBalances->isNotEmpty()) {
            $match = $manualBalances->first(function ($row) use ($normalized) {
                return strtolower(trim((string) $row->leave_type)) === $normalized;
            });
            if (!$match) {
                return 0.0;
            }
            $entitled = (float) $match->entitled_days;
            $displayType = $match->leave_type;
        } elseif ($this->isActAccrualEnabled($asOfDate) && $orgAssignment && $orgAssignment->date_of_joining) {
            $joinDate = Carbon::parse($orgAssignment->date_of_joining);
            $law = app(\App\Services\ShopAndOfficeLeaveCalculator::class)->calculate($joinDate, $asOfDate);
            if (str_contains($normalized, 'casual')) {
                $entitled = (float) $law['casual_days'];
                $displayType = 'Casual Leave';
            } elseif (str_contains($normalized, 'annual')) {
                $entitled = (float) $law['annual_days'];
                $displayType = 'Annual Leave';
            } else {
                return 0.0;
            }
        } else {
            return 0.0;
        }

        $used = $this->getUsedLeaveDays($employee->id, $displayType, $year, $excludeLeaveId);
        return max(0, round($entitled - $used, 4));
    }
}


/*
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\leave_master;
use App\Models\employee;
use App\Models\LeaveSetting;
use Illuminate\Support\Facades\Validator;
use App\Mail\LeaveApprovedMail;
use App\Mail\LeaveRejectedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class LeaveMasterController extends Controller
{
    public function index()
    {
        $leaveMasters = leave_master::with('employee')->get();
        return response()->json($leaveMasters);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'reporting_date' => 'required|date',
            'leave_type' => 'required|string|max:255',
            'leave_date' => 'nullable|date',
            'leave_from' => 'nullable|date',
            'leave_to' => 'nullable|date|after_or_equal:leave_from',
            'period' => 'nullable|string|max:255',
            'is_half_day' => 'nullable|boolean',
            'is_short_leave' => 'nullable|boolean',
            'short_leave_slot' => 'nullable|string|max:255',
            'cancel_from' => 'nullable|date',
            'cancel_to' => 'nullable|date|after_or_equal:cancel_from',
            'reason' => 'nullable|string|max:1000',
            'status' => 'required|in:Pending,Approved,HR_Approved,Rejected',
            'force_continue' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $duplicateCheck = $this->checkForDuplicateLeave($request);
        if ($duplicateCheck) {
            return response()->json([
                'message' => $duplicateCheck,
                'duplicate_found' => true
            ], 422);
        }

        $employee = employee::with('organizationAssignment')->findOrFail($request->employee_id);
        $orgAssignment = $employee->organizationAssignment;

        // හරියටම Boolean විදිහට අල්ලගන්නවා
        $isHalfDay = filter_var($request->is_half_day, FILTER_VALIDATE_BOOLEAN);
        $isShortLeave = filter_var($request->is_short_leave, FILTER_VALIDATE_BOOLEAN);

        $requestedDurationInDays = 0;
        if ($request->filled('leave_from') && $request->filled('leave_to')) {
            $from = new \DateTime($request->leave_from);
            $to = new \DateTime($request->leave_to);
            $requestedDurationInDays = $from->diff($to)->days + 1;
        } elseif ($request->filled('leave_date')) {
            $requestedDurationInDays = 1;
        }

        // Half Day හෝ Short Leave ද කියලා බලලා Duration එක හරියටම දෙනවා
        if ($isHalfDay) {
            $requestedDurationInDays = 0.5;
        } elseif ($isShortLeave) {
            $requestedDurationInDays = 0.25;
        }

        $overLimitInfo = null;

        // Check if employee is in probationary period
        if ($orgAssignment && $orgAssignment->probationary_period) {
            $requestDate = $request->filled('leave_date')
                ? Carbon::parse($request->leave_date)
                : ($request->filled('leave_from') ? Carbon::parse($request->leave_from) : now());

            $leaveBalance = $this->calculateProbationaryLeaveBalance($request->employee_id, $requestDate);

            // Calculate requested half-days (0.25 days = 0.5 half-days, 0.5 days = 1 half-day)
            $requestedHalfDays = $requestedDurationInDays * 2;

            // Probation අය Full Day ඉල්ලුවොත්
            if ($requestedDurationInDays > 0.5) {
                if (!$request->boolean('force_continue')) {
                    return response()->json([
                        'message' => 'Employees in probation period can only take half-day or short leaves at a time',
                        'limit_exceeded' => true,
                        'continue_allowed' => true
                    ], 422);
                }

                if ($leaveBalance['available_half_days'] < 1) {
                    if (!$request->boolean('force_continue')) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' . $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }
                    $overLimitInfo = ['reason' => 'probation_no_balance', 'amount' => $requestedDurationInDays];
                } else {
                    $validHalfDays = min($leaveBalance['available_half_days'], $requestedHalfDays);
                    $overLimitHalfDays = $requestedHalfDays - $validHalfDays;
                    $overLimitDuration = $overLimitHalfDays / 2;

                    if ($overLimitDuration > 0) {
                        $overLimitInfo = ['reason' => 'probation_partial_balance', 'amount' => $overLimitDuration];
                    }
                }
            } else {
                // Short Leave හෝ Half Day ඉල්ලුවොත් Balance එක තියෙනවද බලනවා
                if ($leaveBalance['available_half_days'] < ceil($requestedHalfDays)) {
                    if (!$request->boolean('force_continue')) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' . $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }
                    $overLimitInfo = ['reason' => 'probation_no_balance', 'amount' => $requestedDurationInDays];
                }
            }
        }

        $data = $request->all();
        $data['is_half_day'] = $isHalfDay;
        $data['is_short_leave'] = $isShortLeave;
        $data['leave_duration'] = $requestedDurationInDays; // හරියටම 0.25 හරි 0.5 හරි යනවා
        $data['period'] = $request->input('period');
        $data['short_leave_slot'] = $request->input('short_leave_slot');

        if ($overLimitInfo) {
            if ($overLimitInfo['reason'] === 'probation_full_day') {
                $data['leave_duration'] = $requestedDurationInDays / 2;
            } elseif ($overLimitInfo['reason'] === 'probation_monthly_limit' || $overLimitInfo['reason'] === 'probation_no_balance') {
                $data['leave_duration'] = 0;
            } elseif ($overLimitInfo['reason'] === 'probation_partial_balance') {
                $data['leave_duration'] = max(0, $requestedDurationInDays - $overLimitInfo['amount']);
            }
            $data['over_limit'] = $overLimitInfo['amount'];
        } else {
            $data['over_limit'] = 0;
        }

        $leaveMaster = leave_master::create($data);
        return response()->json($leaveMaster, 201);
    }

    public function show(string $id)
    {
        $leaveMaster = leave_master::where("employee_id", $id)->get();
        return response()->json($leaveMaster);
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'sometimes|exists:employees,id',
            'reporting_date' => 'sometimes|date',
            'leave_type' => 'sometimes|string|max:255',
            'leave_date' => 'nullable|date',
            'leave_from' => 'sometimes|date',
            'leave_to' => 'sometimes|date|after_or_equal:leave_from',
            'period' => 'nullable|string|max:255',
            'is_half_day' => 'nullable|boolean',
            'is_short_leave' => 'nullable|boolean',
            'short_leave_slot' => 'nullable|string|max:255',
            'cancel_from' => 'nullable|date',
            'cancel_to' => 'nullable|date|after_or_equal:cancel_from',
            'reason' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:Pending,Approved,HR_Approved,Rejected',
            'force_continue' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $leaveMaster = leave_master::findOrFail($id);

        $duplicateCheck = $this->checkForDuplicateLeave($request, $id);
        if ($duplicateCheck) {
            return response()->json([
                'message' => $duplicateCheck,
                'duplicate_found' => true
            ], 422);
        }

        $employee = employee::with('organizationAssignment')->findOrFail($leaveMaster->employee_id);
        $orgAssignment = $employee->organizationAssignment;

        $isHalfDay = filter_var($request->is_half_day, FILTER_VALIDATE_BOOLEAN);
        $isShortLeave = filter_var($request->is_short_leave, FILTER_VALIDATE_BOOLEAN);

        $fullDuration = 0;
        if ($request->filled('leave_from') && $request->filled('leave_to')) {
            $from = new \DateTime($request->leave_from);
            $to = new \DateTime($request->leave_to);
            $fullDuration = $from->diff($to)->days + 1;
        } elseif ($request->filled('leave_date')) {
            $fullDuration = 1;
        }

        $requestedDurationInDays = $fullDuration;
        if ($isHalfDay) {
            $requestedDurationInDays = 0.5;
        } elseif ($isShortLeave) {
            $requestedDurationInDays = 0.25;
        }

        $overLimitInfo = null;

        if ($orgAssignment && $orgAssignment->probationary_period) {
            $requestDate = $request->filled('leave_date')
                ? Carbon::parse($request->leave_date)
                : ($request->filled('leave_from') ? Carbon::parse($request->leave_from) : now());

            $leaveBalance = $this->calculateProbationaryLeaveBalance($request->employee_id, $requestDate);

            $requestedHalfDays = $requestedDurationInDays * 2;

            if ($requestedDurationInDays > 0.5) {
                if (!$request->boolean('force_continue')) {
                    return response()->json([
                        'message' => 'Employees in probation period can only take half-day or short leaves at a time',
                        'limit_exceeded' => true,
                        'continue_allowed' => true
                    ], 422);
                }

                if ($leaveBalance['available_half_days'] < 1) {
                    if (!$request->boolean('force_continue')) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' . $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }
                    $overLimitInfo = ['reason' => 'probation_no_balance', 'amount' => $requestedDurationInDays];
                } else {
                    $validHalfDays = min($leaveBalance['available_half_days'], $requestedHalfDays);
                    $overLimitHalfDays = $requestedHalfDays - $validHalfDays;
                    $overLimitDuration = $overLimitHalfDays / 2;

                    if ($overLimitDuration > 0) {
                        $overLimitInfo = ['reason' => 'probation_partial_balance', 'amount' => $overLimitDuration];
                    }
                }
            } else {
                if ($leaveBalance['available_half_days'] < ceil($requestedHalfDays)) {
                    if (!$request->boolean('force_continue')) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' . $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }
                    $overLimitInfo = ['reason' => 'probation_no_balance', 'amount' => $requestedDurationInDays];
                }
            }
        }

        $data = $request->all();
        $data['is_half_day'] = $isHalfDay;
        $data['is_short_leave'] = $isShortLeave;
        $data['leave_duration'] = $requestedDurationInDays;
        $data['period'] = $request->input('period');
        $data['short_leave_slot'] = $request->input('short_leave_slot');

        if ($overLimitInfo) {
            if ($overLimitInfo['reason'] === 'probation_full_day') {
                $data['leave_duration'] = $requestedDurationInDays / 2;
            } elseif ($overLimitInfo['reason'] === 'probation_monthly_limit' || $overLimitInfo['reason'] === 'probation_no_balance') {
                $data['leave_duration'] = 0;
            } elseif ($overLimitInfo['reason'] === 'probation_partial_balance') {
                $data['leave_duration'] = max(0, $requestedDurationInDays - $overLimitInfo['amount']);
            }
            $data['over_limit'] = $overLimitInfo['amount'];
        } else {
            $data['over_limit'] = 0;
        }

        $leaveMaster->update($data);
        return response()->json($leaveMaster);
    }

    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:Pending,Approved,HR_Approved,Rejected',
            'rejection_reason' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $leaveMaster = leave_master::with('employee.contactDetail')->findOrFail($id);
        $oldStatus = $leaveMaster->status;

        $leaveMaster->update([
            'status' => $request->status,
            'rejection_reason' => $request->rejection_reason
        ]);

        if ($oldStatus !== $request->status) {
            $this->sendStatusEmail($leaveMaster, $request->status, $request->rejection_reason);
        }

        return response()->json([
            'message' => 'Leave status updated successfully',
            'leave' => $leaveMaster
        ]);
    }

    private function sendStatusEmail($leave, $status, $rejectionReason = null)
    {
        $employee = $leave->employee;

        if (!$employee->contactDetail || !$employee->contactDetail->email) {
            Log::warning('Cannot send email notification: Employee contact details missing');
            return;
        }

        try {
            if ($status === 'Approved' || $status === 'HR_Approved') {
                Mail::to($employee->contactDetail->email)->send(new LeaveApprovedMail($leave, $employee));
            } elseif ($status === 'Rejected') {
                Mail::to($employee->contactDetail->email)->send(new LeaveRejectedMail($leave, $employee, $rejectionReason));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send leave status email', ['error' => $e->getMessage()]);
        }
    }

    public function destroy(string $id)
    {
        $leaveMaster = leave_master::findOrFail($id);
        $leaveMaster->delete();
        return response()->json(null, 204);
    }

    public function getLeaveRecordCountsByEmployee($employeeId)
    {
        $leaveCounts = leave_master::where('employee_id', $employeeId)
            ->selectRaw('
            leave_type,
            SUM(CASE WHEN (is_half_day = 0 AND is_short_leave = 0) AND status != "Rejected" THEN COALESCE(leave_duration, 1) ELSE 0 END) as approved_full_days,
            SUM(CASE WHEN is_half_day = 1 AND status != "Rejected" THEN 0.5 ELSE 0 END) as approved_half_days,
            SUM(CASE WHEN is_short_leave = 1 AND status != "Rejected" THEN 0.25 ELSE 0 END) as approved_short_leaves,
            SUM(CASE WHEN (is_half_day = 0 AND is_short_leave = 0) AND status = "Rejected" THEN COALESCE(leave_duration, 1) ELSE 0 END) as rejected_full_days,
            SUM(CASE WHEN is_half_day = 1 AND status = "Rejected" THEN 0.5 ELSE 0 END) as rejected_half_days,
            SUM(CASE WHEN is_short_leave = 1 AND status = "Rejected" THEN 0.25 ELSE 0 END) as rejected_short_leaves
        ')
            ->groupBy('leave_type')
            ->get();

        return response()->json($leaveCounts);
    }

    public function getPendingLeaveRecords()
    {
        $pendingLeaves = leave_master::with('employee')->where('status', 'Pending')->get();
        return response()->json($pendingLeaves);
    }

    public function getApprovedLeaveRecords()
    {
        $approvedLeaves = leave_master::with('employee')->where('status', 'Approved')->get();
        return response()->json($approvedLeaves);
    }

    public function getHRApprovedLeaveRecords()
    {
        $hrApprovedLeaves = leave_master::with('employee')->where('status', 'HR_Approved')->get();
        return response()->json($hrApprovedLeaves);
    }

    public function getRejectedLeaveRecords()
    {
        $rejectedLeaves = leave_master::with('employee')->where('status', 'Rejected')->get();
        return response()->json($rejectedLeaves);
    }

    public function getApprovedLeavesByDate(Request $request)
    {
        $date = $request->query('date');
        if (!$date) return response()->json(['message' => 'Date is required'], 422);

        $leaveCount = leave_master::where('status', 'Approved')
            ->where(function ($query) use ($date) {
                $query->where('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->where('leave_from', '<=', $date)->where('leave_to', '>=', $date);
                    });
            })->count();

        return response()->json($leaveCount);
    }

    private function calculateProbationaryLeaveBalance($employeeId, $requestDate = null)
    {
        $requestDate = $requestDate ?? now();
        $currentYear = $requestDate->year;

        $monthsElapsed = min($requestDate->month, 12);
        $maxAccruedLeaves = min($monthsElapsed, 7);

        $usedLeaves = leave_master::where('employee_id', $employeeId)
            ->where('status', '!=', 'Rejected')
            ->whereYear('reporting_date', $currentYear)
            ->get();

        $usedHalfDays = 0;
        foreach ($usedLeaves as $leave) {
            if ($leave->is_short_leave) {
                $usedHalfDays += 0.5;
            } elseif ($leave->is_half_day) {
                $usedHalfDays += 1;
            } else {
                $usedHalfDays += ($leave->leave_duration * 2);
            }
        }

        $availableHalfDays = max(0, $maxAccruedLeaves - $usedHalfDays);

        return [
            'available_half_days' => $availableHalfDays,
            'used_half_days' => $usedHalfDays,
            'max_accrued' => $maxAccruedLeaves
        ];
    }

    private function checkForDuplicateLeave($request, $excludeId = null)
    {
        $employeeId = $request->employee_id;

        if ($request->leave_date) {
            $query = leave_master::where('employee_id', $employeeId)
                ->where('status', '!=', 'Rejected')
                ->where(function($query) use ($request) {
                    $query->where('leave_date', $request->leave_date)
                          ->orWhere(function($q) use ($request) {
                              $q->where('leave_from', '<=', $request->leave_date)
                                ->where('leave_to', '>=', $request->leave_date);
                          });
                });

            if ($excludeId) $query->where('id', '!=', $excludeId);
            $existing = $query->first();

            if ($existing) {
                $existingDateStr = $existing->leave_date ? $existing->leave_date : "{$existing->leave_from} to {$existing->leave_to}";
                return "You already have a leave request for {$request->leave_date}. Existing leave: {$existingDateStr} ({$existing->status})";
            }
        }

        if ($request->leave_from && $request->leave_to) {
            $query = leave_master::where('employee_id', $employeeId)
                ->where('status', '!=', 'Rejected')
                ->where(function($query) use ($request) {
                    $query->where(function($q) use ($request) {
                        $q->whereBetween('leave_date', [$request->leave_from, $request->leave_to]);
                    })->orWhere(function($q) use ($request) {
                        $q->where(function($subQ) use ($request) {
                            $subQ->where('leave_from', '<=', $request->leave_to)
                                 ->where('leave_to', '>=', $request->leave_from);
                        });
                    });
                });

            if ($excludeId) $query->where('id', '!=', $excludeId);
            $existing = $query->first();

            if ($existing) {
                $existingDateStr = $existing->leave_date ? $existing->leave_date : "{$existing->leave_from} to {$existing->leave_to}";
                return "Your requested leave period overlaps with an existing leave: {$existingDateStr} ({$existing->status})";
            }
        }

        return null;
    }

    public function getLeaveEligibility(Request $request)
    {
        $empNumber = $request->query('emp_number');
        $employeeId = $request->query('employee_id');
        $requestedDateStr = $request->query('date');

        if (!$empNumber && !$employeeId) {
            return response()->json(['message' => 'Either emp_number or employee_id is required'], 422);
        }

        if ($empNumber) {
            $employee = employee::with('organizationAssignment')->where('attendance_employee_no', $empNumber)->first();
        } else {
            $employee = employee::with('organizationAssignment')->find($employeeId);
        }

        if (!$employee) return response()->json(['message' => 'Employee not found'], 404);

        $orgAssignment = $employee->organizationAssignment;

        if (!$orgAssignment || !$orgAssignment->date_of_joining) {
            return response()->json([
                'message' => 'Date of Joining is not set for this employee. Cannot calculate leaves.'
            ], 400);
        }

        $targetDate = $requestedDateStr ? Carbon::parse($requestedDateStr) : Carbon::now();
        $currentYear = $targetDate->year;

        $joinDate = Carbon::parse($orgAssignment->date_of_joining);
        $joinYear = $joinDate->year;
        $joinMonth = $joinDate->month;

        $totalAnnualLeaves = 0;
        $totalCasualLeaves = 0;
        $isFirstYear = false;
        $note = '';

        // ==============================================================
        // SRI LANKAN SHOP & OFFICE ACT - LEAVE CALCULATION
        // ==============================================================

        if ($currentYear == $joinYear) {
            // 1. පළමු වසර (First Year of Joining)
            $isFirstYear = true;
            $totalAnnualLeaves = 0; // පළමු වසරට Annual Leaves නැත

            // සෑම මාස 2ක සේවයකටම දින 1ක් බැගින් Casual Leaves හිමිවේ
            $monthsCompleted = $joinDate->diffInMonths($targetDate);
            $totalCasualLeaves = floor($monthsCompleted / 2);
            $note = 'First Year: 1 Casual Leave per 2 completed months. No Annual Leaves.';

        } elseif ($currentYear == $joinYear + 1) {
            // 2. දෙවන වසර (Second Year of Service)
            $totalCasualLeaves = 7; // දෙවන වසරේ සිට සම්පූර්ණ Casual 7 හිමිවේ
            $note = 'Second Year: 7 Casual Leaves. Annual leaves based on joined month.';

            // පළමු වසරේ බැඳුණු මාසය අනුව Annual Leaves තීරණය වීම
            if ($joinMonth >= 1 && $joinMonth <= 3) {
                $totalAnnualLeaves = 14;
            } elseif ($joinMonth >= 4 && $joinMonth <= 6) {
                $totalAnnualLeaves = 10;
            } elseif ($joinMonth >= 7 && $joinMonth <= 9) {
                $totalAnnualLeaves = 7;
            } elseif ($joinMonth >= 10 && $joinMonth <= 12) {
                $totalAnnualLeaves = 4;
            }

        } else {
            // 3. තුන්වන වසරේ සිට ඉදිරියට (Third Year Onwards)
            $totalCasualLeaves = 7;
            $totalAnnualLeaves = 14;
            $note = 'Standard leaves: 14 Annual and 7 Casual leaves.';
        }

        // ==============================================================

        $eligibleLeaves = [];

        // --- Casual Leave දත්ත සකස් කිරීම ---
        if ($totalCasualLeaves > 0) {
            $usedCasual = $this->getUsedLeaveDays($employee->id, 'Casual Leave', $currentYear);
            $eligibleLeaves[] = [
                'leave_type' => 'Casual Leave',
                'total_days' => $totalCasualLeaves,
                'used_days' => $usedCasual,
                'available_days' => max(0, $totalCasualLeaves - $usedCasual),
                'is_half_day_only' => false,
                'note' => $isFirstYear ? "Available: $totalCasualLeaves (Earned 1 per 2 months)" : "Standard 7 Days"
            ];
        }

        // --- Annual Leave දත්ත සකස් කිරීම ---
        if ($totalAnnualLeaves > 0) {
            $usedAnnual = $this->getUsedLeaveDays($employee->id, 'Annual Leave', $currentYear);
            $eligibleLeaves[] = [
                'leave_type' => 'Annual Leave',
                'total_days' => $totalAnnualLeaves,
                'used_days' => $usedAnnual,
                'available_days' => max(0, $totalAnnualLeaves - $usedAnnual),
                'is_half_day_only' => false,
                'note' => $note
            ];
        }

        return response()->json([
            'employee_id' => $employee->id,
            'emp_number' => $employee->attendance_employee_no,
            'employee_name' => $employee->display_name ?? $employee->name_with_initials,
            'join_date' => $orgAssignment->date_of_joining,
            'is_first_year' => $isFirstYear,
            'eligible_leaves' => $eligibleLeaves,
        ]);
    }

    private function getUsedLeaveDays($employeeId, $leaveType, $year)
    {
        $leaves = leave_master::where('employee_id', $employeeId)
            ->where('leave_type', $leaveType)
            ->whereIn('status', ['Approved', 'HR_Approved', 'Pending'])
            ->where(function ($query) use ($year) {
                $query->whereYear('leave_date', $year)
                    ->orWhereYear('leave_from', $year);
            })
            ->get();

        $totalDays = 0;
        foreach ($leaves as $leave) {
            if ($leave->is_short_leave) {
                $totalDays += 0.25;
            } elseif ($leave->is_half_day) {
                $totalDays += 0.5;
            } else {
                $totalDays += $leave->leave_duration ?? 1;
            }
        }

        return $totalDays;
    }

    private function getUsedLeaveDaysInQuarter($employeeId, $leaveType, $year, $startMonth, $endMonth)
    {
        $startDate = Carbon::create($year, $startMonth, 1)->startOfMonth();
        $endDate = Carbon::create($year, $endMonth, 1)->endOfMonth();

        $leaves = leave_master::where('employee_id', $employeeId)
            ->where('leave_type', $leaveType)
            ->whereIn('status', ['Approved', 'HR_Approved', 'Pending'])
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('leave_date', [$startDate, $endDate])
                    ->orWhere(function ($q) use ($startDate, $endDate) {
                        $q->whereBetween('leave_from', [$startDate, $endDate]);
                    });
            })
            ->get();

        $totalDays = 0;
        foreach ($leaves as $leave) {
            if ($leave->is_short_leave) {
                $totalDays += 0.25;
            } elseif ($leave->is_half_day) {
                $totalDays += 0.5;
            } else {
                $totalDays += $leave->leave_duration ?? 1;
            }
        }

        return $totalDays;
    }
}






//=============================================================================================



/*
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\leave_master;
use App\Models\employee;
use App\Models\LeaveSetting;
use Illuminate\Support\Facades\Validator;
use App\Mail\LeaveApprovedMail;
use App\Mail\LeaveRejectedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class LeaveMasterController extends Controller
{
    public function index()
    {
        $leaveMasters = leave_master::with('employee')->get();
        return response()->json($leaveMasters);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'reporting_date' => 'required|date',
            'leave_type' => 'required|string|max:255',
            'leave_date' => 'nullable|date',
            'leave_from' => 'nullable|date',
            'leave_to' => 'nullable|date|after_or_equal:leave_from',
            'period' => 'nullable|string|max:255',
            'is_half_day' => 'nullable|boolean',
            'is_short_leave' => 'nullable|boolean',
            'short_leave_slot' => 'nullable|string|max:255',
            'cancel_from' => 'nullable|date',
            'cancel_to' => 'nullable|date|after_or_equal:cancel_from',
            'reason' => 'nullable|string|max:1000',
            'status' => 'required|in:Pending,Approved,HR_Approved,Rejected',
            'force_continue' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $duplicateCheck = $this->checkForDuplicateLeave($request);
        if ($duplicateCheck) {
            return response()->json([
                'message' => $duplicateCheck,
                'duplicate_found' => true
            ], 422);
        }

        $employee = employee::with('organizationAssignment')->findOrFail($request->employee_id);
        $orgAssignment = $employee->organizationAssignment;

        // හරියටම Boolean විදිහට අල්ලගන්නවා
        $isHalfDay = filter_var($request->is_half_day, FILTER_VALIDATE_BOOLEAN);
        $isShortLeave = filter_var($request->is_short_leave, FILTER_VALIDATE_BOOLEAN);

        $requestedDurationInDays = 0;
        if ($request->filled('leave_from') && $request->filled('leave_to')) {
            $from = new \DateTime($request->leave_from);
            $to = new \DateTime($request->leave_to);
            $requestedDurationInDays = $from->diff($to)->days + 1;
        } elseif ($request->filled('leave_date')) {
            $requestedDurationInDays = 1;
        }

        // Half Day හෝ Short Leave ද කියලා බලලා Duration එක හරියටම දෙනවා
        if ($isHalfDay) {
            $requestedDurationInDays = 0.5;
        } elseif ($isShortLeave) {
            $requestedDurationInDays = 0.25;
        }

        $overLimitInfo = null;

        // Check if employee is in probationary period
        if ($orgAssignment && $orgAssignment->probationary_period) {
            $requestDate = $request->filled('leave_date')
                ? Carbon::parse($request->leave_date)
                : ($request->filled('leave_from') ? Carbon::parse($request->leave_from) : now());

            $leaveBalance = $this->calculateProbationaryLeaveBalance($request->employee_id, $requestDate);

            // Calculate requested half-days (0.25 days = 0.5 half-days, 0.5 days = 1 half-day)
            $requestedHalfDays = $requestedDurationInDays * 2;

            // Probation අය Full Day ඉල්ලුවොත්
            if ($requestedDurationInDays > 0.5) {
                if (!$request->boolean('force_continue')) {
                    return response()->json([
                        'message' => 'Employees in probation period can only take half-day or short leaves at a time',
                        'limit_exceeded' => true,
                        'continue_allowed' => true
                    ], 422);
                }

                if ($leaveBalance['available_half_days'] < 1) {
                    if (!$request->boolean('force_continue')) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' . $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }
                    $overLimitInfo = ['reason' => 'probation_no_balance', 'amount' => $requestedDurationInDays];
                } else {
                    $validHalfDays = min($leaveBalance['available_half_days'], $requestedHalfDays);
                    $overLimitHalfDays = $requestedHalfDays - $validHalfDays;
                    $overLimitDuration = $overLimitHalfDays / 2;

                    if ($overLimitDuration > 0) {
                        $overLimitInfo = ['reason' => 'probation_partial_balance', 'amount' => $overLimitDuration];
                    }
                }
            } else {
                // Short Leave හෝ Half Day ඉල්ලුවොත් Balance එක තියෙනවද බලනවා
                if ($leaveBalance['available_half_days'] < ceil($requestedHalfDays)) {
                    if (!$request->boolean('force_continue')) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' . $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }
                    $overLimitInfo = ['reason' => 'probation_no_balance', 'amount' => $requestedDurationInDays];
                }
            }
        }

        $data = $request->all();
        $data['is_half_day'] = $isHalfDay;
        $data['is_short_leave'] = $isShortLeave;
        $data['leave_duration'] = $requestedDurationInDays; // හරියටම 0.25 හරි 0.5 හරි යනවා
        $data['period'] = $request->input('period');
        $data['short_leave_slot'] = $request->input('short_leave_slot');

        if ($overLimitInfo) {
            if ($overLimitInfo['reason'] === 'probation_full_day') {
                $data['leave_duration'] = $requestedDurationInDays / 2;
            } elseif ($overLimitInfo['reason'] === 'probation_monthly_limit' || $overLimitInfo['reason'] === 'probation_no_balance') {
                $data['leave_duration'] = 0;
            } elseif ($overLimitInfo['reason'] === 'probation_partial_balance') {
                $data['leave_duration'] = max(0, $requestedDurationInDays - $overLimitInfo['amount']);
            }
            $data['over_limit'] = $overLimitInfo['amount'];
        } else {
            $data['over_limit'] = 0;
        }

        $leaveMaster = leave_master::create($data);
        return response()->json($leaveMaster, 201);
    }

    public function show(string $id)
    {
        $leaveMaster = leave_master::where("employee_id", $id)->get();
        return response()->json($leaveMaster);
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'sometimes|exists:employees,id',
            'reporting_date' => 'sometimes|date',
            'leave_type' => 'sometimes|string|max:255',
            'leave_date' => 'nullable|date',
            'leave_from' => 'sometimes|date',
            'leave_to' => 'sometimes|date|after_or_equal:leave_from',
            'period' => 'nullable|string|max:255',
            'is_half_day' => 'nullable|boolean',
            'is_short_leave' => 'nullable|boolean',
            'short_leave_slot' => 'nullable|string|max:255',
            'cancel_from' => 'nullable|date',
            'cancel_to' => 'nullable|date|after_or_equal:cancel_from',
            'reason' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:Pending,Approved,HR_Approved,Rejected',
            'force_continue' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $leaveMaster = leave_master::findOrFail($id);

        $duplicateCheck = $this->checkForDuplicateLeave($request, $id);
        if ($duplicateCheck) {
            return response()->json([
                'message' => $duplicateCheck,
                'duplicate_found' => true
            ], 422);
        }

        $employee = employee::with('organizationAssignment')->findOrFail($leaveMaster->employee_id);
        $orgAssignment = $employee->organizationAssignment;

        $isHalfDay = filter_var($request->is_half_day, FILTER_VALIDATE_BOOLEAN);
        $isShortLeave = filter_var($request->is_short_leave, FILTER_VALIDATE_BOOLEAN);

        $fullDuration = 0;
        if ($request->filled('leave_from') && $request->filled('leave_to')) {
            $from = new \DateTime($request->leave_from);
            $to = new \DateTime($request->leave_to);
            $fullDuration = $from->diff($to)->days + 1;
        } elseif ($request->filled('leave_date')) {
            $fullDuration = 1;
        }

        $requestedDurationInDays = $fullDuration;
        if ($isHalfDay) {
            $requestedDurationInDays = 0.5;
        } elseif ($isShortLeave) {
            $requestedDurationInDays = 0.25;
        }

        $overLimitInfo = null;

        if ($orgAssignment && $orgAssignment->probationary_period) {
            $requestDate = $request->filled('leave_date')
                ? Carbon::parse($request->leave_date)
                : ($request->filled('leave_from') ? Carbon::parse($request->leave_from) : now());

            $leaveBalance = $this->calculateProbationaryLeaveBalance($request->employee_id, $requestDate);

            $requestedHalfDays = $requestedDurationInDays * 2;

            if ($requestedDurationInDays > 0.5) {
                if (!$request->boolean('force_continue')) {
                    return response()->json([
                        'message' => 'Employees in probation period can only take half-day or short leaves at a time',
                        'limit_exceeded' => true,
                        'continue_allowed' => true
                    ], 422);
                }

                if ($leaveBalance['available_half_days'] < 1) {
                    if (!$request->boolean('force_continue')) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' . $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }
                    $overLimitInfo = ['reason' => 'probation_no_balance', 'amount' => $requestedDurationInDays];
                } else {
                    $validHalfDays = min($leaveBalance['available_half_days'], $requestedHalfDays);
                    $overLimitHalfDays = $requestedHalfDays - $validHalfDays;
                    $overLimitDuration = $overLimitHalfDays / 2;

                    if ($overLimitDuration > 0) {
                        $overLimitInfo = ['reason' => 'probation_partial_balance', 'amount' => $overLimitDuration];
                    }
                }
            } else {
                if ($leaveBalance['available_half_days'] < ceil($requestedHalfDays)) {
                    if (!$request->boolean('force_continue')) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' . $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }
                    $overLimitInfo = ['reason' => 'probation_no_balance', 'amount' => $requestedDurationInDays];
                }
            }
        }

        $data = $request->all();
        $data['is_half_day'] = $isHalfDay;
        $data['is_short_leave'] = $isShortLeave;
        $data['leave_duration'] = $requestedDurationInDays;
        $data['period'] = $request->input('period');
        $data['short_leave_slot'] = $request->input('short_leave_slot');

        if ($overLimitInfo) {
            if ($overLimitInfo['reason'] === 'probation_full_day') {
                $data['leave_duration'] = $requestedDurationInDays / 2;
            } elseif ($overLimitInfo['reason'] === 'probation_monthly_limit' || $overLimitInfo['reason'] === 'probation_no_balance') {
                $data['leave_duration'] = 0;
            } elseif ($overLimitInfo['reason'] === 'probation_partial_balance') {
                $data['leave_duration'] = max(0, $requestedDurationInDays - $overLimitInfo['amount']);
            }
            $data['over_limit'] = $overLimitInfo['amount'];
        } else {
            $data['over_limit'] = 0;
        }

        $leaveMaster->update($data);
        return response()->json($leaveMaster);
    }

    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:Pending,Approved,HR_Approved,Rejected',
            'rejection_reason' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $leaveMaster = leave_master::with('employee.contactDetail')->findOrFail($id);
        $oldStatus = $leaveMaster->status;

        $leaveMaster->update([
            'status' => $request->status,
            'rejection_reason' => $request->rejection_reason
        ]);

        if ($oldStatus !== $request->status) {
            $this->sendStatusEmail($leaveMaster, $request->status, $request->rejection_reason);
        }

        return response()->json([
            'message' => 'Leave status updated successfully',
            'leave' => $leaveMaster
        ]);
    }

    private function sendStatusEmail($leave, $status, $rejectionReason = null)
    {
        $employee = $leave->employee;

        if (!$employee->contactDetail || !$employee->contactDetail->email) {
            Log::warning('Cannot send email notification: Employee contact details missing');
            return;
        }

        try {
            if ($status === 'Approved' || $status === 'HR_Approved') {
                Mail::to($employee->contactDetail->email)->send(new LeaveApprovedMail($leave, $employee));
            } elseif ($status === 'Rejected') {
                Mail::to($employee->contactDetail->email)->send(new LeaveRejectedMail($leave, $employee, $rejectionReason));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send leave status email', ['error' => $e->getMessage()]);
        }
    }

    public function destroy(string $id)
    {
        $leaveMaster = leave_master::findOrFail($id);
        $leaveMaster->delete();
        return response()->json(null, 204);
    }

    public function getLeaveRecordCountsByEmployee($employeeId)
    {
        $leaveCounts = leave_master::where('employee_id', $employeeId)
            ->selectRaw('
            leave_type,
            SUM(CASE WHEN (is_half_day = 0 AND is_short_leave = 0) AND status != "Rejected" THEN COALESCE(leave_duration, 1) ELSE 0 END) as approved_full_days,
            SUM(CASE WHEN is_half_day = 1 AND status != "Rejected" THEN 0.5 ELSE 0 END) as approved_half_days,
            SUM(CASE WHEN is_short_leave = 1 AND status != "Rejected" THEN 0.25 ELSE 0 END) as approved_short_leaves,
            SUM(CASE WHEN (is_half_day = 0 AND is_short_leave = 0) AND status = "Rejected" THEN COALESCE(leave_duration, 1) ELSE 0 END) as rejected_full_days,
            SUM(CASE WHEN is_half_day = 1 AND status = "Rejected" THEN 0.5 ELSE 0 END) as rejected_half_days,
            SUM(CASE WHEN is_short_leave = 1 AND status = "Rejected" THEN 0.25 ELSE 0 END) as rejected_short_leaves
        ')
            ->groupBy('leave_type')
            ->get();

        return response()->json($leaveCounts);
    }

    public function getPendingLeaveRecords()
    {
        $pendingLeaves = leave_master::with('employee')->where('status', 'Pending')->get();
        return response()->json($pendingLeaves);
    }

    public function getApprovedLeaveRecords()
    {
        $approvedLeaves = leave_master::with('employee')->where('status', 'Approved')->get();
        return response()->json($approvedLeaves);
    }

    public function getHRApprovedLeaveRecords()
    {
        $hrApprovedLeaves = leave_master::with('employee')->where('status', 'HR_Approved')->get();
        return response()->json($hrApprovedLeaves);
    }

    public function getRejectedLeaveRecords()
    {
        $rejectedLeaves = leave_master::with('employee')->where('status', 'Rejected')->get();
        return response()->json($rejectedLeaves);
    }

    public function getApprovedLeavesByDate(Request $request)
    {
        $date = $request->query('date');
        if (!$date) return response()->json(['message' => 'Date is required'], 422);

        $leaveCount = leave_master::where('status', 'Approved')
            ->where(function ($query) use ($date) {
                $query->where('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->where('leave_from', '<=', $date)->where('leave_to', '>=', $date);
                    });
            })->count();

        return response()->json($leaveCount);
    }

    private function calculateProbationaryLeaveBalance($employeeId, $requestDate = null)
    {
        $requestDate = $requestDate ?? now();
        $currentYear = $requestDate->year;

        $monthsElapsed = min($requestDate->month, 12);
        $maxAccruedLeaves = min($monthsElapsed, 7);

        $usedLeaves = leave_master::where('employee_id', $employeeId)
            ->where('status', '!=', 'Rejected')
            ->whereYear('reporting_date', $currentYear)
            ->get();

        $usedHalfDays = 0;
        foreach ($usedLeaves as $leave) {
            if ($leave->is_short_leave) {
                $usedHalfDays += 0.5;
            } elseif ($leave->is_half_day) {
                $usedHalfDays += 1;
            } else {
                $usedHalfDays += ($leave->leave_duration * 2);
            }
        }

        $availableHalfDays = max(0, $maxAccruedLeaves - $usedHalfDays);

        return [
            'available_half_days' => $availableHalfDays,
            'used_half_days' => $usedHalfDays,
            'max_accrued' => $maxAccruedLeaves
        ];
    }

    private function checkForDuplicateLeave($request, $excludeId = null)
    {
        $employeeId = $request->employee_id;

        if ($request->leave_date) {
            $query = leave_master::where('employee_id', $employeeId)
                ->where('status', '!=', 'Rejected')
                ->where(function($query) use ($request) {
                    $query->where('leave_date', $request->leave_date)
                          ->orWhere(function($q) use ($request) {
                              $q->where('leave_from', '<=', $request->leave_date)
                                ->where('leave_to', '>=', $request->leave_date);
                          });
                });

            if ($excludeId) $query->where('id', '!=', $excludeId);
            $existing = $query->first();

            if ($existing) {
                $existingDateStr = $existing->leave_date ? $existing->leave_date : "{$existing->leave_from} to {$existing->leave_to}";
                return "You already have a leave request for {$request->leave_date}. Existing leave: {$existingDateStr} ({$existing->status})";
            }
        }

        if ($request->leave_from && $request->leave_to) {
            $query = leave_master::where('employee_id', $employeeId)
                ->where('status', '!=', 'Rejected')
                ->where(function($query) use ($request) {
                    $query->where(function($q) use ($request) {
                        $q->whereBetween('leave_date', [$request->leave_from, $request->leave_to]);
                    })->orWhere(function($q) use ($request) {
                        $q->where(function($subQ) use ($request) {
                            $subQ->where('leave_from', '<=', $request->leave_to)
                                 ->where('leave_to', '>=', $request->leave_from);
                        });
                    });
                });

            if ($excludeId) $query->where('id', '!=', $excludeId);
            $existing = $query->first();

            if ($existing) {
                $existingDateStr = $existing->leave_date ? $existing->leave_date : "{$existing->leave_from} to {$existing->leave_to}";
                return "Your requested leave period overlaps with an existing leave: {$existingDateStr} ({$existing->status})";
            }
        }

        return null;
    }

    public function getLeaveEligibility(Request $request)
    {
        $empNumber = $request->query('emp_number');
        $employeeId = $request->query('employee_id');
        $requestedDateStr = $request->query('date');

        if (!$empNumber && !$employeeId) {
            return response()->json(['message' => 'Either emp_number or employee_id is required'], 422);
        }

        if ($empNumber) {
            $employee = employee::with('organizationAssignment')->where('attendance_employee_no', $empNumber)->first();
        } else {
            $employee = employee::with('organizationAssignment')->find($employeeId);
        }

        if (!$employee) return response()->json(['message' => 'Employee not found'], 404);

        $orgAssignment = $employee->organizationAssignment;
        $targetDate = $requestedDateStr ? Carbon::parse($requestedDateStr) : Carbon::now();
        $targetYear = $targetDate->year;
        $targetMonth = $targetDate->month;

        $isProbation = $orgAssignment && $orgAssignment->probationary_period;
        $probationStartDate = $orgAssignment->probationary_period_from ?? $orgAssignment->date_of_joining ?? null;
        $probationEndDate = $orgAssignment->probationary_period_to ?? null;

        if ($isProbation && $probationEndDate) {
            if ($targetDate->startOfDay()->greaterThan(Carbon::parse($probationEndDate)->startOfDay())) {
                $isProbation = false;
            }
        }

        if ($isProbation) {
            $leaveBalance = $this->calculateProbationaryLeaveBalance($employee->id, $targetDate);
            $probationSettings = LeaveSetting::with(['quarters.leaveTypes'])->where('employee_type', 'probation')->where('is_active', true)->first();
            $eligibleLeaves = [];

            $totalDays = $probationSettings ? ($probationSettings->annual_leave_days ?? 7) : 7;
            $usedDays = $leaveBalance['used_half_days'] / 2;
            $availableDays = max(0, $totalDays - $usedDays);

            $eligibleLeaves[] = [
                'leave_type' => 'Casual Leave',
                'total_days' => $totalDays,
                'used_days' => $usedDays,
                'available_days' => $availableDays,
                'is_half_day_only' => true,
                'note' => 'Probation employees can only take half-day/short leaves'
            ];

            return response()->json([
                'employee_id' => $employee->id,
                'emp_number' => $employee->attendance_employee_no,
                'employee_name' => $employee->display_name ?? $employee->name_with_initials,
                'is_probation' => true,
                'join_date' => $probationStartDate,
                'probation_end_date' => $probationEndDate,
                'eligible_leaves' => $eligibleLeaves,
                'current_quarter' => null,
                'full_year_access' => false
            ]);
        }

        $joinDate = $orgAssignment ? Carbon::parse($orgAssignment->date_of_joining) : null;
        $joinYear = $joinDate ? $joinDate->year : null;

        $permanentSettings = LeaveSetting::with(['quarters.leaveTypes'])->where('employee_type', 'permanent')->where('is_active', true)->first();

        if (!$permanentSettings) {
            return response()->json([
                'employee_id' => $employee->id,
                'emp_number' => $employee->attendance_employee_no,
                'employee_name' => $employee->display_name ?? $employee->name_with_initials,
                'is_probation' => false,
                'join_date' => $probationStartDate,
                'probation_end_date' => $probationEndDate,
                'eligible_leaves' => [],
                'message' => 'No leave settings configured. Please contact HR.'
            ]);
        }

        $eligibleLeaves = [];
        $currentQuarter = null;
        $fullYearAccess = false;

        if ($joinYear && $joinYear < $targetYear) {
            $fullYearAccess = true;
            $allLeaveTypes = [];
            foreach ($permanentSettings->quarters as $quarter) {
                foreach ($quarter->leaveTypes as $leaveType) {
                    $typeName = $leaveType->name;
                    if (!isset($allLeaveTypes[$typeName])) $allLeaveTypes[$typeName] = 0;
                    $allLeaveTypes[$typeName] += $leaveType->days;
                }
            }

            foreach ($allLeaveTypes as $typeName => $totalDays) {
                $usedDays = $this->getUsedLeaveDays($employee->id, $typeName, $targetYear);
                $eligibleLeaves[] = [
                    'leave_type' => $typeName,
                    'total_days' => $totalDays,
                    'used_days' => $usedDays,
                    'available_days' => max(0, $totalDays - $usedDays),
                    'is_half_day_only' => false,
                    'note' => null
                ];
            }
        } else {
            foreach ($permanentSettings->quarters as $quarter) {
                if ($targetMonth >= $quarter->start_month && $targetMonth <= $quarter->end_month) {
                    $currentQuarter = ['quarter_number' => $quarter->quarter_number, 'name' => $quarter->name];
                    foreach ($quarter->leaveTypes as $leaveType) {
                        $typeName = $leaveType->name;
                        $totalDays = $leaveType->days;
                        $usedDays = $this->getUsedLeaveDaysInQuarter($employee->id, $typeName, $targetYear, $quarter->start_month, $quarter->end_month);

                        $eligibleLeaves[] = [
                            'leave_type' => $typeName,
                            'total_days' => $totalDays,
                            'used_days' => $usedDays,
                            'available_days' => max(0, $totalDays - $usedDays),
                            'is_half_day_only' => false,
                            'note' => "Quarter {$quarter->quarter_number}"
                        ];
                    }
                    break;
                }
            }

            foreach ($permanentSettings->quarters as $quarter) {
                if ($quarter->end_month < $targetMonth) {
                    foreach ($quarter->leaveTypes as $leaveType) {
                        $typeName = $leaveType->name;
                        $totalDays = $leaveType->days;
                        $usedDays = $this->getUsedLeaveDaysInQuarter($employee->id, $typeName, $targetYear, $quarter->start_month, $quarter->end_month);
                        $remainingDays = max(0, $totalDays - $usedDays);

                        $found = false;
                        foreach ($eligibleLeaves as &$leave) {
                            if ($leave['leave_type'] === $typeName) {
                                $leave['total_days'] += $totalDays;
                                $leave['used_days'] += $usedDays;
                                $leave['available_days'] = max(0, $leave['total_days'] - $leave['used_days']);
                                $found = true;
                                break;
                            }
                        }
                        unset($leave);

                        if (!$found && $remainingDays > 0) {
                            $eligibleLeaves[] = [
                                'leave_type' => $typeName,
                                'total_days' => $totalDays,
                                'used_days' => $usedDays,
                                'available_days' => $remainingDays,
                                'is_half_day_only' => false,
                                'note' => "Carried over from Quarter {$quarter->quarter_number}"
                            ];
                        }
                    }
                }
            }
        }

        return response()->json([
            'employee_id' => $employee->id,
            'emp_number' => $employee->attendance_employee_no,
            'employee_name' => $employee->display_name ?? $employee->name_with_initials,
            'is_probation' => false,
            'join_date' => $probationStartDate,
            'probation_end_date' => $probationEndDate,
            'eligible_leaves' => $eligibleLeaves,
            'current_quarter' => $currentQuarter,
            'full_year_access' => $fullYearAccess
        ]);
    }

    private function getUsedLeaveDays($employeeId, $leaveType, $year)
    {
        $leaves = leave_master::where('employee_id', $employeeId)
            ->where('leave_type', $leaveType)
            ->whereIn('status', ['Approved', 'HR_Approved', 'Pending'])
            ->where(function ($query) use ($year) {
                $query->whereYear('leave_date', $year)
                    ->orWhereYear('leave_from', $year);
            })
            ->get();

        $totalDays = 0;
        foreach ($leaves as $leave) {
            if ($leave->is_short_leave) {
                $totalDays += 0.25;
            } elseif ($leave->is_half_day) {
                $totalDays += 0.5;
            } else {
                $totalDays += $leave->leave_duration ?? 1;
            }
        }

        return $totalDays;
    }

    private function getUsedLeaveDaysInQuarter($employeeId, $leaveType, $year, $startMonth, $endMonth)
    {
        $startDate = Carbon::create($year, $startMonth, 1)->startOfMonth();
        $endDate = Carbon::create($year, $endMonth, 1)->endOfMonth();

        $leaves = leave_master::where('employee_id', $employeeId)
            ->where('leave_type', $leaveType)
            ->whereIn('status', ['Approved', 'HR_Approved', 'Pending'])
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('leave_date', [$startDate, $endDate])
                    ->orWhere(function ($q) use ($startDate, $endDate) {
                        $q->whereBetween('leave_from', [$startDate, $endDate]);
                    });
            })
            ->get();

        $totalDays = 0;
        foreach ($leaves as $leave) {
            if ($leave->is_short_leave) {
                $totalDays += 0.25;
            } elseif ($leave->is_half_day) {
                $totalDays += 0.5;
            } else {
                $totalDays += $leave->leave_duration ?? 1;
            }
        }

        return $totalDays;
    }
}

*/
