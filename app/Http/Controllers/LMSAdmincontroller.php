<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\courses;
use App\Models\modules;
use App\Models\enrollments;
use App\Models\progress;
use App\Models\certificates;
use App\Models\exam_results;
use App\Models\exams;

class LMSAdmincontroller extends Controller
{
    public function listAllUsersCourseProgress(Request $request)
    {
        $perPage = (int) ($request->get('per_page', 15));
        $search = $request->get('search');
        $courseId = $request->get('course_id');

        $query = enrollments::query()
            ->with(['user', 'course:id,title', 'course.modules:id,course_id'])
            ->when($courseId, fn($q) => $q->where('course_id', $courseId))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner
                        ->whereHas('user', function ($uq) use ($search) {
                            $uq->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhereHas('course', function ($cq) use ($search) {
                            $cq->where('title', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('enrolled_at');

        $paginator = $query->paginate($perPage);

        $items = $paginator->getCollection()->map(function ($enrollment) {
            $userId = $enrollment->user_id;
            $courseId = $enrollment->course_id;

            $totalModules = $enrollment->course?->modules?->count() ?? 0;
            $completedModules = DB::table('progress')
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->where('completed', true)
                ->count();

            $percentage = $totalModules > 0 ? (int) round(($completedModules / $totalModules) * 100) : 0;

            $hasCertificate = certificates::where('user_id', $userId)
                ->where('course_id', $courseId)
                ->exists();

            return [
                'user' => [
                    'id' => $enrollment->user->id,
                    'name' => $enrollment->user->name,
                    'email' => $enrollment->user->email,
                ],
                'course' => [
                    'id' => $enrollment->course->id,
                    'title' => $enrollment->course->title,
                ],
                'enrolled_at' => $enrollment->enrolled_at,
                'total_modules' => $totalModules,
                'completed_modules' => $completedModules,
                'progress_percentage' => $percentage,
                'certificate_issued' => $hasCertificate,
            ];
        });

        $paginator->setCollection($items);
        return response()->json($paginator);
    }

    public function listAllUsersExamProgress(Request $request)
    {
        $perPage = (int) ($request->get('per_page', 15));
        $search = $request->get('search');
        $examId = $request->get('exam_id');

        $query = exam_results::query()
            ->with(['user:id,name,email', 'exam:id,title,passing_score'])
            ->when($examId, fn($q) => $q->where('exam_id', $examId))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner
                        ->whereHas('user', function ($uq) use ($search) {
                            $uq->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhereHas('exam', function ($eq) use ($search) {
                            $eq->where('title', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('submitted_at');

        // Aggregate per user per exam using a subquery
        $results = $query->get()->groupBy(fn($r) => $r->user_id . '-' . $r->exam_id);

        $aggregated = $results->map(function ($group) {
            $first = $group->first();
            $attempts = $group->count();
            $bestScore = $group->max('score');
            $lastAttemptAt = $group->max('submitted_at');
            $passed = $group->contains(fn($r) => (bool) $r->passed);
            return [
                'user' => [
                    'id' => $first->user->id,
                    'name' => $first->user->name,
                    'email' => $first->user->email,
                ],
                'exam' => [
                    'id' => $first->exam->id,
                    'title' => $first->exam->title,
                    'passing_score' => $first->exam->passing_score,
                ],
                'attempts' => $attempts,
                'best_score' => $bestScore,
                'last_attempt_at' => $lastAttemptAt,
                'passed_any_attempt' => $passed,
            ];
        })->values();

        // Paginate manually since we aggregated in memory
        $page = max(1, (int) $request->get('page', 1));
        $total = $aggregated->count();
        $perPage = max(1, $perPage);
        $items = $aggregated->forPage($page, $perPage)->values();

        return response()->json([
            'current_page' => $page,
            'data' => $items,
            'from' => ($page - 1) * $perPage + 1,
            'last_page' => (int) ceil($total / $perPage),
            'per_page' => $perPage,
            'to' => min($page * $perPage, $total),
            'total' => $total,
        ]);
    }

    public function getUserCourseProgress($userId, Request $request)
    {
        $perPage = (int) ($request->get('per_page', 15));
        $enrollments = enrollments::with(['course.modules'])
            ->where('user_id', $userId)
            ->paginate($perPage);

        $mapped = $enrollments->getCollection()->map(function ($enrollment) use ($userId) {
            $course = $enrollment->course;
            $totalModules = $course->modules->count();
            $completedModules = DB::table('progress')
                ->where('user_id', $userId)
                ->where('course_id', $course->id)
                ->where('completed', true)
                ->count();
            $percentage = $totalModules > 0 ? (int) round(($completedModules / $totalModules) * 100) : 0;
            $hasCertificate = certificates::where('user_id', $userId)
                ->where('course_id', $course->id)
                ->exists();
            return [
                'course' => [
                    'id' => $course->id,
                    'title' => $course->title,
                ],
                'enrolled_at' => $enrollment->enrolled_at,
                'total_modules' => $totalModules,
                'completed_modules' => $completedModules,
                'progress_percentage' => $percentage,
                'certificate_issued' => $hasCertificate,
            ];
        });

        $enrollments->setCollection($mapped);
        return response()->json($enrollments);
    }

    public function getUserExamProgress($userId, Request $request)
    {
        $perPage = (int) ($request->get('per_page', 15));
        $results = exam_results::with('exam')
            ->where('user_id', $userId)
            ->orderByDesc('submitted_at')
            ->get()
            ->groupBy('exam_id');

        $aggregated = $results->map(function ($group) {
            $first = $group->first();
            return [
                'exam' => [
                    'id' => $first->exam->id,
                    'title' => $first->exam->title,
                    'passing_score' => $first->exam->passing_score,
                ],
                'attempts' => $group->count(),
                'best_score' => $group->max('score'),
                'last_attempt_at' => $group->max('submitted_at'),
                'passed_any_attempt' => $group->contains(fn($r) => (bool) $r->passed),
            ];
        })->values();

        // manual pagination
        $page = max(1, (int) $request->get('page', 1));
        $total = $aggregated->count();
        $perPage = max(1, $perPage);
        $items = $aggregated->forPage($page, $perPage)->values();

        return response()->json([
            'current_page' => $page,
            'data' => $items,
            'from' => ($page - 1) * $perPage + 1,
            'last_page' => (int) ceil($total / $perPage),
            'per_page' => $perPage,
            'to' => min($page * $perPage, $total),
            'total' => $total,
        ]);
    }

    public function getLmsStats(Request $request)
    {
        $totalUsersEnrolled = enrollments::distinct('user_id')->count('user_id');
        $totalEnrollments = enrollments::count();
        $totalCertificates = certificates::count();
        $totalExams = exams::count();
        $totalExamAttempts = exam_results::count();
        $passedAttempts = exam_results::where('passed', true)->count();
        $passRate = $totalExamAttempts > 0 ? round(($passedAttempts / $totalExamAttempts) * 100, 2) : 0.0;

        return response()->json([
            'total_users_enrolled' => $totalUsersEnrolled,
            'total_enrollments' => $totalEnrollments,
            'total_certificates' => $totalCertificates,
            'total_exams' => $totalExams,
            'total_exam_attempts' => $totalExamAttempts,
            'exam_pass_rate' => $passRate,
        ]);
    }
}
