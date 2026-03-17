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
            // නිවැරදිව Requested Duration එක (දින වලින්) ගණනය කිරීම
        $requestedDurationInDays = $fullDuration;
        if ($request->is_half_day) {
            $requestedDurationInDays = 0.5;
        } elseif ($request->is_short_leave) {
            $requestedDurationInDays = 0.25;
        }
        
        // Calculate requested half-days (Short leave is considered as half a half-day, i.e., 0.5)
        $requestedHalfDays = $requestedDurationInDays * 2;

        // If it's more than a half day (e.g., Full day), apply probation rules
        if ($requestedDurationInDays > 0.5) {
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
        } elseif (isset($data['is_short_leave']) && $data['is_short_leave']) {
            $leaveDuration = 0.25; // Short leave  (0.25) 
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
            // නිවැරදිව Requested Duration එක (දින වලින්) ගණනය කිරීම
        $requestedDurationInDays = $fullDuration;
        if ($request->is_half_day) {
            $requestedDurationInDays = 0.5;
        } elseif ($request->is_short_leave) {
            $requestedDurationInDays = 0.25;
        }
        
        // Calculate requested half-days (Short leave is considered as half a half-day, i.e., 0.5)
        $requestedHalfDays = $requestedDurationInDays * 2;

        // If it's more than a half day (e.g., Full day), apply probation rules
        if ($requestedDurationInDays > 0.5) {
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
        } elseif (isset($data['is_short_leave']) && $data['is_short_leave']) {
            $leaveDuration = 0.25;
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

    
    public function getLeaveEligibility(Request $request)
    {
        $empNumber = $request->query('emp_number');
        $employeeId = $request->query('employee_id');
        $requestedDateStr = $request->query('date'); // Frontend එකෙන් එවන Date එක

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
        
        // තෝරපු දවස (Date එකක් එව්වේ නැත්නම් අද දවස ගන්නවා)
        $targetDate = $requestedDateStr ? Carbon::parse($requestedDateStr) : Carbon::now();
        $targetYear = $targetDate->year;
        $targetMonth = $targetDate->month;

        $isProbation = $orgAssignment && $orgAssignment->probationary_period;
        $probationStartDate = $orgAssignment->probationary_period_from ?? $orgAssignment->date_of_joining ?? null;
        $probationEndDate = $orgAssignment->probationary_period_to ?? null;

        // ** ප්‍රධාන වෙනස: තෝරපු දවස Probation End Date එකට වඩා පස්සේ නම්, ඔහුව Permanent ලෙස සලකයි **
        if ($isProbation && $probationEndDate) {
            if ($targetDate->startOfDay()->greaterThan(Carbon::parse($probationEndDate)->startOfDay())) {
                $isProbation = false; // පරිවාස කාලය අවසන් වී ඇති බැවින් Permanent ලෙස සලකයි
            }
        }

        // ------------------ PROBATION කාලයේ නිවාඩු ------------------
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
                'note' => 'Probation employees can only take half-day leaves'
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

        // ------------------ PERMANENT කාලයේ නිවාඩු ------------------
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

        // මේ අවුරුද්දට කලින් බැඳිලා නම් හෝ පරිවාසය ඉවර වෙලා ලබන අවුරුද්දේ නිවාඩුවක් දානවා නම්
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
            // බැඳුණු අවුරුද්දෙම (Quarterly)
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

            // පරණ Quarters වල ඉතුරු ටික එකතු කිරීම
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
            'is_probation' => false, //  Permanent
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
            if ($leave->is_half_day) {
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
            if ($leave->is_half_day) {
                $totalDays += 0.5;
            } else {
                $totalDays += $leave->leave_duration ?? 1;
            }
        }

        return $totalDays;
    }
}

*/


