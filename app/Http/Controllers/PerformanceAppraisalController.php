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
                return [
                    'id' => $appraisal->id,
                    'employee_name' => $appraisal->employee->full_name ?? 'Unknown',
                    'employee_id' => $appraisal->employee_id,
                    'attendance_no' => $appraisal->employee->attendance_employee_no ?? '',
                    'appraiser_name' => $appraisal->appraiser->name ?? 'System',
                    'start_date' => $appraisal->start_date,
                    'end_date' => $appraisal->end_date,
                    'employee_self_rating' => $appraisal->employee_self_rating,
                    'supervisor_rating' => $appraisal->supervisor_rating,
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
                'appraiser_id' => 'nullable|exists:users,id',
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

            $data = $validator->validated();
            $data['appraiser_id'] = $data['appraiser_id'] ?? auth()->id() ?? 1;

            $appraisal = PerformanceAppraisal::create($data);

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
     * Store multiple performance appraisals in bulk
     */
    public function storeBulk(Request $request)
    {
        try {
            Log::info('Bulk save performance appraisals request received', [
                'data_count' => is_array($request->get('appraisals')) ? count($request->get('appraisals')) : 0,
                'user_id' => auth()->id()
            ]);

            $validator = Validator::make($request->all(), [
                'appraisals' => 'required|array|min:1',
                'appraisals.*.employee_id' => 'required|integer|exists:employees,id',
                'appraisals.*.appraiser_id' => 'nullable|integer|exists:users,id',
                'appraisals.*.start_date' => 'required|date',
                'appraisals.*.end_date' => 'required|date|after_or_equal:appraisals.*.start_date',
                'appraisals.*.employee_self_rating' => 'required|integer|min:0',
                'appraisals.*.supervisor_rating' => 'required|integer|min:0',
                'appraisals.*.average_rating' => 'required|numeric|min:0',
                'appraisals.*.percentage' => 'required|integer|min:0|max:100',
                'appraisals.*.grade' => 'required|string|max:10',
                'appraisals.*.performance_label' => 'required|string|max:255',
                'appraisals.*.calculation_details' => 'required|array',
                'appraisals.*.task_count' => 'required|integer|min:0',
                'appraisals.*.supervisor_comments' => 'nullable|string',
                'appraisals.*.employee_comments' => 'nullable|string',
                'appraisals.*.status' => 'nullable|string|in:Draft,Completed,Pending Review'
            ]);

            if ($validator->fails()) {
                Log::warning('Bulk save validation failed', [
                    'errors' => $validator->errors()->toArray()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $savedAppraisals = [];
            $duplicates = [];
            $updated = [];
            $restored = [];
            $errors = [];
            
            DB::beginTransaction();
            
            try {
                foreach ($request->appraisals as $index => $appraisalData) {
                    // Set defaults
                    $appraisalData['appraiser_id'] = $appraisalData['appraiser_id'] ?? auth()->id() ?? 1;
                    $appraisalData['status'] = $appraisalData['status'] ?? 'Completed';

                    // Check for existing appraisal with same employee and date range (including soft-deleted)
                    $existing = PerformanceAppraisal::withTrashed()
                        ->where('employee_id', $appraisalData['employee_id'])
                        ->where('start_date', $appraisalData['start_date'])
                        ->where('end_date', $appraisalData['end_date'])
                        ->first();
                    
                    if ($existing) {
                        // If soft-deleted, restore and update
                        if ($existing->trashed()) {
                            $existing->restore();
                            $existing->update($appraisalData);
                            $existing->load(['employee:id,full_name,attendance_employee_no', 'appraiser:id,name']);
                            $restored[] = $existing;
                            
                            Log::info('Performance appraisal restored and updated in bulk', [
                                'appraisal_id' => $existing->id,
                                'employee_id' => $appraisalData['employee_id'],
                                'restored_by' => auth()->id() ?? 'system'
                            ]);
                            continue;
                        }

                        // Check if data is different
                        $newDataHash = md5(serialize([
                            'employee_self_rating' => $appraisalData['employee_self_rating'],
                            'supervisor_rating' => $appraisalData['supervisor_rating'],
                            'average_rating' => (float) $appraisalData['average_rating'],
                            'percentage' => $appraisalData['percentage'],
                            'grade' => $appraisalData['grade'],
                            'performance_label' => $appraisalData['performance_label'],
                            'calculation_details' => $appraisalData['calculation_details'],
                            'task_count' => $appraisalData['task_count'],
                            'supervisor_comments' => $appraisalData['supervisor_comments'] ?? null,
                            'employee_comments' => $appraisalData['employee_comments'] ?? null,
                        ]));

                        $existingDataHash = md5(serialize([
                            'employee_self_rating' => $existing->employee_self_rating,
                            'supervisor_rating' => $existing->supervisor_rating,
                            'average_rating' => (float) $existing->average_rating,
                            'percentage' => $existing->percentage,
                            'grade' => $existing->grade,
                            'performance_label' => $existing->performance_label,
                            'calculation_details' => $existing->calculation_details,
                            'task_count' => $existing->task_count,
                            'supervisor_comments' => $existing->supervisor_comments,
                            'employee_comments' => $existing->employee_comments,
                        ]));

                        if ($newDataHash === $existingDataHash) {
                            // Exact duplicate
                            $duplicates[] = [
                                'index' => $index,
                                'employee_id' => $appraisalData['employee_id'],
                                'message' => 'Performance appraisal with identical data already exists for this employee and date range',
                                'existing_id' => $existing->id,
                                'existing_created_at' => $existing->created_at->format('Y-m-d H:i:s')
                            ];
                            continue;
                        } else {
                            // Data is different - update existing
                            $existing->update($appraisalData);
                            $existing->load(['employee:id,full_name,attendance_employee_no', 'appraiser:id,name']);
                            $updated[] = $existing;
                            
                            Log::info('Performance appraisal updated in bulk (data was different)', [
                                'appraisal_id' => $existing->id,
                                'employee_id' => $appraisalData['employee_id'],
                                'updated_by' => auth()->id() ?? 'system'
                            ]);
                            continue;
                        }
                    }
                    
                    // No existing record - create new
                    try {
                        $appraisal = PerformanceAppraisal::create($appraisalData);
                        $appraisal->load(['employee:id,full_name,attendance_employee_no', 'appraiser:id,name']);
                        $savedAppraisals[] = $appraisal;

                        Log::info('Performance appraisal saved in bulk', [
                            'appraisal_id' => $appraisal->id,
                            'employee_id' => $appraisalData['employee_id'],
                            'saved_by' => auth()->id() ?? 'system'
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Error saving individual appraisal in bulk', [
                            'index' => $index,
                            'employee_id' => $appraisalData['employee_id'],
                            'error' => $e->getMessage()
                        ]);

                        $errors[] = [
                            'index' => $index,
                            'employee_id' => $appraisalData['employee_id'],
                            'message' => 'Failed to save: ' . $e->getMessage()
                        ];
                    }
                }
                
                DB::commit();
                
                $response = [
                    'success' => true,
                    'message' => $this->getBulkSaveMessage(
                        count($savedAppraisals), 
                        count($duplicates), 
                        count($errors),
                        count($updated),
                        count($restored)
                    ),
                    'data' => [
                        'saved' => $savedAppraisals,
                        'saved_count' => count($savedAppraisals),
                        'updated' => $updated,
                        'updated_count' => count($updated),
                        'restored' => $restored,
                        'restored_count' => count($restored),
                        'duplicate_count' => count($duplicates),
                        'error_count' => count($errors),
                        'duplicates' => $duplicates,
                        'errors' => $errors,
                        'total_processed' => count($request->appraisals)
                    ]
                ];
                
                // Return 207 Multi-Status if there were some issues, 201 if all processed successfully
                $statusCode = (count($duplicates) > 0 || count($errors) > 0) ? 207 : 201;
                
                Log::info('Bulk save performance appraisals completed', [
                    'total_requested' => count($request->appraisals),
                    'saved_count' => count($savedAppraisals),
                    'updated_count' => count($updated),
                    'restored_count' => count($restored),
                    'duplicate_count' => count($duplicates),
                    'error_count' => count($errors),
                    'status_code' => $statusCode
                ]);
                
                return response()->json($response, $statusCode);
                
            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }
            
        } catch (\Exception $e) {
            Log::error('Error saving bulk performance appraisals', [
                'data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save performance appraisals',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate appropriate message for bulk save operation
     */
    private function getBulkSaveMessage($savedCount, $duplicateCount, $errorCount, $updatedCount = 0, $restoredCount = 0)
    {
        $messages = [];
        
        if ($savedCount > 0) {
            $messages[] = "{$savedCount} new performance appraisal(s) created";
        }
        
        if ($updatedCount > 0) {
            $messages[] = "{$updatedCount} existing appraisal(s) updated";
        }
        
        if ($restoredCount > 0) {
            $messages[] = "{$restoredCount} deleted appraisal(s) restored and updated";
        }
        
        if ($duplicateCount > 0) {
            $messages[] = "{$duplicateCount} duplicate(s) skipped";
        }
        
        if ($errorCount > 0) {
            $messages[] = "{$errorCount} error(s) encountered";
        }
        
        return implode(', ', $messages);
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
            
            $data = [
                'id' => $appraisal->id,
                'employee_name' => $appraisal->employee->full_name ?? 'Unknown',
                'employee_id' => $appraisal->employee_id,
                'attendance_no' => $appraisal->employee->attendance_employee_no ?? '',
                'appraiser_name' => $appraisal->appraiser->name ?? 'System',
                'start_date' => $appraisal->start_date,
                'end_date' => $appraisal->end_date,
                'employee_self_rating' => $appraisal->employee_self_rating,
                'supervisor_rating' => $appraisal->supervisor_rating,
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
                return [
                    'id' => $appraisal->id,
                    'employee_name' => $appraisal->employee->full_name ?? 'Unknown',
                    'employee_id' => $appraisal->employee_id,
                    'attendance_no' => $appraisal->employee->attendance_employee_no ?? '',
                    'appraiser_name' => $appraisal->appraiser->name ?? 'System',
                    'start_date' => $appraisal->start_date,
                    'end_date' => $appraisal->end_date,
                    'employee_self_rating' => $appraisal->employee_self_rating,
                    'supervisor_rating' => $appraisal->supervisor_rating,
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
                'message' => 'Failed to fetch performance appraisal statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Additional methods like getTrashed, restore, forceDestroy can be added here if needed
}
