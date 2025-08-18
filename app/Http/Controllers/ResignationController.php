<?php

namespace App\Http\Controllers;

use App\Models\loans;
use App\Models\over_time;
use App\Models\Resignation;
use App\Models\ResignationDocument;
use App\Models\employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class ResignationController extends Controller
{
    public function index(Request $request)
    {
        $query = Resignation::with(['employee', 'documents']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $resignations = $query->orderBy('created_at', 'desc')->paginate(10);

        return response()->json($resignations);
    }

   // In the store method of ResignationController.php
public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'employee_id' => 'required|exists:employees,id',
        'resigning_date' => 'required|date',
        'last_working_day' => 'required|date|after_or_equal:resigning_date',
        'resignation_reason' => 'required|string|min:10',
        'documents' => 'sometimes|array',
        'documents.*' => 'file|mimes:pdf,doc,docx,jpg,png|max:5120' 
    ]);

    if ($validator->fails()) {
        return response()->json($validator->errors(), 422);
    }

    // Check for duplicate resignation
    $existingResignation = Resignation::where('employee_id', $request->employee_id)
        ->where('status', 'pending')
        ->first();

    if ($existingResignation) {
        return response()->json([
            'message' => 'This employee already has a pending resignation request'
        ], 422);
    }

    // Validate file sizes before processing
    if ($request->hasFile('documents')) {
        foreach ($request->file('documents') as $document) {
            if ($document->getSize() > 5120 * 1024) {
                return response()->json([
                    'documents' => ['One or more files exceed the 5MB size limit']
                ], 422);
            }
        }
    }

    // Rest of your existing store method...
    $employee = employee::findOrFail($request->employee_id);

    $resignation = Resignation::create([
        'employee_id' => $request->employee_id,
        'attendance_employee_no' => $employee->attendance_employee_no,
        'employee_name' => $employee->full_name,
        'resigning_date' => $request->resigning_date,
        'last_working_day' => $request->last_working_day,
        'resignation_reason' => $request->resignation_reason,
        'status' => 'pending'
    ]);

    // Handle document uploads
    if ($request->hasFile('documents')) {
        foreach ($request->file('documents') as $document) {
            $path = $document->store('employee/resignations', 'public');

            ResignationDocument::create([
                'resignation_id' => $resignation->id,
                'document_name' => $document->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $document->getClientMimeType(),
                'file_size' => $document->getSize()
            ]);
        }
    }

    return response()->json($resignation->load('documents'), 201);
}

    public function show($id)
    {
        $resignation = Resignation::with(['employee', 'documents', 'processedBy'])->findOrFail($id);
        return response()->json($resignation);
    }

    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:approved,rejected',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $resignation = Resignation::findOrFail($id);

        $resignation->update([
            'status' => $request->status,
            'notes' => $request->notes,
            'processed_by' => optional(Auth::user())->id,
            'processed_at' => now()
        ]);


        if (
            loans::where('employee_id', $resignation->employee_id)
                ->where('status', 'active')
                ->exists()
        ) {
            return response()->json(['message' => 'Employee has active loans. Cannot approve resignation.'], 422);
        }
        $employee = employee::findOrFail($resignation->employee_id);
        $employee->update(['is_active' => false]);




        return response()->json($resignation);

    }

    public function testFunction($id)
    {
        $ot = over_time::where('employee_id', $id)
            ->where('status', 'approved')
            ->get('afternoon_ot');
        return $ot;
    }

    public function uploadDocuments(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'documents' => 'required|array',
            'documents.*' => 'file|mimes:pdf,doc,docx,jpg,png|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $resignation = Resignation::findOrFail($id);

        $uploadedDocuments = [];
        foreach ($request->file('documents') as $document) {
            $path = $document->store('employee/resignations', 'public');

            $uploadedDocument = ResignationDocument::create([
                'resignation_id' => $resignation->id,
                'document_name' => $document->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $document->getClientMimeType(),
                'file_size' => $document->getSize()
            ]);

            $uploadedDocuments[] = $uploadedDocument;
        }

        return response()->json($uploadedDocuments, 201);
    }

    public function destroyDocument($resignationId, $documentId)
    {
        $document = ResignationDocument::where('resignation_id', $resignationId)
            ->findOrFail($documentId);

        // Delete file from storage
        Storage::delete(str_replace('/storage', 'public', $document->file_path));

        $document->delete();

        return response()->json(['message' => 'Document deleted successfully']);
    }
}
