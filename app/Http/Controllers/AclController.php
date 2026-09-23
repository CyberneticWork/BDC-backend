<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AclService;
use App\Services\SuperAdminAuth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class AclController extends Controller
{
    public function __construct(private AclService $acl)
    {
    }

    public function me(Request $request)
    {
        return response()->json($this->acl->userPayload($request->user()));
    }

    public function roles(Request $request)
    {
        try {
            return response()->json(['roles' => $this->acl->listRoles()]);
        } catch (Throwable $e) {
            Log::error('ACL list roles failed', ['error' => $e->getMessage()]);
            return response()->json([
                'roles' => [
                    ['id' => 0, 'key' => 'admin', 'name' => 'Administrator', 'based_on' => 'admin', 'is_system' => true, 'users_count' => 0, 'acl_assignable' => false],
                    ['id' => 0, 'key' => 'hr', 'name' => 'HR', 'based_on' => 'hr', 'is_system' => true, 'users_count' => 0, 'acl_assignable' => true],
                    ['id' => 0, 'key' => 'supervisor', 'name' => 'Supervisor', 'based_on' => 'supervisor', 'is_system' => true, 'users_count' => 0, 'acl_assignable' => true],
                    ['id' => 0, 'key' => 'user', 'name' => 'User', 'based_on' => 'user', 'is_system' => true, 'users_count' => 0, 'acl_assignable' => true],
                    ['id' => 0, 'key' => 'employee', 'name' => 'Employee', 'based_on' => 'employee', 'is_system' => true, 'users_count' => 0, 'acl_assignable' => false],
                ],
            ]);
        }
    }

    public function storeRole(Request $request)
    {
        try {
            $this->assertAdmin($request);
            $role = $this->acl->createCustomRole(
                (string) $request->input('name', ''),
                (string) $request->input('based_on', 'user')
            );

            return response()->json([
                'message' => 'Role created',
                'role' => [
                    'id' => $role->id,
                    'key' => $role->role_key,
                    'name' => $role->name,
                    'based_on' => $role->based_on,
                    'is_system' => false,
                ],
                'roles' => $this->acl->listRoles(),
            ], 201);
        } catch (HttpException $e) {
            return response()->json(
                ['message' => $e->getMessage() ?: 'Could not create role.'],
                $e->getStatusCode() ?: 422
            );
        } catch (Throwable $e) {
            Log::error('ACL create role failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Could not create role. Run deploy/sql/2026_09_hr_roles.sql in phpMyAdmin first.',
            ], 422);
        }
    }

    public function destroyRole(Request $request, $id)
    {
        try {
            $this->assertAdmin($request);
            $this->acl->deleteCustomRole((int) $id);

            return response()->json([
                'message' => 'Role deleted',
                'roles' => $this->acl->listRoles(),
            ]);
        } catch (HttpException $e) {
            return response()->json(
                ['message' => $e->getMessage() ?: 'Could not delete role.'],
                $e->getStatusCode() ?: 422
            );
        } catch (Throwable $e) {
            Log::error('ACL delete role failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not delete role.'], 422);
        }
    }

    public function assignRole(Request $request)
    {
        try {
            $this->assertAdmin($request);
            $target = User::findOrFail($request->input('user_id'));
            $user = $this->acl->assignUserRole(
                $request->user(),
                $target,
                (string) $request->input('role', '')
            );

            return response()->json([
                'message' => 'Role assigned',
                'user' => $user,
                'roles' => $this->acl->listRoles(),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'User not found.'], 404);
        } catch (HttpException $e) {
            return response()->json(
                ['message' => $e->getMessage() ?: 'Could not assign role.'],
                $e->getStatusCode() ?: 422
            );
        } catch (Throwable $e) {
            Log::error('ACL assign role failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Could not assign role. Run deploy/sql/2026_09_hr_roles.sql in phpMyAdmin if the users.role column is still an enum.',
            ], 422);
        }
    }

    public function catalog(Request $request)
    {
        $this->assertAdmin($request);
        $company = $this->acl->companyForUser($request->user());

        return response()->json([
            'company_features' => $this->acl->companyFeatures($company),
            'modules' => $this->acl->enabledCatalog($company),
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->assertAdmin($request);
        $target = User::findOrFail($id);

        return response()->json($this->acl->assignmentFor($target));
    }

    public function update(Request $request, $id)
    {
        try {
            $this->assertAdmin($request);
            $target = User::findOrFail($id);
            $modules = $request->input('modules', []);
            if (!is_array($modules)) {
                return response()->json(['message' => 'modules must be an array'], 422);
            }

            $permissions = $this->acl->saveUserAcl($request->user(), $target, $modules);

            return response()->json([
                'message' => 'ACL saved',
                'permissions' => $permissions,
                'assignment' => $this->acl->assignmentFor($target->fresh()),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'User not found.'], 404);
        } catch (HttpException $e) {
            return response()->json(
                ['message' => $e->getMessage() ?: 'ACL save was rejected.'],
                $e->getStatusCode() ?: 422
            );
        } catch (Throwable $e) {
            Log::error('ACL save failed', ['user_id' => $id, 'error' => $e->getMessage()]);
            $raw = $e->getMessage();
            if (
                str_contains($raw, "doesn't exist")
                || str_contains($raw, 'Base table')
                || str_contains($raw, 'Unknown column')
            ) {
                return response()->json([
                    'message' => 'ACL database tables are missing. Run deploy/sql/2026_09_live_user_acl.sql in phpMyAdmin, then save again.',
                ], 422);
            }

            return response()->json([
                'message' => 'Could not save ACL. '.$raw,
            ], 422);
        }
    }

    private function assertAdmin(Request $request): void
    {
        $role = strtolower((string) ($request->user()?->role));
        if ($role !== 'admin' && !app(SuperAdminAuth::class)->isSuperAdmin($request->user())) {
            abort(403, 'Only Admin can allocate HR user ACL.');
        }
    }
}
