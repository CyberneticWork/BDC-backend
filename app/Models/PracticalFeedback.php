<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PracticalFeedback extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'practical_feedbacks'; // Explicitly set the table name

    protected $fillable = [
        'employee_id',
        'kpi_assignment_id',
        'task_name',
        'from_email',
        'to_email',
        'subject',
        'feedback_content',
        'created_by'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    // Relationships
    public function employee()
    {
        return $this->belongsTo(employee::class);
    }

    public function kpiAssignment()
    {
        return $this->belongsTo(KpiTaskAssignment::class, 'kpi_assignment_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeByCreator($query, $userId)
    {
        return $query->where('created_by', $userId);
    }

    // Accessors
    public function getFormattedCreatedAtAttribute()
    {
        return $this->created_at ? $this->created_at->format('M d, Y g:i A') : null;
    }
}
