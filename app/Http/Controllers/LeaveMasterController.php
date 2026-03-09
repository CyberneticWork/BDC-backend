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
            'force_continue' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Check for duplicate leave requests
        $duplicateCheck = $this->checkForDuplicateLeave($request);
        if ($duplicateCheck) {
            return response()->json([
                'message' => $duplicateCheck,
                'duplicate_found' => true
            ], 422);
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
            // If in probation, process special leave rules
            $requestDate = isset($request->leave_date)
                ? Carbon::parse($request->leave_date)
                : (isset($request->leave_from) ? Carbon::parse($request->leave_from) : now());

            $leaveBalance = $this->calculateProbationaryLeaveBalance($request->employee_id, $requestDate);

            // Calculate requested half-days
            $requestedHalfDays = $request->is_half_day ? 1 : ($fullDuration * 2);

            // If full day leave during probation, only allow if they have enough balance
            if (!$request->is_half_day) {
                if (!$request->force_continue) {
                    return response()->json([
                        'message' => 'Employees in probation period can only take half-day leaves at a time',
                        'limit_exceeded' => true,
                        'continue_allowed' => true
                    ], 422);
                }

                // Check if they have enough balance for at least half of the request
                if ($leaveBalance['available_half_days'] < 1) {
                    // Not enough balance for even a half day
                    if (!$request->force_continue) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' .
                                         $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }

                    // They're forcing through with no balance - all is over limit
                    $overLimitInfo = [
                        'reason' => 'probation_no_balance',
                        'amount' => $fullDuration
                    ];
                } else {
                    // They have some balance - calculate how much is valid vs over limit
                    $validHalfDays = min($leaveBalance['available_half_days'], $requestedHalfDays);
                    $overLimitHalfDays = $requestedHalfDays - $validHalfDays;

                    // Convert back to days
                    $validDuration = $validHalfDays / 2;
                    $overLimitDuration = $overLimitHalfDays / 2;

                    if ($overLimitDuration > 0) {
                        $overLimitInfo = [
                            'reason' => 'probation_partial_balance',
                            'amount' => $overLimitDuration
                        ];
                    }
                }
            } else {
                // Half-day request - check if they have balance
                if ($leaveBalance['available_half_days'] < 1) {
                    if (!$request->force_continue) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' .
                                         $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }

                    // They're forcing through with no balance
                    $overLimitInfo = [
                        'reason' => 'probation_no_balance',
                        'amount' => 0.5
                    ];
                }
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
                $data['leave_duration'] = $leaveDuration / 2;
            } elseif ($overLimitInfo['reason'] === 'probation_monthly_limit') {
                // Already used monthly half-day allowance -> this whole requested duration is over limit
                $data['leave_duration'] = 0;
            } elseif ($overLimitInfo['reason'] === 'probation_no_balance') {
                // No balance available - set duration to 0
                $data['leave_duration'] = 0;
            } elseif ($overLimitInfo['reason'] === 'probation_partial_balance') {
                // Partial balance - subtract the over_limit amount from the full duration
                $data['leave_duration'] = $leaveDuration - $overLimitInfo['amount'];
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
            'force_continue' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $leaveMaster = leave_master::findOrFail($id);

        // Check for duplicate leave requests (excluding current record)
        $duplicateCheck = $this->checkForDuplicateLeave($request, $id);
        if ($duplicateCheck) {
            return response()->json([
                'message' => $duplicateCheck,
                'duplicate_found' => true
            ], 422);
        }

        $employee = employee::with('organizationAssignment')->findOrFail($leaveMaster->employee_id);
        $orgAssignment = $employee->organizationAssignment;

        $overLimitInfo = null;

        // Compute requested full duration (days) up-front
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
            // If in probation, process special leave rules
            $requestDate = isset($request->leave_date)
                ? Carbon::parse($request->leave_date)
                : (isset($request->leave_from) ? Carbon::parse($request->leave_from) : now());

            $leaveBalance = $this->calculateProbationaryLeaveBalance($request->employee_id, $requestDate);

            // Calculate requested half-days
            $requestedHalfDays = $request->is_half_day ? 1 : ($fullDuration * 2);

            // If full day leave during probation, only allow if they have enough balance
            if (!$request->is_half_day) {
                if (!$request->force_continue) {
                    return response()->json([
                        'message' => 'Employees in probation period can only take half-day leaves at a time',
                        'limit_exceeded' => true,
                        'continue_allowed' => true
                    ], 422);
                }

                // Check if they have enough balance for at least half of the request
                if ($leaveBalance['available_half_days'] < 1) {
                    // Not enough balance for even a half day
                    if (!$request->force_continue) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' .
                                         $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }

                    // They're forcing through with no balance - all is over limit
                    $overLimitInfo = [
                        'reason' => 'probation_no_balance',
                        'amount' => $fullDuration
                    ];
                } else {
                    // They have some balance - calculate how much is valid vs over limit
                    $validHalfDays = min($leaveBalance['available_half_days'], $requestedHalfDays);
                    $overLimitHalfDays = $requestedHalfDays - $validHalfDays;

                    // Convert back to days
                    $validDuration = $validHalfDays / 2;
                    $overLimitDuration = $overLimitHalfDays / 2;

                    if ($overLimitDuration > 0) {
                        $overLimitInfo = [
                            'reason' => 'probation_partial_balance',
                            'amount' => $overLimitDuration
                        ];
                    }
                }
            } else {
                // Half-day request - check if they have balance
                if ($leaveBalance['available_half_days'] < 1) {
                    if (!$request->force_continue) {
                        return response()->json([
                            'message' => 'You have used all your probationary leave allowance (' .
                                         $leaveBalance['max_accrued'] . ' half-days for the year)',
                            'limit_exceeded' => true,
                            'continue_allowed' => true
                        ], 422);
                    }

                    // They're forcing through with no balance
                    $overLimitInfo = [
                        'reason' => 'probation_no_balance',
                        'amount' => 0.5
                    ];
                }
            }
        }

        $data = $request->all();

        // Calculate leave_duration based on available data
        $leaveDuration = 0;
        if (isset($data['leave_from']) && isset($data['leave_to'])) {
            // Calculate days between leave_from and leave_to (inclusive)
            $from = new \DateTime($data['leave_from']);
            $to = new \DateTime($data['leave_to']);
            $interval = $from->diff($to);
            $leaveDuration = $interval->days + 1; // +1 to include both start and end dates
            $data['leave_duration'] = $leaveDuration;
        } elseif (isset($data['leave_date']) && !isset($data['leave_duration'])) {
            // Single day leave
            $leaveDuration = 1;
            $data['leave_duration'] = 1;
        }

        if (isset($data['is_half_day']) && $data['is_half_day']) {
            $leaveDuration = isset($data['leave_duration']) && $data['leave_duration'] > 0
                ? $data['leave_duration'] / 2
                : 0.5;
            $data['leave_duration'] = $leaveDuration;
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
            } else if ($overLimitInfo['reason'] === 'probation_no_balance') {
                // No balance available - set duration to 0
                $data['leave_duration'] = 0;
            } else if ($overLimitInfo['reason'] === 'probation_partial_balance') {
                // Partial balance - subtract the over_limit amount from the full duration
                $data['leave_duration'] = $leaveDuration - $overLimitInfo['amount'];
            }
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

    /**
     * Calculate available probationary leave balance
     * Probationary employees get 7 half-days per year (1 half-day per month with rollover)
     */
    private function calculateProbationaryLeaveBalance($employeeId, $requestDate = null)
    {
        $requestDate = $requestDate ?? now();
        $currentYear = $requestDate->year;

        // How many months have passed in the current year up to the request date
        $monthsElapsed = min($requestDate->month, 12);

        // Maximum potential half-day leaves accrued so far (1 per month, max 7 per year)
        $maxAccruedLeaves = min($monthsElapsed, 7);

        // Get all approved/pending leaves used in the current year
        $usedLeaves = leave_master::where('employee_id', $employeeId)
            ->where('status', '!=', 'Rejected')
            ->whereYear('reporting_date', $currentYear)
            ->get();

        // Calculate used leave half-days
        $usedHalfDays = 0;
        foreach ($usedLeaves as $leave) {
            // Count leave duration (already accounts for half-days)
            $usedHalfDays += $leave->is_half_day ? 1 : ($leave->leave_duration * 2);
        }

        // Available half-days = accrued - used (minimum 0)
        $availableHalfDays = max(0, $maxAccruedLeaves - $usedHalfDays);

        return [
            'available_half_days' => $availableHalfDays,
            'used_half_days' => $usedHalfDays,
            'max_accrued' => $maxAccruedLeaves
        ];
    }

    /**
     * Check for duplicate leave requests
     */
    private function checkForDuplicateLeave($request, $excludeId = null)
    {
        $employeeId = $request->employee_id;

        // Case 1: Single day leave (leave_date is set)
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

            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }

            $existing = $query->first();

            if ($existing) {
                $existingDateStr = $existing->leave_date
                    ? $existing->leave_date
                    : "{$existing->leave_from} to {$existing->leave_to}";

                return "You already have a leave request for {$request->leave_date}. Existing leave: {$existingDateStr} ({$existing->status})";
            }
        }

        // Case 2: Date range leave (leave_from and leave_to are set)
        if ($request->leave_from && $request->leave_to) {
            $query = leave_master::where('employee_id', $employeeId)
                ->where('status', '!=', 'Rejected')
                ->where(function($query) use ($request) {
                    // Check for any overlap between existing leaves and new request
                    $query->where(function($q) use ($request) {
                        // Existing single day falls within new range
                        $q->whereBetween('leave_date', [$request->leave_from, $request->leave_to]);
                    })->orWhere(function($q) use ($request) {
                        // Existing range overlaps with new range
                        $q->where(function($subQ) use ($request) {
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
                $existingDateStr = $existing->leave_date
                    ? $existing->leave_date
                    : "{$existing->leave_from} to {$existing->leave_to}";

                return "Your requested leave period ({$request->leave_from} to {$request->leave_to}) overlaps with an existing leave: {$existingDateStr} ({$existing->status})";
            }
        }

        return null; // No duplicates found
    }

    /**
     * Get leave eligibility for an employee based on probation status and join date
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLeaveEligibility(Request $request)
    {
        $empNumber = $request->query('emp_number');
        $employeeId = $request->query('employee_id');

        if (!$empNumber && !$employeeId) {
            return response()->json([
                'message' => 'Either emp_number or employee_id is required'
            ], 422);
        }

        // Find employee by EMP number or ID
        if ($empNumber) {
            $employee = employee::with('organizationAssignment')
                ->where('attendance_employee_no', $empNumber)
                ->first();
        } else {
            $employee = employee::with('organizationAssignment')
                ->find($employeeId);
        }

        if (!$employee) {
            return response()->json([
                'message' => 'Employee not found'
            ], 404);
        }

        $orgAssignment = $employee->organizationAssignment;
        $today = Carbon::now();
        $currentYear = $today->year;

        // Check if employee is on probation
        $isProbation = $orgAssignment && $orgAssignment->probationary_period;

        if ($isProbation) {
            // Probation employee logic - use existing probationary leave balance
            $leaveBalance = $this->calculateProbationaryLeaveBalance($employee->id);

            // Get probation leave settings
            $probationSettings = LeaveSetting::with(['quarters.leaveTypes'])
                ->where('employee_type', 'probation')
                ->where('is_active', true)
                ->first();

            $eligibleLeaves = [];

            if ($probationSettings) {
                // For probation, they typically only get Casual Leave
                $eligibleLeaves[] = [
                    'leave_type' => 'Casual Leave',
                    'total_days' => $probationSettings->annual_leave_days ?? 7,
                    'used_days' => $leaveBalance['used_half_days'] / 2, // Convert half-days to days
                    'available_days' => $leaveBalance['available_half_days'] / 2,
                    'is_half_day_only' => true,
                    'note' => 'Probation employees can only take half-day leaves'
                ];
            } else {
                // No probation settings - return default
                $eligibleLeaves[] = [
                    'leave_type' => 'Casual Leave',
                    'total_days' => 7,
                    'used_days' => $leaveBalance['used_half_days'] / 2,
                    'available_days' => $leaveBalance['available_half_days'] / 2,
                    'is_half_day_only' => true,
                    'note' => 'Probation employees can only take half-day leaves (Default: 7 half-days per year)'
                ];
            }

            return response()->json([
                'employee_id' => $employee->id,
                'emp_number' => $employee->attendance_employee_no,
                'employee_name' => $employee->display_name ?? $employee->name_with_initials,
                'is_probation' => true,
                'join_date' => $orgAssignment->date_of_joining ?? null,
                'eligible_leaves' => $eligibleLeaves,
                'current_quarter' => null,
                'full_year_access' => false
            ]);
        }

        // Non-probation employee logic
        $joinDate = $orgAssignment ? Carbon::parse($orgAssignment->date_of_joining) : null;
        $joinYear = $joinDate ? $joinDate->year : null;

        // Get permanent employee leave settings
        $permanentSettings = LeaveSetting::with(['quarters.leaveTypes'])
            ->where('employee_type', 'permanent')
            ->where('is_active', true)
            ->first();

        if (!$permanentSettings) {
            // Return empty eligibility instead of error
            return response()->json([
                'employee_id' => $employee->id,
                'emp_number' => $employee->attendance_employee_no,
                'employee_name' => $employee->display_name ?? $employee->name_with_initials,
                'is_probation' => false,
                'join_date' => $orgAssignment->date_of_joining ?? null,
                'join_year' => $joinYear,
                'current_year' => $currentYear,
                'eligible_leaves' => [],
                'current_quarter' => null,
                'full_year_access' => false,
                'message' => 'No leave settings configured. Please contact HR.'
            ]);
        }

        $eligibleLeaves = [];
        $currentQuarter = null;
        $fullYearAccess = false;

        // Check if join date year is different from current year
        if ($joinYear && $joinYear < $currentYear) {
            // Employee joined in a previous year - full year access to all leave types
            $fullYearAccess = true;

            // Aggregate all leave types from all quarters
            $allLeaveTypes = [];
            foreach ($permanentSettings->quarters as $quarter) {
                foreach ($quarter->leaveTypes as $leaveType) {
                    $typeName = $leaveType->name;
                    if (!isset($allLeaveTypes[$typeName])) {
                        $allLeaveTypes[$typeName] = 0;
                    }
                    $allLeaveTypes[$typeName] += $leaveType->days;
                }
            }

            // Calculate used leaves for each type this year
            foreach ($allLeaveTypes as $typeName => $totalDays) {
                $usedDays = $this->getUsedLeaveDays($employee->id, $typeName, $currentYear);
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
            // Employee joined this year - use quarter-based eligibility
            $currentMonth = $today->month;

            // Find the current quarter based on the current month
            foreach ($permanentSettings->quarters as $quarter) {
                if ($currentMonth >= $quarter->start_month && $currentMonth <= $quarter->end_month) {
                    $currentQuarter = [
                        'quarter_number' => $quarter->quarter_number,
                        'name' => $quarter->name,
                        'start_month' => $quarter->start_month,
                        'end_month' => $quarter->end_month
                    ];

                    // Get leave types for this quarter
                    foreach ($quarter->leaveTypes as $leaveType) {
                        $typeName = $leaveType->name;
                        $totalDays = $leaveType->days;
                        $usedDays = $this->getUsedLeaveDaysInQuarter(
                            $employee->id,
                            $typeName,
                            $currentYear,
                            $quarter->start_month,
                            $quarter->end_month
                        );

                        $eligibleLeaves[] = [
                            'leave_type' => $typeName,
                            'total_days' => $totalDays,
                            'used_days' => $usedDays,
                            'available_days' => max(0, $totalDays - $usedDays),
                            'is_half_day_only' => false,
                            'note' => "Quarter {$quarter->quarter_number}: {$quarter->name}"
                        ];
                    }
                    break;
                }
            }

            // Also add leaves from previous quarters (accumulated)
            foreach ($permanentSettings->quarters as $quarter) {
                if ($quarter->end_month < $currentMonth) {
                    // This is a past quarter - add remaining leaves
                    foreach ($quarter->leaveTypes as $leaveType) {
                        $typeName = $leaveType->name;
                        $totalDays = $leaveType->days;
                        $usedDays = $this->getUsedLeaveDaysInQuarter(
                            $employee->id,
                            $typeName,
                            $currentYear,
                            $quarter->start_month,
                            $quarter->end_month
                        );
                        $remainingDays = max(0, $totalDays - $usedDays);

                        if ($remainingDays > 0) {
                            // Find if this leave type already exists in eligibleLeaves
                            $found = false;
                            foreach ($eligibleLeaves as &$leave) {
                                if ($leave['leave_type'] === $typeName) {
                                    $leave['total_days'] += $totalDays;
                                    $leave['used_days'] += $usedDays;
                                    $leave['available_days'] = max(0, $leave['total_days'] - $leave['used_days']);
                                    $leave['note'] = 'Includes carried over from previous quarters';
                                    $found = true;
                                    break;
                                }
                            }
                            unset($leave);

                            if (!$found) {
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
        }

        return response()->json([
            'employee_id' => $employee->id,
            'emp_number' => $employee->attendance_employee_no,
            'employee_name' => $employee->display_name ?? $employee->name_with_initials,
            'is_probation' => false,
            'join_date' => $orgAssignment->date_of_joining ?? null,
            'join_year' => $joinYear,
            'current_year' => $currentYear,
            'eligible_leaves' => $eligibleLeaves,
            'current_quarter' => $currentQuarter,
            'full_year_access' => $fullYearAccess
        ]);
    }

    /**
     * Get used leave days for a specific leave type in a year
     */
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
            if ($leave->is_half_day) {
                $totalDays += 0.5;
            } else {
                $totalDays += $leave->leave_duration ?? 1;
            }
        }

        return $totalDays;
    }

    /**
     * Get used leave days for a specific leave type within a quarter
     */
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
            if ($leave->is_half_day) {
                $totalDays += 0.5;
            } else {
                $totalDays += $leave->leave_duration ?? 1;
            }
        }

        return $totalDays;
    }
}
