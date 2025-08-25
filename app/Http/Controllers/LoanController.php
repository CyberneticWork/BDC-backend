<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\loans;
use App\Models\employee;
use Illuminate\Validation\Rule;

class LoanController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(loans::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'loan_amount' => 'required|numeric|min:0',
            'installment_amount' => 'required|numeric|min:0',
            'interest_rate_per_annum' => 'nullable|numeric|min:0',
            'start_from' => 'required|date',
            'with_interest' => 'boolean',
        ]);
        
        $loanAmount = $validated['loan_amount'];
        $installmentAmount = $validated['installment_amount'];
        
        // Calculate number of full installments
        $fullInstallments = floor($loanAmount / $installmentAmount);
        
        // Calculate if there's a remainder for the last payment
        $remainder = $loanAmount % $installmentAmount;
        
        // Total installment count (add 1 if there's a remainder)
        $totalInstallments = $fullInstallments + ($remainder > 0 ? 1 : 0);
        
        $loan = new Loans();
        $loan->loan_id = 'LOAN-' . time();
        $loan->employee_id = $validated['employee_id'];
        $loan->loan_amount = $loanAmount;
        $loan->installment_amount = $installmentAmount;
        $loan->start_from = $validated['start_from'];
        $loan->interest_rate_per_annum = $validated['interest_rate_per_annum'] ?? 0;
        $loan->with_interest = $validated['with_interest'] ?? false;
        $loan->installment_count = $totalInstallments;
        $loan->status = 'active';
        $loan->save();
        
        // Store the remainder in a separate field or in a metadata JSON column
        // If you don't have such fields, you could add them with a migration
        
        return response()->json([
            'message' => 'Loan created successfully',
            'loan' => $loan,
            'last_installment_amount' => $remainder > 0 ? $remainder : $installmentAmount
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $loan = loans::findOrFail($id);
        return response()->json($loan);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    { /* ... */
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    { /* ... */
    }

    /**
     * Get employee details by employee number.
     */
    public function getEmployeeByNumber($number)
    {
        $employee = employee::where('attendance_employee_no', $number)->first();
        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }
        return response()->json([
            'id' => $employee->id,
            'full_name' => $employee->full_name,
        ]);
    }
}
