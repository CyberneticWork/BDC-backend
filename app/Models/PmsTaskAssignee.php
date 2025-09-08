<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmsTaskAssignee extends Model
{
    protected $fillable = [
        'task_id',
        'employee_id',
    ];

    /**
     * Get the task that owns the assignee.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(PmsKpiTask::class, 'task_id');
    }

    /**
     * Get the employee that is assigned.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(employee::class, 'employee_id', 'attendance_employee_no');
    }
}