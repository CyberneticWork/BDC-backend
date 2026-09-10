<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\loans;
use App\Models\employee;
use App\Services\LoanReportService;
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
    public function __construct(protected LoanReportService $loanReportService)
    {
    }

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

    public function update(Request $request, $id)
    {
        $loan = loans::findOrFail($id);

        $validated = $request->validate([
            'loan_amount'             => 'sometimes|numeric|min:0.01',
            'installment_amount'      => 'sometimes|numeric|min:0.01',
            'interest_rate_per_annum' => 'sometimes|numeric|min:0',
            'start_from'              => 'sometimes|date',
            'deduct_from'             => 'sometimes|in:basic,bonus,split',
            'installment_deduct_from' => 'sometimes|in:basic,bonus',
            'interest_deduct_from'    => 'sometimes|in:basic,bonus',
            'status'                  => 'sometimes|in:active,completed,cancelled',
        ]);

        $validated = array_merge($validated, $this->normalizeDeductTargets($validated, $loan));

        $loan->update($validated);

        return response()->json(['message' => 'Loan updated successfully', 'loan' => $loan]);
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
            'deduct_from' => 'nullable|in:basic,bonus,split',
            'installment_deduct_from' => 'required|in:basic,bonus',
            'interest_deduct_from' => 'required|in:basic,bonus',
        ]);

        $validated = array_merge($validated, $this->normalizeDeductTargets($validated));

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
            $loan->installment_deduct_from = $validated['installment_deduct_from'];
            $loan->interest_deduct_from = $validated['interest_deduct_from'];
            $loan->deduct_basic_amount = $validated['deduct_basic_amount'] ?? null;
            $loan->deduct_bonus_amount = $validated['deduct_bonus_amount'] ?? null;

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

    /**
     * Request to skip a loan installment month (needs higher approval).
     */
    public function requestSkip(Request $request, $id)
    {
        $loan = loans::findOrFail($id);
        if ($loan->status !== 'active') {
            return response()->json(['message' => 'Only active loans can skip installments'], 422);
        }

        $validated = $request->validate([
            'installment_no' => 'required|integer|min:1',
            'reason' => 'required|string|max:500',
        ]);

        $schedule = is_array($loan->schedule) ? $loan->schedule : [];
        $found = false;
        foreach ($schedule as &$row) {
            $no = (int) ($row['no'] ?? $row['installment_no'] ?? 0);
            if ($no !== (int) $validated['installment_no']) {
                continue;
            }
            $found = true;
            $status = strtolower((string) ($row['skip_status'] ?? ''));
            if (!empty($row['skipped']) || $status === 'approved') {
                return response()->json(['message' => 'This installment is already skipped'], 422);
            }
            if ($status === 'pending_approval') {
                return response()->json(['message' => 'Skip request already pending approval'], 422);
            }
            $row['skip_status'] = 'pending_approval';
            $row['skip_reason'] = $validated['reason'];
            $row['skip_requested_at'] = now()->toDateTimeString();
            break;
        }
        unset($row);

        if (!$found) {
            return response()->json(['message' => 'Installment not found in schedule'], 404);
        }

        $loan->schedule = array_values($schedule);
        $loan->save();

        return response()->json([
            'message' => 'Skip request submitted. Waiting for higher approval.',
            'loan' => $loan->fresh('employee'),
        ]);
    }

    /**
     * Approve or reject a skip request (higher approval).
     * On approve: mark skipped and defer this + later installments by 1 month.
     */
    public function decideSkip(Request $request, $id)
    {
        $loan = loans::findOrFail($id);

        $validated = $request->validate([
            'installment_no' => 'required|integer|min:1',
            'action' => 'required|in:approve,reject',
            'approver_note' => 'nullable|string|max:500',
        ]);

        $schedule = is_array($loan->schedule) ? $loan->schedule : [];
        $targetNo = (int) $validated['installment_no'];
        $found = false;

        foreach ($schedule as $idx => &$row) {
            $no = (int) ($row['no'] ?? $row['installment_no'] ?? 0);
            if ($no !== $targetNo) {
                continue;
            }
            $found = true;
            $status = strtolower((string) ($row['skip_status'] ?? ''));
            if ($status !== 'pending_approval') {
                return response()->json(['message' => 'No pending skip request for this installment'], 422);
            }

            if ($validated['action'] === 'reject') {
                $row['skip_status'] = 'rejected';
                $row['skip_approver_note'] = $validated['approver_note'] ?? null;
                $row['skip_decided_at'] = now()->toDateTimeString();
            } else {
                $priorReason = $row['skip_reason'] ?? null;
                // Higher approval: defer this + later installments by 1 month (no deduction this month)
                for ($j = $idx; $j < count($schedule); $j++) {
                    $schedule[$j] = $this->shiftScheduleRowByMonths($schedule[$j], 1);
                }
                $schedule[$idx]['skip_status'] = 'approved_deferred';
                $schedule[$idx]['skipped'] = false;
                $schedule[$idx]['skip_approver_note'] = $validated['approver_note'] ?? null;
                $schedule[$idx]['skip_decided_at'] = now()->toDateTimeString();
                $schedule[$idx]['skip_reason'] = $priorReason;
            }
            break;
        }
        unset($row);

        if (!$found) {
            return response()->json(['message' => 'Installment not found in schedule'], 404);
        }

        $loan->schedule = array_values($schedule);
        $loan->save();

        return response()->json([
            'message' => $validated['action'] === 'approve'
                ? 'Skip approved. Installment deferred by one month.'
                : 'Skip request rejected.',
            'loan' => $loan->fresh('employee'),
        ]);
    }

    private function shiftScheduleRowByMonths(array $row, int $months): array
    {
        $iso = $row['dueDateIso'] ?? $row['due_date_iso'] ?? null;
        $due = $iso ?: ($row['dueDate'] ?? $row['due_date'] ?? null);
        if (!$due) {
            return $row;
        }

        try {
            $dt = new \DateTime((string) $due);
            $dt->modify(($months >= 0 ? '+' : '') . $months . ' month');
            $row['dueDateIso'] = $dt->format('Y-m-d');
            $row['dueDate'] = $dt->format('d M Y');
            $row['due_date'] = $row['dueDateIso'];
        } catch (\Throwable $e) {
            // keep original
        }

        return $row;
    }

    /**
     * Detailed loan report for export (all loans or single employee)
     */
    public function report(Request $request)
    {
        $employeeNo = $request->query('employee_no');
        $loanId = $request->query('loan_id');

        if ($employeeNo) {
            $employee = employee::where('attendance_employee_no', $employeeNo)->first();
            if (!$employee) {
                return response()->json(['message' => 'Employee not found'], 404);
            }
        }

        $report = $this->loanReportService->buildReport($employeeNo, $loanId);

        return response()->json($report);
    }

    /**
     * Capital installment and interest can each be taken from basic or bonus.
     */
    private function normalizeDeductTargets(array $input, ?loans $loan = null): array
    {
        $installmentFrom = strtolower(trim((string) ($input['installment_deduct_from']
            ?? $loan?->installment_deduct_from
            ?? '')));
        $interestFrom = strtolower(trim((string) ($input['interest_deduct_from']
            ?? $loan?->interest_deduct_from
            ?? '')));

        if (!in_array($installmentFrom, ['basic', 'bonus'], true)) {
            $legacy = strtolower(trim((string) ($input['deduct_from'] ?? $loan?->deduct_from ?? 'bonus')));
            $installmentFrom = $legacy === 'basic' ? 'basic' : 'bonus';
        }
        if (!in_array($interestFrom, ['basic', 'bonus'], true)) {
            $interestFrom = $installmentFrom;
        }

        $same = $installmentFrom === $interestFrom;

        return [
            'installment_deduct_from' => $installmentFrom,
            'interest_deduct_from' => $interestFrom,
            'deduct_from' => $same ? $installmentFrom : 'split',
            'deduct_basic_amount' => ($installmentFrom === 'basic' || $interestFrom === 'basic') ? 1 : 0,
            'deduct_bonus_amount' => ($installmentFrom === 'bonus' || $interestFrom === 'bonus') ? 1 : 0,
        ];
    }
}

