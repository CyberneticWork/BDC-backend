<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmsPerformanceReview extends Model
{
    protected $fillable = [
        'task_id',
        'employee_id',
        'type',
        'status',
        'start_date',
        'due_date',
        'completed_date',
        'cycle',
        'grade',
        'supervisor_id',
        'supervisor_comments',
        'supervisor_progress',
        'supervisor_metrics_id',
        'self_reported_progress',
        'self_reported_metrics_id',
        'supervisor_last_updated',
        'self_reported_last_updated',
        'self_reported_author',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_date' => 'date',
        'supervisor_last_updated' => 'datetime',
        'self_reported_last_updated' => 'datetime',
        'last_updated' => 'datetime',
    ];

    /**
     * Get the task that is being reviewed.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(PmsKpiTask::class, 'task_id');
    }

    /**
     * Get the employee being reviewed.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(employee::class, 'employee_id', 'attendance_employee_no');
    }

    /**
     * Get the supervisor conducting the review.
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(employee::class, 'supervisor_id', 'attendance_employee_no');
    }

    /**
     * Get the supervisor metrics for this review.
     */
    public function supervisorMetrics(): BelongsTo
    {
        return $this->belongsTo(PmsPerformanceMetric::class, 'supervisor_metrics_id');
    }

    /**
     * Get the self-reported metrics for this review.
     */
    public function selfReportedMetrics(): BelongsTo
    {
        return $this->belongsTo(PmsPerformanceMetric::class, 'self_reported_metrics_id');
    }
}