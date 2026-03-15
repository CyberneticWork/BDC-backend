<?php



namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Models\loans;
use App\Models\Employee;

class LoanController extends Controller
{
    public function index()
    {
        return response()->json(
            loans::orderBy('created_at', 'desc')->get()
        );
    }

    public function getEmployeeByNumber(string $number)
    {
        $employee = Employee::where('attendance_employee_no', $number)->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        return response()->json($employee, 200);
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
            'schedule' => 'nullable|array',
            'installment_count' => 'nullable|integer|min:1',
            'deduct_from' => 'required|in:basic,bonus', // new one
        ]);

        
        $loanAmount = (float) $validated['loan_amount'];
        $installmentAmount = (float) $validated['installment_amount'];
        $schedule = $validated['schedule'] ?? null;

        if (is_array($schedule) && count($schedule) > 0) {
            $totalInstallments = count($schedule);
        } else {
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
        $loan->schedule = $schedule;
        $loan->deduct_from = $validated['deduct_from']; // Basic or Bonus 

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
use Illuminate\Support\Facades\Schema;
use App\Models\loans;
use App\Models\Employee;

class LoanController extends Controller
{
   
    public function index()
    {
        return response()->json(
            loans::orderBy('created_at', 'desc')->get()
        );
    }

  
    public function getEmployeeByNumber(string $number)
{
    $employee = Employee::where('attendance_employee_no', $number)->first();

    if (!$employee) {
        return response()->json(['message' => 'Employee not found'], 404);
    }

    return response()->json($employee, 200);
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
            'schedule' => 'nullable|array',
            'installment_count' => 'nullable|integer|min:1',
            
        ]);

        $loanAmount = (float) $validated['loan_amount'];
        $installmentAmount = (float) $validated['installment_amount'];
        $schedule = $validated['schedule'] ?? null;

        // ✅ If schedule exists, installment_count = schedule rows count
        if (is_array($schedule) && count($schedule) > 0) {
            $totalInstallments = count($schedule);
        } else {
            // fallback count (old logic)
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

        // ✅ Save schedule (array -> json) (make sure model has casts)
        $loan->schedule = $schedule;

        $loan->save();

        return response()->json([
            'message' => 'Loan created successfully',
            'loan' => $loan
        ], 201);
    }
}


*/