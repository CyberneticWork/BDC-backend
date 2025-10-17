<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PerformanceAppraisal;
use App\Models\employee;
use App\Models\user;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class PerformanceAppraisalController extends Controller
{
    /**
     * Get all saved performance appraisals with relationships and pagination
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);
            $search = $request->get('search', '');
            
            $query = PerformanceAppraisal::with([
                'employee:id,full_name,attendance_employee_no', 
                'appraiser:id,name'
            ])->orderBy('created_at', 'desc');
            
            // Add search functionality
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->whereHas('employee', function($subQ) use ($search) {
                        $subQ->where('full_name', 'like', "%{$search}%")
                             ->orWhere('attendance_employee_no', 'like', "%{$search}%");
                    })
                    ->orWhereHas('appraiser', function($subQ) use ($search) {
                        $subQ->where('name', 'like', "%{$search}%");
                    })
                    ->orWhere('grade', 'like', "%{$search}%")
                    ->orWhere('performance_label', 'like', "%{$search}%");
                });
            }
            
            $appraisals = $query->paginate($perPage);
            
            // Transform the data to include formatted dates and names
            $appraisals->getCollection()->transform(function ($appraisal) {
                // Get actual ratings from related tables
                $actualSelfRating = $this->getActualSelfRating($appraisal);
                $actualSupervisorRating = $this->getActualSupervisorRating($appraisal);
                
                return [
                    'id' => $appraisal->id,
                    'employee_id' => $appraisal->employee_id,
                    'employee_name' => $appraisal->employee->full_name ?? 'Unknown Employee',
                    'employee_attendance_no' => $appraisal->employee->attendance_employee_no ?? 'N/A',
                    'appraiser_id' => $appraisal->appraiser_id,
                    'appraiser_name' => $appraisal->appraiser->name ?? 'Unknown Appraiser',
                    'start_date' => $appraisal->start_date->format('Y-m-d'),
                    'end_date' => $appraisal->end_date->format('Y-m-d'),
                    'employee_self_rating' => $actualSelfRating,
                    'supervisor_rating' => $actualSupervisorRating,
                    'average_rating' => $appraisal->average_rating,
                    'percentage' => $appraisal->percentage,
                    'grade' => $appraisal->grade,
                    'performance_label' => $appraisal->performance_label,
                    'calculation_details' => $appraisal->calculation_details,
                    'task_count' => $appraisal->task_count,
                    'supervisor_comments' => $appraisal->supervisor_comments,
                    'employee_comments' => $appraisal->employee_comments,
                    'status' => $appraisal->status,
                    'created_at' => $appraisal->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $appraisal->updated_at->format('Y-m-d H:i:s'),
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $appraisals->items(),
                'meta' => [
                    'current_page' => $appraisals->currentPage(),
                    'last_page' => $appraisals->lastPage(),
                    'per_page' => $appraisals->perPage(),
                    'total' => $appraisals->total(),
                    'from' => $appraisals->firstItem(),
                    'to' => $appraisals->lastItem(),
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching performance appraisals', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch performance appraisals',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created performance appraisal
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'employee_id' => 'required|exists:employees,id',
                'appraiser_id' => 'required|exists:users,id',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
                'employee_self_rating' => 'required|integer|min:0|max:100',
                'supervisor_rating' => 'required|integer|min:0|max:100',
                'average_rating' => 'required|numeric|min:0|max:100',
                'percentage' => 'required|integer|min:0|max:100',
                'grade' => 'required|string|max:10',
                'performance_label' => 'required|string|max:255',
                'calculation_details' => 'required|array',
                'task_count' => 'required|integer|min:0',
                'supervisor_comments' => 'nullable|string',
                'employee_comments' => 'nullable|string',
                'status' => 'nullable|string|in:Draft,Completed,Pending Review'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $appraisal = PerformanceAppraisal::create($validator->validated());

            // Load relationships for response
            $appraisal->load(['employee:id,full_name,attendance_employee_no', 'appraiser:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Performance appraisal saved successfully',
                'data' => $appraisal
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error saving performance appraisal', [
                'data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save performance appraisal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified performance appraisal
     */
    public function show($id)
    {
        try {
            $appraisal = PerformanceAppraisal::with([
                'employee:id,full_name,attendance_employee_no', 
                'appraiser:id,name'
            ])->findOrFail($id);
            
            // Get actual ratings from related tables
            $actualSelfRating = $this->getActualSelfRating($appraisal);
            $actualSupervisorRating = $this->getActualSupervisorRating($appraisal);
            
            $data = [
                'id' => $appraisal->id,
                'employee_id' => $appraisal->employee_id,
                'employee_name' => $appraisal->employee->full_name ?? 'Unknown Employee',
                'employee_attendance_no' => $appraisal->employee->attendance_employee_no ?? 'N/A',
                'appraiser_id' => $appraisal->appraiser_id,
                'appraiser_name' => $appraisal->appraiser->name ?? 'Unknown Appraiser',
                'start_date' => $appraisal->start_date->format('Y-m-d'),
                'end_date' => $appraisal->end_date->format('Y-m-d'),
                'employee_self_rating' => $actualSelfRating,
                'supervisor_rating' => $actualSupervisorRating,
                'average_rating' => $appraisal->average_rating,
                'percentage' => $appraisal->percentage,
                'grade' => $appraisal->grade,
                'performance_label' => $appraisal->performance_label,
                'calculation_details' => $appraisal->calculation_details,
                'task_count' => $appraisal->task_count,
                'supervisor_comments' => $appraisal->supervisor_comments,
                'employee_comments' => $appraisal->employee_comments,
                'status' => $appraisal->status,
                'created_at' => $appraisal->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $appraisal->updated_at->format('Y-m-d H:i:s'),
            ];
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Performance appraisal not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching performance appraisal', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch performance appraisal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Soft delete the specified performance appraisal
     */
    public function destroy($id)
    {
        try {
            $appraisal = PerformanceAppraisal::findOrFail($id);
            
            // Soft delete the appraisal
            $appraisal->delete();
            
            Log::info('Performance appraisal soft deleted', [
                'id' => $id,
                'employee_id' => $appraisal->employee_id,
                'deleted_by' => auth()->id() ?? 'system'
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Performance appraisal deleted successfully'
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Performance appraisal not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting performance appraisal', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete performance appraisal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get performance appraisals for a specific employee
     */
    public function getByEmployee($employeeId)
    {
        try {
            $appraisals = PerformanceAppraisal::with([
                'employee:id,full_name,attendance_employee_no', 
                'appraiser:id,name'
            ])
            ->where('employee_id', $employeeId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($appraisal) {
                $actualSelfRating = $this->getActualSelfRating($appraisal);
                $actualSupervisorRating = $this->getActualSupervisorRating($appraisal);
                
                return [
                    'id' => $appraisal->id,
                    'employee_id' => $appraisal->employee_id,
                    'employee_name' => $appraisal->employee->full_name ?? 'Unknown Employee',
                    'employee_attendance_no' => $appraisal->employee->attendance_employee_no ?? 'N/A',
                    'appraiser_id' => $appraisal->appraiser_id,
                    'appraiser_name' => $appraisal->appraiser->name ?? 'Unknown Appraiser',
                    'start_date' => $appraisal->start_date->format('Y-m-d'),
                    'end_date' => $appraisal->end_date->format('Y-m-d'),
                    'employee_self_rating' => $actualSelfRating,
                    'supervisor_rating' => $actualSupervisorRating,
                    'average_rating' => $appraisal->average_rating,
                    'percentage' => $appraisal->percentage,
                    'grade' => $appraisal->grade,
                    'performance_label' => $appraisal->performance_label,
                    'calculation_details' => $appraisal->calculation_details,
                    'task_count' => $appraisal->task_count,
                    'supervisor_comments' => $appraisal->supervisor_comments,
                    'employee_comments' => $appraisal->employee_comments,
                    'status' => $appraisal->status,
                    'created_at' => $appraisal->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $appraisal->updated_at->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $appraisals
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching employee performance appraisals', [
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch employee performance appraisals',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get performance appraisal statistics
     */
    public function getStats()
    {
        try {
            $stats = [
                'total_appraisals' => PerformanceAppraisal::count(),
                'deleted_appraisals' => PerformanceAppraisal::onlyTrashed()->count(),
                'appraisals_this_month' => PerformanceAppraisal::whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count(),
                'grade_distribution' => PerformanceAppraisal::selectRaw('grade, COUNT(*) as count')
                    ->groupBy('grade')
                    ->orderBy('grade')
                    ->get()
                    ->pluck('count', 'grade'),
                'average_percentage' => PerformanceAppraisal::avg('percentage'),
                'top_performers' => PerformanceAppraisal::with('employee:id,full_name')
                    ->where('percentage', '>=', 85)
                    ->orderBy('percentage', 'desc')
                    ->limit(5)
                    ->get()
                    ->map(function($appraisal) {
                        return [
                            'employee_name' => $appraisal->employee->full_name ?? 'Unknown',
                            'percentage' => $appraisal->percentage,
                            'grade' => $appraisal->grade,
                            'performance_label' => $appraisal->performance_label
                        ];
                    })
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching performance appraisal statistics', [
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
     * Get actual self rating from task_progress_submissions table
     */
    private function getActualSelfRating($appraisal)
    {
        try {
            // Get average progress from task_progress_submissions for the employee in the date range
            $avgProgress = DB::table('task_progress_submissions')
                ->join('kpi_task_assignments', 'task_progress_submissions.assignment_id', '=', 'kpi_task_assignments.id')
                ->where('kpi_task_assignments.employee_id', $appraisal->employee_id)
                ->whereBetween('task_progress_submissions.created_at', [$appraisal->start_date, $appraisal->end_date])
                ->avg('task_progress_submissions.progress_percentage');

            return $avgProgress ? round($avgProgress) : $appraisal->employee_self_rating;
        } catch (\Exception $e) {
            Log::warning('Could not fetch actual self rating', ['error' => $e->getMessage()]);
            return $appraisal->employee_self_rating;
        }
    }

    /**
     * Get actual supervisor rating from performance_reviews table
     */
    private function getActualSupervisorRating($appraisal)
    {
        try {
            // Get average appraisal_rating from performance_reviews for the employee in the date range
            $avgRating = DB::table('performance_reviews')
                ->where('employee_id', $appraisal->employee_id)
                ->whereBetween('created_at', [$appraisal->start_date, $appraisal->end_date])
                ->avg('progress'); // progress field stores supervisor's rating

            return $avgRating ? round($avgRating) : $appraisal->supervisor_rating;
        } catch (\Exception $e) {
            Log::warning('Could not fetch actual supervisor rating', ['error' => $e->getMessage()]);
            return $appraisal->supervisor_rating;
        }
    }

    /**
     * Get soft deleted performance appraisals (for restoration purposes)
     */
    public function getTrashed(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);
            $search = $request->get('search', '');
            
            $query = PerformanceAppraisal::onlyTrashed()->with([
                'employee:id,full_name,attendance_employee_no', 
                'appraiser:id,name'
            ])->orderBy('deleted_at', 'desc');
            
            // Add search functionality
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->whereHas('employee', function($subQ) use ($search) {
                        $subQ->where('full_name', 'like', "%{$search}%")
                             ->orWhere('attendance_employee_no', 'like', "%{$search}%");
                    })
                    ->orWhereHas('appraiser', function($subQ) use ($search) {
                        $subQ->where('name', 'like', "%{$search}%");
                    })
                    ->orWhere('grade', 'like', "%{$search}%")
                    ->orWhere('performance_label', 'like', "%{$search}%");
                });
            }
            
            $appraisals = $query->paginate($perPage);
            
            return response()->json([
                'success' => true,
                'data' => $appraisals->items(),
                'meta' => [
                    'current_page' => $appraisals->currentPage(),
                    'last_page' => $appraisals->lastPage(),
                    'per_page' => $appraisals->perPage(),
                    'total' => $appraisals->total(),
                    'from' => $appraisals->firstItem(),
                    'to' => $appraisals->lastItem(),
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching trashed performance appraisals', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch deleted performance appraisals',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft deleted performance appraisal
     */
    public function restore($id)
    {
        try {
            $appraisal = PerformanceAppraisal::withTrashed()->findOrFail($id);
            
            if (!$appraisal->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Performance appraisal is not deleted'
                ], 400);
            }
            
            $appraisal->restore();
            
            Log::info('Performance appraisal restored', [
                'id' => $id,
                'employee_id' => $appraisal->employee_id,
                'restored_by' => auth()->id() ?? 'system'
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Performance appraisal restored successfully'
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Performance appraisal not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error restoring performance appraisal', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore performance appraisal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Force delete the specified performance appraisal (permanent deletion)
     */
    public function forceDestroy($id)
    {
        try {
            $appraisal = PerformanceAppraisal::withTrashed()->findOrFail($id);
            
            Log::info('Performance appraisal force deleted', [
                'id' => $id,
                'employee_id' => $appraisal->employee_id,
                'deleted_by' => auth()->id() ?? 'system'
            ]);
            
            $appraisal->forceDelete();
            
            return response()->json([
                'success' => true,
                'message' => 'Performance appraisal permanently deleted'
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Performance appraisal not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error force deleting performance appraisal', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to permanently delete performance appraisal',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
