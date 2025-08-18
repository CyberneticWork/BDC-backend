<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\sub_departments;
use App\Models\departments;

class SubDepartmentsController extends Controller
{
    public function index()
    {
        // Eager load department and company
        $subDepartments = sub_departments::with(['department.company'])->get();
        return response()->json($subDepartments);
    }

    public function show($id)
    {
        $subDepartment = sub_departments::with(['department.company'])->findOrFail($id);
        return response()->json($subDepartment);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'name' => 'required|string|max:255',
        ]);

        // Only check for duplicates among non-deleted records
        $exists = sub_departments::where('department_id', $validated['department_id'])
            ->whereRaw('LOWER(name) = ?', [strtolower($validated['name'])])
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'A sub department with this name already exists in the selected department.',
                'errors' => ['name' => ['Duplicate sub department name in this department.']]
            ], 409);
        }

        $subDepartment = sub_departments::create([
            'department_id' => $validated['department_id'],
            'name' => $validated['name'],
        ]);

        return response()->json($subDepartment, 201);
    }

    public function update(Request $request, $id)
    {
        $subDepartment = sub_departments::findOrFail($id);

        $validated = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'name' => 'required|string|max:255',
        ]);

        $subDepartment->update($validated);

        return response()->json($subDepartment);
    }

    public function destroy($id)
    {
        $subDepartment = sub_departments::findOrFail($id);
        $subDepartment->delete(); // This sets the deleted_at flag

        return response()->json(['message' => 'Sub Department deleted']);
    }
}
