<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\employee;
use App\Models\user;

class PerformanceEvaluation extends Model
{
    use HasFactory, SoftDeletes;

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

    protected $dates = ['deleted_at'];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }

    public function evaluator()
    {
        return $this->belongsTo(user::class, 'evaluator_id');
    }
}
