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
