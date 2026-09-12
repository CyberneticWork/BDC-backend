<?php

namespace App\Http\Controllers;

use App\Models\EmployeeMedicalQuota;
use App\Models\MedicalClaim;
use App\Models\employee;
use App\Services\CompanyProcessSettings;
use App\Services\LeaveNotificationService;
use App\Services\MedicalClaimService;
use App\Services\PendingPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MedicalClaimController extends Controller
{
    private function denyIfOff(employee $employee): void
    {
        if (!CompanyProcessSettings::usesMedicalClaims($employee)) {
            abort(response()->json(['message' => 'Medical claims pack is not enabled for this company.'], 422));
        }
    }

    private function forbidEmployee(Request $request): void
    {
        if ($request->user()?->role === 'employee') {
            abort(response()->json(['message' => 'Forbidden'], 403));
        }
    }

    public function portalIndex(Request $request)
    {
        $emp = app(EmployeePortalController::class)->linkedEmployee($request);
        $this->denyIfOff($emp);
        $items = MedicalClaim::where('employee_id', $emp->id)->orderByDesc('id')->limit(50)->get();
        return response()->json([
            'quota' => MedicalClaimService::quota($emp),
            'items' => $items,
        ]);
    }

    public function portalStore(Request $request)
    {
        $emp = app(EmployeePortalController::class)->linkedEmployee($request);
        $this->denyIfOff($emp);
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:1000',
            'bill' => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:5120',
            'bill_url' => 'nullable|url|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        if (!$request->hasFile('bill') && !$request->filled('bill_url')) {
            return response()->json(['message' => 'Upload a medical bill.'], 422);
        }

        $quota = MedicalClaimService::quota($emp);
        $amount = round((float) $request->amount, 2);
        if ($amount - $quota['available'] > 0.009) {
            return response()->json([
                'message' => 'Claim exceeds available medical quota (Allocated − Approved − Pending).',
                'available' => $quota['available'],
            ], 422);
        }

        $path = $request->input('bill_url');
        $billName = $request->input('bill_name') ?: 'bill';
        if ($request->hasFile('bill')) {
            $file = $request->file('bill');
            $path = app(\App\Services\FirebaseStorageService::class)->storeFile($file, 'hr/medical-claims');
            $billName = $file->getClientOriginalName();
        }

        $row = MedicalClaim::create([
            'employee_id' => $emp->id,
            'year' => (int) now()->year,
            'amount' => $amount,
            'description' => $request->description,
            'bill_path' => $path,
            'bill_name' => $billName,
            'status' => 'PENDING',
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Medical claim submitted', 'data' => $row], 201);
    }

    public function hrIndex(Request $request)
    {
        $this->forbidEmployee($request);
        if (!Schema::hasTable('medical_claims')) {
            return response()->json(['items' => []]);
        }
        $q = MedicalClaim::with('employee:id,full_name,name_with_initials,attendance_employee_no')
            ->orderByDesc('id');
        if ($status = $request->query('status')) {
            $q->where('status', strtoupper($status));
        }
        return response()->json(['items' => $q->limit(300)->get()]);
    }

    public function hrReview(Request $request, $id)
    {
        $this->forbidEmployee($request);
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:APPROVE,REJECT',
            'note' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $row = MedicalClaim::with('employee.organizationAssignment')->findOrFail($id);
        $this->denyIfOff($row->employee);
        if ($row->status !== 'PENDING') {
            return response()->json(['message' => 'This claim has already been reviewed.'], 422);
        }
        if ($request->action === 'APPROVE') {
            $quota = MedicalClaimService::quota($row->employee, (int) $row->year);
            $withoutThis = $quota['available'] + (float) $row->amount;
            if ((float) $row->amount - $withoutThis > 0.009) {
                return response()->json(['message' => 'Claim exceeds remaining medical quota.'], 422);
            }
        }
        $row->status = $request->action === 'APPROVE' ? 'APPROVED' : 'REJECTED';
        $row->review_note = $request->note;
        $row->reviewed_by = $request->user()->id;
        $row->reviewed_at = now();
        $row->save();

        if ($row->status === 'APPROVED') {
            PendingPaymentService::record($row->employee, 'medical_claim', $row->id, (float) $row->amount);
        }

        LeaveNotificationService::notifyEmployee(
            $row->employee_id,
            $row->status === 'APPROVED' ? 'Medical claim approved' : 'Medical claim rejected',
            $row->status === 'APPROVED'
                ? 'Your medical claim was approved and sent to Pending Payments.'
                : 'Your medical claim was rejected.',
            ['type' => 'medical_claim', 'id' => $row->id, 'status' => $row->status]
        );

        return response()->json(['message' => 'Medical claim updated', 'data' => $row]);
    }

    public function upsertQuota(Request $request)
    {
        $this->forbidEmployee($request);
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'year' => 'nullable|integer|min:2020|max:2100',
            'allocated_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $emp = employee::with('organizationAssignment')->findOrFail($request->employee_id);
        $this->denyIfOff($emp);
        $year = (int) ($request->year ?: now()->year);
        $row = EmployeeMedicalQuota::updateOrCreate(
            ['employee_id' => $emp->id, 'year' => $year],
            ['allocated_amount' => $request->allocated_amount, 'notes' => $request->notes]
        );
        return response()->json(['message' => 'Quota saved', 'data' => $row, 'quota' => MedicalClaimService::quota($emp, $year)]);
    }

    public function billUrl(Request $request, $id)
    {
        $this->forbidEmployee($request);
        $row = MedicalClaim::findOrFail($id);
        $path = (string) $row->bill_path;
        if ($path === '') {
            return response()->json(['message' => 'Bill file not found'], 404);
        }
        if (preg_match('#^https?://#i', $path)) {
            return response()->json([
                'url' => $path,
                'name' => $row->bill_name,
            ]);
        }
        if (!Storage::disk('public')->exists($path)) {
            return response()->json(['message' => 'Bill file not found'], 404);
        }
        return response()->json([
            'url' => url('storage/' . ltrim($path, '/')),
            'name' => $row->bill_name,
        ]);
    }
}
