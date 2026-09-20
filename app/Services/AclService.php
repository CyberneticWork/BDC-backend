<?php

namespace App\Services;

use App\Models\company;
use App\Models\User;
use App\Models\UserAclPermission;
use Illuminate\Support\Facades\Schema;

class AclService
{
    public function catalog(): array
    {
        return array_values(config('hr_acl.modules', []));
    }

    public function companyForUser(?User $user): ?company
    {
        if (!$user) {
            return company::query()->where('portal_active', true)->first()
                ?? company::query()->orderBy('id')->first();
        }

        $user->loadMissing('employee.organizationAssignment.company');
        $company = $user->employee?->organizationAssignment?->company;
        if ($company instanceof company) {
            return $company;
        }

        return company::query()->where('portal_active', true)->first()
            ?? company::query()->orderBy('id')->first();
    }

    public function companyFeatures(?company $company): array
    {
        return [
            'attendance_process' => CompanyProcessSettings::modeOf($company),
            'leave_workflow' => CompanyProcessSettings::usesLeaveWorkflow($company),
            'weekly_off' => CompanyProcessSettings::usesWeeklyOff($company),
            'medical_claims' => CompanyProcessSettings::usesMedicalClaims($company),
            'medical_leave' => CompanyProcessSettings::usesMedicalLeave($company),
            'salary_advance' => CompanyProcessSettings::usesSalaryAdvancePack($company),
        ];
    }

    public function moduleEnabledForCompany(string $moduleKey, ?company $company): bool
    {
        $always = config('hr_acl.always', []);
        if (in_array($moduleKey, $always, true)) {
            return true;
        }

        $packModules = config('hr_acl.pack_modules', []);
        foreach ($packModules as $pack => $keys) {
            if (in_array($moduleKey, $keys, true)) {
                return CompanyProcessSettings::packEnabled($company, $pack);
            }
        }

        $any = config('hr_acl.any_pack_modules', []);
        if (isset($any[$moduleKey]) && is_array($any[$moduleKey])) {
            foreach ($any[$moduleKey] as $pack) {
                if (CompanyProcessSettings::packEnabled($company, $pack)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    public function enabledCatalog(?company $company): array
    {
        return array_values(array_filter(
            $this->catalog(),
            fn ($module) => $this->moduleEnabledForCompany($module['key'], $company)
        ));
    }

    public function effectivePermissions(User $user): array
    {
        $company = $this->companyForUser($user);
        $role = strtolower((string) $user->role);
        $enabled = $this->enabledCatalog($company);

        if ($role === 'admin' || app(SuperAdminAuth::class)->isSuperAdmin($user)) {
            return $this->fullMap($enabled, true);
        }

        $portalOnly = $role === 'employee'
            || ($user->employee_id && !in_array($role, ['admin', 'hr', 'supervisor'], true));
        if ($portalOnly) {
            return $this->intersectEnabled($this->roleTemplate('employee'), $enabled);
        }

        $customized = Schema::hasColumn('users', 'acl_customized') && (bool) $user->acl_customized;
        $source = $customized
            ? $this->storedMap($user)
            : $this->roleTemplate($role);

        $map = $this->intersectEnabled($source, $enabled);

        foreach (config('hr_acl.admin_only', []) as $key) {
            unset($map[$key]);
        }

        return $map;
    }

    public function can(User $user, string $module, string $action): bool
    {
        $perms = $this->effectivePermissions($user);

        return !empty($perms[$module][$action]);
    }

    public function userPayload(User $user): array
    {
        $company = $this->companyForUser($user);
        $data = $user->toArray();
        $data['permissions'] = $this->effectivePermissions($user);
        $data['company_features'] = $this->companyFeatures($company);
        $isSuper = app(SuperAdminAuth::class)->isSuperAdmin($user);
        $data['is_super_admin'] = $isSuper;
        $data['acl_assignable'] = $this->isHrAdmin($user);
        $role = strtolower((string) $user->role);
        if ($isSuper) {
            $data['role'] = 'admin';
            $data['role_label'] = 'Super Admin';
        }
        $data['portal_only'] = !$isSuper && ($role === 'employee'
            || ($user->employee_id && !in_array($role, ['admin', 'hr', 'supervisor'], true)));

        return $data;
    }

    public function saveUserAcl(User $actor, User $target, array $modules): array
    {
        if (!$this->isHrAdmin($actor)) {
            abort(403, 'Only Admin can allocate HR user ACL.');
        }

        if (app(SuperAdminAuth::class)->isSuperAdmin($target) || (int) $target->id === 999999001) {
            abort(422, 'Super Admin permissions cannot be changed.');
        }

        $targetRole = strtolower((string) $target->role);
        if (in_array($targetRole, ['admin', 'employee'], true)) {
            abort(422, 'ACL is allocated to HR / supervisor / user accounts only.');
        }

        $company = $this->companyForUser($target);
        $allowedKeys = collect($this->enabledCatalog($company))->pluck('key')->all();
        $adminOnly = config('hr_acl.admin_only', []);
        $actionKeys = config('hr_acl.actions', ['view', 'add', 'edit', 'delete', 'approve']);

        UserAclPermission::query()->where('user_id', $target->id)->delete();

        foreach ($modules as $row) {
            $key = (string) ($row['module_key'] ?? $row['key'] ?? '');
            if ($key === '' || in_array($key, $adminOnly, true) || !in_array($key, $allowedKeys, true)) {
                continue;
            }
            $meta = collect($this->catalog())->firstWhere('key', $key);
            $allowedActions = $meta['actions'] ?? $actionKeys;

            UserAclPermission::create([
                'user_id' => $target->id,
                'module_key' => $key,
                'can_view' => in_array('view', $allowedActions, true) && !empty($row['view'] ?? $row['can_view'] ?? false),
                'can_add' => in_array('add', $allowedActions, true) && !empty($row['add'] ?? $row['can_add'] ?? false),
                'can_edit' => in_array('edit', $allowedActions, true) && !empty($row['edit'] ?? $row['can_edit'] ?? false),
                'can_delete' => in_array('delete', $allowedActions, true) && !empty($row['delete'] ?? $row['can_delete'] ?? false),
                'can_approve' => in_array('approve', $allowedActions, true) && !empty($row['approve'] ?? $row['can_approve'] ?? false),
            ]);
        }

        if (Schema::hasColumn('users', 'acl_customized')) {
            $target->acl_customized = true;
            $target->save();
        }

        return $this->effectivePermissions($target->fresh());
    }

    public function assignmentFor(User $target): array
    {
        $company = $this->companyForUser($target);
        $enabled = $this->enabledCatalog($company);
        $customized = Schema::hasColumn('users', 'acl_customized') && (bool) $target->acl_customized;
        $current = $customized
            ? $this->storedMap($target)
            : $this->roleTemplate(strtolower((string) $target->role));

        $rows = [];
        foreach ($enabled as $module) {
            if (in_array($module['key'], config('hr_acl.admin_only', []), true)) {
                continue;
            }
            $actions = $current[$module['key']] ?? [];
            $rows[] = array_merge($module, [
                'view' => !empty($actions['view']),
                'add' => !empty($actions['add']),
                'edit' => !empty($actions['edit']),
                'delete' => !empty($actions['delete']),
                'approve' => !empty($actions['approve']),
            ]);
        }

        return [
            'user' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'role' => $target->role,
                'acl_customized' => Schema::hasColumn('users', 'acl_customized') && (bool) $target->acl_customized,
            ],
            'company_features' => $this->companyFeatures($company),
            'modules' => $rows,
        ];
    }

    public function isHrAdmin(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return strtolower((string) $user->role) === 'admin'
            || app(SuperAdminAuth::class)->isSuperAdmin($user);
    }

    private function storedMap(User $user): array
    {
        if (!Schema::hasTable('user_acl_permissions')) {
            return [];
        }

        $map = [];
        foreach (UserAclPermission::query()->where('user_id', $user->id)->get() as $row) {
            $map[$row->module_key] = $row->toActions();
        }

        foreach (config('hr_acl.always', []) as $key) {
            $map[$key] = array_merge($map[$key] ?? [], ['view' => true]);
        }

        return $map;
    }

    private function fullMap(array $enabled, bool $allActions): array
    {
        $map = [];
        foreach ($enabled as $module) {
            $entry = [];
            foreach ($module['actions'] ?? ['view'] as $action) {
                $entry[$action] = $allActions;
            }
            $map[$module['key']] = $entry;
        }

        return $map;
    }

    private function intersectEnabled(array $source, array $enabled): array
    {
        $map = [];
        foreach ($enabled as $module) {
            $key = $module['key'];
            if (empty($source[$key])) {
                continue;
            }
            $entry = [];
            foreach ($module['actions'] ?? ['view'] as $action) {
                $entry[$action] = !empty($source[$key][$action])
                    || ($action === 'edit' && !empty($source[$key]['update']))
                    || ($action === 'add' && !empty($source[$key]['create']));
            }
            if (!empty($entry['view']) || !empty($entry['edit']) || !empty($entry['add']) || !empty($entry['approve'])) {
                $entry['view'] = true;
                $map[$key] = $entry;
            }
        }

        foreach (config('hr_acl.always', []) as $key) {
            $map[$key] = array_merge($map[$key] ?? [], ['view' => true]);
        }

        return $map;
    }

    public function roleTemplate(string $role): array
    {
        $enabled = $this->catalog();
        $full = $this->fullMap($enabled, true);

        $byRole = [
            'admin' => array_keys($full),
            'hr' => array_keys($full),
            'supervisor' => [
                'dashboard', 'show', 'employeeMaster', 'departmentMaster', 'leaveApproval',
                'hrLeaveApproval', 'supervisorLeaveApproval', 'leaveMaster', 'leavecalendar',
                'timeCardApproval', 'weeklyOffManagement', 'medicalClaims', 'pendingPayments',
                'timeCardAuditReport', 'deletedTimeCardReport', 'monthlyWorkingHoursReport',
                'monthlyOtHoursReport', 'dailyOtHoursReport', 'midShiftBreaks', 'allowancesReport', 'createNewBonus',
                'dinnerAllowance',
            ],
            'user' => [
                'dashboard', 'leaveMaster', 'leavecalendar', 'createNewBonus',
            ],
            'employee' => [
                'employeePortal',
            ],
        ];

        $keys = $byRole[$role] ?? $byRole['user'];
        $map = [];
        foreach ($enabled as $module) {
            if (!in_array($module['key'], $keys, true)) {
                continue;
            }
            $entry = [];
            foreach ($module['actions'] ?? ['view'] as $action) {
                $entry[$action] = true;
            }
            $map[$module['key']] = $entry;
        }

        return $map;
    }
}
