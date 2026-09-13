<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\loans;
use App\Models\employee;
use App\Models\Resignation;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\ResignationDocument;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmployeePasswordSendEmail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Services\FcmPushService;
use App\Services\LeaveNotificationService;

class ResignationController extends Controller
{
    public function index(Request $request)
    {
        $query = Resignation::with([
            'employee.organizationAssignment.department',
            'employee.organizationAssignment.designation',
            'documents',
        ]);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $resignations = $query->orderBy('created_at', 'desc')->paginate(10);

        return response()->json($resignations);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'resigning_date' => 'required|date',
            'last_working_day' => 'required|date|after_or_equal:resigning_date',
            'resignation_reason' => 'required|string|min:10',
            'documents' => 'sometimes|array',
            'documents.*' => 'file|mimes:pdf,doc,docx,jpg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $existingResignation = Resignation::where('employee_id', $request->employee_id)
            ->where('status', 'pending')
            ->first();

        if ($existingResignation) {
            return response()->json([
                'message' => 'This employee already has a pending resignation request',
            ], 422);
        }

        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $document) {
                if ($document->getSize() > 5120 * 1024) {
                    return response()->json([
                        'documents' => ['One or more files exceed the 5MB size limit'],
                    ], 422);
                }
            }
        }

        $employee = employee::findOrFail($request->employee_id);

        $payload = [
            'employee_id' => $request->employee_id,
            'resigning_date' => $request->resigning_date,
            'last_working_day' => $request->last_working_day,
            'resignation_reason' => $request->resignation_reason,
            'status' => 'pending',
        ];
        if (Schema::hasColumn('resignations', 'attendance_employee_no')) {
            $payload['attendance_employee_no'] = $employee->attendance_employee_no;
        }
        if (Schema::hasColumn('resignations', 'employee_name')) {
            $payload['employee_name'] = $employee->full_name;
        }
        if (Schema::hasColumn('resignations', 'submitted_via')) {
            $payload['submitted_via'] = $request->input('submitted_via', 'hr');
        }

        $resignation = Resignation::create($payload);

        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $document) {
                $path = app(\App\Services\FirebaseStorageService::class)->storeFile($document, 'hr/resignations');

                ResignationDocument::create([
                    'resignation_id' => $resignation->id,
                    'document_name' => $document->getClientOriginalName(),
                    'file_path' => $path,
                    'file_type' => $document->getClientMimeType(),
                    'file_size' => $document->getSize(),
                ]);
            }
        }

        $created = $resignation->load('documents');
        $this->notifyHrOfRequest($employee, $created);

        return response()->json($created, 201);
    }

    public function portalIndex(Request $request)
    {
        $emp = app(EmployeePortalController::class)->linkedEmployee($request);
        $items = Resignation::with('documents')
            ->where('employee_id', $emp->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['items' => $items]);
    }

    public function portalStore(Request $request)
    {
        $emp = app(EmployeePortalController::class)->linkedEmployee($request);
        $request->merge([
            'employee_id' => $emp->id,
            'submitted_via' => 'portal',
        ]);

        return $this->store($request);
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
            'notes' => 'nullable|string',
            'last_working_day' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $resignation = Resignation::findOrFail($id);
        $status = $request->status;

        if ($status == 'approved') {
            if (
                loans::where('employee_id', $resignation->employee_id)
                    ->where('status', 'active')
                    ->exists()
            ) {
                return response()->json(['message' => 'Employee has active loans. Cannot approve resignation.'], 422);
            }
            $update = [
                'status' => $request->status,
                'notes' => $request->notes,
                'processed_by' => optional(Auth::user())->id,
                'processed_at' => now(),
            ];
            if ($request->filled('last_working_day')) {
                $update['last_working_day'] = $request->last_working_day;
            }
            $resignation->update($update);
            $employee = employee::findOrFail($resignation->employee_id);
            $employee->update(['is_active' => false]);
            LeaveNotificationService::notifyEmployee((int) $resignation->employee_id, 'Resignation approved', 'HR approved your resignation request.', [
                'type' => 'resignation',
                'resignation_id' => $resignation->id,
            ]);

            return response()->json($resignation);
        }

        if ($status == 'rejected') {
            $resignation->update([
                'status' => $request->status,
                'notes' => $request->notes,
                'processed_by' => optional(Auth::user())->id,
                'processed_at' => now(),
            ]);
            LeaveNotificationService::notifyEmployee((int) $resignation->employee_id, 'Resignation declined', $request->notes ?: 'HR declined your resignation request.', [
                'type' => 'resignation',
                'resignation_id' => $resignation->id,
            ]);

            return response()->json($resignation);
        }

        return response()->json($resignation);
    }

    private function notifyHrOfRequest(employee $employee, Resignation $resignation): void
    {
        $name = $employee->full_name ?: $employee->name_with_initials ?: 'Employee';
        $title = 'Resignation request';
        $body = "{$name} submitted a resignation request. Review it later in Resignation approval.";
        $hrIds = User::whereIn('role', ['hr', 'admin'])->pluck('id')->all();
        foreach ($hrIds as $userId) {
            if (Schema::hasTable('notifications')) {
                Notification::create([
                    'user_id' => $userId,
                    'type' => 'resignation',
                    'title' => $title,
                    'message' => $body,
                    'data' => ['resignation_id' => $resignation->id],
                    'is_read' => false,
                ]);
            }
        }
        app(FcmPushService::class)->sendToUsers($hrIds, $title, $body, [
            'type' => 'resignation',
            'resignation_id' => (string) $resignation->id,
        ]);
    }

    private function generateStrongPassword($length = 12)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    public function testFunction(Request $request)
    {
        $pwd = $this->generateStrongPassword(9);

        $mail_data = [
            'password' => $pwd,
            'name' => $request->name,
        ];

        try {
            Mail::to($request->email)->send(new EmployeePasswordSendEmail($mail_data));

            return response()->json($mail_data);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to send email', 'error' => $e->getMessage()], 500);
        }
    }

    public function uploadDocuments(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'documents' => 'required|array',
            'documents.*' => 'file|mimes:pdf,doc,docx,jpg,png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $resignation = Resignation::findOrFail($id);

        $uploadedDocuments = [];
        foreach ($request->file('documents') as $document) {
            $path = app(\App\Services\FirebaseStorageService::class)->storeFile($document, 'hr/resignations');

            $uploadedDocument = ResignationDocument::create([
                'resignation_id' => $resignation->id,
                'document_name' => $document->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $document->getClientMimeType(),
                'file_size' => $document->getSize(),
            ]);

            $uploadedDocuments[] = $uploadedDocument;
        }

        return response()->json($uploadedDocuments, 201);
    }

    public function destroyDocument($resignationId, $documentId)
    {
        $document = ResignationDocument::where('resignation_id', $resignationId)
            ->findOrFail($documentId);

        Storage::delete(str_replace('/storage', 'public', $document->file_path));

        $document->delete();

        return response()->json(['message' => 'Document deleted successfully']);
    }
}
