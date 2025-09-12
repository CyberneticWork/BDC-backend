<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class exams extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'course_id',
        'duration',
        'total_questions',
        'passing_score',
        'created_by',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'total_questions' => 'integer',
        'passing_score' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationship: Exam belongs to Course (optional)
    public function course(): BelongsTo
    {
        return $this->belongsTo(courses::class);
    }

    // Relationship: Exam belongs to User (creator)
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Relationship: Exam has many Questions
    public function questions(): HasMany
    {
        return $this->hasMany(questions::class, 'exam_id', 'id');
    }

    // Relationship: Exam has many ExamResults
    public function examResults(): HasMany
    {
        return $this->hasMany(exam_results::class);
    }
}
