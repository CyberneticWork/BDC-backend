<?php

namespace App\Http\Controllers;

use App\Models\HrNotice;
use App\Models\User;
use App\Models\UserPushToken;
use App\Models\employee;
use App\Services\AclService;
use App\Services\FcmPushService;
use App\Services\LeaveNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class HrNoticeController extends Controller
{
    private function companyId(Request $request): ?int
    {
        $company = app(AclService::class)->companyForUser($request->user());

        return $company?->id;
    }

    public function hrIndex(Request $request)
    {
        if ($request->user()?->role === 'employee') {
            abort(response()->json(['message' => 'Forbidden'], 403));
        }
        if (!Schema::hasTable('hr_notices')) {
            return response()->json(['items' => []]);
        }
        $companyId = $this->companyId($request);
        $q = HrNotice::with('department:id,name')->orderByDesc('id');
        if ($companyId) {
            $q->where(function ($inner) use ($companyId) {
                $inner->where('company_id', $companyId)->orWhereNull('company_id');
            });
        }

        return response()->json(['items' => $q->limit(200)->get()]);
    }

    public function store(Request $request)
    {
        if ($request->user()?->role === 'employee') {
            abort(response()->json(['message' => 'Forbidden'], 403));
        }
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:180',
            'body' => 'required|string|max:4000',
            'scope' => 'required|in:all,department',
            'department_id' => 'nullable|required_if:scope,department|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        if (!Schema::hasTable('hr_notices')) {
            return response()->json(['message' => 'Run database migrate for HR notices.'], 500);
        }

        $companyId = $this->companyId($request);
        $notice = HrNotice::create([
            'company_id' => $companyId,
            'scope' => $request->scope,
            'department_id' => $request->scope === 'department' ? (int) $request->department_id : null,
            'title' => $request->title,
            'body' => $request->body,
            'created_by' => $request->user()->id,
        ]);

        $empQuery = employee::query()->whereHas('organizationAssignment', function ($q) use ($companyId, $request) {
            if ($companyId) {
                $q->where('company_id', $companyId);
            }
            if ($request->scope === 'department') {
                $q->where('department_id', (int) $request->department_id);
            }
        });
        $employeeIds = $empQuery->pluck('id');
        $userIds = User::whereIn('employee_id', $employeeIds)->pluck('id')->all();
        foreach ($employeeIds as $employeeId) {
            LeaveNotificationService::notifyEmployee((int) $employeeId, $notice->title, $notice->body, [
                'type' => 'hr_notice',
                'notice_id' => $notice->id,
                'scope' => $notice->scope,
            ]);
        }
        app(FcmPushService::class)->sendToUsers($userIds, $notice->title, $notice->body, [
            'type' => 'hr_notice',
            'notice_id' => (string) $notice->id,
        ]);

        return response()->json([
            'message' => 'Notice sent to '.$employeeIds->count().' employee(s).',
            'data' => $notice,
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        if ($request->user()?->role === 'employee') {
            abort(response()->json(['message' => 'Forbidden'], 403));
        }
        HrNotice::findOrFail($id)->delete();

        return response()->json(['message' => 'Notice removed']);
    }

    public function portalIndex(Request $request)
    {
        $emp = app(EmployeePortalController::class)->linkedEmployee($request);
        $companyId = $emp->organizationAssignment->company_id ?? null;
        $deptId = $emp->organizationAssignment->department_id ?? null;
        if (!Schema::hasTable('hr_notices')) {
            return response()->json(['items' => []]);
        }
        $items = HrNotice::with('department:id,name')
            ->where(function ($q) use ($companyId) {
                if ($companyId) {
                    $q->where('company_id', $companyId)->orWhereNull('company_id');
                }
            })
            ->where(function ($q) use ($deptId) {
                $q->where('scope', 'all')
                    ->orWhere(function ($d) use ($deptId) {
                        $d->where('scope', 'department')->where('department_id', $deptId);
                    });
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['items' => $items]);
    }

    public function savePushToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string|max:4096',
            'platform' => 'nullable|string|max:30',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed'], 422);
        }
        if (!Schema::hasTable('user_push_tokens')) {
            return response()->json(['message' => 'ok'], 200);
        }
        UserPushToken::updateOrCreate(
            ['user_id' => $request->user()->id, 'token' => $request->token],
            ['platform' => $request->input('platform', 'web')]
        );

        return response()->json(['message' => 'Push alerts enabled']);
    }
}
