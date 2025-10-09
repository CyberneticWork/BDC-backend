<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class exam_results extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'exam_id',
        'score',
        'attempt_number',
        'passed',
        'submitted_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'passed' => 'boolean',
        'submitted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationship: ExamResult belongs to users in users (via attendance_employee_no)
    public function user(): BelongsTo
    {
        return $this->belongsTo(user::class, 'user_id', 'id');
    }

    // Relationship: ExamResult belongs to Exam
    public function exam(): BelongsTo
    {
        return $this->belongsTo(exams::class);
    }
}
