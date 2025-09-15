<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class enrollments extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'course_id',
        'enrolled_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationship: Enrollment belongs to User
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // Relationship: Enrollment belongs to Course
    public function course(): BelongsTo
    {
        return $this->belongsTo(courses::class);
    }
}
