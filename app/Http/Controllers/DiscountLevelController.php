<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DiscountLevel;
use Illuminate\Support\Facades\Validator;

class DiscountLevelController extends Controller
{
    public function index()
    {
        $levels = DiscountLevel::with('creator:id,name')->get();
        return response()->json(['data' => $levels], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'date' => 'required|date',
            'days' => 'required|integer|min:0',
            'value' => 'required|numeric',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['created_by'] = $request->user() ? $request->user()->id : null;

        $level = DiscountLevel::create($data);
        return response()->json(['data' => $level], 201);
    }

    public function show($id)
    {
        $level = DiscountLevel::with('creator:id,name')->find($id);
        if (!$level) {
            return response()->json(['message' => 'Discount level not found.'], 404);
        }
        return response()->json(['data' => $level], 200);
    }

    public function update(Request $request, $id)
    {
        $level = DiscountLevel::find($id);
        if (!$level) {
            return response()->json(['message' => 'Discount level not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'date' => 'sometimes|required|date',
            'days' => 'sometimes|required|integer|min:0',
            'value' => 'sometimes|required|numeric',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $level->update($validator->validated());
        return response()->json(['data' => $level], 200);
    }

    public function destroy($id)
    {
        $level = DiscountLevel::find($id);
        if (!$level) {
            return response()->json(['message' => 'Discount level not found.'], 404);
        }

        $level->delete();
        return response()->json(null, 204);
    }
}
