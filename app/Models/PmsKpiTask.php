<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PmsKpiTask extends Model
{
    protected $fillable = [
        'name',
        'description',
        'status',
        'completion_status',
        'department_id',
        'company_id',
        'priority',
        'category',
        'start_date',
        'end_date',
        'document_count',
        'frequency',
    ];

    /**
     * Get the department that owns the task.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(departments::class, 'department_id');
    }

    /**
     * Get the company that owns the task.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(company::class, 'company_id');
    }

    /**
     * Get the assignees for the task.
     */
    public function assignees(): HasMany
    {
        return $this->hasMany(PmsTaskAssignee::class, 'task_id');
    }

    /**
     * Get the updates for the task.
     */
    public function updates(): HasMany
    {
        return $this->hasMany(PmsTaskUpdate::class, 'task_id');
    }

    /**
     * Get the creator of the task.
     */
    public function creators(): HasMany
    {
        return $this->hasMany(PmsTaskCreator::class, 'task_id');
    }

    /**
     * Get the performance reviews related to this task.
     */
    public function performanceReviews(): HasMany
    {
        return $this->hasMany(PmsPerformanceReview::class, 'task_id');
    }
}