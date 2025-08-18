<?php

namespace App\Http\Controllers;

use App\Models\company;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index()
    {
        $companies = Company::select('id', 'name')->get();
        return response()->json($companies);
    }

    public function store(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'established' => 'nullable|digits:4|integer|min:1900|max:' . (date('Y')),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // Check for duplicate company name (not soft-deleted)
        $exists = company::where('name', $validated['name'])
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Company with this name already exists.',
                'errors' => ['name' => ['This company name is already in use.']]
            ], 409);
        }

        $company = company::create($validated);
        return response()->json($company, 201);
    }

    public function update(Request $request, $id)
    {
        $company = company::findOrFail($id);

        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'established' => 'nullable|digits:4|integer|min:1900|max:' . (date('Y')),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // Check for duplicate company name (not soft-deleted, not current company)
        $exists = company::where('name', $validated['name'])
            ->where('id', '!=', $company->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Company with this name already exists.',
                'errors' => ['name' => ['This company name is already in use.']]
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
