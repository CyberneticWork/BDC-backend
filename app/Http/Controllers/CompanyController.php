<?php

namespace App\Http\Controllers;

use App\Models\company;
use App\Services\CompanyThemeService;
use App\Services\CyberneticAdminAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CompanyController extends Controller
{
    public function __construct(
        private CompanyThemeService $themeService,
        private CyberneticAdminAuth $cyberneticAuth,
    ) {
    }

    public function index()
    {
        $cols = [
            'id', 'company_code', 'name', 'location', 'established',
        ];
        foreach ([
            'nopay_working_days', 'default_sports_fund_percentage',
            'slug', 'frontend_host', 'logo_url', 'theme_primary', 'theme_secondary', 'theme_accent', 'theme_json',
            'late_attendance_policy_enabled',
        ] as $col) {
            if (Schema::hasColumn('companies', $col)) {
                $cols[] = $col;
            }
        }

        return response()->json(company::select($cols)->get());
    }

    /** Public branding for login / tenant host (no auth). */
    public function publicBranding(Request $request)
    {
        if (!Schema::hasColumn('companies', 'slug')) {
            return response()->json(['data' => null]);
        }

        $host = strtolower(trim((string) $request->query('host', '')));
        $host = preg_replace('/^www\./', '', $host);
        $slug = strtolower(trim((string) $request->query('slug', '')));

        $query = company::query()->whereNull('deleted_at');
        $company = null;
        if ($host !== '') {
            $company = (clone $query)->whereRaw('LOWER(frontend_host) = ?', [$host])->first();
        }
        if (!$company && $slug !== '') {
            $company = (clone $query)->whereRaw('LOWER(slug) = ?', [$slug])->first();
        }

        return response()->json(['data' => $company ? $this->brandingPayload($company) : null]);
    }

    public function store(Request $request)
    {
        if (!$this->cyberneticAuth->checkRequest($request)) {
            return response()->json([
                'message' => 'Only Cybernetic Admin can create companies and branding.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'company_code' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'established' => 'nullable|digits:4|integer|min:1900|max:' . (date('Y')),
            'nopay_working_days' => 'nullable|integer|min:1|max:31',
            'slug' => 'nullable|string|max:80',
            'frontend_host' => 'nullable|string|max:191',
            'logo_url' => 'nullable|string|max:500',
            'theme_primary' => 'nullable|string|max:20',
            'theme_secondary' => 'nullable|string|max:20',
            'theme_accent' => 'nullable|string|max:20',
            'late_attendance_policy_enabled' => 'sometimes|boolean',
        ], [
            'company_code.required' => 'Company ID is required. Please enter a unique code (e.g. SPM-S, SPM-C).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $this->normalizeCompanyPayload($validator->validated());

        $codeExists = company::where('company_code', $validated['company_code'])
            ->whereNull('deleted_at')
            ->exists();

        if ($codeExists) {
            return response()->json([
                'message' => 'Company ID already exists.',
                'errors' => ['company_code' => ['This Company ID is already in use. Please enter a unique code.']],
            ], 409);
        }

        $company = company::create($this->onlyExistingColumns($validated));
        return response()->json($company, 201);
    }

    public function update(Request $request, $id)
    {
        $company = company::findOrFail($id);
        $cybernetic = $this->cyberneticAuth->checkRequest($request);

        $rules = [
            'location' => 'nullable|string|max:255',
            'established' => 'nullable|digits:4|integer|min:1900|max:' . (date('Y')),
            'nopay_working_days' => 'nullable|integer|min:1|max:31',
        ];
        if ($cybernetic) {
            $rules['company_code'] = 'required|string|max:50';
            $rules['name'] = 'required|string|max:255';
            $rules['slug'] = 'nullable|string|max:80';
            $rules['frontend_host'] = 'nullable|string|max:191';
            $rules['logo_url'] = 'nullable|string|max:500';
            $rules['theme_primary'] = 'nullable|string|max:20';
            $rules['theme_secondary'] = 'nullable|string|max:20';
            $rules['theme_accent'] = 'nullable|string|max:20';
            $rules['late_attendance_policy_enabled'] = 'sometimes|boolean';
        } else {
            $rules['company_code'] = 'sometimes|string|max:50';
            $rules['name'] = 'sometimes|string|max:255';
        }

        $validator = Validator::make($request->all(), $rules, [
            'company_code.required' => 'Company ID is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        if (isset($validated['company_code'])) {
            $validated['company_code'] = strtoupper(trim($validated['company_code']));
        }
        if (isset($validated['name'])) {
            $validated['name'] = trim($validated['name']);
        }
        if (isset($validated['location'])) {
            $validated['location'] = trim($validated['location']);
        }

        if (!$request->exists('nopay_working_days')) {
            unset($validated['nopay_working_days']);
        } else {
            $validated['nopay_working_days'] = (int) $validated['nopay_working_days'];
            if ($validated['nopay_working_days'] < 1) {
                $validated['nopay_working_days'] = 30;
            }
        }

        if (!$cybernetic) {
            unset(
                $validated['slug'],
                $validated['frontend_host'],
                $validated['logo_url'],
                $validated['theme_primary'],
                $validated['theme_secondary'],
                $validated['theme_accent'],
                $validated['theme_json']
            );
        } else {
            $validated = $this->applyBrandingFields($validated, $company);
            if (Schema::hasColumn('companies', 'late_attendance_policy_enabled') && $request->exists('late_attendance_policy_enabled')) {
                $validated['late_attendance_policy_enabled'] = filter_var($request->input('late_attendance_policy_enabled'), FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (isset($validated['company_code'])) {
            $codeExists = company::where('company_code', $validated['company_code'])
                ->where('id', '!=', $company->id)
                ->whereNull('deleted_at')
                ->exists();
            if ($codeExists) {
                return response()->json([
                    'message' => 'Company ID already exists.',
                    'errors' => ['company_code' => ['This Company ID is already in use. Please enter a unique code.']],
                ], 409);
            }
        }

        $company->update($this->onlyExistingColumns($validated));
        return response()->json($company->fresh());
    }

    public function uploadLogo(Request $request, $id)
    {
        if (!$this->cyberneticAuth->checkRequest($request)) {
            return response()->json(['message' => 'Only Cybernetic Admin can upload company logos.'], 401);
        }

        $company = company::findOrFail($id);
        $request->validate([
            'logo' => 'required|file|mimes:png,jpg,jpeg,webp,svg|max:4096',
            'logo_url' => 'nullable|url|max:500',
        ]);

        $url = $request->input('logo_url');
        $theme = null;
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('company-logos', 'public');
            $url = url('/storage/' . $path);
            $abs = Storage::disk('public')->path($path);
            $theme = $this->themeService->themeFromLogoFile($abs);
        }

        $payload = ['logo_url' => $url];
        if ($theme) {
            $payload['theme_primary'] = $theme['primary'];
            $payload['theme_secondary'] = $theme['secondary'];
            $payload['theme_accent'] = $theme['accent'];
            $payload['theme_json'] = $theme;
        }
        $company->update($payload);

        return response()->json([
            'message' => 'Logo saved',
            'data' => $company->fresh(),
            'theme' => $theme,
        ]);
    }

    public function destroy($id)
    {
        $company = company::findOrFail($id);
        $company->delete();
        return response()->json(['message' => 'Deleted'], 204);
    }

    private function normalizeCompanyPayload(array $validated): array
    {
        $validated['company_code'] = strtoupper(trim($validated['company_code']));
        $validated['name'] = trim($validated['name']);
        $validated['location'] = isset($validated['location']) ? trim($validated['location']) : null;
        $validated['nopay_working_days'] = (int) ($validated['nopay_working_days'] ?? 30);
        if ($validated['nopay_working_days'] < 1) {
            $validated['nopay_working_days'] = 30;
        }
        if (Schema::hasColumn('companies', 'late_attendance_policy_enabled') && array_key_exists('late_attendance_policy_enabled', $validated)) {
            $validated['late_attendance_policy_enabled'] = filter_var($validated['late_attendance_policy_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        return $this->applyBrandingFields($validated, null);
    }

    private function applyBrandingFields(array $validated, ?company $existing): array
    {
        if (!Schema::hasColumn('companies', 'slug')) {
            unset(
                $validated['slug'],
                $validated['frontend_host'],
                $validated['logo_url'],
                $validated['theme_primary'],
                $validated['theme_secondary'],
                $validated['theme_accent'],
                $validated['theme_json']
            );
            return $validated;
        }

        $code = $validated['company_code'] ?? $existing?->company_code ?? 'company';
        $slug = $this->themeService->slugFromCode((string) ($validated['slug'] ?? $existing?->slug ?? $code));
        $validated['slug'] = $slug;
        $validated['frontend_host'] = strtolower(trim((string) ($validated['frontend_host'] ?? $existing?->frontend_host ?? $this->themeService->hostFromSlug($slug))));
        $validated['frontend_host'] = preg_replace('/^www\./', '', $validated['frontend_host']);

        foreach (['theme_primary', 'theme_secondary', 'theme_accent'] as $key) {
            if (!empty($validated[$key])) {
                $validated[$key] = $this->themeService->normalizeHex($validated[$key]) ?? $validated[$key];
            }
        }
        if (!empty($validated['theme_primary'])) {
            $validated['theme_json'] = array_filter([
                'primary' => $validated['theme_primary'] ?? null,
                'secondary' => $validated['theme_secondary'] ?? null,
                'accent' => $validated['theme_accent'] ?? null,
            ]);
        }

        return $validated;
    }

    private function brandingPayload(company $company): array
    {
        $theme = $company->theme_json ?: [];
        return [
            'id' => $company->id,
            'name' => $company->name,
            'company_code' => $company->company_code,
            'slug' => $company->slug,
            'frontend_host' => $company->frontend_host,
            'logo_url' => $company->logo_url,
            'hr_url' => $company->frontend_host ? ('https://' . $company->frontend_host) : null,
            'theme' => [
                'primary' => $company->theme_primary ?: ($theme['primary'] ?? '#0B4F5C'),
                'secondary' => $company->theme_secondary ?: ($theme['secondary'] ?? '#0D9488'),
                'accent' => $company->theme_accent ?: ($theme['accent'] ?? '#FF6B4A'),
                'ink' => $theme['ink'] ?? '#062A32',
                'surface' => $theme['surface'] ?? '#F3FBF9',
            ],
        ];
    }

    private function onlyExistingColumns(array $payload): array
    {
        $kept = [];
        foreach ($payload as $key => $value) {
            if (Schema::hasColumn('companies', $key)) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }
}
