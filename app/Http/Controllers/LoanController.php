<?php



namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\loans;

class LoanController extends Controller
{
    public function index()
    {
        return response()->json(loans::orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'loan_id' => 'nullable|string|max:100',
            'employee_id' => 'required|exists:employees,id',
            'loan_amount' => 'required|numeric|min:0.01',
            'installment_amount' => 'required|numeric|min:0.01',
            'interest_rate_per_annum' => 'nullable|numeric|min:0',
            'start_from' => 'required|date',
            'with_interest' => 'boolean',
            'schedule' => 'nullable|array', // ✅ schedule array from frontend
            'installment_count' => 'nullable|integer|min:1',
        ]);

        $loanAmount = (float) $validated['loan_amount'];
        $installmentAmount = (float) $validated['installment_amount'];
        $schedule = $validated['schedule'] ?? null;

        // ✅ If schedule exists, installment_count = schedule rows count
        if (is_array($schedule) && count($schedule) > 0) {
            $totalInstallments = count($schedule);
        } else {
            // fallback count (old)
            $full = (int) floor($loanAmount / $installmentAmount);
            $rem = fmod($loanAmount, $installmentAmount);
            $totalInstallments = $full + ($rem > 0 ? 1 : 0);
        }

        $loan = new loans();
        $loan->loan_id = $validated['loan_id'] ?? ('LOAN-' . time());
        $loan->employee_id = (int) $validated['employee_id'];
        $loan->loan_amount = $loanAmount;
        $loan->installment_amount = $installmentAmount;
        $loan->start_from = $validated['start_from'];
        $loan->interest_rate_per_annum = (float) ($validated['interest_rate_per_annum'] ?? 0);
        $loan->with_interest = (bool) ($validated['with_interest'] ?? false);

        $loan->installment_count = (int) ($validated['installment_count'] ?? $totalInstallments);
        $loan->status = 'active';

        // ✅ Save schedule (cast array -> json automatically)
        $loan->schedule = $schedule;

        $loan->save();

        return response()->json([
            'message' => 'Loan created successfully',
            'loan' => $loan
        ], 201);
    }
}



/*
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\loans;
use App\Models\employee;

class LoanController extends Controller
{
    public function index()
    {
        return response()->json(loans::orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'loan_id' => 'nullable|string|max:100',          // frontend එවන්නේ නම්
            'employee_id' => 'required|exists:employees,id',
            'loan_amount' => 'required|numeric|min:0.01',
            'installment_amount' => 'required|numeric|min:0.01',
            'interest_rate_per_annum' => 'nullable|numeric|min:0',
            'start_from' => 'required|date',
            'with_interest' => 'boolean',

            // ✅ ADD THIS
            'schedule' => 'nullable|array',

            // (optional) frontend එවන්නේ නම්
            'installment_count' => 'nullable|integer|min:1',
        ]);

        $loanAmount = (float) $validated['loan_amount'];
        $installmentAmount = (float) $validated['installment_amount'];

        // ✅ If schedule is provided, use it to set installment_count
        $schedule = $validated['schedule'] ?? null;

        if (is_array($schedule) && count($schedule) > 0) {
            $totalInstallments = count($schedule);
            $remainder = 0; // not needed when schedule exists
        } else {
            // Fallback calculation (old logic)
            $fullInstallments = (int) floor($loanAmount / $installmentAmount);
            $remainder = fmod($loanAmount, $installmentAmount);
            $totalInstallments = $fullInstallments + ($remainder > 0 ? 1 : 0);
        }

        $loan = new loans();
        $loan->loan_id = $validated['loan_id'] ?? ('LOAN-' . time());
        $loan->employee_id = $validated['employee_id'];
        $loan->loan_amount = $loanAmount;
        $loan->installment_amount = $installmentAmount;
        $loan->start_from = $validated['start_from'];
        $loan->interest_rate_per_annum = (float) ($validated['interest_rate_per_annum'] ?? 0);
        $loan->with_interest = (bool) ($validated['with_interest'] ?? false);

        // ✅ prioritize frontend installment_count if provided, else derived
        $loan->installment_count = (int) ($validated['installment_count'] ?? $totalInstallments);

        $loan->status = 'active';

        // ✅ SAVE SCHEDULE HERE
        $loan->schedule = $schedule;

        $loan->save();

        return response()->json([
            'message' => 'Loan created successfully',
            'loan' => $loan,
            'last_installment_amount' => $remainder > 0 ? $remainder : $installmentAmount
        ], 201);
    }

    public function show(string $id)
    {
        $loan = loans::findOrFail($id);
        return response()->json($loan);
    }

    public function update(Request $request, string $id)
    {
        //
    }

    public function destroy(string $id)
    {
        //
    }

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
*/


