<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PmsPerformanceMetric extends Model
{
    protected $fillable = [
        'metrics_data',
        'source_type',
        'overall_percentage',
    ];

    protected $casts = [
        'metrics_data' => 'json',
    ];

    /**
     * Get the supervisor reviews that use this metric.
     */
    public function supervisorReviews(): HasMany
    {
        return $this->hasMany(PmsPerformanceReview::class, 'supervisor_metrics_id');
    }

    /**
     * Get the self-reported reviews that use this metric.
     */
    public function selfReportedReviews(): HasMany
    {
        return $this->hasMany(PmsPerformanceReview::class, 'self_reported_metrics_id');
    }
}