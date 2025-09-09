<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\attachments;
use App\Models\Course;
use App\Models\courses;
use App\Models\Module;
use App\Models\modules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class LMSController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Get pagination parameters (default to 10 per page, but allow override)
        $perPage = $request->input('per_page', 10);

        // Load courses with relationships, ordered by ID descending
        $courses = courses::with(['modules', 'attachments'])
            ->orderBy("id", "desc")
            ->paginate($perPage);

        return response()->json($courses);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Validate the request data
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'duration' => 'nullable|string|max:50',
            'modules' => 'nullable|array',
            'modules.*.title' => 'required|string|max:255',
            'modules.*.content' => 'nullable|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,mp4,mov,avi|max:10240', // Allow PDF and video files, max 10MB each
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Get the current authenticated user (creator)
        // $user = Auth::user();
        // if (!$user) {
        //     return response()->json(['message' => 'Unauthorized'], 401);
        // }

        // Create the course
        $course = courses::create([
            'title' => $request->title,
            'description' => $request->description,
            'duration' => $request->duration,
            // 'created_by' => $user->id,
            'created_by' => 1,
        ]);

        // Create modules if provided
        if ($request->has('modules') && is_array($request->modules)) {
            foreach ($request->modules as $moduleData) {
                modules::create([
                    'course_id' => $course->id,
                    'title' => $moduleData['title'],
                    'content' => $moduleData['content'] ?? null,
                    'completed' => false,
                ]);
            }
        }

        // Handle attachments: Upload files to storage and create records
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                // Store the file in storage/app/public/attachments
                $path = $file->store('attachments', 'public');
                
                // Determine type based on MIME type
                $mime = $file->getMimeType();
                $type = str_contains($mime, 'pdf') ? 'pdf' : 'video';
                
                // Create attachment record
                attachments::create([
                    'course_id' => $course->id,
                    'name' => $file->getClientOriginalName(),
                    'type' => $type,
                    'url' => Storage::url($path), // Generate public URL
                    'size' => $file->getSize(),
                ]);
            }
        }

        // Load relationships for response
        $course->load(['modules', 'attachments']);

        return response()->json([
            'message' => 'Course created successfully',
            'course' => $course
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id = null)
    {
        if ($id) {
            $course = courses::findOrFail($id);
            $course->load(['modules', 'attachments']);
            return response()->json($course);
        } else {
            $courses = courses::with(['modules', 'attachments'])->orderBy("id", "desc")->get();
            return response()->json($courses);
        }
    }

    /**
     * Update the specified course with modules and attachments.
     */
    public function update(Request $request, string $id)
    {
        // Find the course
        $course = courses::findOrFail($id);

        // Validate the request data (similar to store)
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'duration' => 'nullable|string|max:50',
            'modules' => 'nullable|array',
            'modules.*.title' => 'required|string|max:255',
            'modules.*.content' => 'nullable|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,mp4,mov,avi|max:10240', // Allow PDF and video files, max 10MB each
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Update the course
        $course->update([
            'title' => $request->title,
            'description' => $request->description,
            'duration' => $request->duration,
        ]);

        // Handle modules: Delete existing and recreate (or update if IDs are provided)
        if ($request->has('modules') && is_array($request->modules)) {
            // Delete existing modules
            modules::where('course_id', $course->id)->delete();
            // Create new modules
            foreach ($request->modules as $moduleData) {
                modules::create([
                    'course_id' => $course->id,
                    'title' => $moduleData['title'],
                    'content' => $moduleData['content'] ?? null,
                    'completed' => false,
                ]);
            }
        }

        // Handle attachments: Delete existing files and records, then upload new ones
        if ($request->hasFile('attachments')) {
            // Delete existing attachments and their files
            $existingAttachments = attachments::where('course_id', $course->id)->get();
            foreach ($existingAttachments as $attachment) {
                // Delete the file from storage
                Storage::disk('public')->delete(str_replace('/storage/', '', $attachment->url));
                // Delete the record
                $attachment->delete();
            }
            
            // Upload new files
            foreach ($request->file('attachments') as $file) {
                // Store the file in storage/app/public/attachments
                $path = $file->store('attachments', 'public');
                
                // Determine type based on MIME type
                $mime = $file->getMimeType();
                $type = str_contains($mime, 'pdf') ? 'pdf' : 'video';
                
                // Create attachment record
                attachments::create([
                    'course_id' => $course->id,
                    'name' => $file->getClientOriginalName(),
                    'type' => $type,
                    'url' => Storage::url($path), // Generate public URL
                    'size' => $file->getSize(),
                ]);
            }
        }

        // Load relationships for response
        $course->load(['modules', 'attachments']);

        return response()->json([
            'message' => 'Course updated successfully',
            'course' => $course
        ], 200);
    }

    /**
     * Soft delete the specified course.
     */
    public function destroy(string $id)
    {
        $course = courses::findOrFail($id);
        $course->delete();  // Soft delete (sets deleted_at)

        return response()->json([
            'message' => 'Course deleted successfully'
        ], 200);
    }
}
