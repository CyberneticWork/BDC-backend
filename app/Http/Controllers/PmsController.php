<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\KpiTask;
use App\Models\CreatorRole;
use App\Models\company;
use App\Models\departments;
use App\Models\employee;
use App\Models\KpiTaskAssignment;
use App\Models\TaskProgressSubmission;
use App\Models\PerformanceReview;
use App\Models\PerformanceEvaluation;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;

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

        // Get current user ID for creator_id
        $creatorId = auth()->id();

        $assignments = [];
        foreach ($validated['assignees'] as $attendanceNo) {
            $employee = employee::where('attendance_employee_no', $attendanceNo)->first();
            if (!$employee) {
                return response()->json(['error' => 'Employee not found: ' . $attendanceNo], 400);
            }

            // Determine department: prefer provided department_id, else derive from employee's organizationAssignment
            $departmentId = $validated['department_id'] ?? null;
            if (!$departmentId) {
                try {
                    $departmentId = $employee->organizationAssignment?->department_id ?? null;
                    \Log::info('Derived department id from organizationAssignment', [
                        'derived_department_id' => $departmentId
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('Failed to derive department from organizationAssignment', [
                        'error' => $e->getMessage()
                    ]);
                    $departmentId = null;
                }
            }

            $assignment = KpiTaskAssignment::create([
                'kpi_task_id' => $kpiTask->id,
                'creator_role_id' => $creatorRole->id,
                'creator_id' => $creatorId, // Store current user as creator
                'weights' => $validated['weights'] ?? [],
                'company_id' => $validated['company_id'],
                'department_id' => $departmentId,
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
        try {
            // Get current user and role
            $currentUser = auth()->user();
            if (!$currentUser) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }
            
            $userRole = strtolower($currentUser->role ?? '');
            
            // Build the base query with relations
            $query = KpiTaskAssignment::with([
                'kpiTask:id,task_name',
                'employee:id,full_name,attendance_employee_no',
                'company:id,name',
                'department:id,name',
                'creator:id,role,name',
                'creatorRole:id,role_name'
            ])->whereNull('deleted_at');

            // Apply role-based filtering based on USER ROLES from users table
            if ($userRole === 'admin') {
                // Admin sees ALL tasks - no filtering needed
            } elseif ($userRole === 'hr') {
                // HR can see:
                // 1. Tasks they created themselves (creator_id = current user)
                // 2. Tasks created by users with 'supervisor' role 
                // BUT NOT tasks created by other HR users
                // AND NOT tasks where they are assigned as employee (those go to "My KPI Tasks")
                $query->where(function($q) use ($currentUser) {
                    // Tasks created by this HR user only
                    $q->where('creator_id', $currentUser->id);
                    
                    // OR tasks created by users with 'supervisor' role
                    $q->orWhereHas('creator', function($subQ) {
                        $subQ->where('role', 'supervisor');
                    });
                });
                
                // EXCLUDE tasks where this HR user is assigned as employee
                if ($currentUser->employee_id) {
                    $currentEmployee = employee::find($currentUser->employee_id);
                    if ($currentEmployee) {
                        $query->where('employee_id', '!=', $currentEmployee->id);
                    }
                }
                
            } elseif ($userRole === 'supervisor') {
                // Supervisor can ONLY see tasks they created themselves
                // They cannot see tasks created by other supervisors
                // AND NOT tasks where they are assigned as employee (those go to "My KPI Tasks")
                $query->where('creator_id', $currentUser->id);
                
                // EXCLUDE tasks where this supervisor is assigned as employee  
                if ($currentUser->employee_id) {
                    $currentEmployee = employee::find($currentUser->employee_id);
                    if ($currentEmployee) {
                        $query->where('employee_id', '!=', $currentEmployee->id);
                    }
                }
                
            } elseif ($userRole === 'manager') {
                // Manager sees tasks created by HR + supervisor users + their own
                // BUT NOT tasks where they are assigned as employee (those go to "My KPI Tasks")
                $query->where(function($q) use ($currentUser) {
                    // Tasks created by users with HR role
                    $q->whereHas('creator', function($subQ) {
                        $subQ->where('role', 'hr');
                    });
                    
                    // OR tasks created by users with supervisor role
                    $q->orWhereHas('creator', function($subQ) {
                        $subQ->where('role', 'supervisor');
                    });
                    
                    // OR tasks created by this manager
                    $q->orWhere('creator_id', $currentUser->id);
                });
                
                // EXCLUDE tasks where this manager is assigned as employee
                if ($currentUser->employee_id) {
                    $currentEmployee = employee::find($currentUser->employee_id);
                    if ($currentEmployee) {
                        $query->where('employee_id', '!=', $currentEmployee->id);
                    }
                }
                
            } else {
                // Default for other roles (regular employees, users)
                // Only see tasks they created (if any)
                $query->where('creator_id', $currentUser->id);
                
                // EXCLUDE tasks where they are assigned as employee
                if ($currentUser->employee_id) {
                    $currentEmployee = employee::find($currentUser->employee_id);
                    if ($currentEmployee) {
                        $query->where('employee_id', '!=', $currentEmployee->id);
                    }
                }
            }

            $assignments = $query->orderBy('created_at', 'desc')->get();

            // Log for debugging
            \Log::info('KPI Tasks query results', [
                'user_role' => $userRole,
                'user_id' => $currentUser->id,
                'employee_id' => $currentUser->employee_id,
                'total_assignments' => $assignments->count(),
                'sample_creators' => $assignments->take(3)->map(function($a) {
                    return [
                        'task_name' => $a->kpiTask?->task_name,
                        'creator_id' => $a->creator_id,
                        'assigned_to' => $a->employee_id,
                        'creator_role' => $a->creatorRole?->role_name
                    ];
                })
            ]);

            // Transform to match frontend expectations with null safety
            $transformed = $assignments->map(function ($assignment) {
                return [
                    'id' => $assignment->id,
                    'name' => $assignment->kpiTask?->task_name ?? 'Unknown Task',
                    'description' => $assignment->description ?? '',
                    'company' => $assignment->company_id,
                    'departmentId' => $assignment->department_id,
                    'companyName' => $assignment->company?->name ?? 'Unknown Company',
                    'departmentName' => $assignment->department?->name ?? 'Unknown Department',
                    'department' => $assignment->department?->name ?? 'Unknown Department',
                    'assignees' => [$assignment->employee?->attendance_employee_no ?? 'Unknown'],
                    'assigneeUpdates' => [], // Can be populated later if needed
                    'startDate' => $assignment->start_date?->toDateString() ?? null,
                    'endDate' => $assignment->end_date?->toDateString() ?? null,
                    'status' => $assignment->status ?? 'active',
                    'priority' => $assignment->priority ?? 'medium',
                    'creator' => [
                        'role' => $assignment->creatorRole?->role_name ?? 'Unknown Role',
                        'date' => $assignment->created_at?->toISOString() ?? null,
                        'id' => $assignment->creator_id // Include creator ID
                    ],
                    'weights' => $assignment->weights ?? [],
                    'lastUpdated' => $assignment->created_at?->toISOString() ?? null,
                    'frequency' => 'Monthly', // Default or add to table if needed
                    'category' => 'General', // Default or add to table if needed
                    'approval_status' => $assignment->approval_status ?? 'pending',
                    'completion_status' => $assignment->completion_status ?? 'not-started',
                    'employee_id' => $assignment->employee?->id ?? null,
                    'employee_name' => $assignment->employee?->full_name ?? 'Unknown Employee'
                ];
            });

            return response()->json($transformed);
            
        } catch (\Exception $e) {
            \Log::error('getKpiTaskAssignments error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch KPI task assignments',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all KPI task assignments with approval status for the approval page.
     * This is separate from getKpiTaskAssignments to avoid affecting other pages.
     */
    public function getKpiTaskAssignmentsForApproval(Request $request)
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
            'approval_status', // Include approval_status for the approval page
            'priority',
            'weights',
            'completion_status',
            'created_at'
        ])
        ->orderBy('created_at', 'desc') // Show recently added first
        ->get();

        // Transform to match frontend expectations with approval_status
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
                'start_date' => $assignment->start_date->toDateString(), // Also include underscore version
                'end_date' => $assignment->end_date->toDateString(), // Also include underscore version
                'status' => $assignment->status ?? 'active',
                'approval_status' => $assignment->approval_status ?? 'pending', // Include approval status
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
            $employee = employee::where('attendance_employee_no', $validated['assignees'][0])->first();
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
     *
     * Returns only assignments that have approval_status = 'approved' for non-admin callers.
     */
    public function getEmployeeKpiTaskAssignments(Request $request, $employeeId)
    {
        try {
            $currentUser = auth()->user();
            $userRole = strtolower($currentUser->role ?? '');
            $isAdmin = $userRole === 'admin';

            // Build base query with useful relations
            $query = KpiTaskAssignment::with([
                'kpiTask:id,task_name',
                'creatorRole:id,role_name',
                'company:id,name',
                'department:id,name',
                'employee:id,full_name,attendance_employee_no'
            ])->whereNull('deleted_at');

            // Find employee by numeric ID or attendance number
            $employee = null;
            if (is_numeric($employeeId)) {
                $employee = employee::find($employeeId);
            } else {
                $employee = employee::where('attendance_employee_no', $employeeId)->first();
            }

            if (!$employee) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            // Match by employee database ID
            $query->where('employee_id', $employee->id);

            // IMPORTANT: Only show approved tasks to employees in "My KPI Tasks" page
            // Remove the condition that allows employees to see their own pending tasks
            $query->where('approval_status', 'approved');

            // Optional filters: allow query params for status / date range if needed
            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }
            if ($request->has('start_date')) {
                $query->where('start_date', '>=', $request->query('start_date'));
            }
            if ($request->has('end_date')) {
                $query->where('end_date', '<=', $request->query('end_date'));
            }

            $assignments = $query->orderBy('start_date', 'desc')->get();

            $payload = $assignments->map(function($a) {
                return [
                    'id' => $a->id,
                    'kpi_task_id' => $a->kpi_task_id,
                    'name' => $a->kpiTask->task_name ?? null,
                    'description' => $a->description,
                    'start_date' => $a->start_date ? $a->start_date->toDateString() : null,
                    'end_date' => $a->end_date ? $a->end_date->toDateString() : null,
                    'startDate' => $a->start_date ? $a->start_date->toDateString() : null, // Also add camelCase
                    'endDate' => $a->end_date ? $a->end_date->toDateString() : null, // Also add camelCase
                    'status' => $a->status,
                    'approval_status' => $a->approval_status, // This will always be 'approved' now
                    'priority' => $a->priority,
                    'completion_status' => $a->completion_status,
                    'completionStatus' => $a->completion_status, // Also add camelCase
                    'weights' => $a->weights,
                    'company' => $a->company->name ?? null,
                    'department' => $a->department->name ?? null,
                    'creator_role' => $a->creatorRole->role_name ?? null,
                    'created_at' => $a->created_at?->toISOString(),
                    'lastUpdated' => $a->updated_at?->toISOString(),
                    'employee' => [
                        'id' => $a->employee->id ?? null,
                        'full_name' => $a->employee->full_name ?? null,
                        'attendance_employee_no' => $a->employee->attendance_employee_no ?? null,
                    ],
                ];
            });

            return response()->json($payload);
        } catch (\Exception $e) {
            \Log::error('getEmployeeKpiTaskAssignments error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([], 500);
        }
    }

    /**
     * Store a new task progress submission.
     */
    public function storeTaskProgressSubmission(Request $request)
    {
        // Log incoming request for debugging
        \Log::info('Task progress submission request received', [
            'data' => $request->except(['document']),
            'hasFile' => $request->hasFile('document'),
            'fileInfo' => $request->hasFile('document') ? [
                'name' => $request->file('document')->getClientOriginalName(),
                'size' => $request->file('document')->getSize(),
                'type' => $request->file('document')->getMimeType(),
            ] : null
        ]);

        $validator = \Validator::make($request->all(), [
            'kpi_assignment_id' => 'required|integer|exists:kpi_task_assignments,id',
            'employee_id' => 'required|integer|exists:employees,id',
            'note' => 'required|string|min:5|max:1000',
            'progress_percentage' => 'required|integer|min:0|max:100',
            'performance_metrics' => 'required|string|min:1', // JSON string from FormData
            'document_name' => 'nullable|string|max:255',
            'document_size' => 'nullable|string|max:50',
            'document_type' => 'nullable|string|max:100',
            'document' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,txt|max:10240', // 10MB max
        ], [
            'kpi_assignment_id.required' => 'KPI assignment ID is required',
            'kpi_assignment_id.exists' => 'Invalid KPI assignment ID',
            'employee_id.required' => 'Employee ID is required',
            'employee_id.exists' => 'Invalid employee ID',
            'note.required' => 'Progress note is required',
            'note.min' => 'Progress note must be at least 5 characters',
            'note.max' => 'Progress note cannot exceed 1000 characters',
            'progress_percentage.required' => 'Progress percentage is required',
            'progress_percentage.integer' => 'Progress percentage must be an integer',
            'progress_percentage.min' => 'Progress percentage cannot be less than 0',
            'progress_percentage.max' => 'Progress percentage cannot be more than 100',
            'performance_metrics.required' => 'Performance metrics are required',
            'document.mimes' => 'Document must be a PDF, Word, Excel, image, or text file',
            'document.max' => 'Document size cannot exceed 10MB',
        ]);

        if ($validator->fails()) {
            \Log::warning('Task progress submission validation failed', [
                'errors' => $validator->errors()->toArray(),
                'input' => $request->except(['document'])
            ]);
            
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        try {
            // Parse the JSON string back to array
            $performanceMetrics = json_decode($validated['performance_metrics'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                \Log::error('Invalid JSON in performance_metrics', [
                    'json_error' => json_last_error_msg(),
                    'raw_data' => $validated['performance_metrics']
                ]);
                
                return response()->json([
                    'message' => 'Invalid performance metrics format',
                    'errors' => ['performance_metrics' => ['Performance metrics must be valid JSON']]
                ], 422);
            }
            
            // Ensure progress_percentage is an integer
            $progressPercentage = (int) $validated['progress_percentage'];
            
            // Handle file upload if present
            $documentPath = null;
            if ($request->hasFile('document')) {
                $file = $request->file('document');
                
                // Create directory if it doesn't exist
                $uploadPath = storage_path('app/public/task_documents');
                if (!file_exists($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }
                
                // Store file in storage/app/public/task_documents
                $documentPath = $file->store('task_documents', 'public');
                
                // Convert to public URL
                $documentPath = '/storage/' . $documentPath;
            }

            $submissionData = [
                'kpi_assignment_id' => (int) $validated['kpi_assignment_id'],
                'employee_id' => (int) $validated['employee_id'],
                'note' => $validated['note'],
                'progress_percentage' => $progressPercentage,
                'performance_metrics' => $performanceMetrics, // Use parsed array
                'document_name' => $validated['document_name'] ?? null,
                'document_size' => $validated['document_size'] ?? null,
                'document_type' => $validated['document_type'] ?? null,
                'document_path' => $documentPath,
            ];

            \Log::info('Creating task progress submission', ['data' => $submissionData]);

            $submission = TaskProgressSubmission::create($submissionData);

            // Update the KPI assignment's completion status based on progress
            $assignment = KpiTaskAssignment::find($validated['kpi_assignment_id']);
            if ($assignment) {
                if ($progressPercentage >= 100) {
                    $assignment->completion_status = 'completed';
                } elseif ($progressPercentage > 0) {
                    $assignment->completion_status = 'in-progress';
                } else {
                    $assignment->completion_status = 'not-started';
                }
                $assignment->last_updated = now();
                $assignment->save();
            }

            return response()->json([
                'message' => 'Progress submitted successfully',
                'submission' => $submission->load('employee:id,full_name,attendance_employee_no')
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Error storing task progress submission', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $request->except(['document'])
            ]);

            return response()->json([
                'message' => 'Failed to submit progress',
                'error' => 'An unexpected error occurred while processing your submission.'
            ], 500);
        }
    }

    /**
     * Get task progress submissions for a specific assignment.
     */
    public function getTaskProgressSubmissions($assignmentId)
    {
        try {
            $submissions = TaskProgressSubmission::where('kpi_assignment_id', $assignmentId)
                ->with('employee:id,full_name,attendance_employee_no')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json($submissions, 200);

        } catch (\Exception $e) {
            \Log::error('Error fetching task progress submissions', [
                'assignmentId' => $assignmentId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to fetch progress submissions'
            ], 500);
        }
    }

    /**
     * Get all task progress submissions for an employee.
     */
    public function getEmployeeTaskProgressSubmissions($employeeId)
    {
        try {
            // Find employee by ID or attendance number
            $employee = null;
            if (is_string($employeeId) && preg_match('/^EMP/i', $employeeId)) {
                $employee = employee::where('attendance_employee_no', $employeeId)->first();
            } elseif (is_numeric($employeeId)) {
                $employee = employee::find($employeeId);
                if (!$employee) {
                    $formattedId = 'EMP' . str_pad($employeeId, 4, '0', STR_PAD_LEFT);
                    $employee = employee::where('attendance_employee_no', $formattedId)->first();
                }
            }

            if (!$employee) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            $submissions = TaskProgressSubmission::where('employee_id', $employee->id)
                ->with(['kpiAssignment.kpiTask:id,task_name'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json($submissions, 200);

        } catch (\Exception $e) {
            \Log::error('Error fetching employee progress submissions', [
                'employeeId' => $employeeId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to fetch progress submissions'
            ], 500);
        }
    }

    /**
     * Get all performance reviews with related task and submission data.
     */
    public function getPerformanceReviews(Request $request)
    {
        try {
            $perPage = (int)$request->query('per_page', 8);
            if ($perPage <= 0) { $perPage = 8; }

            // Require authentication for this endpoint
            $currentUser = auth()->user();
            if (!$currentUser) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }

            \Log::info('Performance reviews - User authenticated', [
                'user_id' => $currentUser->id,
                'role' => $currentUser->role ?? 'null'
            ]);

            // Define roles
            $userRole = strtolower($currentUser->role ?? '');
            $isAdmin = $userRole === 'admin';
            $isHR = $userRole === 'hr';
            $isSupervisor = $userRole === 'supervisor';

            if (!$currentUser->employee_id && !$isAdmin && !$isHR && !$isSupervisor) {
                return response()->json([
                    'error' => 'User account is not linked to an employee record',
                    'message' => 'Please contact administrator to link your account to an employee profile'
                ], 403);
            }

            $currentEmployee = null;
            if ($currentUser->employee_id) {
                $currentEmployee = employee::find($currentUser->employee_id);
                if (!$currentEmployee) {
                    return response()->json(['error' => 'Employee record not found'], 404);
                }
            }

            $query = KpiTaskAssignment::with([
                'kpiTask:id,task_name',
                'employee:id,full_name,attendance_employee_no',
                'company:id,name',
                'department:id,name',
                'creator:id,role,name',
                'creatorRole:id,role_name',
                'progressSubmissions' => function($query) {
                    $query->orderBy('created_at', 'desc')->limit(1);
                },
                'performanceReviews' => function($query) {
                    $query->orderBy('updated_at', 'desc')->limit(1);
                }
            ])
            ->whereHas('progressSubmissions');

            // Apply role-based filters
            if ($isAdmin) {
                // Admin sees everything - no filtering needed
            } else if ($isHR) {
                // HR users see:
                // 1. Reviews for tasks they created
                // 2. Reviews for tasks created by supervisors
                // 3. NOT reviews for tasks created by other HR users
                $query->where(function($q) use ($currentUser) {
                    // Reviews for tasks this HR user created
                    $q->where('creator_id', $currentUser->id);
                    
                    // OR reviews for tasks created by supervisors
                    $q->orWhereHas('creator', function($subQ) {
                        $subQ->where('role', 'supervisor');
                    });
                    
                    // If HR is also an employee, see their own reviews
                    if ($currentUser->employee_id) {
                        $q->orWhere('employee_id', $currentUser->employee_id);
                    }
                });
            } else if ($isSupervisor) {
                // Supervisors see only reviews for tasks they created
                $query->where(function($q) use ($currentUser, $currentEmployee) {
                    // Reviews for tasks this supervisor created
                    $q->where('creator_id', $currentUser->id);
                    
                    // If supervisor is also an employee, see their own reviews
                    if ($currentUser->employee_id) {
                        $q->orWhere('employee_id', $currentUser->employee_id);
                    }
                });
            } else {
                // Regular users see only their own reviews
                if ($currentEmployee) {
                    $query->where('employee_id', $currentEmployee->id);
                } else {
                    // Edge case: user with no employee_id who isn't admin/HR/supervisor sees nothing
                    return response()->json([
                        'data' => [],
                        'meta' => [
                            'current_page' => 1,
                            'from' => 0,
                            'last_page' => 0,
                            'per_page' => $perPage,
                            'to' => 0,
                            'total' => 0
                        ]
                    ]);
                }
            }

            // Subselect latest submission timestamp
            $query->addSelect([
                'latest_submission_at' => TaskProgressSubmission::select('created_at')
                    ->whereColumn('kpi_assignment_id', 'kpi_task_assignments.id')
                    ->latest()
                    ->limit(1),
                'latest_review_at' => PerformanceReview::select('updated_at')
                    ->whereColumn('kpi_assignment_id', 'kpi_task_assignments.id')
                    ->latest()
                    ->limit(1),
            ]);

            $query->orderByRaw('COALESCE(latest_submission_at, latest_review_at) DESC');

            $assignments = $query->paginate($perPage);

            // Transform current page collection
            $transformed = $assignments->getCollection()->map(function ($assignment) {
                $latestSubmission = $assignment->progressSubmissions->first();
                $existingReview = PerformanceReview::where('kpi_assignment_id', $assignment->id)->first();

                return [
                    'id' => $assignment->id,
                    'taskId' => $assignment->kpi_task_id,
                    'taskName' => $assignment->kpiTask->task_name ?? 'Unknown Task',
                    'employeeName' => $assignment->employee->full_name ?? 'Unknown Employee',
                    'employeeId' => $assignment->employee->id ?? null,
                    'position' => '',
                    'department' => $assignment->department->name ?? 'Unknown Department',
                    'company' => $assignment->company->name ?? 'Unknown Company',
                    'creatorId' => $assignment->creator_id,
                    'type' => 'Performance Review',
                    'status' => $existingReview ? $existingReview->status : 'Pending',
                    'startDate' => $assignment->start_date?->toDateString(),
                    'dueDate' => $assignment->end_date?->toDateString(),
                    'completedDate' => $existingReview?->completed_date?->toDateString(),
                    'cycle' => $this->deriveCycle($assignment->start_date),
                    
                    // Use supervisor's progress from performance review, not self-reported
                    'progress' => $existingReview ? (int)$existingReview->progress : 0,
                    'grade' => $existingReview?->grade,
                    'supervisorComments' => $existingReview?->supervisor_comments,
                    'performanceMetrics' => $existingReview?->performance_metrics, // Include supervisor's metrics
                    
                    // Keep self-reported data separate
                    'selfReportedProgress' => $latestSubmission?->progress_percentage ?? 0,
                    'selfReportedLastUpdated' => $latestSubmission?->created_at?->toISOString(),
                    'selfReportedAuthor' => $latestSubmission?->employee?->full_name,
                    
                    'lastUpdated' => ($latestSubmission?->created_at ?? $existingReview?->updated_at)?->toISOString(),
                    'weights' => $assignment->weights ?? [],
                    'priority' => $assignment->priority,
                    'description' => $assignment->description,
                    'submissionCount' => $assignment->progressSubmissions->count(),
                    'latestSubmissionNote' => $latestSubmission?->note,
                    'documentCount' => $assignment->progressSubmissions->whereNotNull('document_name')->count(),
                ];
            });

            $assignments->setCollection($transformed);

            return response()->json([
                'data' => $assignments->items(),
                'meta' => [
                    'current_page' => $assignments->currentPage(),
                    'last_page' => $assignments->lastPage(),
                    'per_page' => $assignments->perPage(),
                    'total' => $assignments->total(),
                    'from' => $assignments->firstItem(),
                    'to' => $assignments->lastItem(),
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error fetching performance reviews', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to fetch performance reviews'
            ], 500);
        }
    }

    /**
     * Get performance review details with all submissions.
     */
    public function getPerformanceReviewDetails($assignmentId)
    {
        try {
            $currentUser = auth()->user();
            if (!$currentUser) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }

            $isAdmin = strtolower($currentUser->role ?? '') === 'admin';

            // If user has employee_id, load it; if not and not admin -> deny
            if (!$currentUser->employee_id && !$isAdmin) {
                return response()->json([
                    'error' => 'User account is not linked to an employee record',
                    'message' => 'Please contact administrator to link your account to an employee profile'
                ], 403);
            }

            $currentEmployee = $currentUser->employee_id ? employee::find($currentUser->employee_id) : null;
            if ($currentUser->employee_id && !$currentEmployee) {
                return response()->json(['error' => 'Employee record not found'], 404);
            }

            $assignment = KpiTaskAssignment::with([
                'kpiTask:id,task_name',
                'employee:id,full_name,attendance_employee_no',
                'company:id,name',
                'department:id,name',
                'creator:id,role,name',
                'creatorRole:id,role_name',
                'progressSubmissions' => function($query) {
                    $query->orderBy('created_at', 'desc');
                }
            ])->findOrFail($assignmentId);

            if (!$isAdmin) {
                $isAssigned = $currentEmployee ? ($assignment->employee_id === $currentEmployee->id) : false;
                $isCreator = $assignment->creator_id && ($assignment->creator_id === $currentUser->id);

                // Allow HR to view:
                //  - tasks they created, OR
                //  - tasks created by supervisors (so HR can view supervisor-added assignee reviews)
                $callerRole = strtolower($currentUser->role ?? '');
                $creatorRole = strtolower($assignment->creator?->role ?? '');

                if ($callerRole === 'hr') {
                    $hrAllowed = $isCreator || ($creatorRole === 'supervisor') || $isAssigned;
                    if (!$hrAllowed) {
                        return response()->json(['error' => 'Access denied. You can only view your own task reviews.'], 403);
                    }
                } else {
                    // Non-admin, non-HR: only creator or assignee can view
                    if (! $isCreator && ! $isAssigned) {
                        return response()->json(['error' => 'Access denied. You can only view your own task reviews.'], 403);
                    }
                }
            }

            $existingReview = PerformanceReview::where('kpi_assignment_id', $assignmentId)->first();
            
            $submissions = $assignment->progressSubmissions->map(function($submission) {
                return [
                    'id' => $submission->id,
                    'note' => $submission->note,
                    'progress_percentage' => $submission->progress_percentage,
                    'performance_metrics' => $submission->performance_metrics,
                    'document_name' => $submission->document_name,
                    'document_path' => $submission->document_path,
                    'created_at' => $submission->created_at->toISOString(),
                    'employee_name' => $submission->employee->full_name ?? 'Employee'
                ];
            });

            $latestSubmission = $assignment->progressSubmissions->first();

            return response()->json([
                'assignment' => [
                    'id' => $assignment->id,
                    'task_name' => $assignment->kpiTask->task_name ?? 'Unknown Task',
                    'employee_name' => $assignment->employee->full_name ?? 'Unknown Employee',
                    'employee_id' => $assignment->employee->attendance_employee_no ?? '',
                    'department' => $assignment->department->name ?? 'Unknown Department',
                    'company' => $assignment->company->name ?? 'Unknown Company',
                    'start_date' => $assignment->start_date?->toDateString(),
                    'end_date' => $assignment->end_date?->toDateString(),
                    'description' => $assignment->description,
                    'weights' => $assignment->weights,
                    'priority' => $assignment->priority,
                ],
                'submissions' => $submissions,
                'supervisor_review' => $existingReview ? [
                    'progress' => (int) $existingReview->progress, // Ensure integer for supervisor progress
                    'grade' => $existingReview->grade,
                    'comments' => $existingReview->supervisor_comments,
                    'status' => $existingReview->status,
                    'performance_metrics' => $existingReview->performance_metrics, // Include full metrics
                    'last_updated' => $existingReview->updated_at->toISOString(),
                ] : null,
                'self_reported' => $latestSubmission ? [
                    'progress' => (int) $latestSubmission->progress_percentage, // Employee's self-reported progress
                    'last_updated' => $latestSubmission->created_at->toISOString(),
                    'author' => $latestSubmission->employee->full_name ?? 'Employee',
                    'performance_metrics' => $latestSubmission->performance_metrics,
                ] : null
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error fetching performance review details', [
                'assignment_id' => $assignmentId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to fetch performance review details'
            ], 500);
        }
    }

    /**
     * Get documents for a specific assignment.
     */
    public function getAssignmentDocuments($assignmentId)
    {
        try {
            $documents = TaskProgressSubmission::where('kpi_assignment_id', $assignmentId)
                ->whereNotNull('document_name')
                ->with('employee:id,full_name,attendance_employee_no')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function($submission) {
                    return [
                        'id' => $submission->id,
                        'documentName' => $submission->document_name,
                        'documentSize' => $submission->document_size,
                        'documentType' => $submission->document_type,
                        'documentPath' => $submission->document_path,
                        'author' => $submission->employee->full_name ?? 'Employee',
                        'date' => $submission->created_at->toISOString(),
                        'note' => $submission->note,
                        'progressPercentage' => $submission->progress_percentage,
                        'performanceMetrics' => $submission->performance_metrics,
                    ];
                });

            return response()->json($documents, 200);

        } catch (\Exception $e) {
            \Log::error('Error fetching assignment documents', [
                'assignmentId' => $assignmentId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to fetch documents'
            ], 500);
        }
    }

    /**
     * Derive cycle from date
     */
    private function deriveCycle($date)
    {
        $year = date('Y', strtotime($date));
        $month = date('n', strtotime($date));
        
        if ($month >= 1 && $month <= 3) {
            return $year . ' Q1';
        } elseif ($month >= 4 && $month <= 6) {
            return $year . ' Q2';
        } elseif ($month >= 7 && $month <= 9) {
            return $year . ' Q3';
        } else {
            return $year . ' Q4';
        }
    }

    /**
     * Update or create a performance review with metrics and supervisor feedback
     */
    public function updatePerformanceReview(Request $request, $assignmentId)
    {
        try {
            $validated = $request->validate([
                'progress' => 'required|integer|min:0|max:100',
                'grade' => 'nullable|string|max:5',
                'supervisor_comments' => 'nullable|string',
                'status' => 'required|string|in:Draft,In Progress,Pending Manager,Pending Employee,Completed',
                'performance_metrics' => 'required|array',
                // Add validation for individual metric fields
                'performance_metrics.jobKnowledge' => 'integer|min:0|max:100',
                'performance_metrics.qualityOfWork' => 'integer|min:0|max:100',
                'performance_metrics.productivity' => 'integer|min:0|max:100',
                'performance_metrics.communicationSkills' => 'integer|min:0|max:100',
                'performance_metrics.teamwork' => 'integer|min:0|max:100',
                'performance_metrics.behaviorAtWork' => 'integer|min:0|max:100',
                'performance_metrics.problemSolving' => 'integer|min:0|max:100',
                'performance_metrics.attendance' => 'integer|min:0|max:100',
                'performance_metrics.adaptability' => 'integer|min:0|max:100',
                'performance_metrics.selfDevelopment' => 'integer|min:0|max:100',
                'performance_metrics.discipline' => 'integer|min:0|max:100',
                'performance_metrics.adherenceToGuidelines' => 'integer|min:0|max:100',
            ]);
            
            // Clean and ensure all metrics are integers
            $cleanMetrics = [];
            foreach ($validated['performance_metrics'] as $key => $value) {
                $cleanMetrics[$key] = (int) $value;
            }
            
            // Get the assignment with all related data
            $assignment = KpiTaskAssignment::with([
                'employee', 
                'creatorRole',
                'progressSubmissions' => function($query) {
                    $query->orderBy('created_at', 'desc');
                }
            ])->findOrFail($assignmentId);
            
            // Get the latest submission for self-reported data
            $latestSubmission = $assignment->progressSubmissions->first();
            
            // Get supervisor ID from current auth user
            $supervisorId = auth()->id();
            
            // Find or create performance review for this assignment
            $review = PerformanceReview::updateOrCreate(
                ['kpi_assignment_id' => $assignmentId],
                [
                    'employee_id' => $assignment->employee->id,
                    'supervisor_id' => $supervisorId,
                    'progress' => $validated['progress'], // Store supervisor's progress rating
                    'grade' => $validated['grade'],
                    'supervisor_comments' => $validated['supervisor_comments'],
                    'status' => $validated['status'],
                    'performance_metrics' => $cleanMetrics, // Store clean metrics as JSON
                    'review_type' => 'performance',
                    'review_cycle' => $this->deriveCycle($assignment->start_date),
                    'start_date' => $assignment->start_date,
                    'due_date' => $assignment->end_date,
                    'completed_date' => $validated['status'] === 'Completed' ? now() : null,
                    
                    // Set self-reported data from latest submission
                    'self_reported_progress' => $latestSubmission ? $latestSubmission->progress_percentage : 0,
                    'self_reported_last_updated' => $latestSubmission ? $latestSubmission->created_at : null,
                ]
            );
            
            // Update the KPI assignment status based on review status
            if (isset($validated['status'])) {
                if ($validated['status'] === 'Completed') {
                    $assignment->completion_status = 'completed';
                } elseif ($validated['status'] === 'Draft') {
                    $assignment->completion_status = 'not-started';
                } else {
                    $assignment->completion_status = 'in-progress';
                }
                $assignment->save();
            }
            
            // Log for debugging
            \Log::info('Performance review saved', [
                'assignment_id' => $assignmentId,
                'progress' => $validated['progress'],
                'performance_metrics' => $cleanMetrics,
                'review_id' => $review->id
            ]);
            
            return response()->json([
                'message' => 'Performance review updated successfully',
                'review' => $review->load(['employee', 'supervisor', 'kpiAssignment'])
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Performance review validation failed', [
                'errors' => $e->errors(),
                'input' => $request->except(['performance_metrics'])
            ]);
            
            return response()->json([
                'error' => 'Validation failed',
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], 422);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Assignment not found',
                'message' => 'The specified assignment does not exist'
            ], 404);
            
        } catch (\Exception $e) {
            \Log::error('Error updating performance review', [
                'assignment_id' => $assignmentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to update performance review',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate employee performance based on completed tasks within a date range.
     */
    public function calculateEmployeePerformance(Request $request)
    {
        try {
            $validated = $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'employee_id' => 'nullable|exists:employees,id',
            ]);

            $startDate = $validated['start_date'];
            $endDate = $validated['end_date'];
            $employeeId = $validated['employee_id'] ?? null;
            
            // Log the request parameters
            \Log::info('Performance calculation request', [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'employeeId' => $employeeId
            ]);

            // Build the query to find completed tasks within date range
            // Fix: Use end_date from kpi_task_assignments table instead of due_date from performance_reviews
            $query = KpiTaskAssignment::with([
                    'kpiTask:id,task_name',
                    'employee:id,full_name,attendance_employee_no',
                    'performanceReviews' => function($q) {
                        $q->where('status', 'Completed');
                    }
                ])
                ->where('end_date', '>=', $startDate)
                ->where('end_date', '<=', $endDate)
                ->whereHas('performanceReviews', function($q) {
                    $q->where('status', 'Completed');
                });
                
            // Filter by employee if provided
            if ($employeeId) {
                $query->where('employee_id', $employeeId);
            }
            
            $assignments = $query->get();
            
            // If no tasks are found, return empty result
            if ($assignments->isEmpty()) {
                return response()->json([
                    'message' => 'No completed tasks found within the specified date range.',
                    'data' => null
                ]);
            }
            
            // Group by employee
            $employeeResults = [];
            
            foreach ($assignments as $assignment) {
                $employeeId = $assignment->employee_id;
                $employee = $assignment->employee;
                
                if (!isset($employeeResults[$employeeId])) {
                    $employeeResults[$employeeId] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->full_name,
                        'attendance_no' => $employee->attendance_employee_no,
                        'tasks' => [],
                        'total_score' => 0,
                        'task_count' => 0,
                    ];
                }
                
                // Get the latest completed performance review for this assignment
                $review = $assignment->performanceReviews()
                    ->where('status', 'Completed')
                    ->orderBy('updated_at', 'desc')
                    ->first();
                    
                if (!$review) {
                    continue; // Skip if no completed review found
                }
                
                // Get the task weights from the KPI assignment
                $weights = $assignment->weights ?? [];
                $totalWeight = 0;
                
                if (!empty($weights)) {
                    // Sum up the weight percentages
                    foreach ($weights as $weight) {
                        $totalWeight += isset($weight['percentage']) ? (float)$weight['percentage'] : 0;
                    }
                }
                
                // Use supervisor progress from performance review
                $supervisorProgress = $review->progress;
                
                // Calculate task score: weight * progress / 100
                $taskScore = ($totalWeight * $supervisorProgress) / 100;
                
                // Add task details to the result
                $employeeResults[$employeeId]['tasks'][] = [
                    'task_id' => $assignment->id,
                    'task_name' => $assignment->kpiTask->task_name ?? 'Unknown Task',
                    'supervisor_progress' => $supervisorProgress,
                    'total_weight' => $totalWeight,
                    'task_score' => $taskScore,
                    'start_date' => $assignment->start_date,
                    'end_date' => $assignment->end_date,
                ];
                
                // Add to the employee's total score
                $employeeResults[$employeeId]['total_score'] += $taskScore;
                $employeeResults[$employeeId]['task_count']++;
            }
            
            // Calculate final percentages and grades for each employee
            foreach ($employeeResults as &$result) {
                if ($result['task_count'] > 0) {
                    // Calculate average task score
                    $average = $result['total_score'] / $result['task_count'];
                    
                    // Calculate final percentage (Average / 60) * 100, capped at 100%
                    $finalPercentage = min(100, max(0, round(($average / 60) * 100)));
                    
                    // Assign grade based on percentage
                    $grade = $this->getGrade($finalPercentage);
                    
                    $result['percentage'] = $finalPercentage;
                    $result['grade'] = $grade['grade'];
                    $result['performance_label'] = $grade['label'];
                } else {
                    $result['percentage'] = 0;
                    $result['grade'] = 'N/A';
                    $result['performance_label'] = 'No Data';
                }
            }
            
            // If a specific employee was requested, return just that result, otherwise return all
            if (isset($validated['employee_id'])) {
                $employeeId = $validated['employee_id'];
                return response()->json([
                    'data' => $employeeResults[$employeeId] ?? null
                ]);
            }
            
            return response()->json([
                'data' => array_values($employeeResults)
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error calculating employee performance', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to calculate employee performance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save employee performance evaluation.
     */
    public function saveEmployeePerformance(Request $request)
    {
        try {
            $validated = $request->validate([
                'employee_id' => 'required|exists:employees,id',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'percentage' => 'required|integer|min:0|max:100',
                'grade' => 'required|string|max:5',
                'performance_label' => 'required|string|max:255',
                'calculation_details' => 'required|array',
                'task_count' => 'required|integer|min:0',
            ]);
            
            // Get the current authenticated user as the evaluator
            $evaluatorId = auth()->id();
            
            // If no authenticated user, use a default system user ID
            if (!$evaluatorId) {
                $evaluatorId = 1; // Fallback to system user
            }
            
            // Create or update the performance evaluation
            $evaluation = PerformanceEvaluation::updateOrCreate(
                [
                    'employee_id' => $validated['employee_id'],
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                ],
                [
                    'evaluator_id' => $evaluatorId,
                    'percentage' => $validated['percentage'],
                    'grade' => $validated['grade'],
                    'performance_label' => $validated['performance_label'],
                    'calculation_details' => $validated['calculation_details'],
                    'task_count' => $validated['task_count'],
                ]
            );
            
            // Load the relationships for the response
            $evaluation->load(['employee:id,full_name,attendance_employee_no', 'evaluator:id,name']);
            
            return response()->json([
                'message' => 'Performance evaluation saved successfully',
                'data' => $evaluation
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error saving employee performance evaluation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'error' => 'Failed to save employee performance evaluation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get employee performance evaluations.
     */
    public function getEmployeePerformanceEvaluations(Request $request)
    {
        try {
            $employeeId = $request->query('employee_id');
            
            $query = PerformanceEvaluation::with(['employee:id,full_name,attendance_employee_no', 'evaluator:id,name'])
                ->orderBy('created_at', 'desc');
                
            if ($employeeId) {
                $query->where('employee_id', $employeeId);
            }
            
            $evaluations = $query->get();
            
            return response()->json([
                'data' => $evaluations
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error fetching employee performance evaluations', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch employee performance evaluations: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get KPI dashboard stats: on-target vs need-attention within a date range.
     *
     * Query params:
     *  - start_date (YYYY-MM-DD) optional (defaults to first day of current month)
     *  - end_date   (YYYY-MM-DD) optional (defaults to last day of current month)
     *
     * Logic:
     *  - Need Attention: assignments in the date window that have NO progress submissions and not completed.
     *  - On Target: assignments in the date window that have a completed performance review and the completed_date is
     *               within the window and completed on or before the assignment's end_date (on-time).
     */
    public function getKpiPerformance(Request $request)
    {
        try {
            $startDate = $request->query('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
            $endDate = $request->query('end_date') ?? Carbon::now()->endOfMonth()->toDateString();

            Log::info('KPI performance request', ['start' => $startDate, 'end' => $endDate]);

            // Get assignments that overlap the requested window
            $assignmentsQuery = KpiTaskAssignment::whereNull('deleted_at')
                ->where(function($q) use ($startDate, $endDate) {
                    // Overlap: assignment.start <= end AND assignment.end >= start
                    $q->where('start_date', '<=', $endDate)
                      ->where('end_date', '>=', $startDate);
                })
                ->with([
                    'progressSubmissions:id,kpi_assignment_id', 
                    'performanceReviews' => function($q) {
                        $q->where('status', 'Completed')->orderBy('completed_date', 'desc');
                    },
                    'kpiTask:id,task_name'
                ]);

            $assignments = $assignmentsQuery->get();

            $needAttention = 0;
            $onTarget = 0;

            foreach ($assignments as $assignment) {
                // Need Attention: no submissions and not completed
                $hasSubmissions = ($assignment->progressSubmissions && $assignment->progressSubmissions->count() > 0);
                $isCompleted = strtolower($assignment->completion_status ?? '') === 'completed';

                if (!$hasSubmissions && !$isCompleted) {
                    $needAttention++;
                }

                // On Target: has a completed review whose completed_date is in range and completed on or before assignment end_date
                $completedReview = $assignment->performanceReviews->first(); // ordered by completed_date desc
                if ($completedReview && $completedReview->completed_date) {
                    $completedDate = Carbon::parse($completedReview->completed_date)->toDateString();
                    $assignmentEnd = $assignment->end_date ? Carbon::parse($assignment->end_date)->toDateString() : $endDate;

                    if ($completedDate >= $startDate && $completedDate <= $endDate && $completedDate <= $assignmentEnd) {
                        $onTarget++;
                    }
                }
            }

            return response()->json([
                'data' => [
                    'onTarget' => $onTarget,
                    'needAttention' => $needAttention,
                    'totalInWindow' => $assignments->count(),
                    'startDate' => $startDate,
                    'endDate' => $endDate
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error computing KPI performance stats', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Failed to compute KPI dashboard stats'], 500);
        }
    }

    /**
     * Get grade based on percentage.
     */
    private function getGrade($percentage)
    {
        if ($percentage >= 81) return ['grade' => 'A+', 'label' => 'Excellent'];
        if ($percentage >= 61) return ['grade' => 'A', 'label' => 'Above Average'];
        if ($percentage >= 41) return ['grade' => 'B', 'label' => 'Average'];
        if ($percentage >= 21) return ['grade' => 'B-', 'label' => 'Below Average'];
        return ['grade' => 'C', 'label' => 'Poor Performance'];
    }

    /**
     * Get PMS dashboard statistics
     */
    public function getDashboardStats()
    {
        try {
            $currentUser = auth()->user();
            if (!$currentUser) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }

            $userRole = strtolower($currentUser->role ?? '');
            $isAdmin = $userRole === 'admin';

            // Base queries with role-based filtering
            $kpiQuery = KpiTaskAssignment::whereNull('deleted_at');
            $reviewsQuery = KpiTaskAssignment::whereHas('progressSubmissions');

            // Apply role-based restrictions
            if (!$isAdmin && $currentUser->employee_id) {
                $currentEmployee = employee::find($currentUser->employee_id);
                if ($currentEmployee) {
                    if ($userRole === 'manager') {
                        // Manager sees HR + supervisor tasks + assigned tasks
                        $kpiQuery->where(function($q) use ($currentEmployee) {
                            $q->whereHas('creatorRole', function($subQ) {
                                $subQ->whereIn('role_name', ['HR', 'Human Resources', 'HR Manager', 'Supervisor', 'Operations', 'Management']);
                            })->orWhere('employee_id', $currentEmployee->id);
                        });
                        $reviewsQuery->where(function($q) use ($currentEmployee) {
                            $q->whereHas('creatorRole', function($subQ) {
                                $subQ->whereIn('role_name', ['HR', 'Human Resources', 'HR Manager', 'Supervisor', 'Operations', 'Management']);
                            })->orWhere('employee_id', $currentEmployee->id);
                        });
                    } elseif ($userRole === 'supervisor') {
                        // Supervisor sees own created + assigned tasks
                        $kpiQuery->where(function($q) use ($currentEmployee) {
                            $q->where('employee_id', $currentEmployee->id)
                              ->orWhereHas('creatorRole', function($subQ) {
                                  $subQ->whereIn('role_name', ['Supervisor', 'Operations', 'Management']);
                              });
                        });
                        $reviewsQuery->where(function($q) use ($currentEmployee) {
                            $q->where('employee_id', $currentEmployee->id)
                              ->orWhereHas('creatorRole', function($subQ) {
                                  $subQ->whereIn('role_name', ['Supervisor', 'Operations', 'Management']);
                              });
                        });
                    } else {
                        // Default: only assigned tasks
                        $kpiQuery->where('employee_id', $currentEmployee->id);
                        $reviewsQuery->where('employee_id', $currentEmployee->id);
                    }
                }
            }

            // Count statistics
            $totalKpiTasks = $kpiQuery->count();
            $activeReviews = $reviewsQuery->count();
            $completedTasks = (clone $kpiQuery)->where('completion_status', 'completed')->count();
            $upcomingDeadlines = (clone $kpiQuery)->where('end_date', '>=', now())
                                                   ->where('end_date', '<=', now()->addDays(30))
                                                   ->count();

            // Calculate goal progress percentage
            $goalProgress = $totalKpiTasks > 0 ? round(($completedTasks / $totalKpiTasks) * 100) : 0;

            return response()->json([
                'activeReviews' => $activeReviews,
                'goalProgress' => $goalProgress,
                'upcomingDeadlines' => $upcomingDeadlines,
                'totalKpiTasks' => $totalKpiTasks,
                'completedTasks' => $completedTasks,
                'progressIncrease' => 5, // Could calculate from historical data
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching PMS dashboard stats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to fetch dashboard statistics'
            ], 500);
        }
    }

    /**
     * Get upcoming deadlines for dashboard
     */
    public function getUpcomingDeadlines()
    {
        try {
            $currentUser = auth()->user();
            if (!$currentUser) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }

            $userRole = strtolower($currentUser->role ?? '');
            $isAdmin = $userRole === 'admin';

            $query = KpiTaskAssignment::with(['kpiTask:id,task_name', 'employee:id,full_name'])
                ->whereNull('deleted_at')
                ->where('end_date', '>=', now())
                ->where('end_date', '<=', now()->addDays(30))
                ->whereNotIn('completion_status', ['completed']);

            // Apply role-based restrictions (same logic as dashboard stats)
            if (!$isAdmin && $currentUser->employee_id) {
                $currentEmployee = employee::find($currentUser->employee_id);
                if ($currentEmployee) {
                    if ($userRole === 'manager') {
                        $query->where(function($q) use ($currentEmployee) {
                            $q->whereHas('creatorRole', function($subQ) {
                                $subQ->whereIn('role_name', ['HR', 'Human Resources', 'HR Manager', 'Supervisor', 'Operations', 'Management']);
                            })->orWhere('employee_id', $currentEmployee->id);
                        });
                    } elseif ($userRole === 'supervisor') {
                        $query->where(function($q) use ($currentEmployee) {
                            $q->where('employee_id', $currentEmployee->id)
                              ->orWhereHas('creatorRole', function($subQ) {
                                  $subQ->whereIn('role_name', ['Supervisor', 'Operations', 'Management']);
                              });
                        });
                    } else {
                        $query->where('employee_id', $currentEmployee->id);
                    }
                }
            }

            $deadlines = $query->orderBy('end_date', 'asc')
                              ->limit(10)
 ->get()
                              ->map(function($assignment) {
                                  $daysLeft = now()->diffInDays($assignment->end_date, false);
                                  $type = $daysLeft <= 7 ? 'urgent' : ($daysLeft <= 14 ? 'review' : 'goals');
                                  
                                  return [
                                      'id' => $assignment->id,
                                      'name' => $assignment->kpiTask->task_name ?? 'Task',
                                      'deadline' => $assignment->end_date->toDateString(),
                                      'daysLeft' => max(0, $daysLeft),
                                      'type' => $type,
                                      'assignee' => $assignment->employee->full_name ?? 'Unknown'
                                  ];
                              });

            return response()->json($deadlines);

        } catch (\Exception $e) {
            \Log::error('Error fetching upcoming deadlines', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to fetch upcoming deadlines'
            ], 500);
        }
    }

    /**
     * Store a newly created KPI task.
     */
    public function storeKpiTask(Request $request)
    {
        try {
            $validated = $request->validate([
                'task_name'   => 'required|string|max:255|unique:kpi_tasks,task_name,NULL,id,deleted_at,NULL',
                'description' => 'nullable|string|max:1000',
            ], [
                'task_name.required' => 'Task name is required',
                'task_name.unique'   => 'A KPI task with this name already exists',
                'task_name.max'      => 'Task name cannot exceed 255 characters',
                'description.max'    => 'Description cannot exceed 1000 characters',
            ]);

           

            $kpiTask = KpiTask::create($validated);

            \Log::info('KPI task created successfully', [
                'task_name' => $kpiTask->task_name
            ]);

            return response()->json($kpiTask, 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (QueryException $qe) {
            // Handle duplicate key gracefully
            if ($qe->getCode() === '23000') {
                return response()->json(['message' => 'A KPI task with this name already exists'], 422);
            }
            \Log::error('QueryException creating KPI task', ['error' => $qe->getMessage()]);
            return response()->json(['error' => 'Database error creating KPI task'], 500);
        } catch (\Exception $e) {
            \Log::error('Error creating KPI task', ['data' => $request->all(), 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to create KPI task'], 500);
        }
    }

    /**
     * Update the specified KPI task.
     */
    public function updateKpiTask(Request $request, $id)
    {
        try {
            $kpiTask = KpiTask::findOrFail($id);

            $validated = $request->validate([
                'task_name'   => ['required','string','max:255',
                    Rule::unique('kpi_tasks','task_name')->ignore($id)->whereNull('deleted_at')
                ],
                'description' => 'nullable|string|max:1000',
            ], [
                'task_name.required' => 'Task name is required',
                'task_name.unique'   => 'A KPI task with this name already exists',
                'task_name.max'      => 'Task name cannot exceed 255 characters',
                'description.max'    => 'Description cannot exceed 1000 characters',
            ]);

            $kpiTask->update($validated);

            \Log::info('KPI task updated successfully', [
                'id' => $kpiTask->id,
                'task_name' => $kpiTask->task_name
            ]);

            return response()->json($kpiTask, 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'KPI task not found'], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (QueryException $qe) {
            if ($qe->getCode() === '23000') {
                return response()->json(['message' => 'A KPI task with this name already exists'], 422);
            }
            \Log::error('QueryException updating KPI task', ['id' => $id, 'error' => $qe->getMessage()]);
            return response()->json(['error' => 'Database error updating KPI task'], 500);
        } catch (\Exception $e) {
            \Log::error('Error updating KPI task', ['id' => $id, 'data' => $request->all(), 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to update KPI task'], 500);
        }
    }

    /**
     * Remove the specified KPI task (soft delete).
     */
    public function destroyKpiTask($id)
    {
        try {
            $kpiTask = KpiTask::findOrFail($id);

            // Check if this task is being used in any assignments
            $assignmentCount = KpiTaskAssignment::where('kpi_task_id', $id)
                ->whereNull('deleted_at')
                ->count();

            if ($assignmentCount > 0) {
                return response()->json([
                    'message' => 'Cannot delete this KPI task as it is currently assigned to employees',
                    'assignments_count' => $assignmentCount
                ], 422);
            }

            $kpiTask->delete(); // This will soft delete due to SoftDeletes trait

            \Log::info('KPI task deleted successfully', [
                'id' => $id,
                'task_name' => $kpiTask->task_name
            ]);

            return response()->json([
                'message' => 'KPI task deleted successfully'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'KPI task not found'], 404);
        } catch (\Exception $e) {
            \Log::error('Error deleting KPI task', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to delete KPI task',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new Creator Role.
     */
    public function storeCreatorRole(Request $request)
    {
        try {
            $validated = $request->validate([
                'role_name' => ['required', 'string', 'max:255',
                    Rule::unique('creator_roles', 'role_name')->whereNull('deleted_at')
                ],
            ], [
                'role_name.required' => 'Role name is required',
                'role_name.unique' => 'A creator role with this name already exists',
                'role_name.max' => 'Role name cannot exceed 255 characters',
            ]);

            $role = CreatorRole::create([
                'role_name' => $validated['role_name'],
            ]);

            return response()->json($role, 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (QueryException $qe) {
            if ($qe->getCode() === '23000') {
                return response()->json(['message' => 'A creator role with this name already exists'], 422);
            }
            \Log::error('QueryException creating CreatorRole', ['error' => $qe->getMessage()]);
            return response()->json(['error' => 'Database error creating creator role'], 500);
        } catch (\Exception $e) {
            \Log::error('Error creating CreatorRole', ['error' => $e->getMessage(), 'data' => $request->all()]);
            return response()->json(['error' => 'Failed to create creator role'], 500);
        }
    }

    /**
     * Update an existing Creator Role.
     */
    public function updateCreatorRole(Request $request, $id)
    {
        try {
            $role = CreatorRole::findOrFail($id);

            $validated = $request->validate([
                'role_name' => ['required', 'string', 'max:255',
                    Rule::unique('creator_roles', 'role_name')->ignore($id)->whereNull('deleted_at')
                ],
            ], [
                'role_name.required' => 'Role name is required',
                'role_name.unique' => 'A creator role with this name already exists',
                'role_name.max' => 'Role name cannot exceed 255 characters',
            ]);

            $role->update(['role_name' => $validated['role_name']]);

            return response()->json($role, 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Creator role not found'], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (QueryException $qe) {
            if ($qe->getCode() === '23000') {
                return response()->json(['message' => 'A creator role with this name already exists'], 422);
            }
            \Log::error('QueryException updating CreatorRole', ['id' => $id, 'error' => $qe->getMessage()]);
            return response()->json(['error' => 'Database error updating creator role'], 500);
        } catch (\Exception $e) {
            \Log::error('Error updating CreatorRole', ['id' => $id, 'error' => $e->getMessage(), 'data' => $request->all()]);
            return response()->json(['error' => 'Failed to update creator role'], 500);
        }
    }

    /**
     * Soft-delete a Creator Role (prevents deletion if assigned).
     */
    public function destroyCreatorRole($id)
    {
        try {
            $role = CreatorRole::findOrFail($id);

            // Prevent deletion if assigned to any KPI assignments (not soft-deleted)
            $assignmentCount = KpiTaskAssignment::where('creator_role_id', $id)->whereNull('deleted_at')->count();
            if ($assignmentCount > 0) {
                return response()->json([
                    'message' => 'Cannot delete this creator role because it is assigned to KPIs',
                    'assignments_count' => $assignmentCount
                ], 422);
            }

            $role->delete(); // soft delete

            return response()->json(['message' => 'Creator role deleted'], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Creator role not found'], 404);
        } catch (\Exception $e) {
            \Log::error('Error deleting CreatorRole', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to delete creator role'], 500);
        }
    }

    /**
     * Approve a KPI task assignment.
     */
    public function approveKpiTask($id)
    {
        try {
            $assignment = KpiTaskAssignment::findOrFail($id);
            $assignment->approval_status = 'approved';
            $assignment->save();

            \Log::info('KPI task approved', ['id' => $id]);

            return response()->json([
                'message' => 'KPI task approved successfully',
                'assignment' => $assignment
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'KPI task not found'], 404);
        } catch (\Exception $e) {
            \Log::error('Error approving KPI task', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to approve KPI task',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject a KPI task assignment with optional reason.
     */
    public function rejectKpiTask(Request $request, $id)
    {
        try {
            // reason is optional now
            $reason = $request->input('reason', null);

            $assignment = KpiTaskAssignment::findOrFail($id);
            $assignment->approval_status = 'rejected';

            // store reason if provided (notes field exists)
            if (!empty($reason)) {
                $assignment->notes = $reason;
            }

            $assignment->save();

            \Log::info('KPI task rejected', [
                'id' => $id,
                'reason' => $reason
            ]);

            return response()->json([
                'message' => 'KPI task rejected successfully',
                'assignment' => $assignment
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'KPI task not found'], 404);
        } catch (\Exception $e) {
            \Log::error('Error rejecting KPI task', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to reject KPI task',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
