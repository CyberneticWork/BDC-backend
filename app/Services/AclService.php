<?php

namespace App\Services;

use App\Models\company;
use App\Models\HrRole;
use App\Models\User;
use App\Models\UserAclPermission;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

        if ($this->isPortalRole($role)) {
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
        $data['role_label'] = $data['role_label'] ?? $this->roleLabel($role);
        $data['portal_only'] = !$isSuper && $this->isPortalRole($role);

        return $data;
    }

    public function ensureSchema(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'acl_customized')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('acl_customized')->default(false);
            });
        }

        if (Schema::hasTable('user_acl_permissions')) {
            return;
        }

        Schema::create('user_acl_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('module_key', 80);
            $table->boolean('can_view')->default(false);
            $table->boolean('can_add')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_approve')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'module_key'], 'user_acl_user_module_unique');
            $table->index('module_key');
        });
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
        if (!$this->isAclAssignableRole($targetRole)) {
            abort(422, 'ACL is allocated to staff roles only (not Admin or Employee).');
        }

        $this->ensureSchema();

        $company = $this->companyForUser($target);
        $allowedKeys = collect($this->enabledCatalog($company))->pluck('key')->all();
        $adminOnly = config('hr_acl.admin_only', []);
        $actionKeys = config('hr_acl.actions', ['view', 'add', 'edit', 'delete', 'approve']);
        $now = now();

        DB::transaction(function () use ($target, $modules, $allowedKeys, $adminOnly, $actionKeys, $now) {
            UserAclPermission::query()->where('user_id', $target->id)->delete();

            $rows = [];
            foreach ($modules as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = (string) ($row['module_key'] ?? $row['key'] ?? '');
                if ($key === '' || in_array($key, $adminOnly, true) || !in_array($key, $allowedKeys, true)) {
                    continue;
                }
                $meta = collect($this->catalog())->firstWhere('key', $key);
                $allowedActions = $meta['actions'] ?? $actionKeys;
                $rows[] = [
                    'user_id' => $target->id,
                    'module_key' => $key,
                    'can_view' => in_array('view', $allowedActions, true) && !empty($row['view'] ?? $row['can_view'] ?? false) ? 1 : 0,
                    'can_add' => in_array('add', $allowedActions, true) && !empty($row['add'] ?? $row['can_add'] ?? false) ? 1 : 0,
                    'can_edit' => in_array('edit', $allowedActions, true) && !empty($row['edit'] ?? $row['can_edit'] ?? false) ? 1 : 0,
                    'can_delete' => in_array('delete', $allowedActions, true) && !empty($row['delete'] ?? $row['can_delete'] ?? false) ? 1 : 0,
                    'can_approve' => in_array('approve', $allowedActions, true) && !empty($row['approve'] ?? $row['can_approve'] ?? false) ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows) {
                UserAclPermission::query()->insert($rows);
            }

            if (Schema::hasColumn('users', 'acl_customized')) {
                DB::table('users')->where('id', $target->id)->update([
                    'acl_customized' => 1,
                    'updated_at' => $now,
                ]);
                $target->acl_customized = true;
            }
        });

        return $this->effectivePermissions($target->fresh());
    }

    public function assignmentFor(User $target): array
    {
        $company = $this->companyForUser($target);
        $enabled = $this->enabledCatalog($company);
        $customized = Schema::hasColumn('users', 'acl_customized') && (bool) $target->acl_customized;
        $current = $customized
            ? $this->storedMap($target)
            : $this->roleTemplate($this->templateKeyFor((string) $target->role));

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

        $keys = $byRole[$this->templateKeyFor($role)] ?? $byRole['user'];
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

    public function ensureRoleSchema(): void
    {
        if (Schema::hasTable('users')) {
            try {
                $col = DB::selectOne("SHOW COLUMNS FROM `users` LIKE 'role'");
                $type = strtolower((string) ($col->Type ?? $col->type ?? ''));
                if ($type !== '' && str_contains($type, 'enum')) {
                    DB::statement("ALTER TABLE `users` MODIFY `role` VARCHAR(40) NOT NULL DEFAULT 'user'");
                }
            } catch (\Throwable) {
            }
        }

        if (!Schema::hasTable('hr_roles')) {
            Schema::create('hr_roles', function (Blueprint $table) {
                $table->id();
                $table->string('role_key', 40)->unique();
                $table->string('name', 80);
                $table->string('based_on', 40)->default('user');
                $table->boolean('is_system')->default(false);
                $table->timestamps();
            });
        }

        $now = now();
        foreach (HrRole::SYSTEM as $row) {
            if (!HrRole::query()->where('role_key', $row['role_key'])->exists()) {
                HrRole::query()->create([
                    'role_key' => $row['role_key'],
                    'name' => $row['name'],
                    'based_on' => $row['based_on'],
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function listRoles(): array
    {
        $this->ensureRoleSchema();
        $counts = User::query()
            ->selectRaw('role, COUNT(*) as c')
            ->groupBy('role')
            ->pluck('c', 'role');

        return HrRole::query()->orderByDesc('is_system')->orderBy('name')->get()->map(function (HrRole $role) use ($counts) {
            return [
                'id' => $role->id,
                'key' => $role->role_key,
                'name' => $role->name,
                'based_on' => $role->based_on,
                'is_system' => (bool) $role->is_system,
                'users_count' => (int) ($counts[$role->role_key] ?? 0),
                'acl_assignable' => $this->isAclAssignableRole($role->role_key),
            ];
        })->values()->all();
    }

    public function allowedRoleKeys(): array
    {
        $this->ensureRoleSchema();
        $keys = HrRole::query()->pluck('role_key')->map(fn ($k) => strtolower((string) $k))->all();

        return array_values(array_unique(array_merge(
            ['admin', 'hr', 'supervisor', 'user', 'employee'],
            $keys
        )));
    }

    public function createCustomRole(string $name, string $basedOn): HrRole
    {
        $this->ensureRoleSchema();
        $name = trim($name);
        $basedOn = strtolower(trim($basedOn));
        if ($name === '') {
            abort(422, 'Role name is required.');
        }
        if (!in_array($basedOn, HrRole::STAFF_BASES, true)) {
            abort(422, 'New roles must be based on HR, Supervisor, or User.');
        }

        $key = substr((string) Str::slug($name, '_'), 0, 40);
        if ($key === '' || in_array($key, ['admin', 'employee', 'super_admin'], true)) {
            abort(422, 'Choose a different role name.');
        }
        if (HrRole::query()->where('role_key', $key)->exists()) {
            abort(422, 'That role already exists.');
        }

        return HrRole::query()->create([
            'role_key' => $key,
            'name' => $name,
            'based_on' => $basedOn,
            'is_system' => false,
        ]);
    }

    public function deleteCustomRole(int $id): void
    {
        $this->ensureRoleSchema();
        $role = HrRole::query()->findOrFail($id);
        if ($role->is_system) {
            abort(422, 'System roles cannot be deleted.');
        }
        $fallback = in_array($role->based_on, HrRole::STAFF_BASES, true) ? $role->based_on : 'user';
        User::query()->where('role', $role->role_key)->update(['role' => $fallback]);
        $role->delete();
    }

    public function assignUserRole(User $actor, User $target, string $roleKey): User
    {
        $this->ensureRoleSchema();
        $roleKey = strtolower(trim($roleKey));
        if (!in_array($roleKey, $this->allowedRoleKeys(), true)) {
            abort(422, 'Unknown role.');
        }
        if (app(SuperAdminAuth::class)->isSuperAdmin($target) || (int) $target->id === 999999001) {
            abort(422, 'Super Admin role cannot be changed.');
        }
        if ($roleKey === 'admin' && !$this->isHrAdmin($actor)) {
            abort(403, 'Only Admin can assign the Administrator role.');
        }

        $payload = ['role' => $roleKey, 'updated_at' => now()];
        if ($this->isPortalRole($roleKey) && Schema::hasColumn('users', 'acl_customized')) {
            $payload['acl_customized'] = 0;
        }
        DB::table('users')->where('id', $target->id)->update($payload);

        return $target->fresh();
    }

    public function isPortalRole(string $role): bool
    {
        return strtolower($role) === 'employee';
    }

    public function isAclAssignableRole(string $role): bool
    {
        $role = strtolower($role);

        return $role !== '' && !in_array($role, ['admin', 'employee', 'super_admin'], true);
    }

    public function roleLabel(string $role): string
    {
        $role = strtolower($role);
        if (Schema::hasTable('hr_roles')) {
            $name = HrRole::query()->where('role_key', $role)->value('name');
            if ($name) {
                return (string) $name;
            }
        }
        $fallback = [
            'admin' => 'Administrator',
            'hr' => 'HR',
            'supervisor' => 'Supervisor',
            'user' => 'User',
            'employee' => 'Employee',
        ];

        return $fallback[$role] ?? ($role !== '' ? $role : 'User');
    }

    public function templateKeyFor(string $role): string
    {
        $role = strtolower($role);
        if (in_array($role, ['admin', 'hr', 'supervisor', 'user', 'employee'], true)) {
            return $role;
        }
        if (!Schema::hasTable('hr_roles')) {
            return 'user';
        }
        $based = strtolower((string) (HrRole::query()->where('role_key', $role)->value('based_on') ?: 'user'));

        return in_array($based, ['admin', 'hr', 'supervisor', 'user', 'employee'], true) ? $based : 'user';
    }
}
