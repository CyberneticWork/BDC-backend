<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KpiTaskAssignment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'kpi_task_id', 
        'creator_role_id', 
        'creator_id',
        'weights',
        'company_id',
        'department_id',
        'employee_id',
        'start_date',
        'end_date',
        'status',
        'approval_status',
        'priority',
        'description',
        'completion_status',
        'kpi_type', // Add this new field
        'last_updated'
    ];

    protected $casts = [
        'kpi_type' => 'boolean',
        'weights' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'last_updated' => 'datetime'
    ];

    public function kpiTask()
    {
        return $this->belongsTo(KpiTask::class);
    }

    public function creatorRole()
    {
        return $this->belongsTo(CreatorRole::class);
    }

    public function company()
    {
        return $this->belongsTo(company::class);
    }

    public function department()
    {
        return $this->belongsTo(departments::class);
    }

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }

    public function progressSubmissions()
    {
        return $this->hasMany(TaskProgressSubmission::class, 'kpi_assignment_id');
    }

    public function performanceReviews()
    {
        return $this->hasMany(PerformanceReview::class, 'kpi_assignment_id');
    }

    // Add this relationship
    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'creator_id');
    }
}