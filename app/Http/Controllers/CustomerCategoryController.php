<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CustomerCategory;
use Illuminate\Http\Request;

class CustomerCategoryController extends Controller
{
    public function index()
    {
        return CustomerCategory::orderBy('name')->pluck('name', 'id')->map(function($name, $id){
            return ['id' => $id, 'name' => $name];
        })->values();
        // or simply: return CustomerCategory::all();
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|unique:customer_categories,name']);
        $category = CustomerCategory::create([
            'name' => $request->name,
            'description' => $request->description ?? null
        ]);
        return response()->json($category, 201);
    }
}
