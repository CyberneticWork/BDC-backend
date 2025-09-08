<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class progress extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'attendance_employee_no',
        'course_id',
        'module_id',
        'completed',
        'completed_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'completed' => 'boolean',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationship: Progress belongs to Employee (via attendance_employee_no)
    public function employee(): BelongsTo
    {
        return $this->belongsTo(employee::class, 'attendance_employee_no', 'attendance_employee_no');
    }

    // Relationship: Progress belongs to Course
    public function course(): BelongsTo
    {
        return $this->belongsTo(courses::class);
    }

    // Relationship: Progress belongs to Module
    public function module(): BelongsTo
    {
        return $this->belongsTo(modules::class);
    }
}
