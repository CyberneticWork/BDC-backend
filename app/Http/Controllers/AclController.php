<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AclService;
use App\Services\SuperAdminAuth;
use Illuminate\Http\Request;

class AclController extends Controller
{
    public function __construct(private AclService $acl)
    {
    }

    public function me(Request $request)
    {
        return response()->json($this->acl->userPayload($request->user()));
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
    }

    private function assertAdmin(Request $request): void
    {
        $role = strtolower((string) ($request->user()?->role));
        if ($role !== 'admin' && !app(SuperAdminAuth::class)->isSuperAdmin($request->user())) {
            abort(403, 'Only Admin can allocate HR user ACL.');
        }
    }
}
