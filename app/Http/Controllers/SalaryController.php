<?php

namespace App\Http\Controllers;

use App\Models\SalaryProcessAudit;
use App\Models\salary_process;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\DB;

class SalaryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $salary = salary_process::all();
        return response()->json($salary, 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'basic_salary' => 'required|numeric|min:0',
            'increment_active' => 'boolean',
            'increment_value' => 'nullable|numeric|min:0',
            'increment_effected_date' => 'nullable|date',
            'ot_morning' => 'nullable|numeric|min:0',
            'ot_evening' => 'nullable|numeric|min:0',
            'enable_epf_etf' => 'boolean',
            'br1' => 'boolean',
            'br2' => 'boolean',
            'stamp' => 'boolean',
            'total_loan_amount' => 'nullable|numeric|min:0',
            'installment_count' => 'nullable|integer|min:0',
            'installment_amount' => 'nullable|numeric|min:0',
            'approved_no_pay_days' => 'integer|min:0',
            'status' => 'required|in:pending,processed,issued,hold',
            'month' => 'required|string|max:2',
            'year' => 'required|string|max:4',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            // 1. Salary Record එක හොයනවා.
            // $id කියන්නේ Frontend එකෙන් එවන Employee ID එකයි.
            $salaryRecord = salary_process::where('employee_id', $id)
                ->where('month', $request->month)
                ->where('year', $request->year)
                ->first();

            $originalData = [];

            // 2. Record එකක් නැත්නම් Employee ටේබල් එකෙන් විස්තර අරගෙන අලුත් එකක් හදන්න (Upsert)
            if (!$salaryRecord) {
               // Database එකේ ඇත්තටම තියෙන Columns (attendance_employee_no සහ full_name) විතරක් Select කරනවා
                $empDetails = \Illuminate\Support\Facades\DB::table('employees as e')
                    ->select(
                        'e.attendance_employee_no', 'e.full_name',
                        'c.name as company_name',
                        'd.name as department_name',
                        'sd.name as sub_department_name'
                    )
                    ->leftJoin('organization_assignments as oa', 'e.organization_assignment_id', '=', 'oa.id')
                    ->leftJoin('companies as c', 'oa.company_id', '=', 'c.id')
                    ->leftJoin('departments as d', 'oa.department_id', '=', 'd.id')
                    ->leftJoin('sub_departments as sd', 'oa.sub_department_id', '=', 'sd.id')
                    ->where('e.id', $id)
                    ->first();

                if (!$empDetails) {
                    return response()->json(['message' => 'Employee details not found!'], 404);
                }

                $salaryRecord = new salary_process();
                $salaryRecord->employee_id = $id;
                $salaryRecord->employee_no = $empDetails->attendance_employee_no ?? '-';
                $salaryRecord->full_name = $empDetails->full_name ?? 'Unknown';
                
                $salaryRecord->company_name = $empDetails->company_name ?? '-';
                $salaryRecord->department_name = $empDetails->department_name ?? '-';
                $salaryRecord->sub_department_name = $empDetails->sub_department_name ?? null;
            } else {
                // 
                $originalData = $salaryRecord->toArray();
            }

            // Get the current data for calculations
            $basicSalary = (float) $request->basic_salary;

            // Calculate BR allowance
            $brAllowance = 0;
            if ($request->br1 && $request->br2) {
                $brAllowance = 3500; $brStatus = 'Both BR1 and BR2';
            } elseif ($request->br1) {
                $brAllowance = 1000; $brStatus = 'BR1 Only';
            } elseif ($request->br2) {
                $brAllowance = 2500; $brStatus = 'BR2 Only';
            } else {
                $brStatus = 'None';
            }

            $year = $request->year;
            $month = $request->month;
            $totalDaysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
            $workingDaysInMonth = $totalDaysInMonth - 8; 

            $perDaySalary = $basicSalary / $workingDaysInMonth;
            $noPayDeduction = $request->approved_no_pay_days * $perDaySalary;
            $adjustedBasic = $basicSalary - $noPayDeduction;

            // Allowances
            $allowances = $request->allowances ?? [];
            if (is_string($allowances)) {
                $decoded = json_decode($allowances, true);
                $allowances = is_array($decoded) ? $decoded : [];
            }
            $totalAllowances = 0;
            foreach ($allowances as $allowance) {
                $totalAllowances += (float)($allowance['amount'] ?? 0);
            }

            // EPF/ETF
            $epfEtfBase = $adjustedBasic + $totalAllowances;
            $epfEmployeeDeduction = $request->enable_epf_etf ? $epfEtfBase * 0.08 : 0;
            $epfEmployerContribution = $request->enable_epf_etf ? $epfEtfBase * 0.12 : 0;
            $etfEmployerContribution = $request->enable_epf_etf ? $epfEtfBase * 0.03 : 0;

            // Deductions
            $deductions = $request->deductions ?? [];
            if (is_string($deductions)) {
                $decoded = json_decode($deductions, true);
                $deductions = is_array($decoded) ? $decoded : [];
            }
            $totalFixedDeductions = 0;
            foreach ($deductions as $deduction) {
                $totalFixedDeductions += (float)($deduction['amount'] ?? 0);
            }

            $stampValue = $request->stamp ? 25 : 0;
            $morningOtFees = (float) $request->ot_morning;
            $nightOtFees = (float) $request->ot_evening;
            /*
            $grossSalary = $epfEtfBase + $morningOtFees + $nightOtFees;
            $totalDeductions = $totalFixedDeductions + ((float)($request->installment_amount ?? 0)) + $epfEmployeeDeduction;
            $netSalary = $grossSalary - $totalDeductions - $stampValue;

            $updatedSalaryBreakdown = [
                'basic_salary' => $basicSalary,
                'br_allowance' => $brAllowance,
                'ot_morning_fees' => $morningOtFees,
                'ot_night_fees' => $nightOtFees,
                'adjusted_basic' => $adjustedBasic,
                'per_day_salary' => $perDaySalary,
                'no_pay_deduction' => $noPayDeduction,
                'total_allowances' => $totalAllowances,
                'epf_etf_base' => $epfEtfBase,
                'epf_employee_deduction' => $epfEmployeeDeduction,
                'epf_employer_contribution' => $epfEmployerContribution,
                'etf_employer_contribution' => $etfEmployerContribution,
                'total_fixed_deductions' => $totalFixedDeductions,
                'loan_installment' => (float)($request->installment_amount ?? 0),
                'gross_salary' => $grossSalary,
                'total_deductions' => $totalDeductions,
                'stamp' => $stampValue,
                'net_salary' => $netSalary
            ];
           */
            
            $frontendBreakdown = is_string($request->salary_breakdown) 
                ? json_decode($request->salary_breakdown, true) 
                : ($request->salary_breakdown ?? []);

            // Basic or Bonus 
            $loanDeductFrom = $frontendBreakdown['loan_deduct_from'] ?? 'bonus';

            $grossSalary = $epfEtfBase + $morningOtFees + $nightOtFees;
            $totalDeductions = $totalFixedDeductions + ((float)($request->installment_amount ?? 0)) + $epfEmployeeDeduction;
            $netSalary = $grossSalary - $totalDeductions - $stampValue;

            // පරණ Record එකේ තිබ්බ Loan Settings ගන්නවා
            $oldBreakdown = is_string($salaryRecord->salary_breakdown) ? json_decode($salaryRecord->salary_breakdown, true) : ($salaryRecord->salary_breakdown ?? []);
            $loanDeductFrom = $oldBreakdown['loan_deduct_from'] ?? 'bonus';
            $loanInterest = $oldBreakdown['loan_interest'] ?? 0;
            $newInstallment = (float)($request->installment_amount ?? 0);
            $newPrincipal = $newInstallment > $loanInterest ? $newInstallment - $loanInterest : $newInstallment;

            $updatedSalaryBreakdown = [
                'basic_salary' => $basicSalary,
                'br_allowance' => $brAllowance,
                'ot_morning_fees' => $morningOtFees,
                'ot_night_fees' => $nightOtFees,
                'adjusted_basic' => $adjustedBasic,
                'per_day_salary' => $perDaySalary,
                'no_pay_deduction' => $noPayDeduction,
                'total_allowances' => $totalAllowances,
                'epf_etf_base' => $epfEtfBase,
                'epf_employee_deduction' => $epfEmployeeDeduction,
                'epf_employer_contribution' => $epfEmployerContribution,
                'etf_employer_contribution' => $etfEmployerContribution,
                'total_fixed_deductions' => $totalFixedDeductions,
                
                // ==========================================
                // වෙනස් කළ කොටස: Interest අයින් කළා. Frontend එකෙන් එවන 'basic' හෝ 'bonus' ගන්නවා.
                'loan_installment' => $newInstallment,
                'loan_principal'   => $newInstallment, // Interest නැති නිසා සම්පූර්ණ ගාණම Principal එක
                'loan_interest'    => 0, // පොලිය අයින් කර ඇති නිසා 0 කරනවා
                'loan_deduct_from' => $loanDeductFrom, // React  (Basic/Bonus)
                // ==========================================
                
                'gross_salary' => $grossSalary,
                'total_deductions' => $totalDeductions,
                'stamp' => $stampValue,
                'net_salary' => $netSalary
            ];


            $updatedData = [
                'basic_salary' => $request->basic_salary,
                'increment_active' => (bool)$request->increment_active,
                'increment_value' => $request->increment_value,
                'increment_effected_date' => $request->increment_effected_date,
                'ot_morning' => $request->ot_morning,
                'ot_evening' => $request->ot_evening,
                'enable_epf_etf' => (bool)$request->enable_epf_etf,
                'br1' => (bool)$request->br1,
                'br2' => (bool)$request->br2,
                'br_status' => $brStatus,
                'stamp' => (bool)$request->stamp,
                'total_loan_amount' => $request->total_loan_amount ?: 0,
                'installment_count' => $request->installment_count ?: 0,
                'installment_amount' => $request->installment_amount ?: 0,
                'approved_no_pay_days' => $request->approved_no_pay_days,
                'status' => $request->status, 
                'month' => $request->month,
                'year' => $request->year,
                'salary_breakdown' => $updatedSalaryBreakdown,
                'allowances' => $allowances,
                'deductions' => $deductions,
            ];

            // 3. Save කරන කොටස (Fill & Save)
            $salaryRecord->fill($updatedData);
            $salaryRecord->save();

            // 4. Audit Log
            $changes = [];
            $trackableFields = [
                'basic_salary', 'increment_active', 'increment_value', 'increment_effected_date',
                'ot_morning', 'ot_evening', 'enable_epf_etf', 'br1', 'br2', 'stamp',
                'total_loan_amount', 'installment_count', 'installment_amount',
                'approved_no_pay_days', 'status', 'month', 'year'
            ];

            if (!empty($originalData)) {
                foreach ($trackableFields as $field) {
                    if (!$request->has($field)) continue;

                    $requestValue = $request->input($field);
                    if (in_array($field, ['increment_active', 'enable_epf_etf', 'br1', 'br2', 'stamp'])) {
                        $requestValue = (bool)$requestValue;
                        $originalValue = (bool)($originalData[$field] ?? false);
                    } else {
                        $originalValue = $originalData[$field] ?? null;
                        if (is_numeric($requestValue) && is_numeric($originalValue)) {
                            $requestValue = (string)$requestValue;
                            $originalValue = (string)$originalValue;
                        }
                    }

                    if ($originalValue != $requestValue) {
                        $changes[$field] = ['from' => $originalValue, 'to' => $requestValue];
                    }
                }
            } else {
                $changes['new_record'] = ['summary' => 'Record was created via Edit form'];
            }

            if (Auth::check()) {
                $userId = Auth::id();
                $userName = Auth::user()->name;
            } else if ($request->has('user_id')) {
                $userId = $request->user_id;
                $user = \App\Models\User::find($userId);
                $userName = $user ? $user->name : 'Unknown User';
            } else {
                $userId = null;
                $userName = 'System (Not Authenticated)';
            }

            if (!empty($changes)) {
                SalaryProcessAudit::create([
                    'salary_process_id' => $salaryRecord->id,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'action' => 'update',
                    'changes' => $changes
                ]);
            }

            DB::commit();
            return response()->json([
                'message' => 'Salary record updated successfully',
                'data' => $salaryRecord
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error updating salary record: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        try {
            $salary = salary_process::findOrFail($id);

            // Get user info for audit trail
            if (Auth::check()) {
                $userId = Auth::id();
                $userName = Auth::user()->name;
            } else if ($request->has('user_id')) {
                // Get user info from request payload if Auth isn't available
                $userId = $request->user_id;
                // Look up the user name if possible
                $user = \App\Models\User::find($userId);
                $userName = $user ? $user->name : 'Unknown User';
            } else {
                $userId = null;
                $userName = 'System (Not Authenticated)';
            }

            // Create audit record for deletion
            SalaryProcessAudit::create([
                'salary_process_id' => $id,
                'user_id' => $userId,
                'user_name' => $userName,
                'action' => 'delete',
                'changes' => ['deleted' => true]
            ]);

            $salary->delete();
            return response()->json(['message' => 'Salary record deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting salary record: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get audit logs for a salary process
     */
    public function getAuditLogs(string $id)
    {
        try {
            $salaryRecord = salary_process::findOrFail($id);

            $auditLogs = SalaryProcessAudit::where('salary_process_id', $id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'salary_id' => $id,
                'employee_name' => $salaryRecord->full_name,
                'employee_no' => $salaryRecord->employee_no,
                'audit_logs' => $auditLogs
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error retrieving audit logs: ' . $e->getMessage()
            ], 500);
        }
    }

    public function salaryCSV(Request $request)
    {
        // Get processed salaries
        $salaries = salary_process::with(['employee', 'compensation', 'contactDetails'])
            ->where('status', 'processed')
            ->get();

            // return response()->json($salaries, 200);

        if ($salaries->isEmpty()) {
            return response()->json(['message' => 'No processed salaries found'], 404);
        }
        // return response()->json($salaries, 200);
        // CSV filename with current date
        $filename = 'CEFT_Salary_Payments_' . now()->format('Y-m-d') . '.csv';

        // Open a file handle for writing
        $handle = fopen('php://temp', 'w');

        // Add CSV headers (matching the Excel template)
        fputcsv($handle, [
            'Record Identifier',
            'Employee No',
            'Value Date',
            'Payment Method Name',
            'Debit Account No.',
            'Payable Currency',
            'Payment Amount',
            'Beneficiary Code (Request)',
            'Beneficiary Name',
            'Beneficiary Account No',
            'Beneficiary Bank Code',
            'Beneficiary Bank Branch Code',
            'Corporate Ref No',
            'Payment Instructions 1',
            'Payment Instructions 2',
            'Remarks',
            'Remittance Code',
            'Beneficiary Advise Dispatch Mode',
            'Phone/Mobile No',
            'Email'
        ]);

        // Add data rows
        foreach ($salaries as $salary) {
            fputcsv($handle, [
                $salary->id, // Record Identifier (Debit)
                $salary->employee_no, // Record Identifier (Debit)
                now()->format('d/m/Y'), // Value Date (current date)
                'CEFTS', // Payment Method Name
                '000123456789', // Debit Account No. (hardcoded or configurable)
                'LKR', // Payable Currency
                $salary->salary_breakdown['net_salary'], // Payment Amount
                '', // Beneficiary Code (empty)
                $salary->full_name, // Beneficiary Name
                $salary->compensation->bank_account_no, // Beneficiary Account No
                $salary->compensation->bank_code, // Beneficiary Bank Code
                $salary->compensation->branch_code, // Beneficiary Bank Branch Code
                $salary->employee->attendance_employee_no, // Corporate Ref No
                "Salary for {$salary->month}-{$salary->year}", // Payment Instructions 1
                '', // Payment Instructions 2 (empty)
                'Salary Payment', // Remarks
                '', // Remittance Code (empty)
                '', // Beneficiary Advise Dispatch Mode (empty)
                $salary->contactDetails->mobile_line, // Phone/Mobile No (empty)
                $salary->contactDetails->email  // Email (empty)
            ]);
        }

        // Reset file pointer
        rewind($handle);

        // Get CSV content
        $csv = stream_get_contents($handle);
        fclose($handle);

        // Return CSV as downloadable response
        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }
}
