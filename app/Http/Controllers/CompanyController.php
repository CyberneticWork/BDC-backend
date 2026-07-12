<?php

namespace App\Http\Controllers;

use App\Models\company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CompanyController extends Controller
{
    public function index()
    {
        $companies = Company::select('id', 'company_code', 'name', 'location', 'established')->get();
        return response()->json($companies);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_code' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'established' => 'nullable|digits:4|integer|min:1900|max:' . (date('Y')),
        ], [
            'company_code.required' => 'Company ID is required. Please enter a unique code (e.g. SPM-S, SPM-C).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();
        $validated['company_code'] = strtoupper(trim($validated['company_code']));
        $validated['name'] = trim($validated['name']);
        $validated['location'] = isset($validated['location']) ? trim($validated['location']) : null;

        // Enforce unique company_code (excluding soft-deleted)
        $codeExists = company::where('company_code', $validated['company_code'])
            ->whereNull('deleted_at')
            ->exists();

        if ($codeExists) {
            return response()->json([
                'message' => 'Company ID already exists.',
                'errors' => ['company_code' => ['This Company ID is already in use. Please enter a unique code.']]
            ], 409);
        }

        // Same name is allowed across multiple companies (differentiated by company_code)
        // but keep a duplicate check that considers company_code so exact duplicates are blocked.
        $duplicate = company::where('name', $validated['name'])
            ->where('company_code', $validated['company_code'])
            ->whereNull('deleted_at')
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'This Company ID and Name combination already exists.',
                'errors' => ['name' => ['Duplicate company record.']]
            ], 409);
        }

        $company = company::create($validated);
        return response()->json($company, 201);
    }

    public function update(Request $request, $id)
    {
        $company = company::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'company_code' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'established' => 'nullable|digits:4|integer|min:1900|max:' . (date('Y')),
        ], [
            'company_code.required' => 'Company ID is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();
        $validated['company_code'] = strtoupper(trim($validated['company_code']));
        $validated['name'] = trim($validated['name']);
        $validated['location'] = isset($validated['location']) ? trim($validated['location']) : null;

        // Check company_code uniqueness excluding current company
        $codeExists = company::where('company_code', $validated['company_code'])
            ->where('id', '!=', $company->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($codeExists) {
            return response()->json([
                'message' => 'Company ID already exists.',
                'errors' => ['company_code' => ['This Company ID is already in use. Please enter a unique code.']]
            ], 409);
        }

        $company->update($validated);
        return response()->json($company);
    }

    public function destroy($id)
    {
        $company = company::findOrFail($id);
        $company->delete();
        return response()->json(['message' => 'Deleted'], 204);
    }
}
