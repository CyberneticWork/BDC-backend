<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class certificates extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'attendance_employee_no',
        'course_id',
        'issued_date',
        'certificate_url',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationship: Certificate belongs to Employee (via attendance_employee_no)
    public function employee(): BelongsTo
    {
        return $this->belongsTo(employee::class, 'attendance_employee_no', 'attendance_employee_no');
    }

    // Relationship: Certificate belongs to Course
    public function course(): BelongsTo
    {
        return $this->belongsTo(courses::class);
    }
}
