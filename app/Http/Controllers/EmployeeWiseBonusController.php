<?php

namespace App\Http\Controllers;

use App\Services\EmployeeWiseBonusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeWiseBonusController extends Controller
{
    public function __construct(protected EmployeeWiseBonusService $service)
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
            'bonus_code' => 'required|string|max:255',
            'bonus_name' => 'required|string|max:255',
            'bonus_description' => 'nullable|string|max:255',
            'employee_id' => 'required|exists:employees,id',
            'amount' => 'required|numeric|min:0',
            'date' => 'required|date',
            'is_annual' => 'nullable|boolean',
            'payment_months' => 'nullable|array',
            'payment_months.*' => 'integer|between:1,12',
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
            'bonus_name' => 'required|string|max:255',
            'bonus_description' => 'nullable|string|max:255',
            'employee_id' => 'required|exists:employees,id',
            'amount' => 'required|numeric|min:0',
            'date' => 'required|date',
            'is_annual' => 'nullable|boolean',
            'payment_months' => 'nullable|array',
            'payment_months.*' => 'integer|between:1,12',
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
