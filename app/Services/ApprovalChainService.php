<?php

namespace App\Services;

use App\Models\User;
use App\Models\employee;

/**
 * Cybernetic Admin approval_chain pack: Employee → Admin → Super Admin.
 * Admin requesters skip straight to the Super Admin queue.
 */
class ApprovalChainService
{
    public static function enabledForEmployee(?employee $employee): bool
    {
        if (!$employee) {
            return false;
        }

        return CompanyProcessSettings::usesApprovalChain($employee);
    }

    public static function isSuperAdmin(?User $user): bool
    {
        return app(SuperAdminAuth::class)->isSuperAdmin($user);
    }

    /** Desk Admin / HR / Supervisor (not Super Admin). */
    public static function isAdminApprover(?User $user): bool
    {
        if (!$user || self::isSuperAdmin($user)) {
            return false;
        }
        $role = strtolower((string) ($user->role ?? ''));

        return in_array($role, ['admin', 'hr', 'supervisor'], true);
    }

    /**
     * Resolve next status when an approver acts.
     *
     * @param  array{pending:string,intermediate:string,final:string,rejected:string}  $statuses
     */
    public static function resolveApproveTransition(
        ?User $actor,
        bool $chainEnabled,
        string $current,
        string $requested,
        array $statuses
    ): array {
        $pending = $statuses['pending'];
        $intermediate = $statuses['intermediate'];
        $final = $statuses['final'];
        $rejected = $statuses['rejected'];

        $wantReject = in_array(strtolower($requested), ['reject', 'rejected'], true)
            || strcasecmp($requested, $rejected) === 0;
        if ($wantReject) {
            return ['status' => $rejected, 'ok' => true];
        }

        $wantApprove = in_array(strtolower($requested), ['approve', 'approved', 'active'], true)
            || strcasecmp($requested, $final) === 0
            || strcasecmp($requested, $intermediate) === 0
            || strcasecmp($requested, 'Approved') === 0;

        if (!$wantApprove) {
            return ['status' => $requested, 'ok' => true];
        }

        if (!$chainEnabled) {
            return ['status' => $final, 'ok' => true];
        }

        $isSuper = self::isSuperAdmin($actor);

        if ($isSuper) {
            return ['status' => $final, 'ok' => true];
        }

        if (strcasecmp($current, $pending) === 0 || strcasecmp($current, $intermediate) === 0) {
            if (strcasecmp($current, $intermediate) === 0 && strcasecmp($requested, $final) === 0) {
                return [
                    'status' => $intermediate,
                    'ok' => false,
                    'message' => 'Final approval requires Super Admin.',
                ];
            }

            return ['status' => $intermediate, 'ok' => true];
        }

        return [
            'status' => $current,
            'ok' => false,
            'message' => 'Final approval requires Super Admin.',
        ];
    }

    /** Initial status when a desk admin (not SA) creates a request under the chain. */
    public static function initialStatusForRequester(?User $user, bool $chainEnabled, array $statuses): string
    {
        if (!$chainEnabled) {
            return $statuses['pending'];
        }
        if (self::isSuperAdmin($user)) {
            return $statuses['final'];
        }
        if (self::isAdminApprover($user)) {
            return $statuses['intermediate'];
        }

        return $statuses['pending'];
    }
}
