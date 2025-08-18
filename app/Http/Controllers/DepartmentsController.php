<?php
namespace App\Http\Controllers;

use App\Models\departments;
use Illuminate\Http\Request;

class DepartmentsController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'name' => 'required|string|max:255',
        ]);

        // Check for duplicate department in the same company (not soft-deleted)
        $exists = departments::where('company_id', $validated['company_id'])
            ->where('name', $validated['name'])
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Department already exists for this company.'
            ], 409);
        }

        $department = departments::create($validated);
        return response()->json($department, 201);
    }

    public function update(Request $request, $id)
    {
        $department = departments::findOrFail($id);
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'name' => 'required|string|max:255',
        ]);
        $department->update($validated);
        return response()->json($department, 200);
    }

    public function destroy($id)
    {
        $department = departments::findOrFail($id);
        $department->delete();
        return response()->json(['message' => 'Deleted successfully.'], 204);
    }
}