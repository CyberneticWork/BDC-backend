<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\inventory_stock;
use App\Services\InventoryStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class InventoryStockController extends Controller
{
    protected InventoryStockService $service;

    public function __construct(InventoryStockService $service)
    {
        $this->service = $service;
    }
    /**
     * Display a paginated listing of the inventory stocks.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 15);
        $stocks = $this->service->getAll($perPage);

        return response()->json($stocks);
    }

    /**
     * Return all inventory stocks with full related details (no pagination).
     */
    public function all(): JsonResponse
    {
        $stocks = $this->service->getAllDetails();

        return response()->json($stocks);
    }

    /**
     * Store a newly created inventory stock in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'center_id' => ['required', 'integer', Rule::exists('centers', 'id')],
            'batch_number' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:0'],
            'created_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'updated_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        // If authenticated, prefer to set created_by automatically
        if ($request->user()) {
            $data['created_by'] = $request->user()->id;
            $data['updated_by'] = $request->user()->id;
        }

        $stock = inventory_stock::create($data);

        return response()->json($stock, 201);
    }

    /**
     * Display the specified inventory stock.
     */
    public function show($id): JsonResponse
    {
        $stock = $this->service->find($id);

        return response()->json($stock);
    }

    /**
     * Update the specified inventory stock in storage.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $stock = inventory_stock::findOrFail($id);

        $data = $request->validate([
            'product_id' => ['sometimes', 'integer', Rule::exists('products', 'id')],
            'center_id' => ['sometimes', 'integer', Rule::exists('centers', 'id')],
            'batch_number' => ['nullable', 'string', 'max:255'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'updated_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        if ($request->user()) {
            $data['updated_by'] = $request->user()->id;
        }

        $stock->update($data);

        return response()->json($stock);
    }

    /**
     * Remove the specified inventory stock from storage (soft delete).
     */
    public function destroy($id)
    {
        $stock = inventory_stock::findOrFail($id);
        $stock->delete();

        return response()->noContent();
    }
}
