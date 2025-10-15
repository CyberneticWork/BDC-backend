<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\user;
use App\Models\employee;

class PerformanceReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'kpi_assignment_id',
        'employee_id',
        'supervisor_id',
        'progress',
        'grade',
        'appraisal_rating', // Add this new field
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

    /**
     * Get the rating label for appraisal rating
     */
    public function getAppraisalRatingLabelAttribute()
    {
        if (!$this->appraisal_rating) return null;
        
        $labels = [
            1 => 'Poor',
            2 => 'Below Average', 
            3 => 'Average',
            4 => 'Above Average',
            5 => 'Excellent'
        ];
        
        return $labels[$this->appraisal_rating] ?? null;
    }

    public function kpiAssignment()
    {
        return $this->belongsTo(KpiTaskAssignment::class, 'kpi_assignment_id');
    }

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(user::class, 'supervisor_id');
    }
}