<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\EmergencyContactRelationshipTypesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psy\Util\Json;

class EmergencyContactRelationshipTypesController extends Controller
{
    protected EmergencyContactRelationshipTypesService $service;

    public function __construct(EmergencyContactRelationshipTypesService $relationshipService)
    {
        $this->service = $relationshipService;
    }

    public function index(): JsonResponse
    {
        $types = $this->service->getAllTypes();
        return response()->json($types, 200);
    }

    public function getOneById(int $id): JsonResponse
    {
        $type = $this->service->getTypeById($id);

        return response()->json($type, 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
        ]);

        $newType = $this->service->createType($validated);

        return response()->json([
            'message' => 'Relationship type created successfully.',
            'data' => $newType
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
        ]);

        $updatedType = $this->service->updateType($id, $validated);

        return response()->json([
            'message' => 'Relationship type updated successfully.',
            'data' => $updatedType
        ], 200);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->service->deleteType($id);

        return response()->json([
            'message' => 'Relationship type deleted successfully.',
        ], 200);
    }
}
