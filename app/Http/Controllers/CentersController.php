<?php

namespace App\Http\Controllers;

use App\Models\centers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CentersController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $centers = centers::with('creator')->get();
        return response()->json($centers);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'created_by' => 'required|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $centers = centers::create($request->all());
        return response()->json($centers, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $center = centers::with('creator')->findOrFail($id);
        return response()->json($center);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $center = centers::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'created_by' => 'integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $center->update($request->all());
        return response()->json($center);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $center = centers::findOrFail($id);
        $center->delete();
        return response()->json(null, 204);
    }
}
