<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PerformanceEvaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'evaluator_id',
        'start_date',
        'end_date',
        'percentage',
        'grade',
        'performance_label',
        'calculation_details',
        'task_count'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'calculation_details' => 'array'
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function evaluator()
    {
        return $this->belongsTo(Employee::class, 'evaluator_id');
    }
}