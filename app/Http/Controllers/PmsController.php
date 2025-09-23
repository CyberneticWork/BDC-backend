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
use App\Models\PerformanceEvaluation; // Make sure this is imported

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

            // Determine department: prefer provided department_id, else derive from employee's organizationAssignment
            $departmentId = $validated['department_id'] ?? null;
            if (!$departmentId) {
                try {
                    $departmentId = $employee->organizationAssignment?->department_id ?? null;
                    \Log::info('Derived department id from organizationAssignment', [
                        'attendance_no' => $attendanceNo,
                        'derived_department_id' => $departmentId
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('Failed to derive department from organizationAssignment', [
                        'attendance_no' => $attendanceNo,
                        'error' => $e->getMessage()
                    ]);
                    $departmentId = null;
                }
            }

            $assignment = KpiTaskAssignment::create([
                'kpi_task_id' => $kpiTask->id,
                'creator_role_id' => $creatorRole->id,
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

            // Employee lookup handling both ID and attendance number
            $employee = null;
            
            // Try to find by attendance number first
            if (is_string($employeeId) && preg_match('/^EMP/i', $employeeId)) {
                $employee = Employee::where('attendance_employee_no', $employeeId)->first();
                \Log::info('Lookup by attendance number', [
                    'employeeId' => $employeeId, 
                    'found' => ($employee ? 'yes' : 'no')
                ]);
            }
            
            // If not found and it's numeric, try by ID
            if (!$employee && is_numeric($employeeId)) {
                $employee = Employee::find($employeeId);
                \Log::info('Lookup by numeric ID', [
                    'employeeId' => $employeeId, 
                    'found' => ($employee ? 'yes' : 'no')
                ]);
                
                // If still not found, try formatted attendance number
                if (!$employee) {
                    $formattedId = 'EMP' . str_pad($employeeId, 4, '0', STR_PAD_LEFT);
                    $employee = Employee::where('attendance_employee_no', $formattedId)->first();
                    \Log::info('Lookup by formatted attendance number', [
                        'formattedId' => $formattedId, 
                        'found' => ($employee ? 'yes' : 'no')
                    ]);
                }
            }

            if (!$employee) {
                \Log::warning('Employee not found', ['employeeId' => $employeeId]);
                return response()->json([
                    'error' => 'Employee not found',
                    'employeeId' => $employeeId
                ], 404);
            }

            \Log::info('Found employee', [
                'id' => $employee->id, 
                'name' => $employee->full_name, 
                'attendance_no' => $employee->attendance_employee_no
            ]);

            // Fetch assignments and log query details
            $query = KpiTaskAssignment::where('employee_id', $employee->id)
                ->whereNull('deleted_at');
                
            \Log::info('Executing query', [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings()
            ]);
            
            $assignments = $query->get();

            \Log::info('Retrieved assignments', ['count' => $assignments->count()]);

            if ($assignments->isEmpty()) {
                \Log::info('No assignments found for employee', ['employeeId' => $employeeId]);
                return response()->json([], 200);
            }

            // Transform assignments manually (no eager loading)
            $transformed = $assignments->map(function ($assignment) {
                // Safely fetch related data (set to null if fails)
                $kpiTask = null;
                $company = null;
                $department = null;
                $creatorRole = null;

                try {
                    $kpiTask = KpiTask::find($assignment->kpi_task_id);
                } catch (\Exception $e) {
                    \Log::warning('Failed to fetch KpiTask', ['id' => $assignment->kpi_task_id, 'error' => $e->getMessage()]);
                }

                try {
                    $company = company::find($assignment->company_id);
                } catch (\Exception $e) {
                    \Log::warning('Failed to fetch company', ['id' => $assignment->company_id, 'error' => $e->getMessage()]);
                }

                try {
                    $department = departments::find($assignment->department_id);
                } catch (\Exception $e) {
                    \Log::warning('Failed to fetch department', ['id' => $assignment->department_id, 'error' => $e->getMessage()]);
                }

                try {
                    $creatorRole = CreatorRole::find($assignment->creator_role_id);
                } catch (\Exception $e) {
                    \Log::warning('Failed to fetch CreatorRole', ['id' => $assignment->creator_role_id, 'error' => $e->getMessage()]);
                }

                // Build response with major data (other fields nullable)
                return [
                    'id' => $assignment->id,
                    'name' => $kpiTask ? $kpiTask->task_name : 'Unknown Task',
                    'description' => $assignment->description ?? ($kpiTask ? $kpiTask->description : ''),

                    'startDate' => $assignment->start_date ? $assignment->start_date->toDateString() : null,
                    'endDate' => $assignment->end_date ? $assignment->end_date->toDateString() : null,
                    'status' => $assignment->status ?? 'active',
                    'priority' => $assignment->priority ?? 'medium',
                    'completionStatus' => $assignment->completion_status ?? 'not-started',
                    'weights' => $assignment->weights ?? [],
                    'lastUpdated' => $assignment->last_updated ? $assignment->last_updated->toISOString() : 
                                    ($assignment->created_at ? $assignment->created_at->toISOString() : null),
                    'documentCount' => 0, // As requested, ignore document data
                    'assignees' => [$employee->attendance_employee_no ?? ''],
                    'assigneeUpdates' => [], // As requested, ignore submission data
                    'company' => $company ? $company->name : null,
                    'department' => $department ? $department->name : null,
                    'creatorRole' => $creatorRole ? $creatorRole->role_name : null,
                ];
            });

            return response()->json($transformed, 200);
        } catch (\Exception $e) {
            \Log::error('Error fetching KPI assignments', [
                'employeeId' => $employeeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to fetch KPI assignments',
                'message' => $e->getMessage()
            ], 500);
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
                $employee = Employee::where('attendance_employee_no', $employeeId)->first();
            } elseif (is_numeric($employeeId)) {
                $employee = Employee::find($employeeId);
                if (!$employee) {
                    $formattedId = 'EMP' . str_pad($employeeId, 4, '0', STR_PAD_LEFT);
                    $employee = Employee::where('attendance_employee_no', $formattedId)->first();
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

            // Base query: only assignments having submissions
            $query = KpiTaskAssignment::with([
                'kpiTask:id,task_name',
                'employee:id,full_name,attendance_employee_no',
                'company:id,name',
                'department:id,name',
                'creatorRole:id,role_name',
                'progressSubmissions' => function ($q) {
                    $q->orderBy('created_at', 'desc');
                }
            ])
            ->whereHas('progressSubmissions')
            // Subselect latest submission timestamp
            ->addSelect([
                'latest_submission_at' => TaskProgressSubmission::select('created_at')
                    ->whereColumn('kpi_assignment_id', 'kpi_task_assignments.id')
                    ->latest()
                    ->limit(1),
                // Subselect latest performance review update (if exists)
                'latest_review_at' => PerformanceReview::select('updated_at')
                    ->whereColumn('kpi_assignment_id', 'kpi_task_assignments.id')
                    ->latest()
                    ->limit(1),
            ]);

            // Order: coalesce latest review updated_at else latest submission
            $query->orderByRaw('COALESCE(latest_review_at, latest_submission_at) DESC');

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
                    'employeeId' => $assignment->employee->attendance_employee_no ?? '',
                    'position' => '',
                    'department' => $assignment->department->name ?? 'Unknown Department',
                    'company' => $assignment->company->name ?? 'Unknown Company',
                    'manager' => $assignment->creatorRole->role_name ?? 'Supervisor',
                    'type' => 'Performance Review',
                    'status' => $existingReview ? $existingReview->status : 'Pending Manager',
                    'startDate' => $assignment->start_date?->toDateString(),
                    'dueDate' => $assignment->end_date?->toDateString(),
                    'completedDate' => $existingReview?->completed_date?->toDateString(),
                    'cycle' => $this->deriveCycle($assignment->start_date),
                    'progress' => $existingReview ? (int)$existingReview->progress : 0, // supervisor progress
                    'grade' => $existingReview?->grade,
                    'supervisorComments' => $existingReview?->supervisor_comments,
                    'lastUpdated' => ($existingReview?->updated_at ?? $latestSubmission?->created_at)?->toISOString(),
                    'performanceMetrics' => $existingReview?->performance_metrics,
                    'selfReportedProgress' => $latestSubmission?->progress_percentage ?? 0,
                    'selfReportedLastUpdated' => $latestSubmission?->created_at?->toISOString(),
                    'selfReportedAuthor' => $latestSubmission?->employee?->full_name,
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
            $assignment = KpiTaskAssignment::with([
                'kpiTask:id,task_name',
                'employee:id,full_name,attendance_employee_no',
                'company:id,name',
                'department:id,name',
                'creatorRole:id,role_name',
                'progressSubmissions' => function($query) {
                    $query->orderBy('created_at', 'desc');
                }
            ])->findOrFail($assignmentId);

            // Get existing performance review if it exists
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
                    'creator_role' => $assignment->creatorRole->role_name ?? 'Supervisor',
                    'start_date' => $assignment->start_date->toDateString(),
                    'end_date' => $assignment->end_date->toDateString(),
                    'priority' => $assignment->priority,
                    'description' => $assignment->description,
                    'weights' => $assignment->weights,
                ],
                'submissions' => $submissions,
                'performance_review' => $existingReview ? [
                    'progress' => $existingReview->progress,
                    'grade' => $existingReview->grade,
                    'supervisor_comments' => $existingReview->supervisor_comments,
                    'status' => $existingReview->status,
                    'performance_metrics' => $existingReview->performance_metrics,
                    'completed_date' => $existingReview->completed_date?->toDateString(),
                    'last_updated' => $existingReview->updated_at->toISOString(),
                ] : null,
                'self_reported' => $latestSubmission ? [
                    'progress' => $latestSubmission->progress_percentage,
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
                'performance_metrics' => 'required|array'
            ]);
            
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
            
            // Get supervisor ID from creator role or current auth user
            $supervisorId = auth()->id(); // Current authenticated user as supervisor
            
            // Find or create performance review for this assignment
            $review = PerformanceReview::updateOrCreate(
                ['kpi_assignment_id' => $assignmentId],
                [
                    'employee_id' => $assignment->employee->id,
                    'supervisor_id' => $supervisorId,
                    'progress' => $validated['progress'],
                    'grade' => $validated['grade'],
                    'supervisor_comments' => $validated['supervisor_comments'],
                    'status' => $validated['status'],
                    'performance_metrics' => $validated['performance_metrics'],
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
            
            // Also update the KPI assignment status
            $assignment->completion_status = match ($validated['status']) {
                'Completed' => 'completed',
                'Draft' => 'not-started',
                default => 'in-progress',
            };
            $assignment->save();
            
            return response()->json([
                'message' => 'Performance review updated successfully',
                'review' => $review->load(['employee', 'supervisor', 'kpiAssignment'])
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
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
}
