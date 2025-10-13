<?php

namespace App\Http\Controllers;

use App\Models\exams;
use App\Models\questions;
use App\Models\exam_results;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ExamController extends Controller
{
    /**
     * Display a listing of exams (with optional filters).
     */
    public function index(Request $request)
    {
        // For testing: Comment out auth and use fixed user ID
        // $userId = auth()->id();
        // $userId = 1;

        // $query = exams::with(['questions', 'course'])->where('created_by', $userId);
        $query = exams::with(['questions', 'course']);


        // Optional filters
        if ($request->has('course_id')) {
            $query->where('course_id', $request->course_id);
        }
        if ($request->has('search')) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        $exams = $query->paginate(10); // Paginate for performance

        return response()->json($exams);
    }

    /**
     * Store a newly created exam.
     */
    public function store(Request $request)
    {
        // For testing: Comment out auth and use fixed user ID
        $userId = auth()->id();
        // $userId = 1;

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'course_id' => 'nullable|exists:courses,id',
            'duration' => 'required|string|max:50',
            'passing_score' => 'required|integer|min:0|max:100',
            'questions' => 'required|array|min:1',
            'questions.*.question' => 'required|string',
            'questions.*.options' => 'required|array|size:4', // Exactly 4 options
            'questions.*.options.*' => 'required|string',
            'questions.*.correct_answer' => 'required|integer|min:0|max:3', // 0-3 for 4 options
            'questions.*.explanation' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $exam = exams::create([
                'title' => $request->title,
                'description' => $request->description,
                'course_id' => $request->course_id,
                'duration' => $request->duration,
                'passing_score' => $request->passing_score,
                'total_questions' => count($request->questions),
                'created_by' => $userId,
            ]);

            // Create questions
            foreach ($request->questions as $questionData) {
                questions::create([
                    'exam_id' => $exam->id,
                    'question' => $questionData['question'],
                    'options' => $questionData['options'],
                    'correct_answer' => $questionData['correct_answer'],
                    'explanation' => $questionData['explanation'] ?? null,
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'Exam created successfully', 'exam' => $exam->load('questions')], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create exam: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified exam.
     */
    public function show($id)
    {
        // For testing: Comment out auth and use fixed user ID
        // $userId = auth()->id();
        // $userId = 1;

        // $exam = exams::with(['questions', 'course'])->where('id', $id)->where('created_by', $userId)->first();
        $exam = exams::with(['questions', 'course'])->find($id);

        if (!$exam) {
            return response()->json(['message' => 'Exam not found'], 404);
        }

        return response()->json($exam);
    }

    /**
     * Update the specified exam.
     */
    public function update(Request $request, $id)
    {
        // For testing: Comment out auth and use fixed user ID
        $userId = auth()->id();
        // $userId = 1;

        $exam = exams::where('id', $id)->where('created_by', $userId)->first();

        if (!$exam) {
            return response()->json(['message' => 'Exam not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'course_id' => 'nullable|exists:courses,id',
            'duration' => 'sometimes|required|string|max:50',
            'passing_score' => 'sometimes|required|integer|min:0|max:100',
            'questions' => 'sometimes|array|min:1',
            'questions.*.id' => 'nullable|exists:questions,id', // For updates
            'questions.*.question' => 'required|string',
            'questions.*.options' => 'required|array|size:4',
            'questions.*.options.*' => 'required|string',
            'questions.*.correct_answer' => 'required|integer|min:0|max:3',
            'questions.*.explanation' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $exam->update($request->only(['title', 'description', 'course_id', 'duration', 'passing_score']));

            if ($request->has('questions')) {
                // Delete existing questions not in the request
                $providedQuestionIds = collect($request->questions)->pluck('id')->filter()->toArray();
                $exam->questions()->whereNotIn('id', $providedQuestionIds)->delete();

                // Update or create questions
                foreach ($request->questions as $questionData) {
                    if (isset($questionData['id'])) {
                        // Update existing
                        $question = questions::find($questionData['id']);
                        if ($question) {
                            $question->update($questionData);
                        }
                    } else {
                        // Create new
                        questions::create(array_merge($questionData, ['exam_id' => $exam->id]));
                    }
                }

                $exam->update(['total_questions' => $exam->questions()->count()]);
            }

            DB::commit();
            return response()->json(['message' => 'Exam updated successfully', 'exam' => $exam->load('questions')]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update exam: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified exam.
     */
    public function destroy($id)
    {
        // For testing: Comment out auth and use fixed user ID
        $userId = auth()->id();
        // $userId = 1;

        $exam = exams::where('id', $id)->where('created_by', $userId)->first();

        if (!$exam) {
            return response()->json(['message' => 'Exam not found'], 404);
        }

        $exam->delete(); // Soft delete
        return response()->json(['message' => 'Exam deleted successfully']);
    }

    /**
     * Submit exam answers and calculate results.
     */
    public function submitExam(Request $request, $id)
    {
        $userId = auth()->id(); // replace with auth()->id() later
        $exam = exams::with('questions')->find($id);

        if (!$exam) {
            return response()->json(['message' => 'Exam not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'answers' => 'required|array',
            // 'answers.*' => 'integer|min:0|max:3', // Assuming 4 options (0-3)
            'answers.*' => 'integer|min:-1|max:3',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $answers = $request->answers;
        $questions = $exam->questions;
        $correctCount = 0;
        $results = [];

        foreach ($questions as $index => $question) {
            $userAnswer = $answers[$index] ?? null;
            $isCorrect = $userAnswer === $question->correct_answer;
            if ($isCorrect)
                $correctCount++;
            $results[] = [
                'question_id' => $question->id,
                'user_answer' => $userAnswer,
                'correct_answer' => $question->correct_answer,
                'is_correct' => $isCorrect,
                'explanation' => $question->explanation,
            ];
        }

        $score = round(($correctCount / max(1, $questions->count())) * 100);
        $passed = $score >= $exam->passing_score;

        // new: compute next attempt number
        $attemptNumber = (int) exam_results::where('user_id', $userId)
            ->where('exam_id', $exam->id)
            ->max('attempt_number');
        $attemptNumber = $attemptNumber ? $attemptNumber + 1 : 1;

        $result = exam_results::create([
            'user_id' => $userId,
            'exam_id' => $exam->id,
            'score' => $score,
            'attempt_number' => $attemptNumber,
            'passed' => $passed,
            'submitted_at' => now(),
        ]);

        return response()->json([
            'exam_id' => $exam->id,
            'score' => $score,
            'passed' => $passed,
            'correct_answers' => $correctCount,
            'total_questions' => $questions->count(),
            'results' => $results,
            'submitted_at' => $result->submitted_at,
        ]);
    }

    /**
     * Get exam results for the authenticated user.
     */
    public function getResults(Request $request)
    {
        // For testing: Comment out auth and use fixed user ID
        $userId = auth()->id();
        // $userId = 1;

        $results = exam_results::with('exam')->where('user_id', $userId)->paginate(10);
        return response()->json($results);
    }
}
