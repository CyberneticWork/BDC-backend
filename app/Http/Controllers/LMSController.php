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
use Illuminate\Support\Facades\Validator;

class LMSController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $courses = courses::orderBy("id", "desc")->paginate(10);
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
            'attachments.*.name' => 'required|string|max:255',
            'attachments.*.type' => 'required|in:pdf,video',
            'attachments.*.url' => 'required|string|max:255',
            'attachments.*.size' => 'nullable|string|max:50',
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

        // Create attachments if provided
        if ($request->has('attachments') && is_array($request->attachments)) {
            foreach ($request->attachments as $attachmentData) {
                attachments::create([
                    'course_id' => $course->id,
                    'name' => $attachmentData['name'],
                    'type' => $attachmentData['type'],
                    'url' => $attachmentData['url'],
                    'size' => $attachmentData['size'] ?? null,
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
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
