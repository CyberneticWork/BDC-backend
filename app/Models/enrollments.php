<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class enrollments extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'attendance_employee_no',
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

    // Relationship: Enrollment belongs to Employee (via attendance_employee_no)
    public function employee(): BelongsTo
    {
        return $this->belongsTo(employee::class, 'attendance_employee_no', 'attendance_employee_no');
    }

    // Relationship: Enrollment belongs to Course
    public function course(): BelongsTo
    {
        return $this->belongsTo(courses::class);
    }
}
