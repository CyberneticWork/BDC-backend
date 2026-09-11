<?php

namespace App\Http\Controllers;

use App\Services\ExcessLateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExcessLateController extends Controller
{
    public function __construct(private ExcessLateService $service)
    {
    }

    public function preview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
            'company_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        return response()->json($this->service->preview(
            (int) $request->year,
            (int) $request->month,
            $request->filled('company_id') ? (int) $request->company_id : null,
            $request->search
        ));
    }

    public function decide(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer',
            'late_date' => 'required|date',
            'action' => 'required|in:leave,reject',
            'leave_type' => 'required_if:action,leave|nullable|in:Casual Leave,Annual Leave',
            'deduct_from' => 'required_if:action,reject|nullable|in:basic,bonus',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        try {
            $row = $this->service->decide(
                (int) $request->employee_id,
                (string) $request->late_date,
                (string) $request->action,
                $request->input('leave_type'),
                $request->input('deduct_from')
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $request->action === 'leave'
                ? 'Leave applied for this late day.'
                : 'Leave rejected. Late minutes will be deducted as NoPay.',
            'decision' => $row,
        ]);
    }
}
