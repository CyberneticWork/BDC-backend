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
            'cancel_from' => 'nullable|date',
            'cancel_to' => 'nullable|date|after_or_equal:cancel_from',
            'reason' => 'nullable|string|max:1000',
            'status' => 'required|in:Pending,Approved,HR_Approved,Rejected'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $leaveMaster = leave_master::create($request->all());
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
            'cancel_from' => 'nullable|date',
            'cancel_to' => 'nullable|date|after_or_equal:cancel_from',
            'reason' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:Pending,Approved,HR_Approved,Rejected'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $leaveMaster = leave_master::findOrFail($id);
        $leaveMaster->update($request->all());
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
    public function getLeaveRecordCountsByEmployee($employeeId)
    {
        $leaveCounts = leave_master::where('employee_id', $employeeId)
            ->selectRaw('leave_type, COUNT(*) as count')
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
}
