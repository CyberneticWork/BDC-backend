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
     * Return product details with center-wise inventory info.
     */
    public function inventoryDetails(Request $request)
    {
        $products = product::with([
            'discountLevel:id,name',
            'productType:id,type',
            'inventoryStocks.center:id,name',
        ])->get();

        $payload = $products->map(function (product $product) {
            return [
                'id' => $product->id,
                'barcode' => $product->barcode,
                'code' => $product->code,
                'name' => $product->name,
                'description' => $product->description,
                'oem_numbers' => $product->oem_numbers,
                'cost' => $product->cost,
                'min_price' => $product->min_price,
                'mrp' => $product->mrp,
                'is_active' => $product->is_active,
                'discount_level' => $product->discountLevel ? [
                    'id' => $product->discountLevel->id,
                    'name' => $product->discountLevel->name,
                ] : null,
                'product_type' => $product->productType ? [
                    'id' => $product->productType->id,
                    'name' => $product->productType->type,
                ] : null,
                'total_available_quantity' => $product->inventoryStocks->sum('quantity'),
                'inventory' => $product->inventoryStocks->map(function ($stock) {
                    return [
                        'inventory_stock_id' => $stock->id,
                        'batch_number' => $stock->batch_number,
                        'center' => $stock->center ? [
                            'id' => $stock->center->id,
                            'name' => $stock->center->name,
                        ] : null,
                        'available_quantity' => $stock->quantity,
                    ];
                })->values(),
            ];
        });

        return response()->json($payload);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Accept frontend-friendly keys and map them to DB columns
        $data = $request->all();
        if (isset($data['discountLevel'])) {
            $data['discount_level_id'] = $data['discountLevel'];
        }
        if (isset($data['productType'])) {
            $data['product_type_id'] = $data['productType'];
        }
        if (isset($data['minPrice'])) {
            $data['min_price'] = $data['minPrice'];
        }
        if (isset($data['oemNumbers'])) {
            $data['oem_numbers'] = $data['oemNumbers'];
        }
        if (isset($data['isActive'])) {
            $data['is_active'] = $data['isActive'];
        }

        $validator = Validator::make($data, [
            'barcode' => 'required|string',
            'code' => 'required|string|unique:products',
            'cost' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'discount_level_id' => 'required|exists:discount_levels,id',
            'created_by' => 'required|integer|exists:users,id',
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

        $product = product::create($data);
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
        // Map frontend keys to DB fields first
        $data = $request->all();
        if (isset($data['discountLevel'])) {
            $data['discount_level_id'] = $data['discountLevel'];
        }
        if (isset($data['productType'])) {
            $data['product_type_id'] = $data['productType'];
        }
        if (isset($data['minPrice'])) {
            $data['min_price'] = $data['minPrice'];
        }
        if (isset($data['oemNumbers'])) {
            $data['oem_numbers'] = $data['oemNumbers'];
        }
        if (isset($data['isActive'])) {
            $data['is_active'] = $data['isActive'];
        }

        $validator = Validator::make($data, [
            'barcode' => 'string',
            'code' => 'string|unique:products,code,' . $product->id,
            'cost' => 'numeric|min:0',
            'description' => 'nullable|string',
            'discount_level_id' => 'exists:discount_levels,id',
            'created_by' => 'integer|exists:users,id',
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

        $product->update($data);
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
