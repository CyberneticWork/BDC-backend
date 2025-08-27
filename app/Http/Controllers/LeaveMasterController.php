<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\leave_master;
use App\Models\employee;
use Illuminate\Support\Facades\Validator;
use App\Mail\LeaveApprovedMail;
use App\Mail\LeaveRejectedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class LeaveMasterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $leaveMasters = leave_master::with('employee')->get();
        return response()->json($leaveMasters);
    }

    /**
     * Store a newly created resource in storage.
     */
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
            'cancel_from' => 'nullable|date',
            'cancel_to' => 'nullable|date|after_or_equal:cancel_from',
            'reason' => 'nullable|string|max:1000',
            'status' => 'required|in:Pending,Approved,HR_Approved,Rejected',
            'force_continue' => 'nullable|boolean' // Add this new parameter
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Get employee organization assignment
        $employee = employee::with('organizationAssignment')->findOrFail($request->employee_id);
        $orgAssignment = $employee->organizationAssignment;

        $overLimitInfo = null;

        // Compute requested full duration (days) up-front so probation checks can use it
        $fullDuration = 0;
        if (isset($request->leave_from) && isset($request->leave_to)) {
            $from = new \DateTime($request->leave_from);
            $to = new \DateTime($request->leave_to);
            $fullDuration = $from->diff($to)->days + 1;
        } elseif (isset($request->leave_date)) {
            $fullDuration = 1;
        }

        // Check if employee is in probationary period
        if ($orgAssignment && $orgAssignment->probationary_period) {
            // If in probation, only allow half-day leaves
            if (!$request->is_half_day) {
                if (!$request->force_continue) {
                    return response()->json([
                        'message' => 'Employees in probation period can only take half-day leaves',
                        'limit_exceeded' => true,
                        'continue_allowed' => true
                    ], 422);
                }
                // Only half is valid, the rest is over limit
                $overLimitInfo = [
                    'reason' => 'probation_full_day',
                    'amount' => $fullDuration / 2,
                ];
            }

            // Check if employee already has a half-day leave this month
            $currentMonth = now()->format('Y-m');
            $existingLeaves = leave_master::where('employee_id', $request->employee_id)
                ->where('status', '!=', 'Rejected')
                ->where(function ($query) use ($currentMonth) {
                    $query->whereRaw("DATE_FORMAT(leave_date, '%Y-%m') = ?", [$currentMonth])
                        ->orWhereRaw("DATE_FORMAT(leave_from, '%Y-%m') = ?", [$currentMonth]);
                })
                ->exists();

            if ($existingLeaves) {
                if (!$request->force_continue) {
                    return response()->json([
                        'message' => 'Employees in probation period can only take one half-day leave per month',
                        'limit_exceeded' => true,
                        'continue_allowed' => true
                    ], 422);
                }
                // Entire requested duration is over limit (if user requested half-day it's 0.5, otherwise fullDuration)
                $overLimitInfo = [
                    'reason' => 'probation_monthly_limit',
                    'amount' => (isset($request->is_half_day) && $request->is_half_day) ? 0.5 : $fullDuration,
                ];
            }
        }

        $data = $request->all();

        // Calculate leave_duration based on available data (use $fullDuration computed earlier if available)
        $leaveDuration = $fullDuration;
        if ($leaveDuration === 0) {
            if (isset($data['leave_from']) && isset($data['leave_to'])) {
                $from = new \DateTime($data['leave_from']);
                $to = new \DateTime($data['leave_to']);
                $leaveDuration = $from->diff($to)->days + 1;
            } elseif (isset($data['leave_date'])) {
                $leaveDuration = 1;
            }
        }

        if (isset($data['is_half_day']) && $data['is_half_day']) {
            $leaveDuration = $leaveDuration > 0 ? $leaveDuration / 2 : 0.5;
        }

        // Apply probation over-limit rules
        if ($overLimitInfo) {
            if ($overLimitInfo['reason'] === 'probation_full_day') {
                // When full-day was requested during probation, only half of the day is valid.
                // Note: this branch only triggers when request->is_half_day is false.
                $data['leave_duration'] = $leaveDuration / 2;
            } elseif ($overLimitInfo['reason'] === 'probation_monthly_limit') {
                // Already used monthly half-day allowance -> this whole requested duration is over limit
                $data['leave_duration'] = 0;
            } else {
                $data['leave_duration'] = $leaveDuration;
            }
            // ensure over_limit is set to the correct amount (was computed above)
            $data['over_limit'] = $overLimitInfo['amount'];
        } else {
            $data['leave_duration'] = $leaveDuration;
        }

        // (over_limit already set above when needed)

        $leaveMaster = leave_master::create($data);
        return response()->json($leaveMaster, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $leaveMaster = leave_master::where("employee_id", $id)->get();
        return response()->json($leaveMaster);
    }

    /**
     * Update the specified resource in storage.
     */
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
            'cancel_from' => 'nullable|date',
            'cancel_to' => 'nullable|date|after_or_equal:cancel_from',
            'reason' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:Pending,Approved,HR_Approved,Rejected',
            'force_continue' => 'nullable|boolean' // Add this new parameter
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $leaveMaster = leave_master::findOrFail($id);
        $employee = employee::with('organizationAssignment')->findOrFail($leaveMaster->employee_id);
        $orgAssignment = $employee->organizationAssignment;

        $overLimitInfo = null;

        // Check if employee is in probationary period
        if ($orgAssignment && $orgAssignment->probationary_period) {
            // If in probation, only allow half-day leaves
            if (isset($request->is_half_day) && !$request->is_half_day) {
                if (!$request->force_continue) {
                    return response()->json([
                        'message' => 'Employees in probation period can only take half-day leaves',
                        'limit_exceeded' => true,
                        'continue_allowed' => true
                    ], 422);
                }

                // Only half is valid, the rest is over limit
                $overLimitInfo = [
                    'reason' => 'probation_full_day',
                    'amount' => $fullDuration / 2,
                ];
            }

            // Check if employee already has a half-day leave this month (excluding current leave)
            $currentMonth = now()->format('Y-m');
            $existingLeaves = leave_master::where('employee_id', $leaveMaster->employee_id)
                ->where('id', '!=', $id)
                ->where('status', '!=', 'Rejected')
                ->where(function ($query) use ($currentMonth) {
                    $query->whereRaw("DATE_FORMAT(leave_date, '%Y-%m') = ?", [$currentMonth])
                        ->orWhereRaw("DATE_FORMAT(leave_from, '%Y-%m') = ?", [$currentMonth]);
                })
                ->exists();

            if ($existingLeaves) {
                if (!$request->force_continue) {
                    return response()->json([
                        'message' => 'Employees in probation period can only take one half-day leave per month',
                        'limit_exceeded' => true,
                        'continue_allowed' => true
                    ], 422);
                }
                // Entire requested duration is over limit (if user requested half-day it's 0.5, otherwise fullDuration)
                $overLimitInfo = [
                    'reason' => 'probation_monthly_limit',
                    'amount' => (isset($request->is_half_day) && $request->is_half_day) ? 0.5 : $fullDuration,
                ];
            }
        }

        $data = $request->all();

        // Calculate leave_duration based on available data
        if (isset($data['leave_from']) && isset($data['leave_to'])) {
            // Calculate days between leave_from and leave_to (inclusive)
            $from = new \DateTime($data['leave_from']);
            $to = new \DateTime($data['leave_to']);
            $interval = $from->diff($to);
            $data['leave_duration'] = $interval->days + 1; // +1 to include both start and end dates
        } elseif (isset($data['leave_date']) && !isset($data['leave_duration'])) {
            // Single day leave
            $data['leave_duration'] = 1;
        }

        if (isset($data['is_half_day']) && $data['is_half_day']) {
            $data['leave_duration'] = isset($data['leave_duration']) && $data['leave_duration'] > 0
                ? $data['leave_duration'] / 2
                : 0.5;
        }

        // For probationary period full-day leave override
        if ($overLimitInfo) {
            if ($overLimitInfo['reason'] === 'probation_full_day') {
                // Only store valid portion (half of the calculated duration)
                $data['leave_duration'] = $data['leave_duration'] / 2;
            } else if ($overLimitInfo['reason'] === 'probation_monthly_limit') {
                // For monthly limit, set the duration to 0 since it's all over limit
                // We're recording it but it's entirely over their limit
                $data['leave_duration'] = 0;
            }
        } else {
            $data['leave_duration'] = $leaveDuration;
        }

        // Set over_limit value if needed
        if ($overLimitInfo) {
            $data['over_limit'] = $overLimitInfo['amount'];
        }

        $leaveMaster->update($data);
        return response()->json($leaveMaster);
    }

    /**
     * Update leave status and send email notification
     */
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

        // Send email only if status changed
        if ($oldStatus !== $request->status) {
            $this->sendStatusEmail($leaveMaster, $request->status, $request->rejection_reason);
        }

        return response()->json([
            'message' => 'Leave status updated successfully',
            'leave' => $leaveMaster
        ]);
    }

    /**
     * Send email notification based on leave status
     */
    private function sendStatusEmail($leave, $status, $rejectionReason = null)
    {
        $employee = $leave->employee;

        // Check if employee has contact details and email
        if (!$employee->contactDetail || !$employee->contactDetail->email) {
            Log::warning('Cannot send email notification: Employee contact details missing', [
                'employee_id' => $employee->id,
                'leave_id' => $leave->id
            ]);
            return;
        }

        try {
            if ($status === 'Approved' || $status === 'HR_Approved') {
                Mail::to($employee->contactDetail->email)->send(new LeaveApprovedMail($leave, $employee));
            } elseif ($status === 'Rejected') {
                Mail::to($employee->contactDetail->email)->send(new LeaveRejectedMail($leave, $employee, $rejectionReason));
            }

            Log::info('Leave status email sent successfully', [
                'employee_id' => $employee->id,
                'leave_id' => $leave->id,
                'status' => $status,
                'email' => $employee->contactDetail->email
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send leave status email', [
                'employee_id' => $employee->id,
                'leave_id' => $leave->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $leaveMaster = leave_master::findOrFail($id);
        $leaveMaster->delete();
        return response()->json(null, 204);
    }

    //return annual/casual/special leave record counts for a specific employee
    //return annual/casual/special leave record counts for a specific employee with half-day support
    public function getLeaveRecordCountsByEmployee($employeeId)
    {
        $leaveCounts = leave_master::where('employee_id', $employeeId)
            ->selectRaw('
            leave_type,
            -- Full days that are not half days
            SUM(CASE WHEN is_half_day = 0 AND status != "Rejected" THEN COALESCE(leave_duration, 1) ELSE 0 END) as approved_full_days,
            -- Half days (each counts as 0.5)
            SUM(CASE WHEN is_half_day = 1 AND status != "Rejected" THEN 0.5 ELSE 0 END) as approved_half_days,
            -- Rejected full days
            SUM(CASE WHEN is_half_day = 0 AND status = "Rejected" THEN COALESCE(leave_duration, 1) ELSE 0 END) as rejected_full_days,
            -- Rejected half days (each counts as 0.5)
            SUM(CASE WHEN is_half_day = 1 AND status = "Rejected" THEN 0.5 ELSE 0 END) as rejected_half_days
        ')
            ->groupBy('leave_type')
            ->get();

        return response()->json($leaveCounts);
    }
    // Get leave records that the status = 'Pending'
    public function getPendingLeaveRecords()
    {
        $pendingLeaves = leave_master::with('employee')->where('status', 'Pending')->get();
        return response()->json($pendingLeaves);
    }

    // Get leave records that the status = 'Approved'
    public function getApprovedLeaveRecords()
    {
        $approvedLeaves = leave_master::with('employee')->where('status', 'Approved')->get();
        return response()->json($approvedLeaves);
    }

    // Get leave records that the status = 'HR_Approved'
    public function getHRApprovedLeaveRecords()
    {
        $hrApprovedLeaves = leave_master::with('employee')->where('status', 'HR_Approved')->get();
        return response()->json($hrApprovedLeaves);
    }

    // Get leave records that the status = 'Rejected'
    public function getRejectedLeaveRecords()
    {
        $rejectedLeaves = leave_master::with('employee')->where('status', 'Rejected')->get();
        return response()->json($rejectedLeaves);
    }
    // Add this method to your LeaveMasterController
// Add this method to your LeaveMasterController
    public function getApprovedLeavesByDate(Request $request)
    {
        $date = $request->query('date');

        if (!$date) {
            return response()->json(['message' => 'Date parameter is required'], 422);
        }

        $leaveCount = leave_master::where('status', 'Approved')
            ->where(function ($query) use ($date) {
                $query->where('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->where('leave_from', '<=', $date)
                            ->where('leave_to', '>=', $date);
                    });
            })
            ->count();

        return response()->json($leaveCount);
    }
}
