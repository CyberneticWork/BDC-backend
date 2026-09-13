<?php

namespace App\Http\Controllers;

use App\Models\PendingPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class PendingPaymentController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()?->role === 'employee') {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (!Schema::hasTable('pending_payments')) {
            return response()->json(['items' => []]);
        }
        $q = PendingPayment::with('employee:id,full_name,name_with_initials,attendance_employee_no')
            ->orderByDesc('id');
        if ($status = $request->query('status')) {
            $q->where('status', strtoupper($status));
        }
        return response()->json(['items' => $q->limit(400)->get()]);
    }

    public function markPaid(Request $request, $id)
    {
        if ($request->user()?->role === 'employee') {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $validator = Validator::make($request->all(), [
            'note' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $row = PendingPayment::findOrFail($id);
        if ($row->status === 'PAID') {
            return response()->json(['message' => 'Already marked paid.'], 422);
        }
        $row->status = 'PAID';
        $row->paid_by = $request->user()->id;
        $row->paid_at = now();
        $row->note = $request->note;
        $row->save();
        return response()->json(['message' => 'Marked as paid', 'data' => $row]);
    }
}
