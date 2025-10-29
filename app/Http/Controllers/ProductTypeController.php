<?php
namespace App\Http\Controllers;

use App\Models\ProductType;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProductTypeController extends Controller
{
    // List all product types (including soft deleted if requested)
    public function index(Request $request)
    {
        $withTrashed = $request->query('with_trashed', false);
        $query = ProductType::query();
        if ($withTrashed) {
            $query->withTrashed();
        }
        return response()->json($query->get());
    }

    // Store a new product type
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|string|max:255',
            'description' => 'nullable|string',
            'created_by' => 'nullable|exists:users,id',
            'status' => 'in:active,deactive',
        ]);
        $data['status'] = $data['status'] ?? 'active';
        $productType = ProductType::create($data);
        return response()->json($productType);
    }

    // Show a single product type
    public function show($id)
    {
        $productType = ProductType::withTrashed()->findOrFail($id);
        return response()->json($productType);
    }

    // Update a product type
    public function update(Request $request, $id)
    {
        $productType = ProductType::withTrashed()->findOrFail($id);
        $data = $request->validate([
            'type' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'created_by' => 'nullable|exists:users,id',
            'status' => 'in:active,deactive',
        ]);
        $productType->update($data);
        return response()->json($productType);
    }

    // Soft delete a product type
    public function destroy($id)
    {
        $productType = ProductType::findOrFail($id);
        $productType->delete();
        return response()->json(['message' => 'Product type soft deleted.']);
    }

    // Restore a soft deleted product type
    public function restore($id)
    {
        $productType = ProductType::withTrashed()->findOrFail($id);
        if ($productType->trashed()) {
            $productType->restore();
            return response()->json(['message' => 'Product type restored.']);
        }
        return response()->json(['message' => 'Product type is not deleted.'], 400);
    }

    // Set status to active or deactive
    public function setStatus(Request $request, $id)
    {
        $productType = ProductType::withTrashed()->findOrFail($id);
        $status = $request->input('status');
        if (!in_array($status, ['active', 'deactive'])) {
            return response()->json(['message' => 'Invalid status.'], 422);
        }
        $productType->status = $status;
        $productType->save();
        return response()->json(['message' => "Status updated to $status.", 'productType' => $productType]);
    }
}
