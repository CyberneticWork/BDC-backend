<?php

namespace App\Http\Controllers;

use App\Models\company;
use App\Services\AclService;
use App\Services\CompanyProcessSettings;
use App\Services\SuperAdminAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Company create from Department Master (add-on `hr_company_create`).
 * HR may only set basic fields; branding, portal host and add-ons stay with Cybernetic Admin.
 */
class HrCompanyController extends Controller
{
    public function __construct(private AclService $acl)
    {
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $seed = $this->acl->companyForUser($user);

        if (!CompanyProcessSettings::usesHrCompanyCreate($seed)) {
            return response()->json([
                'message' => 'Company creation is not enabled for your company. Ask Cybernetic Admin to allow it.',
            ], 403);
        }

        $isSuper = app(SuperAdminAuth::class)->isSuperAdmin($user);
        if (!$isSuper && !$this->acl->can($user, 'departmentMaster', 'add')) {
            return response()->json([
                'message' => 'You do not have permission to add companies (Department Master → add).',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'company_code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-_\/ ]{0,49}$/'],
            'name' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'established' => 'nullable|digits:4|integer|min:1900|max:'.date('Y'),
            'nopay_working_days' => 'nullable|integer|min:1|max:31',
        ], [
            'company_code.required' => 'Company ID is required.',
            'company_code.regex' => 'Company ID may only contain letters, numbers, and - _ / characters.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $code = strtoupper(trim($data['company_code']));
        $name = trim($data['name']);

        if (company::where('company_code', $code)->whereNull('deleted_at')->exists()) {
            return response()->json([
                'message' => 'Company ID already exists.',
                'errors' => ['company_code' => ['This Company ID is already in use. Please enter a unique code.']],
            ], 409);
        }

        if (company::whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])->whereNull('deleted_at')->exists()) {
            return response()->json([
                'message' => 'A company with this name already exists.',
                'errors' => ['name' => ['A company with this name already exists.']],
            ], 409);
        }

        $attributes = [
            'company_code' => $code,
            'name' => $name,
            'location' => trim($data['location']),
            'established' => $data['established'] ?? null,
        ];
        $optional = [
            'nopay_working_days' => (int) ($data['nopay_working_days'] ?? 30) ?: 30,
            'org_group' => $seed?->org_group,
            'attendance_process' => $seed?->attendance_process,
            'portal_active' => false,
        ];
        foreach ($optional as $column => $value) {
            if ($value !== null && Schema::hasColumn('companies', $column)) {
                $attributes[$column] = $value;
            }
        }

        $company = company::create($attributes);

        return response()->json($company->fresh(), 201);
    }
}
