<?php

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
            'status' => 'required|in:Pending,Pending_Supervisor,Approved,HR_Approved,Rejected',
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

        // =========================================================================
        // 🔥 NEW LOGIC: EMPLOYMENT STATUS එක අනුව STATUS එක වෙනස් කිරීම 🔥
        // =========================================================================
        if ($request->status === 'Pending') {
            $employmentType = strtolower($employee->employmentType->name ?? '');

            // Employment Status එක 'Training' නම් මුලින්ම Supervisor ගාවට යනවා
            if (str_contains($employmentType, 'training') || str_contains($employmentType, 'trainee')) {
                $data['status'] = 'Pending_Supervisor';
            } else {
                // අනිත් හැමෝම (Permanent, Contract etc.) කෙලින්ම Leave Approval එකට (Pending) යනවා
                $data['status'] = 'Pending';
            }
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
            'status' => 'required|in:Pending,Pending_Supervisor,Approved,HR_Approved,Rejected',
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
        // Trainee  (Pending, Approved, Rejected)
        $leaves = leave_master::with(['employee.employmentType'])
            ->whereHas('employee.employmentType', function ($query) {
                $query->where('name', 'LIKE', '%training%')
                    ->orWhere('name', 'LIKE', '%trainee%');
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($leaves);
    }

    public function getPendingLeaveRecords()
    {
        // අනිත් ඔක්කොම අය දාපුවා සහ Supervisor Approve කරපුවා පෙන්වන එක (Leave Approval පේජ් එකට)
        $pendingLeaves = leave_master::with('employee')->where('status', 'Pending')->get();
        return response()->json($pendingLeaves);
    }

    // =========================================================================

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
            $query = leave_master::where('employee_id', $employeeId)
                ->where('status', '!=', 'Rejected')
                ->where(function ($query) use ($request) {
                    $query->where('leave_date', $request->leave_date)
                        ->orWhere(function ($q) use ($request) {
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

        if ($currentYear == $joinYear) {
            $isFirstYear = true;
            $totalAnnualLeaves = 0;

            $monthsCompleted = $joinDate->diffInMonths($targetDate);
            $totalCasualLeaves = floor($monthsCompleted / 2);
            $note = 'First Year: 1 Casual Leave per 2 completed months. No Annual Leaves.';
        } elseif ($currentYear == $joinYear + 1) {
            $totalCasualLeaves = 7;
            $note = 'Second Year: 7 Casual Leaves. Annual leaves based on joined month.';

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
            $totalCasualLeaves = 7;
            $totalAnnualLeaves = 14;
            $note = 'Standard leaves: 14 Annual and 7 Casual leaves.';
        }

        $eligibleLeaves = [];

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
            ->whereIn('status', ['Approved', 'HR_Approved', 'Pending', 'Pending_Supervisor'])
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
