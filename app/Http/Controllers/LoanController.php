<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\loans;
use App\Models\employee; // Model එකේ නම 'employee' ලෙස ඔබ ලබාදුන් නිසා
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
    /**
     * සියලුම ලෝන් විස්තර ලබා ගැනීම
     */
    public function index()
    {
        return response()->json(
            loans::with('employee')->orderBy('created_at', 'desc')->get()
        );
    }

    public function getByEmployeeNo(string $employeeNo)
    {
        $employee = employee::where('attendance_employee_no', $employeeNo)->first();
        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }
        return response()->json(
            loans::where('employee_id', $employee->id)->orderBy('created_at', 'desc')->get()
        );
    }

    /**
     * Employee Number එකෙන් Employee ගේ නම සහ ID එක සෙවීම
     */
    public function getEmployeeByNumber(string $number)
    {
        $employee = employee::where('attendance_employee_no', $number)->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        return response()->json($employee, 200);
    }

    /**
     * නව Loan එකක් ඇතුළත් කිරීම
     */
    public function store(Request $request)
    {
        // 1. Validation - මෙතනදී 'attendance_employee_no' එක අනිවාර්යයි
        $validated = $request->validate([
            'loan_id' => 'nullable|string|max:100',
            'attendance_employee_no' => 'required|exists:employees,attendance_employee_no',
            'loan_amount' => 'required|numeric|min:0.01',
            'installment_amount' => 'required|numeric|min:0.01',
            'interest_rate_per_annum' => 'nullable|numeric|min:0',
            'start_from' => 'required|date',
            'with_interest' => 'required|boolean',
            'schedule' => 'nullable|array',
            'installment_count' => 'nullable|integer|min:1',
            'deduct_from' => 'required|in:basic,bonus',
        ]);

        try {
            // 2. Attendance No එකෙන් DB එකේ තියෙන Employee ID එක හොයාගන්නවා
            $employee = employee::where('attendance_employee_no', $validated['attendance_employee_no'])->first();

            // 3. Installment Count එක calculate කිරීම (Frontend එකෙන් එව්වේ නැත්නම්)
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

            // 4. Database එකට Save කිරීම
            $loan = new loans();
            $loan->loan_id = $validated['loan_id'] ?? ('LOAN-' . time());
            
            // වැදගත්ම වෙනස: Attendance No වෙනුවට නියම Database ID එක මෙතනට දානවා
            $loan->employee_id = $employee->id; 
            
            $loan->loan_amount = $loanAmount;
            $loan->installment_amount = $installmentAmount;
            $loan->start_from = $validated['start_from'];
            $loan->interest_rate_per_annum = (float) ($validated['interest_rate_per_annum'] ?? 0);
            $loan->with_interest = (bool) $validated['with_interest'];
            $loan->installment_count = (int) ($validated['installment_count'] ?? $totalInstallments);
            $loan->status = 'active';
            $loan->schedule = $schedule; // JSON cast එක Model එකේ තිබිය යුතුයි
            $loan->deduct_from = $validated['deduct_from'];

            $loan->save();

            return response()->json([
                'message' => 'Loan created successfully',
                'loan' => $loan
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error saving loan',
                'error' => $e->getMessage()
            ], 500);
        }
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

*/
