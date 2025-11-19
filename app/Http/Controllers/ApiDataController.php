<?php

namespace App\Http\Controllers;

use App\Models\absence;
use Carbon\Carbon;
use App\Models\company;
use App\Models\employee;
use App\Models\time_card;
use App\Models\departments;
use App\Models\designation;
use Illuminate\Http\Request;
use App\Models\sub_departments;
use App\Models\organization_assignment;

class ApiDataController extends Controller
{
    public function companies()
    {
        $companies = company::withCount('employees')->get()->map(function ($company) {
            return [
                'id' => $company->id,
                'name' => $company->name,
                // 'code' => null,
                'location' => $company->location,
                'employees' => $company->employees_count,
                'established' => $company->established,
            ];
        });
        return response()->json($companies, 200);
    }

    public function departments()
    {
        $departments = departments::with('company')->get()->map(function ($dept) {
            // Count employees for this department
            $employeeCount = organization_assignment::where('department_id', $dept->id)
                ->pluck('id')
                ->pipe(function ($assignmentIds) {
                    return employee::whereIn('organization_assignment_id', $assignmentIds)->count();
                });

            return [
                'id' => $dept->id,
                'name' => $dept->name,
                // 'code' => null,
                // 'manager' => null,
                'employees' => $employeeCount,
                'company_id' => $dept->company_id,
                'company_name' => $dept->company ? $dept->company->name : null,
                'subdepartments' => [], // Will be filled in frontend
            ];
        });
        return response()->json($departments, 200);
    }

    public function subDepartments()
    {
        $subDepartments = sub_departments::with('department')->get()->map(function ($sub) {
            // Count employees for this sub-department
            $employeeCount = organization_assignment::where('sub_department_id', $sub->id)
                ->pluck('id')
                ->pipe(function ($assignmentIds) {
                    return employee::whereIn('organization_assignment_id', $assignmentIds)->count();
                });

            return [
                'id' => $sub->id,
                'name' => $sub->name,
                // 'manager' => null,
                'employees' => $employeeCount,
                'department_id' => $sub->department_id,
                'department_name' => $sub->department ? $sub->department->name : null,
            ];
        });
        return response()->json($subDepartments, 200);
    }

    public function designations()
    {
        // Assuming you have a Designation model
        $designations = designation::all();
        return response()->json($designations, 200);
    }

    public function addNewDesignation(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:designations,name',
        ]);

        $designation = designation::create([
            'name' => $request->name,
        ]);

        return response()->json(['message' => 'Designation added successfully', 'designation' => $designation], 201);
    }

    public function companiesById($id)
    {
        $company = company::find($id);
        return response()->json($company, 200);
    }

    public function departmentsById($id)
    {
        $department = departments::where('company_id', $id)->get();
        return response()->json($department, 200);
    }

    public function subDepartmentsById($id)
    {
        $subDepartment = sub_departments::where('department_id', $id)->get();
        return response()->json($subDepartment, 200);
    }

    //get employee based on sub department
    public function employeesBySubDepartment($id)
    {
        $subDepartment = sub_departments::find($id);
        if (!$subDepartment) {
            return response()->json(['message' => 'Sub-department not found'], 404);
        }

        $employees = employee::where('is_active', 1)
            ->whereHas('organizationAssignment', function ($query) use ($id) {
                $query->where('sub_department_id', $id);
            })->get(['id', 'full_name']);

        return response()->json($employees, 200);
    }

    public function employeesByCompany($id)
    {
        $company = company::find($id);
        if (!$company) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        $employees = employee::where('is_active', 1)
            ->whereHas('organizationAssignment', function ($query) use ($id) {
                $query->where('company_id', $id);
            })->get(['id', 'full_name', 'attendance_employee_no', 'epf', 'nic']);

        return response()->json($employees, 200);
    }

    public function Absentees()
    {
        $employeeIdsWithRosters = employee::whereHas('rosters')->pluck('id')->toArray();
        $presentEmployees = time_card::distinct()->pluck('employee_id')->toArray();
        $absentEmployeeIds = array_diff($employeeIdsWithRosters, $presentEmployees);

        $today = Carbon::today()->toDateString();

        foreach ($absentEmployeeIds as $employeeId) {
            absence::updateOrCreate(
                [
                    'employee_id' => $employeeId,
                    'date' => $today, // Unique composite key
                ],
                [
                    'reason' => null, // Update reason if needed
                    'updated_at' => Carbon::now(),
                ]
            );
        }

        return response()->json([
            'absent_employees' => $absentEmployeeIds,
            'message' => 'Absent employees stored without duplicates!'
        ], 200);
    }
}
