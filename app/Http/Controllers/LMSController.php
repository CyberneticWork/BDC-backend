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
        // Validate the request data (updated to include module files)
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'duration' => 'nullable|string|max:50',
            'modules' => 'nullable|array',
            'modules.*.title' => 'required|string|max:255',
            'modules.*.content' => 'nullable|string',
            'modules.*.file' => 'nullable|file|mimes:pdf,mp4,mov,avi|max:10240', // New: Allow PDF/video for each module, max 10MB
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,mp4,mov,avi|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Create the course
        $course = courses::create([
            'title' => $request->title,
            'description' => $request->description,
            'duration' => $request->duration,
            'created_by' => 1, // Or use Auth::id() if authentication is set up
        ]);

        // Create modules if provided
        if ($request->has('modules') && is_array($request->modules)) {
            foreach ($request->modules as $index => $moduleData) {
                $path = null;
                if ($request->hasFile("modules.{$index}.file")) {
                    $file = $request->file("modules.{$index}.file");
                    // Save using the original filename sent by the frontend
                    $originalName = $file->getClientOriginalName();
                    $storedPath = $file->storeAs('modules', $originalName, 'public');
                    $path = Storage::url($storedPath); // public URL
                }

                modules::create([
                    'course_id' => $course->id,
                    'title' => $moduleData['title'],
                    'content' => $moduleData['content'] ?? null,
                    'completed' => false,
                    'path' => $path, // Save the file path
                ]);
            }
        }

        // Handle attachments (unchanged)
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                // Save using original filename from frontend
                $originalName = $file->getClientOriginalName();
                $storedPath = $file->storeAs('attachments', $originalName, 'public');
                $mime = $file->getMimeType();
                $type = str_contains($mime, 'pdf') ? 'pdf' : 'video';

                attachments::create([
                    'course_id' => $course->id,
                    'name' => $originalName,
                    'type' => $type,
                    'url' => Storage::url($storedPath),
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

        // Validate the request data (updated to include module files and optional IDs)
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'duration' => 'nullable|string|max:50',
            'modules' => 'nullable|array',
            'modules.*.id' => 'nullable|exists:modules,id', // Optional ID for existing modules
            'modules.*.title' => 'required|string|max:255',
            'modules.*.content' => 'nullable|string',
            'modules.*.file' => 'nullable|file|mimes:pdf,mp4,mov,avi|max:10240', // New: Allow PDF/video for each module
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,mp4,mov,avi|max:10240',
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

        // Handle modules: Update existing, add new, and delete removed ones
        if ($request->has('modules') && is_array($request->modules)) {
            $providedModuleIds = []; // Track IDs from the request

            foreach ($request->modules as $index => $moduleData) {
                $moduleId = $moduleData['id'] ?? null;
                $path = null;

                if ($moduleId) {
                    // Update existing module
                    $module = modules::findOrFail($moduleId);
                    $providedModuleIds[] = $moduleId;

                    // Handle file upload if provided
                    if ($request->hasFile("modules.{$index}.file")) {
                        $file = $request->file("modules.{$index}.file");
                        $storedPath = $file->store('modules', 'public');
                        $path = Storage::url($storedPath);

                        // Delete old file if it exists
                        if ($module->path) {
                            Storage::disk('public')->delete(str_replace('/storage/', '', $module->path));
                        }
                    } else {
                        // Keep existing path if no new file
                        $path = $module->path;
                    }

                    // Update module
                    $module->update([
                        'title' => $moduleData['title'],
                        'content' => $moduleData['content'] ?? null,
                        'path' => $path,
                    ]);
                } else {
                    // Create new module
                    if ($request->hasFile("modules.{$index}.file")) {
                        $file = $request->file("modules.{$index}.file");
                        // Save using original filename sent by the frontend
                        $originalName = $file->getClientOriginalName();
                        $storedPath = $file->storeAs('modules', $originalName, 'public');
                        $path = Storage::url($storedPath);
                    }

                    $newModule = modules::create([
                        'course_id' => $course->id,
                        'title' => $moduleData['title'],
                        'content' => $moduleData['content'] ?? null,
                        'completed' => false,
                        'path' => $path,
                    ]);
                    $providedModuleIds[] = $newModule->id; // Add to provided IDs
                }
            }

            // Delete modules not in the request (and their files)
            $existingModules = modules::where('course_id', $course->id)->get();
            foreach ($existingModules as $existingModule) {
                if (!in_array($existingModule->id, $providedModuleIds)) {
                    if ($existingModule->path) {
                        Storage::disk('public')->delete(str_replace('/storage/', '', $existingModule->path));
                    }
                    $existingModule->delete();
                }
            }
        }

        // Handle attachments (unchanged, but ensure old files are deleted if needed)
        if ($request->hasFile('attachments')) {
            // Delete existing attachments and their files
            $existingAttachments = attachments::where('course_id', $course->id)->get();
            foreach ($existingAttachments as $attachment) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $attachment->url));
                $attachment->delete();
            }

            // Upload new files
            foreach ($request->file('attachments') as $file) {
                // Save using original filename from frontend
                $originalName = $file->getClientOriginalName();
                $storedPath = $file->storeAs('attachments', $originalName, 'public');
                $mime = $file->getMimeType();
                $type = str_contains($mime, 'pdf') ? 'pdf' : 'video';

                attachments::create([
                    'course_id' => $course->id,
                    'name' => $originalName,
                    'type' => $type,
                    'url' => Storage::url($storedPath),
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
    public function removeAttachment($id)
{
    $attachment = attachments::findOrFail($id);
    // Delete the file from storage
    if ($attachment->url) {
        \Illuminate\Support\Facades\Storage::disk('public')->delete(str_replace('/storage/', '', $attachment->url));
    }
    $attachment->delete();

    return response()->json(['message' => 'Attachment removed successfully'], 200);
}
}
