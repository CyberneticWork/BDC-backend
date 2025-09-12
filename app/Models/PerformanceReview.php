<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PerformanceReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'kpi_assignment_id',
        'employee_id',
        'supervisor_id',
        'progress',
        'grade',
        'supervisor_comments',
        'status',
        'review_type',
        'review_cycle',
        'start_date',
        'due_date',
        'completed_date',
        'self_reported_progress',
        'self_reported_last_updated',
        'performance_metrics'
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_date' => 'date',
        'self_reported_last_updated' => 'datetime',
        'performance_metrics' => 'array'
    ];

    public function kpiAssignment()
    {
        return $this->belongsTo(KpiTaskAssignment::class, 'kpi_assignment_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function supervisor()
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }
}