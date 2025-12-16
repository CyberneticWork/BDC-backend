<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveSetting extends Model
{
     use SoftDeletes;

    protected $fillable = [
        'employee_type',
        'annual_leave_days',
        'number_of_quarters',
        'quarters',
        'is_active',
        'description',
    ];

    protected $casts = [
        'quarters' => 'array',
        'is_active' => 'boolean',
        'annual_leave_days' => 'integer',
        'number_of_quarters' => 'integer',
    ];

    /**
     * Get total leave days for this setting
     */
    public function getTotalLeaveDaysAttribute(): int
    {
        if ($this->employee_type === 'probation') {
            return $this->annual_leave_days ?? 0;
        }

        // For permanent employees, sum up quarter leave days
        if ($this->quarters) {
            return collect($this->quarters)->sum('leave_days');
        }

        return 0;
    }

    /**
     * Scope for active settings
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by employee type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('employee_type', $type);
    }
}