<?php

namespace App\Http\Controllers;

use App\Models\PersonalOutEntry;
use App\Services\PersonalOutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PersonalOutController extends Controller
{
    public function __construct(private PersonalOutService $service)
    {
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
            'company_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        return response()->json(['data' => $this->service->list(
            (int) $request->year,
            (int) $request->month,
            $request->filled('company_id') ? (int) $request->company_id : null,
            $request->search
        )]);
    }

    public function employees(Request $request)
    {
        return response()->json(['data' => $this->service->searchEmployees(
            $request->filled('company_id') ? (int) $request->company_id : null,
            $request->search
        )]);
    }

    public function preview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer|exists:employees,id',
            'date' => 'required|date',
            'out_time' => 'nullable|date_format:H:i',
            'in_time' => 'nullable|date_format:H:i',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        try {
            return response()->json($this->service->preview(
                (int) $request->employee_id,
                (string) $request->date,
                $request->input('out_time'),
                $request->input('in_time')
            ));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer|exists:employees,id',
            'date' => 'required|date',
            'out_time' => 'required|date_format:H:i',
            'in_time' => 'required|date_format:H:i',
            'reason' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        try {
            $entry = $this->service->create($validator->validated());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($entry->deduct_days <= 0) {
            $message = 'Saved. Within the ' . PersonalOutService::FREE_MINUTES . '-minute allowance, no deduction.';
        } elseif ($entry->nopay_days > 0) {
            $message = "Saved. {$entry->leave_days} day(s) from leave, {$entry->nopay_days} day(s) NoPay from monthly bonus.";
        } else {
            $message = "Saved. {$entry->deduct_days} day(s) deducted from leave.";
        }

        return response()->json(['message' => $message, 'data' => $entry], 201);
    }

    public function destroy(int $id)
    {
        PersonalOutService::ensureTable();
        $entry = PersonalOutEntry::findOrFail($id);
        $this->service->delete($entry);

        return response()->json(['message' => 'Personal out entry removed and its leave / NoPay reversed.']);
    }
}
