<?php

namespace App\Http\Controllers;

use App\Models\HrNotice;
use App\Models\UserPushToken;
use App\Models\employee;
use App\Services\AclService;
use App\Services\LeaveNotificationService;
use Illuminate\Database\Schema\Blueprint;
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

    private function ensureNoticeColumns(): void
    {
        if (!Schema::hasTable('hr_notices')) {
            return;
        }
        try {
            Schema::table('hr_notices', function (Blueprint $table) {
                if (!Schema::hasColumn('hr_notices', 'location_id')) {
                    $table->unsignedBigInteger('location_id')->nullable()->index();
                }
                if (!Schema::hasColumn('hr_notices', 'employee_ids')) {
                    $table->json('employee_ids')->nullable();
                }
            });
        } catch (\Throwable $e) {
            // column may already exist
        }
    }

    public function hrIndex(Request $request)
    {
        if ($request->user()?->role === 'employee') {
            abort(response()->json(['message' => 'Forbidden'], 403));
        }
        if (!Schema::hasTable('hr_notices')) {
            return response()->json(['items' => []]);
        }
        $this->ensureNoticeColumns();
        $companyId = $this->companyId($request);
        $with = ['department:id,name'];
        if (Schema::hasColumn('hr_notices', 'location_id')) {
            $with[] = 'location:id,name';
        }
        $q = HrNotice::with($with)->orderByDesc('id');
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
            'scope' => 'required|in:all,department,location,employees',
            'department_id' => 'nullable|required_if:scope,department|integer',
            'location_id' => 'nullable|required_if:scope,location|integer',
            'employee_ids' => 'nullable|required_if:scope,employees|array|min:1',
            'employee_ids.*' => 'integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        if (!Schema::hasTable('hr_notices')) {
            return response()->json(['message' => 'Run database migrate for HR notices.'], 500);
        }
        $this->ensureNoticeColumns();

        $companyId = $this->companyId($request);
        $scope = (string) $request->scope;
        $employeeIdsInput = collect($request->input('employee_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $attrs = [
            'company_id' => $companyId,
            'scope' => $scope,
            'department_id' => $scope === 'department' ? (int) $request->department_id : null,
            'title' => $request->title,
            'body' => $request->body,
            'created_by' => $request->user()->id,
        ];
        if (Schema::hasColumn('hr_notices', 'location_id')) {
            $attrs['location_id'] = $scope === 'location' ? (int) $request->location_id : null;
        }
        if (Schema::hasColumn('hr_notices', 'employee_ids')) {
            $attrs['employee_ids'] = $scope === 'employees' ? $employeeIdsInput : null;
        }

        $notice = HrNotice::create($attrs);

        $employeeIds = $this->resolveRecipientEmployeeIds($companyId, $scope, $request, $employeeIdsInput);
        foreach ($employeeIds as $employeeId) {
            LeaveNotificationService::notifyEmployee((int) $employeeId, $notice->title, $notice->body, [
                'type' => 'hr_notice',
                'notice_id' => $notice->id,
                'scope' => $notice->scope,
            ]);
        }

        return response()->json([
            'message' => 'Notice sent to '.$employeeIds->count().' employee(s) (in-app + push where enabled).',
            'data' => $notice->fresh(['department', 'location']),
            'recipient_count' => $employeeIds->count(),
        ], 201);
    }

    private function resolveRecipientEmployeeIds(?int $companyId, string $scope, Request $request, array $employeeIdsInput)
    {
        if ($scope === 'employees') {
            $q = employee::query()->whereIn('id', $employeeIdsInput);
            if ($companyId) {
                $q->whereHas('organizationAssignment', function ($oa) use ($companyId) {
                    $oa->where('company_id', $companyId);
                });
            }

            return $q->pluck('id');
        }

        return employee::query()
            ->whereHas('organizationAssignment', function ($q) use ($companyId, $scope, $request) {
                if ($companyId) {
                    $q->where('company_id', $companyId);
                }
                if ($scope === 'department') {
                    $q->where('department_id', (int) $request->department_id);
                }
                if ($scope === 'location') {
                    $q->where('location_id', (int) $request->location_id);
                }
            })
            ->pluck('id');
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
        $locationId = $emp->organizationAssignment->location_id ?? null;
        $employeeId = (int) $emp->id;
        if (!Schema::hasTable('hr_notices')) {
            return response()->json(['items' => []]);
        }
        $this->ensureNoticeColumns();

        $with = ['department:id,name'];
        if (Schema::hasColumn('hr_notices', 'location_id')) {
            $with[] = 'location:id,name';
        }

        $items = HrNotice::with($with)
            ->where(function ($q) use ($companyId) {
                if ($companyId) {
                    $q->where('company_id', $companyId)->orWhereNull('company_id');
                }
            })
            ->where(function ($q) use ($deptId, $locationId, $employeeId) {
                $q->where('scope', 'all')
                    ->orWhere(function ($d) use ($deptId) {
                        $d->where('scope', 'department')->where('department_id', $deptId);
                    });
                if (Schema::hasColumn('hr_notices', 'location_id')) {
                    $q->orWhere(function ($d) use ($locationId) {
                        $d->where('scope', 'location')->where('location_id', $locationId);
                    });
                }
                if (Schema::hasColumn('hr_notices', 'employee_ids')) {
                    $q->orWhere(function ($d) use ($employeeId) {
                        $d->where('scope', 'employees')
                            ->whereJsonContains('employee_ids', $employeeId);
                    });
                    // MySQL may store ints as numbers; also match string form
                    $q->orWhere(function ($d) use ($employeeId) {
                        $d->where('scope', 'employees')
                            ->whereJsonContains('employee_ids', (string) $employeeId);
                    });
                }
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
