<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CustomerType;
use Illuminate\Http\Request;

class CustomerTypeController extends Controller
{
    public function index()
    {
        return CustomerType::orderBy('name')->pluck('name', 'id')->map(function($name, $id){
            return ['id' => $id, 'name' => $name];
        })->values();
        // or simply: return CustomerType::all();
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|unique:customer_types,name']);
        $type = CustomerType::create([
            'name' => $request->name,
            'description' => $request->description ?? null
        ]);
        return response()->json($type, 201);
    }
}
