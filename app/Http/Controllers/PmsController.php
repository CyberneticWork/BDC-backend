<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\KpiTask;
use App\Models\CreatorRole;
use App\Models\Company; // Updated to PascalCase
use App\Models\Departments; // Updated to PascalCase
use App\Models\Employee; // Updated to PascalCase
use App\Models\KpiTaskAssignment;

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
        $companies = Company::select('id', 'name')->get();
        return response()->json($companies);
    }

    /**
     * Get departments by company.
     */
    public function getDepartmentsByCompany($companyId)
    {
        $departments = Departments::where('company_id', $companyId)
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

        $query = Employee::with('organizationAssignment')
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
        
        $employees = Employee::with(['organizationAssignment.department', 'organizationAssignment.company'])
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

    /**
     * Store a new KPI task assignment (creates multiple records for multiple assignees).
     */
    public function storeKpiTaskAssignment(Request $request)
    {
        $validated = $request->validate([
            'task_name' => 'required|string',
            'description' => 'nullable|string',
            'company_id' => 'required|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'creator_role_name' => 'required|string',
            'assignees' => 'required|array|min:1',
            'assignees.*' => 'required|string', // attendance_employee_no
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'weights' => 'nullable|array',
            'priority' => 'nullable|string|in:low,medium,high',
        ]);

        // Find or create KpiTask by task_name
        $kpiTask = KpiTask::firstOrCreate(['task_name' => $validated['task_name']]);

        // Find CreatorRole by role_name
        $creatorRole = CreatorRole::where('role_name', $validated['creator_role_name'])->first();
        if (!$creatorRole) {
            return response()->json(['error' => 'Creator role not found'], 400);
        }

        $assignments = [];
        foreach ($validated['assignees'] as $attendanceNo) {
            $employee = Employee::where('attendance_employee_no', $attendanceNo)->first();
            if (!$employee) {
                return response()->json(['error' => 'Employee not found: ' . $attendanceNo], 400);
            }

            $assignment = KpiTaskAssignment::create([
                'kpi_task_id' => $kpiTask->id,
                'creator_role_id' => $creatorRole->id,
                'weights' => $validated['weights'] ?? [],
                'company_id' => $validated['company_id'],
                'department_id' => $validated['department_id'],
                'employee_id' => $employee->id,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'status' => 'active',
                'priority' => $validated['priority'] ?? 'medium',
                'description' => $validated['description'],
                'completion_status' => 'not-started',
                'last_updated' => now(),
            ]);

            $assignments[] = $assignment;
        }

        return response()->json($assignments, 201);
    }

    /**
     * Get all KPI task assignments with related data for display.
     */
    public function getKpiTaskAssignments(Request $request)
    {
        $assignments = KpiTaskAssignment::with([
            'kpiTask:id,task_name',
            'employee:id,full_name,attendance_employee_no',
            'company:id,name',
            'department:id,name',
            'creatorRole:id,role_name'
        ])
        ->select([
            'id',
            'kpi_task_id',
            'employee_id',
            'company_id',
            'department_id',
            'creator_role_id',
            'description',
            'start_date',
            'end_date',
            'status',
            'priority',
            'weights',
            'completion_status',
            'created_at'
        ])
        ->orderBy('created_at', 'desc') // Show recently added first
        ->get();

        // Transform to match frontend expectations
        $transformed = $assignments->map(function ($assignment) {
            return [
                'id' => $assignment->id,
                'name' => $assignment->kpiTask->task_name ?? 'Unknown Task',
                'description' => $assignment->description ?? '',
                'company' => $assignment->company_id,
                'departmentId' => $assignment->department_id,
                'companyName' => $assignment->company->name ?? 'Unknown Company',
                'departmentName' => $assignment->department->name ?? 'Unknown Department',
                'department' => $assignment->department->name ?? 'Unknown Department',
                'assignees' => [$assignment->employee->attendance_employee_no ?? ''],
                'assigneeUpdates' => [], // Can be populated later if needed
                'startDate' => $assignment->start_date->toDateString(),
                'endDate' => $assignment->end_date->toDateString(),
                'status' => $assignment->status ?? 'active',
                'priority' => $assignment->priority ?? 'medium',
                'creator' => [
                    'role' => $assignment->creatorRole->role_name ?? 'Unknown Role',
                    'date' => $assignment->created_at->toISOString()
                ],
                'weights' => $assignment->weights ?? [],
                'lastUpdated' => $assignment->created_at->toISOString(),
                'frequency' => 'Monthly', // Default or add to table if needed
                'category' => 'General' // Default or add to table if needed
            ];
        });

        return response()->json($transformed);
    }

    /**
     * Update the specified KPI task assignment in storage.
     */
    public function updateKpiTaskAssignment(Request $request, $id)
    {
        $validated = $request->validate([
            'task_name' => 'sometimes|required|string',
            'description' => 'nullable|string',
            'company_id' => 'sometimes|required|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'creator_role_name' => 'sometimes|required|string',
            'assignees' => 'sometimes|required|array|min:1',
            'assignees.*' => 'required|string', // attendance_employee_no
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after:start_date',
            'weights' => 'nullable|array',
            'priority' => 'nullable|string|in:low,medium,high',
        ]);

        $assignment = KpiTaskAssignment::findOrFail($id);

        // Update KpiTask if task_name changed
        if (isset($validated['task_name'])) {
            $kpiTask = KpiTask::firstOrCreate(['task_name' => $validated['task_name']]);
            $assignment->kpi_task_id = $kpiTask->id;
        }

        // Update CreatorRole if changed
        if (isset($validated['creator_role_name'])) {
            $creatorRole = CreatorRole::where('role_name', $validated['creator_role_name'])->first();
            if (!$creatorRole) {
                return response()->json(['error' => 'Creator role not found'], 400);
            }
            $assignment->creator_role_id = $creatorRole->id;
        }

        // Update other fields
        if (isset($validated['description'])) $assignment->description = $validated['description'];
        if (isset($validated['company_id'])) $assignment->company_id = $validated['company_id'];
        if (isset($validated['department_id'])) $assignment->department_id = $validated['department_id'];
        if (isset($validated['start_date'])) $assignment->start_date = $validated['start_date'];
        if (isset($validated['end_date'])) $assignment->end_date = $validated['end_date'];
        if (isset($validated['weights'])) $assignment->weights = $validated['weights'];
        if (isset($validated['priority'])) $assignment->priority = $validated['priority'];

        // Handle assignees update (this might require creating new assignments or updating existing)
        // For simplicity, assume updating the employee_id if assignees array has one item
        if (isset($validated['assignees']) && count($validated['assignees']) === 1) {
            $employee = Employee::where('attendance_employee_no', $validated['assignees'][0])->first();
            if (!$employee) {
                return response()->json(['error' => 'Employee not found'], 400);
            }
            $assignment->employee_id = $employee->id;
        }

        $assignment->last_updated = now();
        $assignment->save();

        return response()->json($assignment);
    }

    /**
     * Remove the specified KPI task assignment (soft delete).
     */
    public function destroy($id)
    {
        try {
            $assignment = KpiTaskAssignment::find($id);
            if (!$assignment) {
                return response()->json(['message' => 'KPI task assignment not found'], 404);
            }

            // Try normal Eloquent soft delete first (will set deleted_at)
            try {
                $assignment->delete();
                return response()->json(['message' => 'Deleted'], 200);
            } catch (\Throwable $e) {
                // Log the error and fallback to direct DB update of deleted_at to ensure soft-delete behavior
                \Log::error('PmsController::destroy - Eloquent delete failed', [
                    'id' => $id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                \DB::table('kpi_task_assignments')->where('id', $id)->update(['deleted_at' => now()]);

                return response()->json(['message' => 'Deleted (soft) via fallback'], 200);
            }
        } catch (\Throwable $e) {
            \Log::error('PmsController::destroy - unexpected error', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Delete failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get KPI task assignments for a specific employee.
     */
    public function getEmployeeKpiTaskAssignments(Request $request, $employeeId)
    {
        try {
            \Log::info('Fetching KPI assignments for employee', ['employeeId' => $employeeId]);
            
            // Find employee by attendance_employee_no
            $employee = Employee::where('attendance_employee_no', $employeeId)->first(); // Updated to PascalCase
            
            if (!$employee) {
                \Log::warning('Employee not found', ['employeeId' => $employeeId]);
                return response()->json([]);
            }
            
            \Log::info('Found employee', ['id' => $employee->id, 'name' => $employee->full_name]);
            
            $assignments = KpiTaskAssignment::with([
                'kpiTask:id,task_name,description', 
                'creatorRole:id,role_name',
                'company:id,name',
                'department:id,name',
                'employee:id,attendance_employee_no,full_name'
            ])
            ->where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->select([
                'id', 'kpi_task_id', 'creator_role_id', 'weights',
                'company_id', 'department_id', 'employee_id',
                'start_date', 'end_date', 'status', 'priority',
                'description', 'completion_status', 'last_updated', 'created_at'
            ])
            ->orderBy('created_at', 'desc')
            ->get();
            
            \Log::info('Retrieved assignments', ['count' => $assignments->count()]);
            
            // Return sample data if no assignments found (for development)
            if ($assignments->isEmpty()) {
                \Log::info('No assignments found, returning sample data');
                return response()->json([
                    [
                        'id' => 999,
                        'name' => 'Sample Task',
                        'description' => 'This is a test task for ' . $employee->full_name,
                        'startDate' => now()->format('Y-m-d'),
                        'endDate' => now()->addMonths(3)->format('Y-m-d'),
                        'status' => 'active',
                        'priority' => 'medium',
                        'completionStatus' => 'not-started',
                        'weights' => [
                            ['title' => 'Quality', 'percentage' => 25],
                            ['title' => 'Timeliness', 'percentage' => 25],
                            ['title' => 'Teamwork', 'percentage' => 25],
                            ['title' => 'Initiative', 'percentage' => 25],
                        ],
                        'lastUpdated' => now()->toISOString(),
                        'documentCount' => 0,
                        'assignees' => [$employeeId],
                        'assigneeUpdates' => [
                            [
                                'employeeId' => intval(str_replace('EMP', '', $employeeId)),
                                'updates' => []
                            ]
                        ],
                        'company' => 'Your Company',
                        'department' => 'Your Department',
                        'creatorRole' => 'Manager',
                    ]
                ]);
            }
            
            // Transform to match frontend expectations
            $transformed = $assignments->map(function ($assignment) {
                return [
                    'id' => $assignment->id,
                    'name' => $assignment->kpiTask->task_name ?? 'Unknown Task',
                    'description' => $assignment->description ?? $assignment->kpiTask->description ?? '',
                    'startDate' => $assignment->start_date,
                    'endDate' => $assignment->end_date,
                    'status' => $assignment->status,
                    'priority' => $assignment->priority,
                    'completionStatus' => $assignment->completion_status,
                    'weights' => $assignment->weights ?? [],
                    'lastUpdated' => $assignment->last_updated ?? $assignment->created_at,
                    'documentCount' => 0, // Placeholder
                    'assignees' => [$assignment->employee->attendance_employee_no ?? ''],
                    'assigneeUpdates' => [
                        [
                            'employeeId' => intval(str_replace('EMP', '', $assignment->employee->attendance_employee_no ?? '0')),
                            'updates' => []
                        ]
                    ],
                    'company' => $assignment->company->name ?? '',
                    'department' => $assignment->department->name ?? '',
                    'creatorRole' => $assignment->creatorRole->role_name ?? '',
                ];
            });

            return response()->json($transformed);
        } catch (\Exception $e) {
            \Log::error('Error fetching KPI assignments', [
                'employeeId' => $employeeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return a user-friendly error message
            return response()->json([
                [
                    'id' => 9999,
                    'name' => 'Error Loading Tasks',
                    'description' => 'There was a server error loading your tasks. Please try again later.',
                    'startDate' => now()->format('Y-m-d'),
                    'endDate' => now()->addDays(30)->format('Y-m-d'),
                    'status' => 'attention',
                    'priority' => 'high',
                    'completionStatus' => 'not-started',
                    'weights' => [],
                    'lastUpdated' => now()->toISOString(),
                    'documentCount' => 0,
                    'assignees' => [$employeeId],
                    'assigneeUpdates' => [
                        [
                            'employeeId' => intval(str_replace('EMP', '', $employeeId)),
                            'updates' => []
                        ]
                    ]
                ]
            ], 200); // Return 200 with error indicator in data instead of 500
        }
    }
}
