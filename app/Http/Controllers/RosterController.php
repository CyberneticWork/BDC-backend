<?php

namespace App\Http\Controllers;

use App\Models\Roster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class RosterController extends Controller
{
    public function index()
    {
        $rosters = Roster::with(['company', 'department', 'subDepartment', 'employee', 'shift'])
            ->orderBy('created_at', 'desc')
            ->get();
            
        $data = $rosters->map(function ($r) {
            return [
                'id' => $r->id,
                'roster_id' => $r->roster_id,
                'shift_id' => $r->shift_code, // Database එකේ save වෙන shift ID එක
                'shift_code' => $r->shift?->shift_code ?? '-', // ඇත්තම Shift Code එක (උදා: S01)
                'shift_name' => $r->shift?->shift_name ?? $r->shift?->shift_description ?? '-',
                'start_time' => $r->shift?->start_time ? substr($r->shift->start_time, 0, 5) : null,
                'end_time'   => $r->shift?->end_time ? substr($r->shift->end_time, 0, 5) : null,
                'company_id' => $r->company_id,
                'company_name' => $r->company?->name,
                'department_id' => $r->department_id,
                'department_name' => $r->department?->name,
                'sub_department_id' => $r->sub_department_id,
                'sub_department_name' => $r->subDepartment?->name,
                'employee_id' => $r->employee_id,
                'employee_code' => $r->employee?->attendance_employee_no ?? $r->employee?->employee_code ?? null, 
                'employee_name' => $r->employee?->full_name ?? $r->employee?->name_with_initials ?? $r->employee?->first_name ?? 'Unknown',
                'date_from' => $r->date_from,
                'date_to' => $r->date_to,
                'status' => $r->status ?? 'Active',
                'cancel_reason' => $r->cancel_reason,
                'cancelled_at' => $r->cancelled_at,
                'created_at' => $r->created_at,
            ];
        });

        return response()->json($data, 200);
    }

    public function show($id)
    {
        $roster = Roster::find($id);
        if (!$roster) {
            return response()->json(['message' => 'Roster not found'], 404);
        }
        return response()->json($roster, 200);
    }

    protected function isJsonArray(Request $request)
    {
        $content = $request->getContent();
        if (empty($content)) return false;
        $data = json_decode($content, true);
        return is_array($data) && array_keys($data) === range(0, count($data) - 1);
    }

    public function store(Request $request)
    {
        if ($this->isJsonArray($request)) {
            return $this->storeBulk($request);
        }

        $validator = Validator::make($request->all(), [
            'shift_code' => 'required|exists:shifts,id',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'sub_department_id' => 'nullable|exists:sub_departments,id',
            'employee_id' => 'required|exists:employees,id',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'overwrite' => 'nullable|boolean', 
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $overwrite = $request->input('overwrite', false);
        $employeeId = $data['employee_id'];

        $startDate = Carbon::parse($data['date_from']);
        $endDate = Carbon::parse($data['date_to']);
        $dates = [];
        for ($date = $startDate; $date->lte($endDate); $date->addDay()) {
            $dates[] = $date->format('Y-m-d');
        }

        $conflicts = Roster::with('shift')
            ->where('employee_id', $employeeId)
            ->whereIn('date_from', $dates)
            ->get();

        if ($conflicts->count() > 0 && !$overwrite) {
            $conflictDetails = $conflicts->map(function($c) {
                return [
                    'date' => $c->date_from,
                    'shift' => $c->shift->shift_name ?? $c->shift_code
                ];
            });

            return response()->json([
                'status' => 'conflict',
                'message' => 'Roster overlap detected.',
                'conflicts' => $conflictDetails
            ], 409);
        }

        DB::beginTransaction();
        try {
            if ($overwrite) {
                Roster::where('employee_id', $employeeId)->whereIn('date_from', $dates)->delete();
            }

            $rosterId = (int) Roster::max('roster_id') + 1;
            $newRosters = [];
            $now = now();

            foreach ($dates as $day) {
                $newRosters[] = [
                    'roster_id' => $rosterId,
                    'company_id' => $data['company_id'] ?? null,
                    'department_id' => $data['department_id'] ?? null,
                    'sub_department_id' => $data['sub_department_id'] ?? null,
                    'employee_id' => $employeeId,
                    'shift_code' => $data['shift_code'],
                    'date_from' => $day,
                    'date_to' => $day,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            Roster::insert($newRosters);
            DB::commit();

            return response()->json(['message' => 'Roster assigned successfully!', 'roster_id' => $rosterId], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create roster', 'message' => $e->getMessage()], 500);
        }
    }

    public function storeBulk(Request $request)
    {
        $entries = json_decode($request->getContent(), true);

        if (!is_array($entries)) {
            return response()->json(['error' => 'Invalid bulk data format.'], 400);
        }

        $overwrite = $request->query('overwrite', false) === 'true' || $request->input('overwrite', false);
        $rosterId = (int) Roster::max('roster_id') + 1;
        $allConflicts = [];
        $validatedEntries = [];

        foreach ($entries as $index => $entry) {
            $validator = Validator::make($entry, [
                'shift_code' => 'required|exists:shifts,id',
                'company_id' => 'nullable|exists:companies,id',
                'department_id' => 'nullable|exists:departments,id',
                'sub_department_id' => 'nullable|exists:sub_departments,id',
                'employee_id' => 'required|exists:employees,id',
                'date_from' => 'required|date',
                'date_to' => 'required|date|after_or_equal:date_from',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors(), 'index' => $index], 422);
            }

            $validated = $validator->validated();
            $employeeId = $validated['employee_id'];
            
            $startDate = Carbon::parse($validated['date_from']);
            $endDate = Carbon::parse($validated['date_to']);
            $dates = [];
            for ($date = $startDate; $date->lte($endDate); $date->addDay()) {
                $dates[] = $date->format('Y-m-d');
            }

            if (!$overwrite) {
                $conflicts = Roster::with('shift')->where('employee_id', $employeeId)->whereIn('date_from', $dates)->get();
                if ($conflicts->count() > 0) {
                    $emp = \App\Models\employee::find($employeeId);
                    $employeeName = $emp->full_name ?? $emp->name_with_initials ?? $emp->first_name ?? "Emp $employeeId";
                    foreach ($conflicts as $c) {
                        $allConflicts[] = [
                            'employee' => $employeeName,
                            'date' => $c->date_from,
                            'shift' => $c->shift->shift_name ?? $c->shift_code
                        ];
                    }
                }
            }

            $validatedEntries[] = [
                'employee_id' => $employeeId,
                'dates' => $dates,
                'data' => $validated
            ];
        }

        if (count($allConflicts) > 0 && !$overwrite) {
            return response()->json([
                'status' => 'conflict',
                'message' => 'Multiple roster overlaps detected.',
                'conflicts' => $allConflicts
            ], 409);
        }

        DB::beginTransaction();
        try {
            $now = now();
            $insertData = [];

            foreach ($validatedEntries as $item) {
                if ($overwrite) {
                    Roster::where('employee_id', $item['employee_id'])->whereIn('date_from', $item['dates'])->delete();
                }

                foreach ($item['dates'] as $day) {
                    $insertData[] = [
                        'roster_id' => $rosterId,
                        'company_id' => $item['data']['company_id'] ?? null,
                        'department_id' => $item['data']['department_id'] ?? null,
                        'sub_department_id' => $item['data']['sub_department_id'] ?? null,
                        'employee_id' => $item['employee_id'],
                        'shift_code' => $item['data']['shift_code'],
                        'date_from' => $day,
                        'date_to' => $day,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            Roster::insert($insertData);
            DB::commit();

            return response()->json(['message' => 'Bulk roster entries created successfully'], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create bulk roster', 'message' => $e->getMessage()], 500);
        }
    }

    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'sub_department_id' => 'nullable|exists:sub_departments,id',
            'employee_id' => 'nullable|string', 
            'roster_id' => 'nullable|string', 
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = Roster::with(['company', 'department', 'subDepartment', 'employee', 'shift'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->where(function ($q) use ($request) {
                $dateFrom = $request->date_from;
                $dateTo = $request->date_to ?? $request->date_from;

                if ($dateFrom && $dateTo) {
                    $q->whereBetween('date_from', [$dateFrom, $dateTo])
                        ->orWhereBetween('date_to', [$dateFrom, $dateTo])
                        ->orWhere(function ($query) use ($dateFrom, $dateTo) {
                            $query->where('date_from', '<=', $dateFrom)
                                ->where('date_to', '>=', $dateTo);
                        });
                } elseif ($dateFrom) {
                    $q->where('date_from', '>=', $dateFrom);
                }
            });
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('employee_id')) {
            $query->whereHas('employee', function($q) use ($request) {
                $q->where('id', $request->employee_id)
                  ->orWhere('attendance_employee_no', $request->employee_id);
            });
        }

        try {
            $rosters = $query->get()->map(function ($roster) {
                return [
                    'roster_details' => [
                        'id' => $roster->id,
                        'roster_id' => $roster->roster_id,
                        'shift_id' => $roster->shift_code,
                        'shift_code' => $roster->shift?->shift_code ?? '-',
                        'shift_name' => $roster->shift?->shift_name ?? $roster->shift?->shift_description ?? '-',
                        'start_time' => $roster->shift?->start_time ? substr($roster->shift->start_time, 0, 5) : null,
                        'end_time'   => $roster->shift?->end_time ? substr($roster->shift->end_time, 0, 5) : null,
                        'date_from' => $roster->date_from,
                        'date_to' => $roster->date_to,
                        'status' => $roster->status ?? 'Active',
                        'cancel_reason' => $roster->cancel_reason,
                        'cancelled_at' => $roster->cancelled_at,
                    ],
                    'organization_details' => [
                        'company' => $roster->company ? [
                            'id' => $roster->company->id,
                            'name' => $roster->company->name,
                        ] : null,
                        'department' => $roster->department ? [
                            'id' => $roster->department->id,
                            'name' => $roster->department->name,
                        ] : null,
                    ],
                    'employee_details' => [
                        'id' => $roster->employee_id,
                        'employee_code' => $roster->employee?->attendance_employee_no ?? $roster->employee?->employee_code ?? null,
                        'full_name' => $roster->employee?->full_name ?? $roster->employee?->name_with_initials ?? $roster->employee?->first_name ?? 'Unknown',
                    ],
                ];
            });

            return response()->json([
                'status' => 'success',
                'count' => $rosters->count(),
                'data' => $rosters,
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Error retrieving roster data', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $roster = Roster::findOrFail($id);
            $roster->delete();
            return response()->json(['message' => 'Roster deleted successfully', 'success' => true], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete roster', 'success' => false], 500);
        }
    }

    public function cancel(Request $request, $id)
    {
        $validated = $request->validate([
            'cancel_reason' => 'nullable|string|max:255',
        ]);

        $roster = Roster::findOrFail($id);

        if ($roster->isCancelled()) {
            return response()->json(['message' => 'Roster is already cancelled', 'success' => false], 422);
        }

        $roster->update([
            'status' => 'Cancelled',
            'cancel_reason' => $validated['cancel_reason'] ?? null,
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'message' => 'Roster cancelled successfully',
            'success' => true,
            'data' => $roster->fresh(['employee', 'shift']),
        ]);
    }

    public function bulkCancel(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:rosters,id',
            'cancel_reason' => 'nullable|string|max:255',
        ]);

        $count = Roster::whereIn('id', $validated['ids'])
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'Cancelled');
            })
            ->update([
                'status' => 'Cancelled',
                'cancel_reason' => $validated['cancel_reason'] ?? null,
                'cancelled_at' => now(),
            ]);

        return response()->json([
            'message' => "Successfully cancelled {$count} roster record(s)",
            'success' => true,
            'cancelled' => $count,
        ]);
    }

    public function bulkDestroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:rosters,id'
        ]);

        if ($validator->fails()) return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);

        try {
            $deletedCount = Roster::whereIn('id', $request->input('ids'))->delete();
            return response()->json(['message' => "Successfully deleted {$deletedCount} roster record(s)", 'success' => true], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete rosters', 'success' => false], 500);
        }
    }
}


/*
namespace App\Http\Controllers;

use App\Models\Roster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RosterController extends Controller
{
    // Controller methods for managing rosters will go here

    public function index()
    {
        // Eager load to avoid N+1
        $rosters = Roster::with(['company', 'department', 'subDepartment', 'employee', 'shift'])
            ->orderBy('created_at', 'desc')
            ->get();
        $data = $rosters->map(function ($r) {
            return [
                'id' => $r->id,
                'roster_id' => $r->roster_id,
                'shift_id' => $r->shift_code,
                'shift_code' => $r->shift_code,
                
                'shift_name' => $r->shift?->shift_name ?? $r->shift?->shift_description,

                'start_time' => $r->shift?->start_time ? substr($r->shift->start_time, 0, 5) : null,
                'end_time'   => $r->shift?->end_time ? substr($r->shift->end_time, 0, 5) : null,

                'company_id' => $r->company_id,
                'company_name' => $r->company?->name,
                'department_id' => $r->department_id,
                'department_name' => $r->department?->name,
                'sub_department_id' => $r->sub_department_id,
                'sub_department_name' => $r->subDepartment?->name,
                'employee_id' => $r->employee_id,
                'employee_name' => $r->employee?->full_name
                    ?? $r->employee?->name_with_initials
                    ?? null,
                'date_from' => $r->date_from,
                'date_to' => $r->date_to,
                'created_at' => $r->created_at,
            ];
        });

        return response()->json($data, 200);
    }

    public function show($id)
    {
        $roster = Roster::find($id);
        if (!$roster) {
            return response()->json(['message' => 'Roster not found'], 404);
        }

        return response()->json($roster, 200);
    }

    public function store(Request $request)
    {
        // Check if the request contains JSON array data
        if ($this->isJsonArray($request)) {
            return $this->storeBulk($request);
        }

        // Single entry validation
        $validator = Validator::make($request->all(), [
            'roster_id' => 'nullable|integer', // allow null; we may set it automatically
            'shift_code' => 'required|exists:shifts,id',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'sub_department_id' => 'nullable|exists:sub_departments,id',
            'employee_id' => 'nullable|exists:employees,id',
            'is_recurring' => 'boolean',
            'recurrence_pattern' => 'nullable|string',
            'notes' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'custom_created_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Set custom created_at if provided, otherwise use current timestamp
        if (isset($data['custom_created_at'])) {
            $data['created_at'] = $data['custom_created_at'];
            unset($data['custom_created_at']); // Remove from data array
        }

        // Build group signature
        $signature = [
            'company_id' => $data['company_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'sub_department_id' => $data['sub_department_id'] ?? null,
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
            'shift_code' => $data['shift_code'],
        ];

        // Check if a roster group with the same signature already exists
        $existingRosterId = $this->findExistingRosterGroupId($signature);

        if ($existingRosterId !== null) {
            // If request tries to create a new roster_id for an existing group -> block
            if (isset($data['roster_id']) && (int) $data['roster_id'] !== (int) $existingRosterId) {
                return response()->json([
                    'errors' => [
                        'roster' => ["A roster already exists for this company/department/sub-department, date range and shift (roster_id: {$existingRosterId}). Use the same roster_id to add employees to the existing roster."]
                    ]
                ], 422);
            }
            // No roster_id provided -> attach to existing group
            $data['roster_id'] = $existingRosterId;
        } else {
            // No existing group -> assign roster_id if not provided
            if (!isset($data['roster_id'])) {
                $data['roster_id'] = (int) Roster::max('roster_id') + 1;
            }
        }

        try {
            $roster = Roster::create($data);
            return response()->json($roster, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create roster',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    protected function isJsonArray(Request $request)
    {
        $content = $request->getContent();
        if (empty($content)) {
            return false;
        }

        $data = json_decode($content, true);
        return is_array($data) && array_keys($data) === range(0, count($data) - 1);
    }

    public function storeBulk(Request $request)
    {
        $entries = json_decode($request->getContent(), true);

        if (!is_array($entries)) {
            return response()->json(['error' => 'Invalid bulk data format. Expected JSON array.'], 400);
        }

        $validatedEntries = [];
        $errors = [];
        $rosterId = null;

        $commonSignature = null;

        foreach ($entries as $index => $entry) {
            $validator = Validator::make($entry, [
                'roster_id' => 'required|integer',
                'shift_code' => 'required|exists:shifts,id',
                'company_id' => 'nullable|exists:companies,id',
                'department_id' => 'nullable|exists:departments,id',
                'sub_department_id' => 'nullable|exists:sub_departments,id',
                'employee_id' => 'nullable|exists:employees,id',
                'is_recurring' => 'boolean',
                'recurrence_pattern' => 'nullable|string',
                'notes' => 'nullable|string',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                $errors[$index] = $validator->errors();
                continue;
            }

            $validated = $validator->validated();

            // Enforce same roster_id across the batch
            if ($rosterId === null) {
                $rosterId = $validated['roster_id'];
            } elseif ((int) $validated['roster_id'] !== (int) $rosterId) {
                $errors[$index] = ['roster_id' => 'All entries in a bulk request must have the same roster_id'];
                continue;
            }

            // Build and enforce same group signature across the batch
            $signature = [
                'company_id' => $validated['company_id'] ?? null,
                'department_id' => $validated['department_id'] ?? null,
                'sub_department_id' => $validated['sub_department_id'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'shift_code' => $validated['shift_code'],
            ];

            if ($commonSignature === null) {
                $commonSignature = $signature;
            } elseif ($signature !== $commonSignature) {
                $errors[$index] = ['group' => 'All entries must share the same company/department/sub-department, date range, and shift_code'];
                continue;
            }

            $validatedEntries[] = $validated;
        }

        if (!empty($errors)) {
            return response()->json(['errors' => $errors], 422);
        }

        $now = now();
        $validatedEntries = array_map(function ($entry) use ($now) {
            $entry['created_at'] = $now;
            $entry['updated_at'] = $now;
            return $entry;
        }, $validatedEntries);

        // Prevent creating a new roster group if another one already exists with a different roster_id
        $existingRosterId = $this->findExistingRosterGroupId($commonSignature);

        if ($existingRosterId !== null && (int) $existingRosterId !== (int) $rosterId) {
            return response()->json([
                'errors' => [
                    'roster' => ["A roster already exists for this company/department/sub-department, date range and shift (roster_id: {$existingRosterId}). Use the same roster_id to add employees to the existing roster."]
                ]
            ], 422);
        }

        DB::beginTransaction();
        try {
            Roster::insert($validatedEntries);
            DB::commit();

            return response()->json([
                'message' => 'Bulk roster entries created successfully',
                'roster_id' => $rosterId,
                'count' => count($validatedEntries),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to create bulk roster entries',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $roster = Roster::find($id);
        if (!$roster) {
            return response()->json(['message' => 'Roster not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'shift_code' => 'required|exists:shifts,id',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'sub_department_id' => 'nullable|exists:sub_departments,id',
            'employee_id' => 'nullable|exists:employees,id',
            'is_recurring' => 'boolean',
            'recurrence_pattern' => 'nullable|string',
            'notes' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $roster->update($validator->validated());

        return response()->json($roster, 200);
    }

    public function destroy($id)
    {
        try {
            $roster = Roster::findOrFail($id);
            
            // Perform soft delete
            $roster->delete();
            
            return response()->json([
                'message' => 'Roster deleted successfully',
                'success' => true
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Roster not found',
                'success' => false
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete roster',
                'error' => $e->getMessage(),
                'success' => false
            ], 500);
        }
    }

    // Add this method to get trashed rosters if needed
    public function getTrashed()
    {
        try {
            $trashedRosters = Roster::onlyTrashed()
                ->with(['company', 'department', 'subDepartment', 'employee'])
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $trashedRosters
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch trashed rosters',
                'error' => $e->getMessage(),
                'success' => false
            ], 500);
        }
    }

    // Add this method to restore soft deleted rosters if needed
    public function restore($id)
    {
        try {
            $roster = Roster::onlyTrashed()->findOrFail($id);
            $roster->restore();
            
            return response()->json([
                'message' => 'Roster restored successfully',
                'success' => true
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to restore roster',
                'error' => $e->getMessage(),
                'success' => false
            ], 500);
        }
    }

    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'sub_department_id' => 'nullable|exists:sub_departments,id',
            'employee_id' => 'nullable|exists:employees,id',
            'roster_id' => 'nullable|string', // Add this line

        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = Roster::with(['company', 'department', 'subDepartment', 'employee', 'shift'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->where(function ($q) use ($request) {
                $dateFrom = $request->date_from;
                $dateTo = $request->date_to ?? $request->date_from;

                if ($dateFrom && $dateTo) {
                    $q->whereBetween('date_from', [$dateFrom, $dateTo])
                        ->orWhereBetween('date_to', [$dateFrom, $dateTo])
                        ->orWhere(function ($query) use ($dateFrom, $dateTo) {
                            $query->where('date_from', '<=', $dateFrom)
                                ->where('date_to', '>=', $dateTo);
                        });
                } elseif ($dateFrom) {
                    $q->where('date_from', '>=', $dateFrom);
                }
            });
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('sub_department_id')) {
            $query->where('sub_department_id', $request->sub_department_id);
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        // Add this new filter condition
        if ($request->filled('roster_id')) {
            $query->where('roster_id', 'LIKE', '%' . $request->roster_id . '%');
        }

        try {
            $rosters = $query->get()->map(function ($roster) {
                return [
                    'roster_details' => [
                        'id' => $roster->id,
                        'roster_id' => $roster->roster_id,
                        'shift_code' => $roster->shift_code,

                          // ✅ add these
            'shift_real_code' => $roster->shift?->shift_code,
            'shift_name' => $roster->shift?->shift_name ?? $roster->shift?->shift_description,
            'start_time' => $roster->shift?->start_time ? substr($roster->shift->start_time, 0, 5) : null,
            'end_time'   => $roster->shift?->end_time ? substr($roster->shift->end_time, 0, 5) : null,

                        'is_recurring' => $roster->is_recurring,
                        'recurrence_pattern' => $roster->recurrence_pattern,
                        'notes' => $roster->notes,
                        'date_from' => $roster->date_from,
                        'date_to' => $roster->date_to,
                    ],
                    'organization_details' => [
                        'company' => $roster->company ? [
                            'id' => $roster->company->id,
                            'name' => $roster->company->name,
                        ] : null,
                        'department' => $roster->department ? [
                            'id' => $roster->department->id,
                            'name' => $roster->department->name,
                        ] : null,
                        'sub_department' => $roster->subDepartment ? [
                            'id' => $roster->subDepartment->id,
                            'name' => $roster->subDepartment->name,
                        ] : null,
                    ],
                    'employee_details' => $roster->employee ? [
                        'id' => $roster->employee->id,
                        'name' => $roster->employee->name_with_initials ?? null,
                        'full_name' => $roster->employee->full_name ?? null,
                        'epf' => $roster->employee->epf ?? null,
                        'attendance_no' => $roster->employee->attendance_employee_no ?? null,
                    ] : null,
                ];
            });

            return response()->json([
                'status' => 'success',
                'count' => $rosters->count(),
                'data' => $rosters,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving roster data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

   
    private function findExistingRosterGroupId(array $signature): ?int
    {
        // Only enforce when company-level assignment is in play
        if (!array_key_exists('company_id', $signature)) {
            return null;
        }

        $query = Roster::query()
            ->where('company_id', $signature['company_id'])
            ->where('department_id', $signature['department_id'])
            ->where('sub_department_id', $signature['sub_department_id'])
            ->where('date_from', $signature['date_from'])
            ->where('date_to', $signature['date_to'])
            ->where('shift_code', $signature['shift_code']);

        $existing = $query->select('roster_id')->first();

        return $existing?->roster_id ? (int) $existing->roster_id : null;
    }

    public function bulkDestroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:rosters,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'success' => false
            ], 422);
        }

        try {
            $ids = $request->input('ids');
            $deletedCount = Roster::whereIn('id', $ids)->delete();
            
            if ($deletedCount === 0) {
                return response()->json([
                    'message' => 'No rosters were found to delete',
                    'success' => false
                ], 404);
            }

            return response()->json([
                'message' => "Successfully deleted {$deletedCount} roster record(s)",
                'deleted_count' => $deletedCount,
                'success' => true
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete rosters',
                'error' => $e->getMessage(),
                'success' => false
            ], 500);
        }
    }
}
*/