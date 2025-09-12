<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\KpiTask;
use App\Models\CreatorRole;
use App\Models\company;
use App\Models\departments;
use App\Models\employee;

class PmsController extends Controller
{
    /**
     * Get all KPI tasks for dropdown population.
     */
    public function getKpiTasks()
    {
        $tasks = KpiTask::select('id', 'task_name')->get();
        return response()->json($tasks);
    }

    /**
     * Get all creator roles for dropdown population.
     */
    public function getCreatorRoles()
    {
        $roles = CreatorRole::select('id', 'role_name')->get();
        return response()->json($roles);
    }

    /**
     * Get all companies.
     */
    public function getCompanies()
    {
        $companies = company::select('id', 'name')->get();
        return response()->json($companies);
    }

    /**
     * Get departments by company.
     */
    public function getDepartmentsByCompany($companyId)
    {
        $departments = departments::where('company_id', $companyId)
            ->select('id', 'name')
            ->get();
        return response()->json($departments);
    }

    /**
     * Get employees by company and optionally by department.
     */
    public function getEmployeesByCompany(Request $request)
    {
        $companyId = $request->query('company_id');
        $departmentId = $request->query('department_id');
        $search = $request->query('search', '');

        if (!$companyId) {
            return response()->json(['message' => 'Company ID is required'], 400);
        }

        $query = employee::with('organizationAssignment')
            ->whereHas('organizationAssignment', function($q) use ($companyId, $departmentId) {
                $q->where('company_id', $companyId);
                if ($departmentId) {
                    $q->where('department_id', $departmentId);
                }
            })
            ->select('id', 'full_name', 'attendance_employee_no');

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        $employees = $query->get();
        return response()->json($employees);
    }

    /**
     * Search employees by attendance employee number.
     */
    public function searchEmployeesByAttendanceNo(Request $request)
    {
        $search = $request->query('search', '');
        
        if (empty($search)) {
            return response()->json([]);
        }
        
        $employees = employee::with(['organizationAssignment.department', 'organizationAssignment.company'])
            ->where('attendance_employee_no', 'like', "%{$search}%")
            ->select('id', 'full_name', 'attendance_employee_no')
            ->limit(10)
            ->get()
            ->map(function($employee) {
                return [
                    'id' => $employee->attendance_employee_no,
                    'name' => $employee->full_name,
                    'attendance_no' => $employee->attendance_employee_no,
                    'department' => $employee->organizationAssignment->department->name ?? 'Not Assigned',
                    'company' => $employee->organizationAssignment->company->name ?? 'Not Assigned'
                ];
            });
        
        return response()->json($employees);
    }
}
