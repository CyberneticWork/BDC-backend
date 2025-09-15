<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiTaskAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'kpi_task_id', 
        'creator_role_id', 
        'weights',
        'company_id',
        'department_id',
        'employee_id',
        'start_date',
        'end_date',
        'status',
        'priority',
        'description',
        'completion_status',
        'last_updated'
    ];

    protected $casts = [
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
        return $this->belongsTo(company::class); // Updated to lowercase
    }

    public function department()
    {
        return $this->belongsTo(departments::class); // Updated to lowercase
    }

    public function employee()
    {
        return $this->belongsTo(employee::class); // Updated to lowercase
    }

    public function progressSubmissions()
    {
        return $this->hasMany(TaskProgressSubmission::class, 'kpi_assignment_id');
    }

    public function performanceReviews()
    {
        return $this->hasMany(PerformanceReview::class, 'kpi_assignment_id');
    }
}