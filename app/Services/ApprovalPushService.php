<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\employee;
use App\Models\leave_master;
use Illuminate\Support\Facades\Schema;

/**
 * In-app + FCM alerts for the next approver on leave / advance / loan requests.
 * Works for desk (PC) and mobile once the user has registered a push token or
 * keeps the HR tab open with browser notification permission.
 */
class ApprovalPushService
{
    public const SUPER_ADMIN_VIRTUAL_ID = 999999001;

    public static function notifyUsers(array $userIds, string $title, string $body, array $data = []): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return;
        }

        $type = (string) ($data['type'] ?? 'approval');
        if (Schema::hasTable('notifications')) {
            foreach ($userIds as $userId) {
                try {
                    Notification::create([
                        'user_id' => $userId,
                        'type' => $type,
                        'title' => $title,
                        'message' => $body,
                        'data' => $data,
                        'is_read' => false,
                    ]);
                } catch (\Throwable $e) {
                    // skip one recipient
                }
            }
        }

        try {
            app(FcmPushService::class)->sendToUsers($userIds, $title, $body, array_map('strval', $data));
        } catch (\Throwable $e) {
            // push is optional when Firebase is offline
        }
    }

    /** HR / Admin / Supervisor desk users who act on first-level approvals. */
    public static function adminApproverIds(): array
    {
        try {
            return User::whereIn('role', ['hr', 'admin', 'supervisor'])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Super Admin recipients (DB role + virtual Super Admin login). */
    public static function superAdminApproverIds(): array
    {
        $ids = [self::SUPER_ADMIN_VIRTUAL_ID];
        try {
            $ids = array_merge(
                $ids,
                User::where('role', 'super_admin')->pluck('id')->map(fn ($id) => (int) $id)->all()
            );
        } catch (\Throwable $e) {
            // ignore
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public static function employeeUserIds(?int $employeeId): array
    {
        if (!$employeeId) {
            return [];
        }
        try {
            return User::where('employee_id', $employeeId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function employeeLabel(?employee $employee): string
    {
        if (!$employee) {
            return 'Employee';
        }

        return $employee->full_name ?: $employee->name_with_initials ?: 'Employee';
    }

    public static function notifyAdmins(string $title, string $body, array $data = []): void
    {
        self::notifyUsers(self::adminApproverIds(), $title, $body, $data);
    }

    public static function notifySuperAdmins(string $title, string $body, array $data = []): void
    {
        self::notifyUsers(self::superAdminApproverIds(), $title, $body, $data);
    }

    public static function leaveSubmitted(leave_master $leave): void
    {
        $status = (string) $leave->status;
        // Covering person is notified by LeaveNotificationService; only alert desk approvers here.
        if ($status === 'Pending_Covering') {
            return;
        }

        $name = self::employeeLabel($leave->employee);
        $range = trim(($leave->leave_from ?? '').' to '.($leave->leave_to ?? ''));

        if (in_array($status, ['Pending', 'Pending_Supervisor'], true) || $status === '') {
            self::notifyAdmins(
                'Leave approval needed',
                "{$name} submitted leave {$range}. Open Leave Approval.",
                ['type' => 'leave_approval', 'leave_id' => (string) $leave->id, 'status' => $status ?: 'Pending']
            );
        }
    }

    public static function leaveStatusNeedsNextApprover(leave_master $leave, string $status): void
    {
        $name = self::employeeLabel($leave->employee);
        $range = trim(($leave->leave_from ?? '').' to '.($leave->leave_to ?? ''));
        $data = ['type' => 'leave_approval', 'leave_id' => (string) $leave->id, 'status' => $status];

        if ($status === 'Pending_Supervisor' || $status === 'Pending') {
            self::notifyAdmins(
                'Leave approval needed',
                "{$name}'s leave {$range} is waiting for Admin approval.",
                $data
            );

            return;
        }

        if ($status === 'HR_Approved') {
            self::notifySuperAdmins(
                'Leave needs Super Admin',
                "{$name}'s leave {$range} was Admin-approved. Final approval needed.",
                $data
            );
        }
    }

    public static function advanceSubmitted(?employee $employee, $advanceId, $amount): void
    {
        $name = self::employeeLabel($employee);
        self::notifyAdmins(
            'Salary advance approval needed',
            "{$name} requested an advance of {$amount}. Review Advance Approvals.",
            ['type' => 'salary_advance', 'id' => (string) $advanceId, 'status' => 'PENDING']
        );
    }

    public static function advanceAwaitingSuperAdmin(?employee $employee, $advanceId, $amount): void
    {
        $name = self::employeeLabel($employee);
        self::notifySuperAdmins(
            'Advance needs Super Admin',
            "{$name}'s advance of {$amount} was Admin-approved. Final approval needed.",
            ['type' => 'salary_advance', 'id' => (string) $advanceId, 'status' => 'ADMIN_APPROVED']
        );
    }

    public static function loanSubmitted(?employee $employee, $loanId, $amount): void
    {
        $name = self::employeeLabel($employee);
        self::notifyAdmins(
            'Loan approval needed',
            "{$name} requested a loan of {$amount}. Review Loan approval.",
            ['type' => 'loan', 'loan_id' => (string) $loanId]
        );
    }

    public static function loanAwaitingSuperAdmin(?employee $employee, $loanId, $amount): void
    {
        $name = self::employeeLabel($employee);
        self::notifySuperAdmins(
            'Loan needs Super Admin',
            "{$name}'s loan of {$amount} was Admin-approved. Final approval needed.",
            ['type' => 'loan', 'loan_id' => (string) $loanId, 'status' => 'admin_approved']
        );
    }
}
