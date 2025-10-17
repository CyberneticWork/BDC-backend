<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\employee;
use App\Models\user;

class PerformanceAppraisal extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'appraiser_id',
        'start_date',
        'end_date',
        'employee_self_rating',
        'supervisor_rating',
        'average_rating',
        'percentage',
        'grade',
        'performance_label',
        'calculation_details',
        'task_count',
        'supervisor_comments',
        'employee_comments',
        'status'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'calculation_details' => 'array',
        'average_rating' => 'decimal:2'
    ];

    protected $dates = ['deleted_at'];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }

    public function appraiser()
    {
        return $this->belongsTo(user::class, 'appraiser_id');
    }
}
