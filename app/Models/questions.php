<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class questions extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'exam_id',
        'question',
        'options',
        'correct_answer',
        'explanation',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'options' => 'json',
        'correct_answer' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationship: Question belongs to Exam
    public function exam(): BelongsTo
    {
        return $this->belongsTo(exams::class, 'exam_id', 'id');
    }
}
