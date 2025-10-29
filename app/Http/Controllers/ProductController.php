<?php

namespace App\Http\Controllers;

use App\Models\product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $products = product::with(['discountLevel', 'productType'])->get();
        return response()->json($products);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required|string',
            'code' => 'required|string|unique:products',
            'cost' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'discount_level_id' => 'required|exists:discount_levels,id',
            'is_active' => 'boolean',
            'min_price' => 'required|numeric|min:0',
            'mrp' => 'required|numeric|min:0',
            'name' => 'required|string',
            'oem_numbers' => 'nullable|string',
            'product_type_id' => 'required|exists:product_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $product = product::create($request->all());
        return response()->json($product, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(product $product)
    {
        $product->load(['discountLevel', 'productType']);
        return response()->json($product);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, product $product)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'string',
            'code' => 'string|unique:products,code,' . $product->id,
            'cost' => 'numeric|min:0',
            'description' => 'nullable|string',
            'discount_level_id' => 'exists:discount_levels,id',
            'is_active' => 'boolean',
            'min_price' => 'numeric|min:0',
            'mrp' => 'numeric|min:0',
            'name' => 'string',
            'oem_numbers' => 'nullable|string',
            'product_type_id' => 'exists:product_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $product->update($request->all());
        return response()->json($product);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(product $product)
    {
        $product->delete();
        return response()->json(['message' => 'Product deleted successfully']);
    }
}
