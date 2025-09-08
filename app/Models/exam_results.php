<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class exam_results extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'attendance_employee_no',
        'exam_id',
        'score',
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

    // Relationship: ExamResult belongs to Employee (via attendance_employee_no)
    public function employee(): BelongsTo
    {
        return $this->belongsTo(employee::class, 'attendance_employee_no', 'attendance_employee_no');
    }

    // Relationship: ExamResult belongs to Exam
    public function exam(): BelongsTo
    {
        return $this->belongsTo(exams::class);
    }
}
