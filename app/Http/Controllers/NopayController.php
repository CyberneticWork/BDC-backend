<?php

namespace App\Http\Controllers;

use App\Models\NoPayRecord;
use App\Models\employee;
use App\Models\time_card;
use App\Models\leave_master;
use App\Models\roster;
use App\Models\leaveCalendar;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NopayController extends Controller
{
    public function index(Request $request)
    {
        $query = NoPayRecord::with(['employee', 'processedBy'])
            ->orderBy('date', 'desc');

        if ($request->has('month')) {
            $query->whereMonth('date', $request->month);
        }

        if ($request->has('year')) {
            $query->whereYear('date', $request->year);
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('company_id')) {
            $query->whereHas('employee.organizationAssignment', function($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('employee', function($q) use ($search) {
                $q->where('full_name', 'like', "%$search%")
                  ->orWhere('attendance_employee_no', 'like', "%$search%");
            });
        }

        // Pagination
        if ($request->has('page') && $request->has('per_page')) {
            $perPage = $request->per_page;
            return $query->paginate($perPage);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'no_pay_count' => 'required|numeric|min:0.5|max:2',
            'description' => 'required|string|max:500',
            'status' => 'sometimes|in:Pending,Approved,Rejected',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $noPayRecord = NoPayRecord::create([
            'employee_id' => $request->employee_id,
            'date' => $request->date,
            'no_pay_count' => $request->no_pay_count,
            'description' => $request->description,
            'status' => $request->status ?? 'Pending',
            'processed_by' => Auth::id(),
        ]);

        return response()->json($noPayRecord, 201);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'no_pay_count' => 'sometimes|numeric|min:0.5|max:2',
            'description' => 'sometimes|string|max:500',
            'status' => 'sometimes|in:Pending,Approved,Rejected',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $noPayRecord = NoPayRecord::findOrFail($id);
        $noPayRecord->update($request->all());

        return response()->json($noPayRecord);
    }

    public function bulkUpdateStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'exists:no_pay_records,id',
            'status' => 'required|in:Pending,Approved,Rejected',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $updatedCount = NoPayRecord::whereIn('id', $request->ids)
            ->update([
                'status' => $request->status,
                'processed_by' => Auth::id(),
            ]);

        return response()->json([
            'message' => 'Successfully updated ' . $updatedCount . ' records',
            'updated_count' => $updatedCount
        ]);
    }

    public function destroy($id)
    {
        $noPayRecord = NoPayRecord::findOrFail($id);
        $noPayRecord->delete();

        return response()->json(null, 204);
    }

    public function bulkDestroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'exists:no_pay_records,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $deletedCount = NoPayRecord::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => 'Successfully deleted ' . $deletedCount . ' records',
            'deleted_count' => $deletedCount
        ]);
    }

    public function generateDailyNoPayRecords(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'company_id' => 'sometimes|exists:companies,id',
            'status' => 'sometimes|in:Pending,Approved,Rejected',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $date = $request->date;
        $status = $request->status ?? 'Pending';
        $carbonDate = Carbon::parse($date);

        // Build employee query and optionally filter by company
        $employeesQuery = employee::where('is_active', '1');

        if ($request->filled('company_id')) {
            $companyId = $request->company_id;
            $employeesQuery->whereHas('organizationAssignment', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        }

        $employees = $employeesQuery->with(['organizationAssignment', 'compensation'])->get();

        $generatedRecords = [];

        foreach ($employees as $employee) {
            // Skip if employee has compensation.active_nopay set to false
            if ($employee->compensation && $employee->compensation->active_nopay === false) {
                continue;
            }

            // Check if it's employee's day off
            $dayOff = $employee->organizationAssignment->day_off ?? null;
            if ($dayOff && $carbonDate->dayName === $dayOff) {
                continue;
            }

            // Check if employee has roster assignment for this day
            $hasRoster = $this->checkEmployeeRoster($employee, $date);
            if (!$hasRoster) {
                continue;
            }

            // Check if employee has time card for the date
            $hasTimeCard = time_card::where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->exists();

            if ($hasTimeCard) {
                continue;
            }

            // Check if employee has approved leave for the date
            $hasLeave = leave_master::where('employee_id', $employee->id)
                ->where('status', 'Approved')
                ->where(function($query) use ($date) {
                    $query->whereDate('leave_date', $date)
                        ->orWhere(function($q) use ($date) {
                            $q->whereDate('leave_from', '<=', $date)
                                ->whereDate('leave_to', '>=', $date);
                        });
                })
                ->exists();

            if ($hasLeave) {
                continue;
            }

            // Check if company/department has holiday
            $hasHoliday = $this->checkCompanyHoliday($employee, $date);
            if ($hasHoliday) {
                continue;
            }

            // Create no pay record
            try {
                $record = NoPayRecord::firstOrCreate([
                    'employee_id' => $employee->id,
                    'date' => $date,
                ], [
                    'no_pay_count' => 1, // Default full day
                    'description' => 'Automatic no-pay record - no attendance, no approved leave, and not a holiday/day off',
                    'status' => $status,
                    'processed_by' => Auth::id(),
                ]);

                if ($record->wasRecentlyCreated) {
                    $generatedRecords[] = $record;
                }
            } catch (\Exception $e) {
                return response()->json(['error' => 'Failed to create no-pay record: ' . $e->getMessage()], 500);
            }
        }

        return response()->json([
            'message' => count($generatedRecords) . ' no-pay records generated',
            'records' => $generatedRecords
        ]);
    }

    protected function checkEmployeeRoster($employee, $date)
    {
        // Check if employee has roster assignment for this day
        return roster::where('employee_id', $employee->id)
            ->where(function($query) use ($date) {
                $query->whereNull('date_from')
                    ->orWhere('date_from', '<=', $date);
            })
            ->where(function($query) use ($date) {
                $query->whereNull('date_to')
                    ->orWhere('date_to', '>=', $date);
            })
            ->exists();
    }

    protected function checkCompanyHoliday($employee, $date)
    {
        $orgAssignment = $employee->organizationAssignment;
        if (!$orgAssignment) {
            return false;
        }

        // Check company holidays
        $companyHoliday = leaveCalendar::where('company_id', $orgAssignment->company_id)
            ->whereDate('start_date', '<=', $date)
            ->where(function($query) use ($date) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $date);
            })
            ->exists();

        if ($companyHoliday) {
            return true;
        }

        // Check department holidays
        if ($orgAssignment->department_id) {
            $deptHoliday = leaveCalendar::where('department_id', $orgAssignment->department_id)
                ->whereDate('start_date', '<=', $date)
                ->where(function($query) use ($date) {
                    $query->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', $date);
                })
                ->exists();

            if ($deptHoliday) {
                return true;
            }
        }

        return false;
    }

    public function getNoPayStats(Request $request)
    {
        $query = NoPayRecord::query();

        if ($request->has('month')) {
            $query->whereMonth('date', $request->month);
        }

        if ($request->has('year')) {
            $query->whereYear('date', $request->year);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('company_id')) {
            $query->whereHas('employee.organizationAssignment', function($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });
        }

        $totalRecords = $query->count();
        $totalDays = $query->sum('no_pay_count');
        $affectedEmployees = $query->distinct('employee_id')->count('employee_id');

        return response()->json([
            'total_records' => $totalRecords,
            'total_days' => $totalDays,
            'affected_employees' => $affectedEmployees
        ]);
    }
}
