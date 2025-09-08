<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmsTaskCreator extends Model
{
    protected $fillable = [
        'task_id',
        'employee_id',
        'name',
        'role',
        'date',
    ];

    protected $casts = [
        'date' => 'datetime',
    ];

    /**
     * Get the task that the creator created.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(PmsKpiTask::class, 'task_id');
    }

    /**
     * Get the employee that created the task.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(employee::class, 'employee_id', 'attendance_employee_no');
    }
}