<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PerformanceEvaluation;
use App\Models\employee;
use App\Models\user;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class PerformanceEvaluationController extends Controller
{
    /**
     * Get all saved performance evaluations with relationships and pagination
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);
            $search = $request->get('search', '');
            
            $query = PerformanceEvaluation::with([
                'employee:id,full_name,attendance_employee_no', 
                'evaluator:id,name'
            ])->orderBy('created_at', 'desc');
            
            // Add search functionality
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->whereHas('employee', function($subQ) use ($search) {
                        $subQ->where('full_name', 'like', "%{$search}%")
                             ->orWhere('attendance_employee_no', 'like', "%{$search}%");
                    })
                    ->orWhereHas('evaluator', function($subQ) use ($search) {
                        $subQ->where('name', 'like', "%{$search}%");
                    })
                    ->orWhere('grade', 'like', "%{$search}%")
                    ->orWhere('performance_label', 'like', "%{$search}%");
                });
            }
            
            $evaluations = $query->paginate($perPage);
            
            // Transform the data to include formatted dates and names
            $evaluations->getCollection()->transform(function ($evaluation) {
                return [
                    'id' => $evaluation->id,
                    'employee_id' => $evaluation->employee_id,
                    'employee_name' => $evaluation->employee->full_name ?? 'Unknown Employee',
                    'employee_attendance_no' => $evaluation->employee->attendance_employee_no ?? 'N/A',
                    'evaluator_id' => $evaluation->evaluator_id,
                    'evaluator_name' => $evaluation->evaluator->name ?? 'Unknown Evaluator',
                    'start_date' => $evaluation->start_date->format('Y-m-d'),
                    'end_date' => $evaluation->end_date->format('Y-m-d'),
                    'percentage' => $evaluation->percentage,
                    'grade' => $evaluation->grade,
                    'performance_label' => $evaluation->performance_label,
                    'calculation_details' => $evaluation->calculation_details,
                    'task_count' => $evaluation->task_count,
                    'created_at' => $evaluation->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $evaluation->updated_at->format('Y-m-d H:i:s'),
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $evaluations->items(),
                'meta' => [
                    'current_page' => $evaluations->currentPage(),
                    'last_page' => $evaluations->lastPage(),
                    'per_page' => $evaluations->perPage(),
                    'total' => $evaluations->total(),
                    'from' => $evaluations->firstItem(),
                    'to' => $evaluations->lastItem(),
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching performance evaluations', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch performance evaluations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created performance evaluation
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'employee_id' => 'required|exists:employees,id',
                'evaluator_id' => 'required|exists:users,id',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
                'percentage' => 'required|integer|min:0|max:100',
                'grade' => 'required|string|max:10',
                'performance_label' => 'required|string|max:255',
                'calculation_details' => 'required|array',
                'task_count' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $evaluation = PerformanceEvaluation::create($validator->validated());

            // Load relationships for response
            $evaluation->load(['employee:id,full_name,attendance_employee_no', 'evaluator:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Performance evaluation saved successfully',
                'data' => $evaluation
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error saving performance evaluation', [
                'data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save performance evaluation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified performance evaluation
     */
    public function show($id)
    {
        try {
            $evaluation = PerformanceEvaluation::with([
                'employee:id,full_name,attendance_employee_no', 
                'evaluator:id,name'
            ])->findOrFail($id);
            
            $data = [
                'id' => $evaluation->id,
                'employee_id' => $evaluation->employee_id,
                'employee_name' => $evaluation->employee->full_name ?? 'Unknown Employee',
                'employee_attendance_no' => $evaluation->employee->attendance_employee_no ?? 'N/A',
                'evaluator_id' => $evaluation->evaluator_id,
                'evaluator_name' => $evaluation->evaluator->name ?? 'Unknown Evaluator',
                'start_date' => $evaluation->start_date->format('Y-m-d'),
                'end_date' => $evaluation->end_date->format('Y-m-d'),
                'percentage' => $evaluation->percentage,
                'grade' => $evaluation->grade,
                'performance_label' => $evaluation->performance_label,
                'calculation_details' => $evaluation->calculation_details,
                'task_count' => $evaluation->task_count,
                'created_at' => $evaluation->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $evaluation->updated_at->format('Y-m-d H:i:s'),
            ];
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Performance evaluation not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching performance evaluation', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch performance evaluation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified performance evaluation
     */
    public function update(Request $request, $id)
    {
        try {
            $evaluation = PerformanceEvaluation::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'employee_id' => 'sometimes|exists:employees,id',
                'evaluator_id' => 'sometimes|exists:users,id',
                'start_date' => 'sometimes|date',
                'end_date' => 'sometimes|date|after:start_date',
                'percentage' => 'sometimes|integer|min:0|max:100',
                'grade' => 'sometimes|string|max:10',
                'performance_label' => 'sometimes|string|max:255',
                'calculation_details' => 'sometimes|array',
                'task_count' => 'sometimes|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $evaluation->update($validator->validated());

            // Load relationships for response
            $evaluation->load(['employee:id,full_name,attendance_employee_no', 'evaluator:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Performance evaluation updated successfully',
                'data' => $evaluation
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Performance evaluation not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating performance evaluation', [
                'id' => $id,
                'data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update performance evaluation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Soft delete the specified performance evaluation
     */
    public function destroy($id)
    {
        try {
            $evaluation = PerformanceEvaluation::findOrFail($id);
            
            // Soft delete the evaluation
            $evaluation->delete();
            
            Log::info('Performance evaluation soft deleted', [
                'id' => $id,
                'employee_id' => $evaluation->employee_id,
                'deleted_by' => auth()->id() ?? 'system'
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Performance evaluation deleted successfully'
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Performance evaluation not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting performance evaluation', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete performance evaluation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get performance evaluations for a specific employee
     */
    public function getByEmployee($employeeId)
    {
        try {
            $evaluations = PerformanceEvaluation::with([
                'employee:id,full_name,attendance_employee_no', 
                'evaluator:id,name'
            ])
            ->where('employee_id', $employeeId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($evaluation) {
                return [
                    'id' => $evaluation->id,
                    'employee_id' => $evaluation->employee_id,
                    'employee_name' => $evaluation->employee->full_name ?? 'Unknown Employee',
                    'employee_attendance_no' => $evaluation->employee->attendance_employee_no ?? 'N/A',
                    'evaluator_id' => $evaluation->evaluator_id,
                    'evaluator_name' => $evaluation->evaluator->name ?? 'Unknown Evaluator',
                    'start_date' => $evaluation->start_date->format('Y-m-d'),
                    'end_date' => $evaluation->end_date->format('Y-m-d'),
                    'percentage' => $evaluation->percentage,
                    'grade' => $evaluation->grade,
                    'performance_label' => $evaluation->performance_label,
                    'calculation_details' => $evaluation->calculation_details,
                    'task_count' => $evaluation->task_count,
                    'created_at' => $evaluation->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $evaluation->updated_at->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $evaluations
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching employee performance evaluations', [
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch employee performance evaluations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get performance evaluation statistics
     */
    public function getStats()
    {
        try {
            $stats = [
                'total_evaluations' => PerformanceEvaluation::count(),
                'deleted_evaluations' => PerformanceEvaluation::onlyTrashed()->count(),
                'evaluations_this_month' => PerformanceEvaluation::whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count(),
                'grade_distribution' => PerformanceEvaluation::selectRaw('grade, COUNT(*) as count')
                    ->groupBy('grade')
                    ->orderBy('grade')
                    ->get()
                    ->pluck('count', 'grade'),
                'average_percentage' => PerformanceEvaluation::avg('percentage'),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching performance evaluation statistics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get soft deleted performance evaluations (for restoration purposes)
     */
    public function getTrashed(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);
            $search = $request->get('search', '');
            
            $query = PerformanceEvaluation::onlyTrashed()->with([
                'employee:id,full_name,attendance_employee_no', 
                'evaluator:id,name'
            ])->orderBy('deleted_at', 'desc');
            
            // Add search functionality
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->whereHas('employee', function($subQ) use ($search) {
                        $subQ->where('full_name', 'like', "%{$search}%")
                             ->orWhere('attendance_employee_no', 'like', "%{$search}%");
                    })
                    ->orWhereHas('evaluator', function($subQ) use ($search) {
                        $subQ->where('name', 'like', "%{$search}%");
                    })
                    ->orWhere('grade', 'like', "%{$search}%")
                    ->orWhere('performance_label', 'like', "%{$search}%");
                });
            }
            
            $evaluations = $query->paginate($perPage);
            
            return response()->json([
                'success' => true,
                'data' => $evaluations->items(),
                'meta' => [
                    'current_page' => $evaluations->currentPage(),
                    'last_page' => $evaluations->lastPage(),
                    'per_page' => $evaluations->perPage(),
                    'total' => $evaluations->total(),
                    'from' => $evaluations->firstItem(),
                    'to' => $evaluations->lastItem(),
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching trashed performance evaluations', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch deleted performance evaluations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft deleted performance evaluation
     */
    public function restore($id)
    {
        try {
            $evaluation = PerformanceEvaluation::withTrashed()->findOrFail($id);
            
            if (!$evaluation->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Performance evaluation is not deleted'
                ], 400);
            }
            
            $evaluation->restore();
            
            Log::info('Performance evaluation restored', [
                'id' => $id,
                'employee_id' => $evaluation->employee_id,
                'restored_by' => auth()->id() ?? 'system'
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Performance evaluation restored successfully'
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Performance evaluation not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error restoring performance evaluation', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore performance evaluation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Force delete the specified performance evaluation (permanent deletion)
     */
    public function forceDestroy($id)
    {
        try {
            $evaluation = PerformanceEvaluation::withTrashed()->findOrFail($id);
            
            Log::info('Performance evaluation force deleted', [
                'id' => $id,
                'employee_id' => $evaluation->employee_id,
                'deleted_by' => auth()->id() ?? 'system'
            ]);
            
            $evaluation->forceDelete();
            
            return response()->json([
                'success' => true,
                'message' => 'Performance evaluation permanently deleted'
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Performance evaluation not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error force deleting performance evaluation', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to permanently delete performance evaluation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store multiple performance evaluations in bulk
     */
    public function storeBulk(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'evaluations' => 'required|array|min:1',
                'evaluations.*.employee_id' => 'required|exists:employees,id',
                'evaluations.*.evaluator_id' => 'required|exists:users,id',
                'evaluations.*.start_date' => 'required|date',
                'evaluations.*.end_date' => 'required|date|after:start_date',
                'evaluations.*.percentage' => 'required|integer|min:0|max:100',
                'evaluations.*.grade' => 'required|string|max:10',
                'evaluations.*.performance_label' => 'required|string|max:255',
                'evaluations.*.calculation_details' => 'required|array',
                'evaluations.*.task_count' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $evaluationsData = $validator->validated()['evaluations'];
            $savedEvaluations = [];
            $duplicates = [];
            $errors = [];

            DB::beginTransaction();

            foreach ($evaluationsData as $index => $evaluationData) {
                try {
                    // Check for existing evaluation for same employee and date range
                    $existingEvaluation = PerformanceEvaluation::where('employee_id', $evaluationData['employee_id'])
                        ->where('start_date', $evaluationData['start_date'])
                        ->where('end_date', $evaluationData['end_date'])
                        ->whereNull('deleted_at') // Only check non-deleted records
                        ->first();

                    if ($existingEvaluation) {
                        // Get employee name for better duplicate reporting
                        $employee = employee::find($evaluationData['employee_id']);
                        $employeeName = $employee ? $employee->full_name : 'Unknown Employee';
                        
                        $duplicates[] = [
                            'index' => $index,
                            'employee_id' => $evaluationData['employee_id'],
                            'employee_name' => $employeeName,
                            'start_date' => $evaluationData['start_date'],
                            'end_date' => $evaluationData['end_date'],
                            'existing_id' => $existingEvaluation->id,
                            'existing_created_at' => $existingEvaluation->created_at->format('Y-m-d H:i:s'),
                            'message' => 'Evaluation already exists for this employee and date range'
                        ];
                        continue; // Skip this duplicate entry
                    }

                    // Additional check for overlapping date ranges for the same employee
                    $overlappingEvaluation = PerformanceEvaluation::where('employee_id', $evaluationData['employee_id'])
                        ->where(function($query) use ($evaluationData) {
                            $query->where(function($q) use ($evaluationData) {
                                // New start date falls within existing range
                                $q->where('start_date', '<=', $evaluationData['start_date'])
                                  ->where('end_date', '>=', $evaluationData['start_date']);
                            })->orWhere(function($q) use ($evaluationData) {
                                // New end date falls within existing range
                                $q->where('start_date', '<=', $evaluationData['end_date'])
                                  ->where('end_date', '>=', $evaluationData['end_date']);
                            })->orWhere(function($q) use ($evaluationData) {
                                // Existing range falls within new range
                                $q->where('start_date', '>=', $evaluationData['start_date'])
                                  ->where('end_date', '<=', $evaluationData['end_date']);
                            });
                        })
                        ->whereNull('deleted_at')
                        ->first();

                    if ($overlappingEvaluation) {
                        // Get employee name for better duplicate reporting
                        $employee = employee::find($evaluationData['employee_id']);
                        $employeeName = $employee ? $employee->full_name : 'Unknown Employee';
                        
                        $duplicates[] = [
                            'index' => $index,
                            'employee_id' => $evaluationData['employee_id'],
                            'employee_name' => $employeeName,
                            'start_date' => $evaluationData['start_date'],
                            'end_date' => $evaluationData['end_date'],
                            'existing_id' => $overlappingEvaluation->id,
                            'existing_start_date' => $overlappingEvaluation->start_date->format('Y-m-d'),
                            'existing_end_date' => $overlappingEvaluation->end_date->format('Y-m-d'),
                            'existing_created_at' => $overlappingEvaluation->created_at->format('Y-m-d H:i:s'),
                            'message' => 'Overlapping evaluation period exists for this employee'
                        ];
                        continue; // Skip this overlapping entry
                    }

                    // If no duplicates found, create the evaluation
                    $evaluation = PerformanceEvaluation::create($evaluationData);
                    $evaluation->load(['employee:id,full_name,attendance_employee_no', 'evaluator:id,name']);
                    $savedEvaluations[] = $evaluation;

                } catch (\Exception $e) {
                    Log::error('Error saving individual evaluation in bulk', [
                        'index' => $index,
                        'employee_id' => $evaluationData['employee_id'],
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    $errors[] = [
                        'index' => $index,
                        'employee_id' => $evaluationData['employee_id'],
                        'message' => 'Failed to save evaluation: ' . $e->getMessage()
                    ];
                }
            }

            DB::commit();

            Log::info('Bulk performance evaluations processed', [
                'total_requested' => count($evaluationsData),
                'saved_count' => count($savedEvaluations),
                'duplicates_count' => count($duplicates),
                'errors_count' => count($errors),
                'processed_by' => auth()->id() ?? 'system'
            ]);

            // Determine response status based on results
            $statusCode = 201; // Default success
            if (count($savedEvaluations) === 0) {
                $statusCode = 400; // Bad request if nothing was saved
            } else if (count($duplicates) > 0 || count($errors) > 0) {
                $statusCode = 207; // Multi-status for partial success
            }

            return response()->json([
                'success' => count($savedEvaluations) > 0,
                'message' => $this->getBulkSaveMessage(count($savedEvaluations), count($duplicates), count($errors)),
                'data' => [
                    'saved_evaluations' => $savedEvaluations,
                    'saved_count' => count($savedEvaluations),
                    'duplicates' => $duplicates,
                    'errors' => $errors,
                    'total_requested' => count($evaluationsData)
                ]
            ], $statusCode);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error in bulk save performance evaluations', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save evaluations in bulk',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate appropriate message based on bulk save results
     */
    private function getBulkSaveMessage($savedCount, $duplicateCount, $errorCount)
    {
        if ($savedCount === 0) {
            if ($duplicateCount > 0 && $errorCount === 0) {
                return 'No evaluations saved - all entries were duplicates';
            } elseif ($errorCount > 0 && $duplicateCount === 0) {
                return 'No evaluations saved - all entries had errors';
            } else {
                return 'No evaluations saved - duplicates and errors found';
            }
        } elseif ($duplicateCount === 0 && $errorCount === 0) {
            return "All {$savedCount} evaluation(s) saved successfully";
        } else {
            $message = "{$savedCount} evaluation(s) saved successfully";
            if ($duplicateCount > 0) {
                $message .= ", {$duplicateCount} duplicate(s) skipped";
            }
            if ($errorCount > 0) {
                $message .= ", {$errorCount} error(s) encountered";
            }
            return $message;
        }
    }
}
