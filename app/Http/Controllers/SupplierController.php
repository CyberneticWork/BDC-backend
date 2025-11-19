<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;

class SupplierController extends Controller
{
    /** Display a listing of the suppliers. */
    public function index(): JsonResponse
    {
        $suppliers = Supplier::orderBy('id', 'desc')->get();
        return response()->json($suppliers);
    }

    /** Store a newly created supplier. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_name' => 'required|string|max:255',
            'phone_number' => 'nullable|string|max:50',
            'nic' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'address1' => 'nullable|string|max:500',
            'address2' => 'nullable|string|max:500',
            'credit_value' => 'nullable|numeric',
            'credit_period' => 'nullable|integer',
        ]);

        $supplier = Supplier::create($data);

        return response()->json($supplier, 201);
    }

    /** Display the specified supplier. */
    public function show($id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        return response()->json($supplier);
    }

    /** Update the specified supplier. */
    public function update(Request $request, $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);

        $data = $request->validate([
            'supplier_name' => 'sometimes|required|string|max:255',
            'phone_number' => 'nullable|string|max:50',
            'nic' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'address1' => 'nullable|string|max:500',
            'address2' => 'nullable|string|max:500',
            'credit_value' => 'nullable|numeric',
            'credit_period' => 'nullable|integer',
        ]);

        $supplier->update($data);

        return response()->json($supplier);
    }

    /** Remove the specified supplier from storage. */
    public function destroy($id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->delete();

        return response()->json(null, 204);
    }
}
