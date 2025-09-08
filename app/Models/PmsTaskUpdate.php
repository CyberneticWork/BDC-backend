<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmsTaskUpdate extends Model
{
    protected $fillable = [
        'task_id',
        'employee_id',
        'note',
        'author',
        'document_name',
        'document_size',
        'document_type',
        'document_path',
        'progress_percentage',
        'performance_metrics',
        'date',
    ];

    protected $casts = [
        'performance_metrics' => 'json',
        'date' => 'datetime',
    ];

    /**
     * Get the task that owns the update.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(PmsKpiTask::class, 'task_id');
    }

    /**
     * Get the employee that made the update.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(employee::class, 'employee_id', 'attendance_employee_no');
    }
}