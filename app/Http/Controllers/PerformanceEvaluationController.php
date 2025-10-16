<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PerformanceEvaluation;
use App\Models\employee;
use App\Models\user;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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
}
