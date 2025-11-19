<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;


class AccountController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $accounts = Account::with('creator')->get();
        return response()->json($accounts, 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Generate account number based on account type
     */
    private function generateAccountNumber($accountType)
    {
        $ranges = [
            'ASSETS' => [1000, 1999],
            'LIABILITIES' => [2000, 2999],
            'EQUITY' => [3000, 3999],
            'INCOME' => [4000, 4999],
            'EXPENSES' => [6000, 6999],
        ];

        if (!isset($ranges[$accountType])) {
            throw new \Exception('Invalid account type');
        }

        [$min, $max] = $ranges[$accountType];

        // Find the highest account number in the range
        $latestAccount = Account::whereBetween('accountNumber', [$min, $max])
            ->orderBy('accountNumber', 'desc')
            ->first();

        $nextNumber = $latestAccount ? (int)$latestAccount->accountNumber + 1 : $min;

        if ($nextNumber > $max) {
            throw new \Exception('No available account numbers in the specified range');
        }

        return (string)$nextNumber;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'accountName' => 'required|string|max:255',
            'accountType' => 'required|string|in:EQUITY,EXPENSES,LIABILITIES,INCOME,ASSETS',
            'accountSubCategory' => 'required|string|max:255',
            'openingBalance' => 'required|numeric|min:0',
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        try {
            // Generate account number based on account type
            $accountNumber = $this->generateAccountNumber($validated['accountType']);

            $account = Account::create([
                'accountNumber' => $accountNumber,
                'accountName' => $validated['accountName'],
                'accountType' => $validated['accountType'],
                'accountSubCategory' => $validated['accountSubCategory'],
                'openingBalance' => $validated['openingBalance'],
                'created_by' => $validated['user_id'],
            ]);

            return response()->json([
                'message' => 'Account created successfully',
                'account' => $account->load('creator'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create account',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $account = Account::with('creator')->find($id);

        if (!$account) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        return response()->json($account, 200);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Account $account)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $account = Account::find($id);

        if (!$account) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'accountName' => 'sometimes|required|string|max:255',
            'accountType' => 'sometimes|required|string|in:EQUITY,EXPENSES,LIABILITIES,INCOME,ASSETS',
            'accountSubCategory' => 'sometimes|required|string|max:255',
            'openingBalance' => 'sometimes|required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $account->update($validator->validated());

            return response()->json([
                'message' => 'Account updated successfully',
                'account' => $account->load('creator'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update account',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $account = Account::find($id);

        if (!$account) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        try {
            $account->delete();

            return response()->json([
                'message' => 'Account deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete account',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
