<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShiftOvertimeRate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'shift_id',
        'shift_hours_per_day',
        'working_days_per_month',
        'ot_multiplier',
        'holiday_multiplier',
        'ignore_hours_threshold'
    ];

    protected $casts = [
        'shift_hours_per_day' => 'decimal:2',
        'working_days_per_month' => 'decimal:2',
        'ot_multiplier' => 'decimal:2',
        'holiday_multiplier' => 'decimal:2',
        'ignore_hours_threshold' => 'array'
    ];

    public function shift()
    {
        return $this->belongsTo(shifts::class, 'shift_id', 'id');
    }

    /**
     * Calculate hourly rate based on employee's basic salary
     */
    public function calculateHourlyRate($basicSalary)
    {
        $totalMonthlyHours = $this->shift_hours_per_day * $this->working_days_per_month;
        return $totalMonthlyHours > 0 ? $basicSalary / $totalMonthlyHours : 0;
    }

    /**
     * Calculate OT rate based on employee's basic salary
     */
    public function calculateOtRate($basicSalary)
    {
        return $this->calculateHourlyRate($basicSalary) * $this->ot_multiplier;
    }

    /**
     * Calculate holiday rate based on employee's basic salary
     */
    public function calculateHolidayRate($basicSalary)
    {
        return $this->calculateHourlyRate($basicSalary) * $this->holiday_multiplier;
    }
}
