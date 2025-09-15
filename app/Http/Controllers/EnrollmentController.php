<?php

namespace App\Http\Controllers;

use App\Models\enrollments;
use App\Models\courses;
use App\Models\certificates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EnrollmentController extends Controller
{
    /**
     * Display a listing of enrolled courses for the authenticated user.
     */
    public function index(Request $request)
    {
        $userId = auth()->id();

        $query = enrollments::with(['course.modules', 'course.attachments'])
            ->where('user_id', $userId);

        // Optional filters
        if ($request->has('status')) {
            // Add status filtering if implemented
            $query->where('status', $request->status);
        }

        $enrollments = $query->paginate(10);

        return response()->json($enrollments);
    }

    /**
     * Enroll the authenticated user in a course.
     */
    public function enroll(Request $request, $courseId)
    {
        $userId = auth()->id();

        // Validate course exists
        $course = courses::findOrFail($courseId);

        // Check if already enrolled
        $existingEnrollment = enrollments::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->first();

        if ($existingEnrollment) {
            return response()->json([
                'message' => 'User is already enrolled in this course'
            ], 409);
        }

        // Create enrollment
        DB::beginTransaction();
        try {
            $enrollment = enrollments::create([
                'user_id' => $userId,
                'course_id' => $courseId,
                'enrolled_at' => now(),
            ]);

            // Initialize progress records for each module
            foreach ($course->modules as $module) {
                DB::table('progress')->insert([
                    'user_id' => $userId,
                    'course_id' => $courseId,
                    'module_id' => $module->id,
                    'completed' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Successfully enrolled in course',
                'enrollment' => $enrollment->load('course')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to enroll in course: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check enrollment status for a specific course.
     */
    public function checkEnrollment($courseId)
    {
        $userId = auth()->id();

        $enrollment = enrollments::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->first();

        return response()->json([
            'enrolled' => !!$enrollment,
            'enrollment' => $enrollment ? $enrollment->load('course') : null,
        ]);
    }

    /**
     * Get enrollment progress for a specific course.
     */
    public function getProgress($courseId)
    {
        $userId = auth()->id();

        // Get enrollment with course and module progress
        $enrollment = enrollments::with(['course.modules'])
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->firstOrFail();

        // Get progress records
        $progress = DB::table('progress')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->get();

        // Calculate progress statistics
        $totalModules = $enrollment->course->modules->count();
        $completedModules = $progress->where('completed', true)->count();
        $progressPercentage = $totalModules > 0
            ? round(($completedModules / $totalModules) * 100)
            : 0;

        return response()->json([
            'enrollment' => $enrollment,
            'progress' => [
                'total_modules' => $totalModules,
                'completed_modules' => $completedModules,
                'progress_percentage' => $progressPercentage,
                'module_progress' => $progress,
            ]
        ]);
    }

    /**
     * Update module completion status.
     */
    public function updateModuleProgress(Request $request, $courseId, $moduleId)
    {
        $userId = auth()->id();

        // Verify enrollment exists
        $enrollment = enrollments::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->firstOrFail();

        // Update progress
        $updated = DB::table('progress')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('module_id', $moduleId)
            ->update([
                'completed' => $request->completed,
                'updated_at' => now()
            ]);

        if (!$updated) {
            return response()->json([
                'message' => 'Failed to update module progress'
            ], 400);
        }

        // Check if course is now completed and issue certificate if needed
        $this->checkAndIssueCertificate($userId, $courseId);

        // Get updated progress
        return $this->getProgress($courseId);
    }

    /**
     * Remove enrollment from a course.
     */
    public function unenroll($courseId)
    {
        $userId = auth()->id();

        $enrollment = enrollments::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->firstOrFail();

        DB::beginTransaction();
        try {
            // Delete progress records
            DB::table('progress')
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->delete();

            // Delete enrollment
            $enrollment->delete();

            DB::commit();
            return response()->json([
                'message' => 'Successfully unenrolled from course'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to unenroll from course: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get overall progress for the authenticated user.
     */
    public function getUserProgress()
    {
        $userId = auth()->id();

        // Get all enrollments with courses and modules
        $enrollments = enrollments::with(['course.modules'])
            ->where('user_id', $userId)
            ->get();

        $totalCourses = $enrollments->count();
        $completedCourses = 0;
        $totalModules = 0;
        $completedModules = 0;
        $enrolledCourses = [];

        foreach ($enrollments as $enrollment) {
            $course = $enrollment->course;
            $modules = $course->modules;
            $totalModules += $modules->count();

            // Get progress for this course
            $progressRecords = DB::table('progress')
                ->where('user_id', $userId)
                ->where('course_id', $course->id)
                ->get();

            $courseCompletedModules = $progressRecords->where('completed', true)->count();
            $completedModules += $courseCompletedModules;

            $isCompleted = $modules->count() > 0 && $courseCompletedModules === $modules->count();
            if ($isCompleted) {
                $completedCourses++;
            }

            $enrolledCourses[] = [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
                'duration' => $course->duration,
                'enrolled_at' => $enrollment->enrolled_at,
                'completed' => $isCompleted,
                'total_modules' => $modules->count(),
                'completed_modules' => $courseCompletedModules,
                'progress_percentage' => $modules->count() > 0
                    ? round(($courseCompletedModules / $modules->count()) * 100)
                    : 0,
            ];
        }

        // Get user's certificates
        $certificates = certificates::with('course')
            ->where('user_id', $userId)
            ->get()
            ->map(function ($certificate) {
                return [
                    'id' => $certificate->id,
                    'course_id' => $certificate->course_id,
                    'course_title' => $certificate->course->title ?? 'Unknown Course',
                    'issued_date' => $certificate->issued_date,
                    'certificate_url' => $certificate->certificate_url,
                    'created_at' => $certificate->created_at,
                ];
            });

        return response()->json([
            'totalCourses' => $totalCourses,
            'completedCourses' => $completedCourses,
            'totalModules' => $totalModules,
            'completedModules' => $completedModules,
            'certificatesEarned' => $certificates->count(),
            'enrolledCourses' => $enrolledCourses,
            'certificates' => $certificates,
        ]);
    }

    /**
     * Check if course is completed and issue certificate if needed.
     */
    private function checkAndIssueCertificate($userId, $courseId)
    {
        // Get course with modules
        $course = courses::with('modules')->find($courseId);
        if (!$course || $course->modules->isEmpty()) {
            return;
        }

        // Check if all modules are completed
        $totalModules = $course->modules->count();
        $completedModules = DB::table('progress')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('completed', true)
            ->count();

        $isCompleted = $completedModules === $totalModules;

        if ($isCompleted) {
            // Check if certificate already exists
            $existingCertificate = certificates::where('user_id', $userId)
                ->where('course_id', $courseId)
                ->first();

            if (!$existingCertificate) {
                // Issue certificate
                certificates::create([
                    'user_id' => $userId,
                    'course_id' => $courseId,
                    'issued_date' => now()->toDateString(),
                    'certificate_url' => null, // Can be updated later with actual certificate file
                ]);
            }
        }
    }
}
