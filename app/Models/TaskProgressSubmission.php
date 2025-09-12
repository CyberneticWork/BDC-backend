<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskProgressSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'kpi_assignment_id',
        'employee_id',
        'note',
        'progress_percentage',
        'performance_metrics',
        'document_name',
        'document_size',
        'document_type',
        'document_path'
    ];

    protected $casts = [
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
}