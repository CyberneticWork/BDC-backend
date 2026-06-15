<?php

namespace App\Http\Controllers;

use App\Services\EmployeeWiseAllowanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeWiseAllowanceController extends Controller
{
    protected EmployeeWiseAllowanceService $service;

    public function __construct(EmployeeWiseAllowanceService $service)
    {
        $this->service = $service;
    }

    public function index(): JsonResponse
    {
        $data = $this->service->getAll();

        return response()->json($data, 200);
    }

    public function getOneById(int $id): JsonResponse
    {
        $record = $this->service->getOneById($id);

        return response()->json($record, 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'allowance_code' => 'required|string|max:255',
            'allowance_name' => 'required|string|max:255',
            'allowance_description' => 'nullable|string|max:255',
            'employee_id' => 'required|exists:employees,id',
            'amount' => 'required|numeric|min:0',
            'date' => 'required|date'
        ]);

        $newRecord = $this->service->createOne($validated);

        return response()->json([
            'message' => 'Record created successfully.',
            'data' => $newRecord
        ], 200);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'allowance_name' => 'required|string|max:255',
            'allowance_description' => 'nullable|string|max:255',
            'employee_id' => 'required|exists:employees,id',
            'amount' => 'required|numeric|min:0',
            'date' => 'required|date',
            'status' => 'required|string|in:active,inactive'
        ]);

        $updatedRecord = $this->service->updateOne($id, $validated);

        return response()->json([
            'message' => 'Record updated successfully.',
            'data' => $updatedRecord
        ], 200);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->service->deleteOne($id);

        return response()->json([
            'message' => 'Record deleted successfully.',
        ], 200);
    }
}
