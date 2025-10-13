<?php

namespace App\Http\Controllers;

use App\Models\AccountGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AccountGroupController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $accountGroups = AccountGroup::all();
            return response()->json($accountGroups, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch account groups',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'accountGroup' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $validated = $validator->validated();

            $accountGroup = AccountGroup::create([
                'accountGroup' => $validated['accountGroup'],
            ]);

            return response()->json([
                'message' => 'Account Group created successfully',
                'accountGroup' => $accountGroup,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create account group',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(AccountGroup $accountGroup)
    {
        return response()->json($accountGroup, 200);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(AccountGroup $accountGroup)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, AccountGroup $accountGroup)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AccountGroup $accountGroup)
    {
        //
    }
}
