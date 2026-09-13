<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\employee;
use App\Models\leave_master;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class LeaveNotificationService
{
    public static function notifyEmployee(?int $employeeId, string $title, string $message, array $data = []): void
    {
        if (!$employeeId || !Schema::hasTable('notifications')) {
            return;
        }

        $userIds = User::where('employee_id', $employeeId)->pluck('id');
        foreach ($userIds as $userId) {
            Notification::create([
                'user_id' => $userId,
                'type' => $data['type'] ?? 'leave',
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'is_read' => false,
            ]);
        }
    }

    public static function leaveSubmitted(leave_master $leave): void
    {
        $emp = $leave->employee;
        $name = $emp?->full_name ?? 'Employee';
        if ($leave->covering_employee_id) {
            self::notifyEmployee(
                (int) $leave->covering_employee_id,
                'Leave covering request',
                "{$name} asked you to cover leave from {$leave->leave_from} to {$leave->leave_to}.",
                ['leave_id' => $leave->id, 'type' => 'leave_covering']
            );
        }
    }

    public static function statusChanged(leave_master $leave, string $status, ?string $reason = null): void
    {
        $title = 'Leave update';
        $message = "Your leave request is now {$status}.";
        if ($status === 'Rejected' && $reason) {
            $message .= " Reason: {$reason}";
        }
        if (in_array($status, ['Approved', 'HR_Approved'], true)) {
            $title = 'Leave approved';
            $message = 'Your leave request was approved.';
        } elseif ($status === 'Rejected') {
            $title = 'Leave rejected';
        } elseif ($status === 'Pending_Supervisor') {
            $title = 'Leave covering approved';
            $message = 'Your covering person approved. The request is with your supervisor.';
        } elseif ($status === 'Pending') {
            $message = 'Your leave is with HR / management for approval.';
        }

        self::notifyEmployee((int) $leave->employee_id, $title, $message, [
            'leave_id' => $leave->id,
            'status' => $status,
            'type' => 'leave_status',
        ]);
    }

    public static function smsNotConfigured(employee $employee, string $text): void
    {
        Log::info('Leave SMS skipped (no SMS provider configured)', [
            'employee_id' => $employee->id,
            'text' => $text,
        ]);
    }
}
