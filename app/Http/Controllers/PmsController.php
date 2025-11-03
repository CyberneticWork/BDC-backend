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
use App\Models\PracticalFeedback;
use App\Models\Notification;
use App\Models\User;
use App\Models\KpiWeight; // Import the KpiWeight model
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use App\Models\PerformanceAppraisal;

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
            ->whereHas('organizationAssignment', function ($q) use ($companyId, $departmentId) {
                $q->where('company_id', $companyId);
                if ($departmentId) {
                    $q->where('department_id', $departmentId);
                }
            })
            ->select('id', 'full_name', 'attendance_employee_no');

        if ($search) {
            $query->where(function ($q) use ($search) {
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
            ->map(function ($employee) {
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
            'kpi_type' => 'nullable|boolean',
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

        // Check for duplicate assignments before creating
        $duplicateEmployees = [];
        $validAssignments = [];

        foreach ($validated['assignees'] as $attendanceNo) {
            $employee = employee::where('attendance_employee_no', $attendanceNo)->first();
            if (!$employee) {
                return response()->json(['error' => 'Employee not found: ' . $attendanceNo], 400);
            }

            // Check for existing assignment with same task, employee, and overlapping date range
            $existingAssignment = KpiTaskAssignment::where('kpi_task_id', $kpiTask->id)
            ->where('employee_id', $employee->id)
            ->where(function ($query) use ($validated) {
                // Only consider it a duplicate if it's exactly the same date range
                // or if there's a significant overlap (more than just 1 day)
                $query->where(function ($q) use ($validated) {
                    // Exact same date range
                    $q->where('start_date', $validated['start_date'])
                    ->where('end_date', $validated['end_date']);
                })->orWhere(function ($q) use ($validated) {
                    // Or significant overlap (more than 50% of the shorter period)
                    $newStart = $validated['start_date'];
                    $newEnd = $validated['end_date'];
                    
                    // Calculate if there's substantial overlap
                    $q->where(function ($subQ) use ($newStart, $newEnd) {
                        $subQ->where('start_date', '<=', $newStart)
                            ->where('end_date', '>=', $newEnd); // Existing completely contains new
                    })->orWhere(function ($subQ) use ($newStart, $newEnd) {
                        $subQ->where('start_date', '>=', $newStart)
                            ->where('end_date', '<=', $newEnd); // New completely contains existing
                    });
                });
            })
            ->whereNull('deleted_at')
            ->first();

            if ($existingAssignment) {
                // Found duplicate - add to list
                $duplicateEmployees[] = [
                    'employee' => $employee->full_name,
                    'attendance_no' => $attendanceNo,
                    'existing_start' => $existingAssignment->start_date->toDateString(),
                    'existing_end' => $existingAssignment->end_date->toDateString(),
                    'new_start' => $validated['start_date'],
                    'new_end' => $validated['end_date']
                ];
            } else {
                // No duplicate found - add to valid assignments
                $validAssignments[] = $employee;
            }
        }

        // Convert string values to boolean if needed
        $kpiType = 0; // default to regular
        if (isset($validated['kpi_type'])) {
            if ($validated['kpi_type'] === 'performance_appraisal' || $validated['kpi_type'] === '1' || $validated['kpi_type'] === 1 || $validated['kpi_type'] === true) {
                $kpiType = 1;
            }
        }

        // If duplicates found, return error with details
        if (!empty($duplicateEmployees)) {
            $errorMessage = "Duplicate KPI task assignments detected for the following employees:\n\n";
            foreach ($duplicateEmployees as $duplicate) {
                $errorMessage .= "• {$duplicate['employee']} ({$duplicate['attendance_no']})\n";
                $errorMessage .= "  Existing: {$duplicate['existing_start']} to {$duplicate['existing_end']}\n";
                $errorMessage .= "  New: {$duplicate['new_start']} to {$duplicate['new_end']}\n\n";
            }
            $errorMessage .= "Please choose different date ranges that don't overlap with existing assignments.";

            return response()->json([
                'error' => 'Duplicate assignments found',
                'message' => $errorMessage,
                'duplicates' => $duplicateEmployees
            ], 422);
        }

        // If no duplicates, proceed with creating assignments
        $assignments = [];

        // Merge incoming weights with DB templates so all template weights are stored.
        // Incoming may be e.g. [{id, title, percentage}, {title, percentage}, ...]
        $incomingWeights = $validated['weights'] ?? [];
        // Load template weights
        $templateWeights = KpiWeight::all()->map(function ($w) {
            return [
                'id' => $w->id,
                'title' => $w->name,
                'description' => $w->description ?? '',
                'percentage' => 0
            ];
        })->toArray();

        // Build lookup of incoming weights by id and by normalized title
        $incomingById = [];
        $incomingByName = [];
        foreach ($incomingWeights as $w) {
            $idKey = isset($w['id']) ? (string) $w['id'] : null;
            $nameKey = isset($w['title']) ? strtolower(trim($w['title'])) : (isset($w['name']) ? strtolower(trim($w['name'])) : null);
            if ($idKey)
                $incomingById[$idKey] = $w;
            if ($nameKey)
                $incomingByName[$nameKey] = $w;
        }

        // Merge: prefer incoming percentage if present, else 0
        $mergedWeights = [];
        foreach ($templateWeights as $tpl) {
            $percent = 0;
            // match by id first
            if ($tpl['id'] && isset($incomingById[(string) $tpl['id']])) {
                $w = $incomingById[(string) $tpl['id']];
                $percent = isset($w['percentage']) ? (int) $w['percentage'] : (isset($w['percent']) ? (int) $w['percent'] : 0);
            } else {
                // try match by name/title
                $key = strtolower(trim($tpl['title'] ?? ''));
                if ($key && isset($incomingByName[$key])) {
                    $w = $incomingByName[$key];
                    $percent = isset($w['percentage']) ? (int) $w['percentage'] : (isset($w['percent']) ? (int) $w['percent'] : 0);
                }
            }
            $mergedWeights[] = [
                'id' => $tpl['id'],
                'title' => $tpl['title'],
                'description' => $tpl['description'],
                'percentage' => $percent
            ];
        }

        // Append any custom incoming weights not present in templates
        foreach ($incomingWeights as $w) {
            $matched = false;
            if (isset($w['id'])) {
                foreach ($mergedWeights as $m) {
                    if ($m['id'] && (string) $m['id'] === (string) $w['id']) {
                        $matched = true;
                        break;
                    }
                }
            } else {
                $nameKey = isset($w['title']) ? strtolower(trim($w['title'])) : (isset($w['name']) ? strtolower(trim($w['name'])) : null);
                foreach ($mergedWeights as $m) {
                    if ($nameKey && strtolower(trim($m['title'])) === $nameKey) {
                        $matched = true;
                        break;
                    }
                }
            }
            if (!$matched) {
                $mergedWeights[] = [
                    'id' => $w['id'] ?? null,
                    'title' => $w['title'] ?? ($w['name'] ?? 'Custom'),
                    'description' => $w['description'] ?? '',
                    'percentage' => isset($w['percentage']) ? (int) $w['percentage'] : 0
                ];
            }
        }

        foreach ($validAssignments as $employee) {
            // Determine department: prefer provided department_id, else derive from employee's organizationAssignment
            $departmentId = $validated['department_id'] ?? null;
            if (!$departmentId) {
                try {
                    $departmentId = $employee->organizationAssignment?->department_id ?? null;
                } catch (\Throwable $e) {
                    \Log::warning('Failed to get department from employee organizationAssignment', [
                        'employee_id' => $employee->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $assignment = KpiTaskAssignment::create([
                'kpi_task_id' => $kpiTask->id,
                'creator_role_id' => $creatorRole->id,
                'creator_id' => $creatorId,
                'weights' => $mergedWeights,
                'company_id' => $validated['company_id'],
                'department_id' => $departmentId,
                'employee_id' => $employee->id,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'status' => 'active',
                'priority' => $validated['priority'] ?? 'medium',
                'description' => $validated['description'],
                'completion_status' => 'not-started',
                'kpi_type' => $kpiType,
                // Remove this line: 'last_updated' => now(),
                // Let last_updated remain NULL for new tasks
            ]);

            $assignments[] = $assignment;
        }

        // Log successful creation
        \Log::info('KPI task assignments created successfully', [
            'task_name' => $validated['task_name'],
            'creator_id' => $creatorId,
            'assignments_count' => count($assignments),
            'date_range' => $validated['start_date'] . ' to ' . $validated['end_date']
        ]);

        return response()->json([
            'message' => 'KPI task assignments created successfully',
            'assignments' => $assignments,
            'total_created' => count($assignments)
        ], 201);
    }

    /**
     * Check if assigning KPI would exceed weight limits for assignees
     */
    public function checkAssigneeWeights(Request $request)
    {
        try {
            $validated = $request->validate([
                'assignees' => 'required|array',
                'assignees.*' => 'required|string',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'weights' => 'required|array',
                'kpi_task_id' => 'nullable|exists:kpi_tasks,id'
            ]);

            $month = Carbon::parse($validated['start_date'])->format('Y-m');
            $newWeightsTotal = collect($validated['weights'])->sum('percentage');
            $overLimitAssignees = [];

            foreach ($validated['assignees'] as $attendanceNo) {
                $employee = Employee::where('attendance_employee_no', $attendanceNo)->first();
                if (!$employee) continue;

                // Get existing regular KPI assignments for the month
                $existingAssignments = KpiTaskAssignment::where('employee_id', $employee->id)
                    ->where('kpi_type', 0) // Regular KPIs only
                    ->where(function ($query) use ($month) {
                        $startOfMonth = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
                        $endOfMonth = Carbon::createFromFormat('Y-m', $month)->endOfMonth();
                        
                        $query->where(function ($q) use ($startOfMonth, $endOfMonth) {
                            $q->where('start_date', '<=', $endOfMonth)
                            ->where('end_date', '>=', $startOfMonth);
                        });
                    })
                    ->when($validated['kpi_task_id'], function ($query, $taskId) {
                        // Exclude current task when editing
                        return $query->where('id', '!=', $taskId);
                    })
                    ->get();

                // Calculate total weights for existing assignments
                $currentTotal = $existingAssignments->reduce(function ($total, $assignment) {
                    return $total + collect($assignment->weights)->sum('percentage');
                }, 0);

                // Check if adding new weights would exceed 100%
                if (($currentTotal + $newWeightsTotal) > 100) {
                    $overLimitAssignees[] = [
                        'id' => $employee->id,
                        'name' => $employee->full_name,
                        'currentTotal' => $currentTotal,
                        'newTotal' => $currentTotal + $newWeightsTotal
                    ];
                }
            }

            return response()->json([
                'hasOverLimit' => count($overLimitAssignees) > 0,
                'overLimitAssignees' => $overLimitAssignees
            ]);

        } catch (\Exception $e) {
            \Log::error('Error checking assignee weights', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to check assignee weights'
            ], 500);
        }
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
                $query->where(function ($q) use ($currentUser) {
                    // Tasks created by this HR user only
                    $q->where('creator_id', $currentUser->id);

                    // OR tasks created by users with 'supervisor' role
                    $q->orWhereHas('creator', function ($subQ) {
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
                $query->where(function ($q) use ($currentUser) {
                    // Tasks created by users with HR role
                    $q->whereHas('creator', function ($subQ) {
                        $subQ->where('role', 'hr');
                    });

                    // OR tasks created by users with supervisor role
                    $q->orWhereHas('creator', function ($subQ) {
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
                'sample_creators' => $assignments->take(3)->map(function ($a) {
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
                // Clean weights: only include weights that exist in current template
                $cleanedWeights = [];
                if ($assignment->weights) {
                    $currentTemplateWeights = KpiWeight::all()->pluck('id')->toArray();

                    foreach ($assignment->weights as $weight) {
                        // Only include if weight ID exists in current templates OR has assigned percentage > 0
                        $weightId = $weight['id'] ?? null;
                        $hasPercentage = isset($weight['percentage']) && $weight['percentage'] > 0;

                        if ($weightId && (in_array($weightId, $currentTemplateWeights) || $hasPercentage)) {
                            $cleanedWeights[] = $weight;
                        }
                    }
                }

                return [
                    'id' => $assignment->id,
                    'name' => $assignment->kpiTask->task_name ?? 'Unknown Task',
                    'description' => $assignment->description,
                    'assignees' => $assignment->employee ? [$assignment->employee->attendance_employee_no] : [],
                    'assigneeNames' => $assignment->employee ? [$assignment->employee->full_name] : [],
                    'company' => $assignment->company_id,
                    'companyName' => $assignment->company->name ?? 'Unknown Company',
                    'department' => $assignment->department_id,
                    'departmentId' => $assignment->department_id,
                    'departmentName' => $assignment->department->name ?? 'No Department',
                    'startDate' => $assignment->start_date,
                    'endDate' => $assignment->end_date,
                    'status' => $assignment->status ?? 'active',
                    'priority' => $assignment->priority ?? 'medium',
                    'creator' => [
                        'role' => $assignment->creatorRole->role_name ?? 'Unknown Role'
                    ],
                    'kpi_type' => (bool) $assignment->kpi_type,
                    'creatorRole' => $assignment->creatorRole->role_name ?? 'Unknown Role',
                    'weights' => $cleanedWeights, // Use cleaned weights
                    'lastUpdated' => $assignment->last_updated ? $assignment->last_updated->toISOString() : $assignment->updated_at->toISOString(),
                    'assigneeUpdates' => [],
                    'completion_status' => $assignment->completion_status ?? 'not-started',
                    'approval_status' => $assignment->approval_status ?? 'pending',
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
                'approval_status',
                'priority',
                'weights',
                'kpi_type',
                'completion_status',
                'created_at',
                'updated_at',
                'last_updated' // Make sure this is included
            ])
            ->orderBy('created_at', 'desc')
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
                'assigneeUpdates' => [],
                'startDate' => $assignment->start_date->toDateString(),
                'endDate' => $assignment->end_date->toDateString(),
                'status' => $assignment->status ?? 'active',
                'approval_status' => $assignment->approval_status ?? 'pending',
                'priority' => $assignment->priority ?? 'medium',
                'creator' => [
                    'role' => $assignment->creatorRole->role_name ?? 'Unknown Role',
                    'date' => $assignment->created_at->toISOString()
                ],
                'kpi_type' => (bool) $assignment->kpi_type,
                'weights' => $assignment->weights ?? [],
                'lastUpdated' => $assignment->created_at->toISOString() ?? null,
                'last_updated' => $assignment->last_updated ? $assignment->last_updated->toISOString() : null,
                'updated_at' => $assignment->updated_at->toISOString(),
                'created_at' => $assignment->created_at->toISOString(), // Make sure this is included
                'frequency' => 'Monthly',
                'category' => 'General'
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
            'task_name' => 'nullable|string',
            'description' => 'nullable|string',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'creator_role_name' => 'nullable|string',
            'assignees' => 'nullable|array',
            'assignees.*' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'weights' => 'nullable|array',
            'priority' => 'nullable|string|in:low,medium,high',
            'kpi_type' => 'nullable|boolean',
        ]);

        $assignment = KpiTaskAssignment::findOrFail($id);
        
        // Record previous status for logging
        $previousStatus = $assignment->approval_status;

        // Reset approval status to pending when task is updated
        if ($assignment->approval_status !== 'pending') {
            $assignment->approval_status = 'pending';
            
            // Log the change
            \Log::info('KPI task approval status reset due to update', [
                'task_id' => $id,
                'previous_status' => $previousStatus,
                'updated_by' => auth()->id()
            ]);
        }

        if (isset($validated['kpi_type'])) {
            $kpiType = 0;
            if ($validated['kpi_type'] === 'performance_appraisal' || $validated['kpi_type'] === '1' || $validated['kpi_type'] === 1 || $validated['kpi_type'] === true) {
                $kpiType = 1;
            }
            $assignment->kpi_type = $kpiType;
        }

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
        if (isset($validated['description']))
            $assignment->description = $validated['description'];
        if (isset($validated['company_id']))
            $assignment->company_id = $validated['company_id'];
        if (isset($validated['department_id']))
            $assignment->department_id = $validated['department_id'];
        if (isset($validated['start_date']))
            $assignment->start_date = $validated['start_date'];
        if (isset($validated['end_date']))
            $assignment->end_date = $validated['end_date'];
        if (isset($validated['weights']))
            $assignment->weights = $validated['weights'];
        if (isset($validated['priority']))
            $assignment->priority = $validated['priority'];

        // Handle assignees update (this might require creating new assignments or updating existing)
        // For simplicity, assume updating the employee_id if assignees array has one item
        if (isset($validated['assignees']) && count($validated['assignees']) === 1) {
            $employee = employee::where('attendance_employee_no', $validated['assignees'][0])->first();
            if (!$employee) {
                return response()->json(['error' => 'Employee not found'], 400);
            }
            $assignment->employee_id = $employee->id;
        }

        // If weights are being updated, merge with template weights
        if (isset($validated['weights'])) {
            // Get incoming weights from request
            $incomingWeights = $validated['weights'];

            // Load current template weights (excludes deleted weights)
            $templateWeights = KpiWeight::all()->map(function ($w) {
                return [
                    'id' => $w->id,
                    'title' => $w->name,
                    'description' => $w->description ?? '',
                    'percentage' => 0
                ];
            })->toArray();

            // Build lookup maps
            $incomingById = [];
            $incomingByName = [];
            foreach ($incomingWeights as $w) {
                $idKey = isset($w['id']) ? (string) $w['id'] : null;
                $nameKey = isset($w['title']) ? strtolower(trim($w['title'])) : (isset($w['name']) ? strtolower(trim($w['name'])) : null);
                if ($idKey)
                    $incomingById[$idKey] = $w;
                if ($nameKey)
                    $incomingByName[$nameKey] = $w;
            }

            // Merge: only include weights that exist in current templates
            $mergedWeights = [];
            foreach ($templateWeights as $tpl) {
                $percent = 0;
                // match by id first
                if ($tpl['id'] && isset($incomingById[(string) $tpl['id']])) {
                    $w = $incomingById[(string) $tpl['id']];
                    $percent = isset($w['percentage']) ? (int) $w['percentage'] : (isset($w['percent']) ? (int) $w['percent'] : 0);
                } else {
                    // try match by name/title
                    $key = strtolower(trim($tpl['title'] ?? ''));
                    if ($key && isset($incomingByName[$key])) {
                        $w = $incomingByName[$key];
                        $percent = isset($w['percentage']) ? (int) $w['percentage'] : (isset($w['percent']) ? (int) $w['percent'] : 0);
                    }
                }
                $mergedWeights[] = [
                    'id' => $tpl['id'],
                    'title' => $tpl['title'],
                    'description' => $tpl['description'],
                    'percentage' => $percent
                ];
            }

            // Note: We're NOT appending custom incoming weights that aren't in templates
            // This ensures deleted weights are completely removed

            // Update with merged weights (only current template weights)
            $assignment->weights = $mergedWeights;
        }

        // Always update last_updated timestamp
        $assignment->last_updated = now();
        $assignment->save();

        // Make sure to return the updated assignment with the last_updated field
        return response()->json([
            'message' => 'KPI task assignment updated successfully',
            'assignment' => $assignment->fresh() // This ensures we get the latest data
        ]);
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
            ])->whereNull('deleted_at')
            ->select([
            'id',
            'kpi_task_id',
            'creator_role_id',
            'company_id',
            'department_id',
            'employee_id',
            'description',
            'start_date',
            'end_date',
            'status',
            'approval_status',
            'priority',
            'completion_status',
            'weights',
            'kpi_type',  // Add this field to the select
            'created_at',
            'updated_at'
        ]);

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

            $payload = $assignments->map(function ($a) {
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
                    'kpi_type' => (bool) $a->kpi_type,
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
            \Log::error('getEmployeeKpiTaskAssignments error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
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
            'rating' => 'nullable|integer|min:1|max:5',
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
            'rating.integer' => 'Rating must be an integer',
            'rating.min' => 'Rating must be at least 1',
            'rating.max' => 'Rating cannot be more than 5',
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
                'rating' => isset($validated['rating']) ? (int) $validated['rating'] : null,
                'performance_metrics' => $performanceMetrics, // Use parsed array
                'document_name' => $validated['document_name'] ?? null,
                'document_size' => $validated['document_size'] ?? null,
                'document_type' => $validated['document_type'] ?? null,
                'document_path' => $documentPath,
            ];

            \Log::info('Creating task progress submission', ['data' => $submissionData]);

            $submission = TaskProgressSubmission::create($submissionData);

            // Update the KPI assignment's completion status based on progress
            $assignment = KpiTaskAssignment::with('kpiTask:id,task_name')->find($validated['kpi_assignment_id']);
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

                // Send notification to the creator of the task
                if ($assignment->creator_id) {
                    $taskName = $assignment->kpiTask ? $assignment->kpiTask->task_name : 'KPI Task';
                    Notification::create([
                        'user_id' => $assignment->creator_id,
                        'type' => 'task_progress',
                        'title' => 'Task Progress Submitted',
                        'message' => "Progress has been submitted for your KPI task: {$taskName} (Assignment ID: {$assignment->id})",
                        'data' => [
                            'assignment_id' => $assignment->id,
                            'submission_id' => $submission->id,
                            'progress_percentage' => $progressPercentage
                        ]
                    ]);
                }
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
            $perPage = (int) $request->query('per_page', 8);
            if ($perPage <= 0) {
                $perPage = 8;
            }

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
                'progressSubmissions' => function ($query) {
                    $query->orderBy('created_at', 'desc')->limit(1);
                },
                'performanceReviews' => function ($query) {
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
                $query->where(function ($q) use ($currentUser) {
                    // Reviews for tasks this HR user created
                    $q->where('creator_id', $currentUser->id);

                    // OR reviews for tasks created by supervisors
                    $q->orWhereHas('creator', function ($subQ) {
                        $subQ->where('role', 'supervisor');
                    });

                    // If HR is also an employee, see their own reviews
                    if ($currentUser->employee_id) {
                        $q->orWhere('employee_id', $currentUser->employee_id);
                    }
                });
            } else if ($isSupervisor) {
                // Supervisors see only reviews for tasks they created
                $query->where(function ($q) use ($currentUser, $currentEmployee) {
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
                    'progress' => $existingReview ? (int) $existingReview->progress : 0,
                    'grade' => $existingReview?->grade,
                    'supervisorComments' => $existingReview?->supervisor_comments,
                    'performanceMetrics' => $existingReview?->performance_metrics, // Include supervisor's metrics

                    'selfRating' => $latestSubmission ? $latestSubmission->rating : null,
                    'isPerformanceAppraisal' => (bool) $assignment->kpi_type,

                    'appraisal_rating' => $existingReview ? $existingReview->appraisal_rating : null,
                    // 'appraisalRating' => $existingReview ? $existingReview->appraisal_rating : null, // Alternative naming
                    // 'appraisal' => $existingReview ? $existingReview->appraisal_rating : null,

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
                    'taskType' => $assignment->kpi_type ? 'Performance Appraisal' : 'Regular Task',
                    'kpiType' => $assignment->kpi_type ?? false,
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
                'employee.contactDetail:employee_id,email',
                'company:id,name',
                'department:id,name',
                'creatorRole:id,role_name',
                'progressSubmissions' => function ($query) {
                    $query->orderBy('created_at', 'desc');
                },
                'progressSubmissions.employee:id,full_name,attendance_employee_no'
            ])->findOrFail($assignmentId);

            if (!$isAdmin) {
                // Check if user has access to this assignment
                $hasAccess = false;

                if ($currentEmployee) {
                    // Employee can see their own assignments
                    if ($assignment->employee_id === $currentEmployee->id) {
                        $hasAccess = true;
                    }

                    // HR/Manager/Supervisor can see assignments in their scope
                    $userRole = strtolower($currentUser->role ?? '');
                    if (in_array($userRole, ['hr', 'manager', 'supervisor'])) {
                        $hasAccess = true;
                    }
                }

                if (!$hasAccess) {
                    return response()->json(['error' => 'Access denied'], 403);
                }
            }

            $existingReview = PerformanceReview::where('kpi_assignment_id', $assignmentId)->first();

            $submissions = $assignment->progressSubmissions->map(function ($submission) {
                return [
                    'id' => $submission->id,
                    'note' => $submission->note,
                    'progressPercentage' => $submission->progress_percentage,
                    'performanceMetrics' => $submission->performance_metrics,
                    'documentName' => $submission->document_name,
                    'documentSize' => $submission->document_size,
                    'documentType' => $submission->document_type,
                    'documentPath' => $submission->document_path,
                    'author' => $submission->employee->full_name ?? 'Employee',
                    'date' => $submission->created_at->toISOString(),
                    'rating' => $submission->rating,
                ];
            });

            $latestSubmission = $assignment->progressSubmissions->first();
            $selfRating = $latestSubmission ? $latestSubmission->rating : null;
            // Process supervisor review performance metrics
            $supervisorTaskProgress = 0;
            if ($existingReview && $existingReview->performance_metrics) {
                $metrics = is_string($existingReview->performance_metrics)
                    ? json_decode($existingReview->performance_metrics, true)
                    : $existingReview->performance_metrics;

                if (is_array($metrics) && !empty($metrics)) {
                    $taskName = $assignment->kpiTask->task_name ?? null;
                    $supervisorTaskProgress = $taskName && isset($metrics[$taskName]) ? $metrics[$taskName] : 0;
                }
            }

            // Get employee's user account email
            $employeeUser = User::where('employee_id', $assignment->employee_id)->first();

            return response()->json([
                'id' => $assignment->id,
                'taskName' => $assignment->kpiTask->task_name ?? null,
                'employee' => $assignment->employee,
                'employeeUser' => $employeeUser ? ['email' => $employeeUser->email] : null,
                'company' => $assignment->company,
                'department' => $assignment->department,
                'creatorRole' => $assignment->creatorRole,
                'description' => $assignment->description,
                'startDate' => $assignment->start_date,
                'endDate' => $assignment->end_date,
                'status' => $assignment->status,
                'priority' => $assignment->priority,
                'weights' => $assignment->weights,
                'submissions' => $submissions,
                'selfRating' => $latestSubmission ? $latestSubmission->rating : null,
                'isPerformanceAppraisal' => (bool) $assignment->kpi_type,
                'latestSubmission' => $latestSubmission ? [
                    'note' => $latestSubmission->note,
                    'progressPercentage' => $latestSubmission->progress_percentage,
                    'author' => $latestSubmission->employee->full_name ?? 'Employee',
                    'date' => $latestSubmission->created_at->toISOString(),
                ] : null,
                'performanceReview' => $existingReview ? [
                    'id' => $existingReview->id,
                    'progress' => $existingReview->progress ?? $supervisorTaskProgress,
                    'grade' => $existingReview->grade,
                    'appraisal_rating' => $existingReview->appraisal_rating,
                    'supervisorComments' => $existingReview->supervisor_comments,
                    'status' => $existingReview->status,
                    'performanceMetrics' => $existingReview->performance_metrics,
                    'createdAt' => $existingReview->created_at->toISOString(),
                ] : null
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error fetching performance review details', [
                'assignment_id' => $assignmentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
                ->map(function ($submission) {
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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
            // Base validation rules
            $validationRules = [
                'progress' => 'required|integer|min:0|max:100',
                'grade' => 'nullable|string|max:5',
                'supervisor_comments' => 'nullable|string',
                'status' => 'required|string|in:Draft,In Progress,Pending Manager,Pending Employee,Completed',
                'performance_metrics' => 'required|array',
            ];

            // Get the assignment with task information
            $assignment = KpiTaskAssignment::with([
                'kpiTask:id,task_name',
                'employee',
                'progressSubmissions' => function ($query) {
                    $query->orderBy('created_at', 'desc');
                }
            ])->findOrFail($assignmentId);

            // Determine whether this is an appraisal task
            $isAppraisalTask = (bool) ($assignment->kpi_type ?? false);

            // Add appraisal-specific validation when applicable
            if ($isAppraisalTask) {
                $validationRules['appraisal_rating'] = 'required|integer|min:1|max:5';
            }

            // Validate request
            $validated = $request->validate($validationRules);

            // Get task name for performance metrics
            $taskName = $assignment->kpiTask->task_name ?? 'Unknown Task';

            // Clean and ensure all metrics are integers
            $cleanMetrics = [];
            foreach ($validated['performance_metrics'] as $key => $value) {
                $cleanMetrics[$key] = (int) $value;
            }

            // Add the specific task progress to performance metrics
            $cleanMetrics[$taskName] = (int) $validated['progress'];

            // Get the latest submission for self-reported data
            $latestSubmission = $assignment->progressSubmissions->first();

            // Get supervisor ID from current auth user
            $supervisorId = auth()->id();

            // Prepare data for update/create
            $reviewData = [
                'employee_id' => $assignment->employee->id ?? null,
                'supervisor_id' => $supervisorId,
                'progress' => (int) $validated['progress'],
                'grade' => $validated['grade'] ?? null,
                'supervisor_comments' => $validated['supervisor_comments'] ?? null,
                'status' => $validated['status'],
                'performance_metrics' => $cleanMetrics,
                'review_type' => 'performance',
                'review_cycle' => $this->deriveCycle($assignment->start_date),
                'start_date' => $assignment->start_date,
                'due_date' => $assignment->end_date,
                'completed_date' => $validated['status'] === 'Completed' ? now() : null,
                // Self-reported snapshot
                'self_reported_progress' => $latestSubmission ? $latestSubmission->progress_percentage : 0,
                'self_reported_last_updated' => $latestSubmission ? $latestSubmission->created_at : null,
            ];

            // Include appraisal rating when relevant
            if ($isAppraisalTask && isset($validated['appraisal_rating'])) {
                $reviewData['appraisal_rating'] = (int) $validated['appraisal_rating'];
            }

            // Find or create performance review for this assignment
            $review = PerformanceReview::updateOrCreate(
                ['kpi_assignment_id' => $assignmentId],
                $reviewData
            );

            // Update the KPI assignment completion status based on review status
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

            // Notify employee's user account about the review update
            try {
                if ($assignment->employee && $assignment->employee->id) {
                    $employeeUser = \App\Models\User::where('employee_id', $assignment->employee->id)->first();
                    if ($employeeUser) {
                        \App\Models\Notification::create([
                            'user_id' => $employeeUser->id,
                            'type' => 'performance_review_updated',
                            'title' => 'Performance Review Updated',
                            'message' => "Your performance review for task '{$taskName}' has been updated (Status: {$validated['status']}).",
                            'data' => [
                                'assignment_id' => $assignment->id,
                                'review_id' => $review->id,
                                'status' => $validated['status'],
                                'progress' => $validated['progress']
                            ]
                        ]);
                    }
                }
            } catch (\Throwable $notifyEx) {
                \Log::warning('Failed to create performance review notification', [
                    'assignment_id' => $assignment->id ?? null,
                    'review_id' => $review->id ?? null,
                    'error' => $notifyEx->getMessage()
                ]);
            }

            // Log for debugging
            \Log::info('Performance review saved', [
                'assignment_id' => $assignmentId,
                'task_name' => $taskName,
                'progress' => $validated['progress'],
                'performance_metrics' => $cleanMetrics,
                'review_id' => $review->id,
                'is_appraisal_task' => $isAppraisalTask,
                'appraisal_rating' => $isAppraisalTask ? ($validated['appraisal_rating'] ?? null) : null,
            ]);

            return response()->json([
                'message' => 'Performance review updated successfully',
                'review' => $review->load(['employee', 'supervisor', 'kpiAssignment'])
            ]);

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

    // /**
    //  * Calculate employee performance based on completed tasks within a date range.
    //  */
    // public function calculateEmployeePerformance(Request $request)
    // {
    //     try {
    //         $validated = $request->validate([
    //             'start_date' => 'required|date',
    //             'end_date' => 'required|date|after_or_equal:start_date',
    //             'employee_id' => 'nullable|exists:employees,id',
    //         ]);

    //         $startDate = $validated['start_date'];
    //         $endDate = $validated['end_date'];
    //         $employeeId = $validated['employee_id'] ?? null;

    //         // Log the request parameters
    //         \Log::info('Performance calculation request', [
    //             'startDate' => $startDate,
    //             'endDate' => $endDate,
    //             'employeeId' => $employeeId
    //         ]);

    //         // Build the query to find completed tasks within date range
    //         // Fix: Use end_date from kpi_task_assignments table instead of due_date from performance_reviews
    //         $query = KpiTaskAssignment::with([
    //             'kpiTask:id,task_name',
    //             'employee:id,full_name,attendance_employee_no',
    //             'performanceReviews' => function ($q) {
    //                 $q->where('status', 'Completed');
    //             }
    //         ])
    //             ->where('end_date', '>=', $startDate)
    //             ->where('end_date', '<=', $endDate)
    //             ->whereHas('performanceReviews', function ($q) {
    //                 $q->where('status', 'Completed');
    //             });

    //         // Filter by employee if provided
    //         if ($employeeId) {
    //             $query->where('employee_id', $employeeId);
    //         }

    //         $assignments = $query->get();

    //         // If no tasks are found, return empty result
    //         if ($assignments->isEmpty()) {
    //             return response()->json([
    //                 'message' => 'No completed tasks found within the specified date range.',
    //                 'data' => null
    //             ]);
    //         }

    //         // Group by employee
    //         $employeeResults = [];

    //         foreach ($assignments as $assignment) {
    //             $employeeId = $assignment->employee_id;
    //             $employee = $assignment->employee;

    //             if (!isset($employeeResults[$employeeId])) {
    //                 $employeeResults[$employeeId] = [
    //                     'employee_id' => $employeeId,
    //                     'employee_name' => $employee->full_name,
    //                     'attendance_no' => $employee->attendance_employee_no,
    //                     'tasks' => [],
    //                     'total_score' => 0,
    //                     'task_count' => 0,
    //                 ];
    //             }

    //             // Get the latest completed performance review for this assignment
    //             $review = $assignment->performanceReviews()
    //                 ->where('status', 'Completed')
    //                 ->orderBy('updated_at', 'desc')
    //                 ->first();

    //             if (!$review) {
    //                 continue; // Skip if no completed review found
    //             }

    //             // Get the task weights from the KPI assignment
    //             $weights = $assignment->weights ?? [];
    //             $totalWeight = 0;

    //             if (!empty($weights)) {
    //                 // Sum up the weight percentages
    //                 foreach ($weights as $weight) {
    //                     $totalWeight += isset($weight['percentage']) ? (float) $weight['percentage'] : 0;
    //                 }
    //             }

    //             // Use supervisor progress from performance review
    //             $supervisorProgress = $review->progress;

    //             // Calculate task score: weight * progress / 100
    //             $taskScore = ($totalWeight * $supervisorProgress) / 100;

    //             // Add task details to the result
    //             $employeeResults[$employeeId]['tasks'][] = [
    //                 'task_id' => $assignment->id,
    //                 'task_name' => $assignment->kpiTask->task_name ?? 'Unknown Task',
    //                 'supervisor_progress' => $supervisorProgress,
    //                 'total_weight' => $totalWeight,
    //                 'task_score' => $taskScore,
    //                 'start_date' => $assignment->start_date,
    //                 'end_date' => $assignment->end_date,
    //             ];

    //             // Add to the employee's total score
    //             $employeeResults[$employeeId]['total_score'] += $taskScore;
    //             $employeeResults[$employeeId]['task_count']++;
    //         }

    //         // Calculate final percentages and grades for each employee
    //         foreach ($employeeResults as &$result) {
    //             if ($result['task_count'] > 0) {
    //                 // Calculate average task score
    //                 $average = $result['total_score'] / $result['task_count'];

    //                 // Calculate final percentage (Average / 60) * 100, capped at 100%
    //                 $finalPercentage = min(100, max(0, round(($average / 60) * 100)));

    //                 // Assign grade based on percentage
    //                 $grade = $this->getGrade($finalPercentage);

    //                 $result['percentage'] = $finalPercentage;
    //                 $result['grade'] = $grade['grade'];
    //                 $result['performance_label'] = $grade['label'];
    //             } else {
    //                 $result['percentage'] = 0;
    //                 $result['grade'] = 'N/A';
    //                 $result['performance_label'] = 'No Data';
    //             }
    //         }

    //         // If a specific employee was requested, return just that result, otherwise return all
    //         if (isset($validated['employee_id'])) {
    //             $employeeId = $validated['employee_id'];
    //             return response()->json([
    //                 'data' => $employeeResults[$employeeId] ?? null
    //             ]);
    //         }

    //         return response()->json([
    //             'data' => array_values($employeeResults)
    //         ]);

    //     } catch (\Exception $e) {
    //         \Log::error('Error calculating employee performance', [
    //             'error' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString()
    //         ]);

    //         return response()->json([
    //             'error' => 'Failed to calculate employee performance: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }

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
                'company_id' => 'nullable|exists:companies,id',
                'department_id' => 'nullable|exists:departments,id',
            ]);

            $startDate = $validated['start_date'];
            $endDate = $validated['end_date'];
            $employeeId = $validated['employee_id'] ?? null;
            $companyId = $validated['company_id'] ?? null;
            $departmentId = $validated['department_id'] ?? null;

            // Log the request parameters
            \Log::info('Performance calculation request', [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'employeeId' => $employeeId,
                'companyId' => $companyId,
                'departmentId' => $departmentId
            ]);

            // Build the query to find completed tasks within date range
            $query = KpiTaskAssignment::with([
                'kpiTask:id,task_name',
                'employee:id,full_name,attendance_employee_no',
                'performanceReviews' => function ($q) {
                    $q->where('status', 'Completed');
                }
            ])
                ->where('end_date', '>=', $startDate)
                ->where('end_date', '<=', $endDate)
                // FILTER: Only include regular KPI tasks (kpi_type = 0 or false)
                ->where(function ($q) {
                    $q->where('kpi_type', 0)
                    ->orWhere('kpi_type', false)
                    ->orWhereNull('kpi_type');
                })
                ->whereHas('performanceReviews', function ($q) {
                    $q->where('status', 'Completed');
                });

            // Filter by employee if provided
            if ($employeeId) {
                $query->where('employee_id', $employeeId);
            }
            // Filter by company if provided
            if ($companyId) {
                $query->where('company_id', $companyId);
            }
            // Filter by department if provided 
            if ($departmentId) {
                $query->where('department_id', $departmentId);
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
                        $totalWeight += isset($weight['percentage']) ? (float) $weight['percentage'] : 0;
                    }
                }

                // Use supervisor progress from performance review
                $supervisorProgress = $review->progress;

                // Calculate task score: (weight * progress) / 100
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
                    // Final percentage is simply the total score (already out of 100%), capped at 100%
                    $finalPercentage = min(100, max(0, round($result['total_score'])));

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
     */
    public function getKpiPerformance(Request $request)
    {
        try {
            // Get current authenticated user
            $currentUser = auth()->user();
            if (!$currentUser) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }

            $userRole = strtolower($currentUser->role ?? '');
            $isAdmin = $userRole === 'admin';
            $isHR = $userRole === 'hr';
            $isManager = $userRole === 'manager';

            $startDate = $request->query('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
            $endDate = $request->query('end_date') ?? Carbon::now()->endOfMonth()->toDateString();

            Log::info('KPI performance request', [
                'start' => $startDate,
                'end' => $endDate,
                'user_id' => $currentUser->id,
                'user_role' => $userRole
            ]);

            // Base query with date range filters
            $assignmentsQuery = KpiTaskAssignment::whereNull('deleted_at')
                ->where(function ($q) use ($startDate, $endDate) {
                    // Task overlaps with date range: assignment.start <= end AND assignment.end >= start
                    $q->where('start_date', '<=', $endDate)
                        ->where('end_date', '>=', $startDate);
                })
                ->with([
                    'progressSubmissions' => function ($q) use ($startDate, $endDate) {
                        // Load ALL submissions for this assignment (not just within date range)
                        // We need to check if ANY submission exists for need attention logic
                        $q->orderBy('created_at', 'desc');
                    },
                    'performanceReviews' => function ($q) {
                        $q->where('status', 'Completed')->orderBy('completed_date', 'desc');
                    },
                    'kpiTask:id,task_name',
                    'employee:id,full_name,attendance_employee_no'
                ]);

            // Apply role-based filtering - SAME AS getKpiTaskAssignments
            if ($isAdmin) {
                // Admin sees ALL tasks - no filtering needed
            } elseif ($isHR) {
                // HR can see:
                // 1. Tasks they created themselves (creator_id = current user)
                // 2. Tasks created by users with 'supervisor' role
                // BUT NOT tasks created by other HR users
                $assignmentsQuery->where(function ($q) use ($currentUser) {
                    // Tasks created by this HR user only
                    $q->where('creator_id', $currentUser->id);

                    // OR tasks created by users with 'supervisor' role
                    $q->orWhereHas('creator', function ($subQ) {
                        $subQ->where('role', 'supervisor');
                    });
                });

                // EXCLUDE tasks where this HR user is assigned as employee
                if ($currentUser->employee_id) {
                    $currentEmployee = employee::find($currentUser->employee_id);
                    if ($currentEmployee) {
                        $assignmentsQuery->where('employee_id', '!=', $currentEmployee->id);
                    }
                }

            } elseif ($userRole === 'supervisor') {
                // Supervisor can ONLY see tasks they created themselves
                // They cannot see tasks created by other supervisors
                $assignmentsQuery->where('creator_id', $currentUser->id);

                // EXCLUDE tasks where this supervisor is assigned as employee
                if ($currentUser->employee_id) {
                    $currentEmployee = employee::find($currentUser->employee_id);
                    if ($currentEmployee) {
                        $assignmentsQuery->where('employee_id', '!=', $currentEmployee->id);
                    }
                }

            } elseif ($isManager) {
                // Manager sees tasks created by HR + supervisor users + their own
                $assignmentsQuery->where(function ($q) use ($currentUser) {
                    // Tasks created by users with HR role
                    $q->whereHas('creator', function ($subQ) {
                        $subQ->where('role', 'hr');
                    });

                    // OR tasks created by users with supervisor role
                    $q->orWhereHas('creator', function ($subQ) {
                        $subQ->where('role', 'supervisor');
                    });

                    // OR tasks created by this manager
                    $q->orWhere('creator_id', $currentUser->id);
                });

                // EXCLUDE tasks where this manager is assigned as employee
                if ($currentUser->employee_id) {
                    $currentEmployee = employee::find($currentUser->employee_id);
                    if ($currentEmployee) {
                        $assignmentsQuery->where('employee_id', '!=', $currentEmployee->id);
                    }
                }

            } else {
                // Default for other roles (regular employees, users)
                // Only see tasks they created (if any)
                $assignmentsQuery->where('creator_id', $currentUser->id);

                // EXCLUDE tasks where they are assigned as employee
                if ($currentUser->employee_id) {
                    $currentEmployee = employee::find($currentUser->employee_id);
                    if ($currentEmployee) {
                        $assignmentsQuery->where('employee_id', '!=', $currentEmployee->id);
                    }
                }
            }

            $assignments = $assignmentsQuery->get();

            $needAttention = 0;
            $onTarget = 0;
            $inProgress = 0;

            foreach ($assignments as $assignment) {
                $taskEndDate = Carbon::parse($assignment->end_date);
                $now = Carbon::now();

                // Check if task has ANY submissions (not just within date range)
                $hasAnySubmissions = $assignment->progressSubmissions->count() > 0;

                // Check if any submission was made before task end date
                $hasSubmissionBeforeEnd = false;
                foreach ($assignment->progressSubmissions as $submission) {
                    if (Carbon::parse($submission->created_at)->lte($taskEndDate)) {
                        $hasSubmissionBeforeEnd = true;
                        break;
                    }
                }

                // Check completion status
                $isCompleted = strtolower($assignment->completion_status ?? '') === 'completed';

                // Check if task is "On Target" (has completed review within timeline)
                $completedReview = $assignment->performanceReviews->first();
                $isOnTarget = false;

                if ($completedReview && $completedReview->completed_date) {
                    $completedDate = Carbon::parse($completedReview->completed_date);
                    $assignmentEnd = Carbon::parse($assignment->end_date);
                    $rangeStart = Carbon::parse($startDate);
                    $rangeEnd = Carbon::parse($endDate);

                    // On target if: completed within date range AND before/on task end date
                    if (
                        $completedDate->between($rangeStart, $rangeEnd) &&
                        $completedDate->lte($assignmentEnd)
                    ) {
                        $onTarget++;
                        $isOnTarget = true;
                    }
                }

                // Alternative: if no completed review but has timely submission AND is completed, also count as on target
                if (!$isOnTarget && $hasSubmissionBeforeEnd && $isCompleted) {
                    $onTarget++;
                    $isOnTarget = true;
                }

                // Only categorize as "Need Attention" or "In Progress" if NOT already "On Target"
                if (!$isOnTarget) {
                    // Need Attention: No submissions at all (regardless of task status)
                    if (!$hasAnySubmissions) {
                        $needAttention++;
                    } else {
                        // Has submissions but not yet completed or not meeting timeline - count as in progress
                        $inProgress++;
                    }
                }
            }

            // Verify that our counts add up to the total
            $totalCounted = $needAttention + $onTarget + $inProgress;
            $totalTasks = $assignments->count();

            // Log for debugging



            Log::info('KPI stats calculation', [
                'user_role' => $userRole,
                'user_id' => $currentUser->id,
                'date_range' => [$startDate, $endDate],
                'needAttention' => $needAttention,
                'onTarget' => $onTarget,
                'inProgress' => $inProgress,
                'totalCounted' => $totalCounted,
                'totalTasks' => $totalTasks,
                'filtering_logic' => $isAdmin ? 'admin_all' : ($isHR ? 'hr_filter' : ($userRole === 'supervisor' ? 'supervisor_created_only' : 'default')),
                'sample_tasks' => $assignments->take(3)->map(function ($a) {
                    return [
                        'task_name' => $a->kpiTask?->task_name,
                        'assignee' => $a->employee?->full_name,
                        'submissions_count' => $a->progressSubmissions->count(),
                        'completion_status' => $a->completion_status,
                        'end_date' => $a->end_date,
                        'creator_id' => $a->creator_id
                    ];
                })
            ]);

            if ($totalCounted !== $totalTasks) {
                Log::warning('KPI stats count mismatch', [
                    'needAttention' => $needAttention,
                    'onTarget' => $onTarget,
                    'inProgress' => $inProgress,
                    'totalCounted' => $totalCounted,
                    'totalTasks' => $totalTasks
                ]);
            }

            return response()->json([
                'data' => [
                    'onTarget' => $onTarget,
                    'needAttention' => $needAttention,
                    'inProgress' => $inProgress,
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
        if ($percentage >= 81)
            return ['grade' => 'A+', 'label' => 'Excellent'];
        if ($percentage >= 61)
            return ['grade' => 'A', 'label' => 'Above Average'];
        if ($percentage >= 41)
            return ['grade' => 'B', 'label' => 'Average'];
        if ($percentage >= 21)
            return ['grade' => 'B-', 'label' => 'Below Average'];
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
                        $kpiQuery->where(function ($q) use ($currentEmployee) {
                            $q->whereHas('creatorRole', function ($subQ) {
                                $subQ->whereIn('role_name', ['HR', 'Human Resources', 'HR Manager', 'Supervisor', 'Operations', 'Management']);
                            })->orWhere('employee_id', $currentEmployee->id);
                        });
                        $reviewsQuery->where(function ($q) use ($currentEmployee) {
                            $q->whereHas('creatorRole', function ($subQ) {
                                $subQ->whereIn('role_name', ['HR', 'Human Resources', 'HR Manager', 'Supervisor', 'Operations', 'Management']);
                            })->orWhere('employee_id', $currentEmployee->id);
                        });
                    } elseif ($userRole === 'supervisor') {
                        // Supervisor sees own created + assigned tasks
                        $kpiQuery->where(function ($q) use ($currentEmployee) {
                            $q->where('employee_id', $currentEmployee->id)
                                ->orWhereHas('creatorRole', function ($subQ) {
                                    $subQ->whereIn('role_name', ['Supervisor', 'Operations', 'Management']);
                                });
                        });
                        $reviewsQuery->where(function ($q) use ($currentEmployee) {
                            $q->where('employee_id', $currentEmployee->id)
                                ->orWhereHas('creatorRole', function ($subQ) {
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
                        $query->where(function ($q) use ($currentEmployee) {
                            $q->whereHas('creatorRole', function ($subQ) {
                                $subQ->whereIn('role_name', ['HR', 'Human Resources', 'HR Manager', 'Supervisor', 'Operations', 'Management']);
                            })->orWhere('employee_id', $currentEmployee->id);
                        });
                    } elseif ($userRole === 'supervisor') {
                        $query->where(function ($q) use ($currentEmployee) {
                            $q->where('employee_id', $currentEmployee->id)
                                ->orWhereHas('creatorRole', function ($subQ) {
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
                ->map(function ($assignment) {
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
                'task_name' => 'required|string|max:255|unique:kpi_tasks,task_name,NULL,id,deleted_at,NULL',
                'description' => 'nullable|string|max:1000',
            ], [
                'task_name.required' => 'Task name is required',
                'task_name.unique' => 'A KPI task with this name already exists',
                'task_name.max' => 'Task name cannot exceed 255 characters',
                'description.max' => 'Description cannot exceed 1000 characters',
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
                'task_name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('kpi_tasks', 'task_name')->ignore($id)->whereNull('deleted_at')
                ],
                'description' => 'nullable|string|max:1000',
            ], [
                'task_name.required' => 'Task name is required',
                'task_name.unique' => 'A KPI task with this name already exists',
                'task_name.max' => 'Task name cannot exceed 255 characters',
                'description.max' => 'Description cannot exceed 1000 characters',
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
                'role_name' => [
                    'required',
                    'string',
                    'max:255',
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
                'role_name' => [
                    'required',
                    'string',
                    'max:255',
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
            $assignment = KpiTaskAssignment::with(['employee', 'kpiTask'])->findOrFail($id);
            $assignment->approval_status = 'approved';
            $assignment->save();

            // Create in-app notification for the employee
            if ($assignment->employee) {
                // Find the user associated with this employee
                $user = \App\Models\User::where('employee_id', $assignment->employee_id)->first();

                if ($user) {
                    \App\Models\Notification::create([
                        'user_id' => $user->id,
                        'type' => 'kpi_approved',
                        'title' => 'KPI Task Approved',
                        'message' => "Your KPI task '{$assignment->kpiTask->task_name}' has been approved. Check your my KPI tasks for details.",
                        'data' => [
                            'task_id' => $assignment->id,
                            'task_name' => $assignment->kpiTask->task_name,
                            'approved_at' => now()->toISOString()
                        ]
                    ]);
                }
            }

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

    /**
     * Submit practical feedback for a performance review
     */
    public function submitPracticalFeedback(Request $request, $assignmentId)
    {
        try {
            $validated = $request->validate([
                'feedback' => 'required|string|min:10|max:2000',
                'email' => 'required|email',
                'subject' => 'required|string|min:5|max:255',
                'taskName' => 'nullable|string'
            ]);

            // Get the assignment with employee details
            $assignment = KpiTaskAssignment::with([
                'employee.contactDetail:employee_id,email',
                'employee:id,full_name,attendance_employee_no',
                'kpiTask:id,task_name'
            ])->findOrFail($assignmentId);

            // Get sender (current user) email
            $currentUser = auth()->user();
            $senderEmail = $currentUser->email ?? 'system@company.com';

            // Get recipient email - prioritize employee's user account email, then contact detail
            $employee = $assignment->employee;
            $employeeUser = User::where('employee_id', $employee->id)->first();
            $recipientEmail = $employeeUser ? $employeeUser->email :
                ($employee->contactDetail ? $employee->contactDetail->email : $validated['email']);

            $employeeName = $employee->full_name ?? 'Employee';
            $taskName = $validated['taskName'] ?? $assignment->kpiTask->task_name ?? 'Task';

            // Create practical feedback record
            $feedback = PracticalFeedback::create([
                'employee_id' => $assignment->employee_id,
                'kpi_assignment_id' => $assignmentId,
                'task_name' => $taskName,
                'from_email' => $senderEmail,
                'to_email' => $recipientEmail,
                'subject' => $validated['subject'],
                'feedback_content' => $validated['feedback'],
                'created_by' => $currentUser->id
            ]);

            // Here you would implement actual email sending
            // Mail::to($recipientEmail)->send(new PracticalFeedbackMail($feedback));

            \Log::info('Practical feedback created successfully', [
                'feedback_id' => $feedback->id,
                'assignment_id' => $assignmentId,
                'from' => $senderEmail,
                'to' => $recipientEmail,
                'subject' => $validated['subject'],
                'employee' => $employeeName,
                'task' => $taskName
            ]);

            return response()->json([
                'message' => 'Practical feedback sent successfully',
                'data' => [
                    'feedback_id' => $feedback->id,
                    'sent_from' => $senderEmail,
                    'sent_to' => $recipientEmail,
                    'subject' => $validated['subject'],
                    'employee_name' => $employeeName,
                    'task_name' => $taskName,
                    'created_at' => $feedback->formatted_created_at
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error submitting practical feedback', [
                'assignment_id' => $assignmentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to submit practical feedback',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get practical feedback history
     */
    public function getPracticalFeedbackHistory(Request $request)
    {
        try {
            $query = PracticalFeedback::with(['employee:id,full_name', 'creator:id,name'])
                ->orderBy('created_at', 'desc');

            // Filter by employee if provided
            if ($request->has('employee_id')) {
                $query->forEmployee($request->employee_id);
            }

            // Filter by creator if provided
            if ($request->has('created_by')) {
                $query->byCreator($request->created_by);
            }

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('email_status', $request->status);
            }

            $feedbacks = $query->paginate(15);

            return response()->json($feedbacks);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch feedback history'], 500);
        }
    }

    /**
     * Get feedback statistics
     */
    public function getFeedbackStats()
    {
        try {
            $stats = [
                'total_sent' => PracticalFeedback::sent()->count(),
                'total_pending' => PracticalFeedback::pending()->count(),
                'total_failed' => PracticalFeedback::failed()->count(),
                'this_month' => PracticalFeedback::whereMonth('created_at', now()->month)->count(),
                'this_week' => PracticalFeedback::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count()
            ];

            return response()->json($stats);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch feedback statistics'], 500);
        }
    }

    /**
     * Get user's notifications
     */
    public function getUserNotifications(Request $request)
    {
        $user = auth()->user();
        $limit = $request->query('limit', 20);

        $notifications = \App\Models\Notification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($notifications);
    }

    /**
     * Mark notification as read
     */
    public function markNotificationRead($notificationId)
    {
        $notification = \App\Models\Notification::where('user_id', auth()->id())
            ->findOrFail($notificationId);

        $notification->update([
            'is_read' => true,
            'read_at' => now()
        ]);

        return response()->json(['message' => 'Notification marked as read']);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllNotificationsRead()
    {
        \App\Models\Notification::where('user_id', auth()->id())
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now()
            ]);

        return response()->json(['message' => 'All notifications marked as read']);
    }

    /**
     * Get unread notification count
     */
    public function getUnreadCount()
    {
        $count = \App\Models\Notification::where('user_id', auth()->id())
            ->where('is_read', false)
            ->count();

        return response()->json(['unread_count' => $count]);
    }
    public function getKpiWeights()
    {
        try {
            // Only fetch non-deleted weights
            $weights = KpiWeight::whereNull('deleted_at')
                ->select('id', 'name', 'description')
                ->orderBy('name')
                ->get();

            return response()->json($weights);
        } catch (\Exception $e) {
            \Log::error('Error fetching KPI weights', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to fetch KPI weights'
            ], 500);
        }
    }

    /**
     * Create a new KPI weight
     */
    public function createKpiWeight(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:kpi_weights,name,NULL,id,deleted_at,NULL',
                'description' => 'nullable|string|max:1000'
            ], [
                'name.required' => 'Weight name is required',
                'name.unique' => 'A weight with this name already exists',
                'name.max' => 'Weight name cannot exceed 255 characters',
                'description.max' => 'Description cannot exceed 1000 characters'
            ]);

            $weight = KpiWeight::create($validated);

            \Log::info('KPI weight created successfully', [
                'weight_id' => $weight->id,
                'name' => $weight->name
            ]);

            return response()->json($weight, 201);
        } catch (\Exception $e) {
            \Log::error('Error creating KPI weight', [
                'data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to create KPI weight'
            ], 500);
        }
    }

    /**
     * Update a KPI weight
     */
    public function updateKpiWeight(Request $request, $id)
    {
        try {
            $weight = KpiWeight::findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:kpi_weights,name,' . $id . ',id,deleted_at,NULL',
                'description' => 'nullable|string|max:1000'
            ], [
                'name.required' => 'Weight name is required',
                'name.unique' => 'A weight with this name already exists',
                'name.max' => 'Weight name cannot exceed 255 characters',
                'description.max' => 'Description cannot exceed 1000 characters'
            ]);

            $weight->update($validated);

            \Log::info('KPI weight updated successfully', [
                'weight_id' => $weight->id,
                'name' => $weight->name
            ]);

            return response()->json($weight);
        } catch (\Exception $e) {
            \Log::error('Error updating KPI weight', [
                'id' => $id,
                'data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to update KPI weight'
            ], 500);
        }
    }

    /**
     * Soft delete a KPI weight with optional force parameter
     */
    public function deleteKpiWeight(Request $request, $id)
    {
        try {
            $weight = KpiWeight::findOrFail($id);

            // Force soft delete without checking usage
            $weight->delete();

            \Log::info('KPI weight soft deleted successfully', [
                'weight_id' => $id,
                'name' => $weight->name
            ]);

            return response()->json(['message' => 'Weight deleted successfully']);
        } catch (\Exception $e) {
            \Log::error('Error deleting KPI weight', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to delete KPI weight: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate performance appraisal based on completed tasks within a date range.
     * Uses formula: Average = (Self Rating + Supervisor Rating) / 2, % = (Average / 60) * 100
     */
    // public function calculatePerformanceAppraisal(Request $request)
    // {
    //     try {
    //         $validated = $request->validate([
    //             'start_date' => 'required|date',
    //             'end_date' => 'required|date|after_or_equal:start_date',
    //             'employee_id' => 'nullable|exists:employees,id',
    //         ]);

    //         $startDate = $validated['start_date'];
    //         $endDate = $validated['end_date'];
    //         $employeeId = $validated['employee_id'] ?? null;

    //         // Log the request parameters
    //         \Log::info('Performance appraisal calculation request', [
    //             'startDate' => $startDate,
    //             'endDate' => $endDate,
    //             'employeeId' => $employeeId
    //         ]);

    //         // Build the query to find completed tasks within date range
    //         $query = KpiTaskAssignment::with([
    //             'kpiTask:id,task_name',
    //             'employee:id,full_name,attendance_employee_no',
    //             'performanceReviews' => function ($q) {
    //                 $q->where('status', 'Completed');
    //             },
    //             'progressSubmissions' => function ($q) {
    //                 $q->orderBy('created_at', 'desc');
    //             }
    //         ])
    //         ->where('end_date', '>=', $startDate)
    //         ->where('end_date', '<=', $endDate)
    //         ->whereHas('performanceReviews', function ($q) {
    //             $q->where('status', 'Completed');
    //         });

    //         // Filter by employee if provided
    //         if ($employeeId) {
    //             $query->where('employee_id', $employeeId);
    //         }

    //         $assignments = $query->get();

    //         // If no tasks are found, return empty result
    //         if ($assignments->isEmpty()) {
    //             return response()->json([
    //                 'message' => 'No completed tasks found within the specified date range.',
    //                 'data' => null
    //             ]);
    //         }

    //         // Group by employee
    //         $employeeResults = [];

    //         foreach ($assignments as $assignment) {
    //             $employeeId = $assignment->employee_id;
    //             $employee = $assignment->employee;

    //             if (!isset($employeeResults[$employeeId])) {
    //                 $employeeResults[$employeeId] = [
    //                     'employee_id' => $employeeId,
    //                     'employee_name' => $employee->full_name,
    //                     'attendance_no' => $employee->attendance_employee_no,
    //                     'tasks' => [],
    //                     'total_self_rating' => 0,
    //                     'total_supervisor_rating' => 0,
    //                     'task_count' => 0,
    //                 ];
    //             }

    //             // Get the latest completed performance review for this assignment
    //             $review = $assignment->performanceReviews()
    //                 ->where('status', 'Completed')
    //                 ->orderBy('updated_at', 'desc')
    //                 ->first();

    //             if (!$review) {
    //                 continue; // Skip if no completed review found
    //             }

    //             // Get latest submission from employee for self-rating
    //             $latestSubmission = $assignment->progressSubmissions()->first();
    //             $employeeSelfRating = $latestSubmission ? $latestSubmission->progress_percentage : 0;

    //             // Get supervisor rating from performance review
    //             $supervisorRating = $review->progress;

    //             // Add task details to the result
    //             $employeeResults[$employeeId]['tasks'][] = [
    //                 'task_id' => $assignment->id,
    //                 'task_name' => $assignment->kpiTask->task_name ?? 'Unknown Task',
    //                 'employee_self_rating' => $employeeSelfRating,
    //                 'supervisor_rating' => $supervisorRating,
    //                 'task_weight' => 0, // Not used in this calculation
    //                 'start_date' => $assignment->start_date,
    //                 'end_date' => $assignment->end_date,
    //             ];

    //             // Add to the employee's totals
    //             $employeeResults[$employeeId]['total_self_rating'] += $employeeSelfRating;
    //             $employeeResults[$employeeId]['total_supervisor_rating'] += $supervisorRating;
    //             $employeeResults[$employeeId]['task_count']++;
    //         }

    //         // Calculate final percentages and grades for each employee
    //         foreach ($employeeResults as &$result) {
    //             if ($result['task_count'] > 0) {
    //                 // Calculate average self and supervisor ratings
    //                 $avgSelfRating = $result['total_self_rating'] / $result['task_count'];
    //                 $avgSupervisorRating = $result['total_supervisor_rating'] / $result['task_count'];
                    
    //                 // Calculate combined average: (Self Rating + Supervisor Rating) / 2
    //                 $averageRating = ($avgSelfRating + $avgSupervisorRating) / 2;
                    
    //                 // Calculate final percentage: (Average / 60) * 100, capped at 100%
    //                 $finalPercentage = min(100, max(0, round(($averageRating / 60) * 100)));
                    
    //                 // Assign grade based on percentage using appraisal grading system
    //                 $grade = $this->getAppraisalGrade($finalPercentage);

    //                 $result['employee_self_rating'] = round($avgSelfRating);
    //                 $result['supervisor_rating'] = round($avgSupervisorRating);
    //                 $result['average_rating'] = round($averageRating, 2);
    //                 $result['percentage'] = $finalPercentage;
    //                 $result['grade'] = $grade['grade'];
    //                 $result['performance_label'] = $grade['label'];
    //                 $result['performance_description'] = $grade['description'] ?? null;
    //             } else {
    //                 $result['employee_self_rating'] = 0;
    //                 $result['supervisor_rating'] = 0;
    //                 $result['average_rating'] = 0;
    //                 $result['percentage'] = 0;
    //                 $result['grade'] = 'N/A';
    //                 $result['performance_label'] = 'No Data';
    //                 $result['performance_description'] = 'No completed tasks found';
    //             }
    //         }

    //         // If a specific employee was requested, return just that result, otherwise return all
    //         if (isset($validated['employee_id'])) {
    //             $employeeId = $validated['employee_id'];
    //             return response()->json([
    //                 'data' => $employeeResults[$employeeId] ?? null
    //             ]);
    //         }

    //         return response()->json([
    //             'data' => array_values($employeeResults)
    //         ]);

    //     } catch (\Exception $e) {
    //         \Log::error('Error calculating performance appraisal', [
    //             'error' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString()
    //         ]);

    //         return response()->json([
    //             'error' => 'Failed to calculate performance appraisal: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }
    /**
     * Calculate performance appraisal based on completed tasks within a date range.
     * Uses formula: Average = (Self Rating + Supervisor Rating) / 2, % = (Average / 60) * 100
     */
    public function calculatePerformanceAppraisal(Request $request)
    {
        try {
            $validated = $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'employee_id' => 'nullable|exists:employees,id',
                'company_id' => 'nullable|exists:companies,id',
                'department_id' => 'nullable|exists:departments,id',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100'
            ]);

            $startDate = $validated['start_date'];
            $endDate = $validated['end_date'];
            $employeeId = $validated['employee_id'] ?? null;
            $companyId = $validated['company_id'] ?? null;
            $departmentId = $validated['department_id'] ?? null;
            $page = $validated['page'] ?? 1;
            $perPage = $validated['per_page'] ?? 10;

            // Log the request parameters
            \Log::info('Performance appraisal calculation request', [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'employeeId' => $employeeId,
                'companyId' => $companyId,
                'departmentId' => $departmentId,
                'page' => $page,
                'per_page' => $perPage
            ]);

            // Build the query to find completed tasks within date range
            $query = KpiTaskAssignment::with([
                'kpiTask:id,task_name',
                'employee:id,full_name,attendance_employee_no',
                'performanceReviews' => function ($q) {
                    $q->where('status', 'Completed')
                    ->orderBy('updated_at', 'desc');
                },
                'progressSubmissions' => function ($q) {
                    $q->orderBy('created_at', 'desc');
                }
            ])
            ->where('end_date', '>=', $startDate)
            ->where('end_date', '<=', $endDate)
            // FILTER: Only include performance appraisal tasks (kpi_type = 1 or true)
            ->where(function ($q) {
                $q->where('kpi_type', 1)
                ->orWhere('kpi_type', true);
            })
            ->whereHas('performanceReviews', function ($q) {
                $q->where('status', 'Completed');
            });

            // Filter by employee if provided
            if ($employeeId) {
                $query->where('employee_id', $employeeId);
            }

            // Filter by company if provided    
            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            // Filter by department if provided
            if ($departmentId) {
                $query->where('department_id', $departmentId);
            }

            // Get total count for pagination
            $totalCount = $query->count();

            // Apply pagination if not requesting a specific employee
            if (!$employeeId && $totalCount > $perPage) {
                $assignments = $query->skip(($page - 1) * $perPage)
                                    ->take($perPage)
                                    ->get();
            } else {
                $assignments = $query->get();
            }

            // If no tasks are found, return empty result
            if ($assignments->isEmpty()) {
                return response()->json([
                    'message' => 'No completed performance appraisal tasks found within the specified date range.',
                    'data' => [],
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 1
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
                        'total_self_rating' => 0,
                        'total_supervisor_rating' => 0,
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

                // UPDATED: Get the LATEST task progress submission rating (1-5) from employee for self-rating
                $latestSubmission = $assignment->progressSubmissions()
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                // Use the rating column (1-5) instead of progress_percentage
                $employeeSelfRating = $latestSubmission && $latestSubmission->rating ? (int) $latestSubmission->rating : 0;

                // UPDATED: Get supervisor appraisal rating (1-5) from performance review instead of progress percentage
                $supervisorRating = $review->appraisal_rating ? (int) $review->appraisal_rating : 0;

                // Skip this task if either rating is missing (0)
                if ($employeeSelfRating === 0 || $supervisorRating === 0) {
                    \Log::warning('Skipping task due to missing ratings', [
                        'assignment_id' => $assignment->id,
                        'employee_self_rating' => $employeeSelfRating,
                        'supervisor_rating' => $supervisorRating
                    ]);
                    continue;
                }

                // Add task details to the result
                $employeeResults[$employeeId]['tasks'][] = [
                    'task_id' => $assignment->id,
                    'task_name' => $assignment->kpiTask->task_name ?? 'Unknown Task',
                    'employee_self_rating' => $employeeSelfRating,
                    'supervisor_rating' => $supervisorRating,
                    'task_weight' => 0, // Not used in this calculation
                    'start_date' => $assignment->start_date,
                    'end_date' => $assignment->end_date,
                    'submission_date' => $latestSubmission ? $latestSubmission->created_at->toISOString() : null,
                    'review_date' => $review->updated_at->toISOString(),
                ];

                // UPDATED: Add to the employee's TOTAL ratings (sum of 1-5 ratings)
                $employeeResults[$employeeId]['total_self_rating'] += $employeeSelfRating;
                $employeeResults[$employeeId]['total_supervisor_rating'] += $supervisorRating;
                $employeeResults[$employeeId]['task_count']++;
            }

            // Calculate final percentages and grades for each employee
            foreach ($employeeResults as &$result) {
                if ($result['task_count'] > 0) {
                    // UPDATED CALCULATION: Sum all ratings, then find combined average
                    $totalSelfRating = $result['total_self_rating'];
                    $totalSupervisorRating = $result['total_supervisor_rating'];
                    
                    // Calculate combined average: (Total Self Rating + Total Supervisor Rating) / 2
                    $averageRating = ($totalSelfRating + $totalSupervisorRating) / 2;
                    
                    // Calculate dividend based on task count (5 points per appraisal task)
                    $dividend = $result['task_count'] * 5;
                    
                    // Calculate final percentage: (Average / dividend) * 100, capped at 100%
                    $finalPercentage = min(100, max(0, round(($averageRating / $dividend) * 100)));
                    
                    // Assign grade based on percentage using appraisal grading system
                    $grade = $this->getAppraisalGrade($finalPercentage);

                    // UPDATED: Store the totals and averages properly (now using ratings 1-5)
                    $result['total_self_rating_sum'] = $totalSelfRating; // Sum of all self ratings (1-5)
                    $result['total_supervisor_rating_sum'] = $totalSupervisorRating; // Sum of all supervisor ratings (1-5)
                    $result['employee_self_rating'] = round($totalSelfRating, 1); // Display as sum of ratings
                    $result['supervisor_rating'] = round($totalSupervisorRating, 1); // Display as sum of ratings
                    $result['average_rating'] = round($averageRating, 2);
                    $result['dividend'] = $dividend; // Add dividend to response for transparency
                    $result['percentage'] = $finalPercentage;
                    $result['grade'] = $grade['grade'];
                    $result['performance_label'] = $grade['label'];
                    $result['performance_description'] = $grade['description'] ?? null;
                } else {
                    $result['total_self_rating_sum'] = 0;
                    $result['total_supervisor_rating_sum'] = 0;
                    $result['employee_self_rating'] = 0;
                    $result['supervisor_rating'] = 0;
                    $result['average_rating'] = 0;
                    $result['dividend'] = 0;
                    $result['percentage'] = 0;
                    $result['grade'] = 'N/A';
                    $result['performance_label'] = 'No Data';
                    $result['performance_description'] = 'No completed tasks found';
                }
            }

            // Prepare response
            $responseData = array_values($employeeResults);

            // If a specific employee was requested, return just that result
            if (isset($validated['employee_id'])) {
                $employeeId = $validated['employee_id'];
                return response()->json([
                    'data' => $employeeResults[$employeeId] ?? null
                ]);
            }

            // Return paginated results
            $response = [
                'data' => $responseData,
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalCount
            ];

            // Add pagination metadata if we have multiple pages
            if ($totalCount > $perPage) {
                $response['last_page'] = ceil($totalCount / $perPage);
                $response['from'] = (($page - 1) * $perPage) + 1;
                $response['to'] = min($page * $perPage, $totalCount);
            } else {
                $response['last_page'] = 1;
                $response['from'] = $totalCount > 0 ? 1 : 0;
                $response['to'] = $totalCount;
            }

            return response()->json($response);

        } catch (\Exception $e) {
            \Log::error('Error calculating performance appraisal', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to calculate performance appraisal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save performance appraisal.
     */
    public function savePerformanceAppraisal(Request $request)
    {
        try {
            $validated = $request->validate([
                'employee_id' => 'required|integer|exists:employees,id',
                'appraiser_id' => 'nullable|integer|exists:users,id',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'employee_self_rating' => 'required|integer|min:0',
                'supervisor_rating' => 'required|integer|min:0',
                'average_rating' => 'required|numeric|min:0',
                'percentage' => 'required|integer|min:0|max:100',
                'grade' => 'required|string|max:5',
                'performance_label' => 'required|string|max:255',
                'calculation_details' => 'required|array',
                'task_count' => 'required|integer|min:0',
                'supervisor_comments' => 'nullable|string',
                'employee_comments' => 'nullable|string',
                'status' => 'nullable|string|in:Draft,Completed,Pending Review'
            ]);

            // Set default values
            $validated['appraiser_id'] = $validated['appraiser_id'] ?? auth()->id() ?? 1;
            $validated['status'] = $validated['status'] ?? 'Completed';

            // Check for existing appraisal with same employee and exact date range (including soft-deleted records)
            $existingAppraisal = PerformanceAppraisal::withTrashed()
                ->where('employee_id', $validated['employee_id'])
                ->where('start_date', $validated['start_date'])
                ->where('end_date', $validated['end_date'])
                ->first();

            if ($existingAppraisal) {
                // If the existing record is soft-deleted, we can restore and update it
                if ($existingAppraisal->trashed()) {
                    // Restore the soft-deleted record
                    $existingAppraisal->restore();
                    
                    // Update with new data
                    $existingAppraisal->update($validated);
                    $existingAppraisal->load(['employee:id,full_name,attendance_employee_no', 'appraiser:id,name']);
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Performance appraisal restored and updated successfully',
                        'data' => $existingAppraisal,
                        'action' => 'restored_and_updated'
                    ], 200);
                }

                // If not soft-deleted, check if the data is actually different (excluding timestamps and ID)
                $newDataHash = md5(serialize([
                    'employee_self_rating' => $validated['employee_self_rating'],
                    'supervisor_rating' => $validated['supervisor_rating'],
                    'average_rating' => (float) $validated['average_rating'],
                    'percentage' => $validated['percentage'],
                    'grade' => $validated['grade'],
                    'performance_label' => $validated['performance_label'],
                    'calculation_details' => $validated['calculation_details'],
                    'task_count' => $validated['task_count'],
                    'supervisor_comments' => $validated['supervisor_comments'] ?? null,
                    'employee_comments' => $validated['employee_comments'] ?? null,
                ]));

                $existingDataHash = md5(serialize([
                    'employee_self_rating' => $existingAppraisal->employee_self_rating,
                    'supervisor_rating' => $existingAppraisal->supervisor_rating,
                    'average_rating' => (float) $existingAppraisal->average_rating,
                    'percentage' => $existingAppraisal->percentage,
                    'grade' => $existingAppraisal->grade,
                    'performance_label' => $existingAppraisal->performance_label,
                    'calculation_details' => $existingAppraisal->calculation_details,
                    'task_count' => $existingAppraisal->task_count,
                    'supervisor_comments' => $existingAppraisal->supervisor_comments,
                    'employee_comments' => $existingAppraisal->employee_comments,
                ]));

                if ($newDataHash === $existingDataHash) {
                    // Data is exactly the same - return duplicate error
                    return response()->json([
                        'success' => false,
                        'message' => 'Performance appraisal with identical data already exists for this employee and date range',
                        'error_type' => 'duplicate_data',
                        'existing_id' => $existingAppraisal->id,
                        'existing_created_at' => $existingAppraisal->created_at->format('Y-m-d H:i:s')
                    ], 409);
                } else {
                    // Data is different - update the existing record
                    $existingAppraisal->update($validated);
                    $existingAppraisal->load(['employee:id,full_name,attendance_employee_no', 'appraiser:id,name']);
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Performance appraisal updated successfully (data was different)',
                        'data' => $existingAppraisal,
                        'action' => 'updated'
                    ], 200);
                }
            }

            // No existing record found - create new appraisal
            $appraisal = PerformanceAppraisal::create($validated);

            // Load relationships for the response
            $appraisal->load(['employee:id,full_name,attendance_employee_no', 'appraiser:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Performance appraisal saved successfully',
                'data' => $appraisal,
                'action' => 'created'
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error saving performance appraisal', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save performance appraisal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get performance appraisals.
     */
    public function getPerformanceAppraisals(Request $request)
    {
        try {
            $query = PerformanceAppraisal::with(['employee', 'appraiser'])
                ->orderBy('created_at', 'desc');

            // Filter by employee if provided
            if ($request->has('employee_id')) {
                $query->where('employee_id', $request->employee_id);
            }

            // Filter by date range if provided
            if ($request->has('start_date')) {
                $query->where('start_date', '>=', $request->start_date);
            }

            if ($request->has('end_date')) {
                $query->where('end_date', '<=', $request->end_date);
            }

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $appraisals = $query->paginate($request->get('per_page', 15));

            return response()->json($appraisals);

        } catch (\Exception $e) {
            \Log::error('Error fetching performance appraisals', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to fetch performance appraisals: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get appraisal grade based on percentage using the new grading system.
     */
    private function getAppraisalGrade($percentage)
    {
        if ($percentage >= 81)
            return [
                'grade' => 'A+', 
                'label' => 'Excellent',
                'description' => 'Consistently superior performance. Contributions regularly surpass position requirements and expectations. Has positive impact on organizational goals.'
            ];
        if ($percentage >= 61)
            return [
                'grade' => 'A', 
                'label' => 'Above Average',
                'description' => 'Performance is regularly above expectations for the position. Exceeds expectations in some areas.'
            ];
        if ($percentage >= 41)
            return [
                'grade' => 'B', 
                'label' => 'Average',
                'description' => 'Performance is acceptable on all counts with no exceptions.'
            ];
        if ($percentage >= 21)
            return [
                'grade' => 'B-', 
                'label' => 'Below Average',
                'description' => 'Meets some performance standards for the position. Some competency issues noted. Intervention recommended.'
            ];
        
        return [
            'grade' => 'C', 
            'label' => 'Poor Performance',
            'description' => 'Well below requirements for successful job performance. Significant improvements must occur immediately. Performance improvement plan mandatory.'
        ];
    }
}
