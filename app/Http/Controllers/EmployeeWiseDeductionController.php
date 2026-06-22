<?php

namespace App\Http\Controllers;

use App\Services\EmployeeWiseDeductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeWiseDeductionController extends Controller
{
    public function __construct(protected EmployeeWiseDeductionService $service)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->service->getAll(), 200);
    }

    public function getOneById(int $id): JsonResponse
    {
        return response()->json($this->service->getOneById($id), 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deduction_code' => 'required|string|max:255',
            'deduction_name' => 'required|string|max:255',
            'deduction_description' => 'nullable|string|max:255',
            'employee_id' => 'required|exists:employees,id',
            'amount' => 'required|numeric|min:0',
            'date' => 'required|date',
        ]);

        $newRecord = $this->service->createOne($validated);

        return response()->json([
            'message' => 'Record created successfully.',
            'data' => $newRecord,
        ], 200);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'deduction_name' => 'required|string|max:255',
            'deduction_description' => 'nullable|string|max:255',
            'employee_id' => 'required|exists:employees,id',
            'amount' => 'required|numeric|min:0',
            'date' => 'required|date',
            'status' => 'required|string|in:active,inactive',
        ]);

        $updatedRecord = $this->service->updateOne($id, $validated);

        return response()->json([
            'message' => 'Record updated successfully.',
            'data' => $updatedRecord,
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
