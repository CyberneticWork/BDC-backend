<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MonthlyLateDeductionItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'run_id',
        'employee_id',
        'month',
        'year',
        'total_late_minutes',
        'late_day_count',
        'free_minutes',
        'chargeable_minutes',
        'excess_minutes',
        'short_leave_count',
        'short_leave_days',
        'annual_leave_days',
        'casual_leave_days',
        'nopay_days',
        'nopay_minutes',
        'annual_balance_before',
        'casual_balance_before',
        'annual_balance_after',
        'casual_balance_after',
        'band',
        'status',
        'breakdown',
        'late_days',
        'created_leave_ids',
        'created_nopay_ids',
        'notes',
    ];

    protected $casts = [
        'short_leave_days' => 'float',
        'annual_leave_days' => 'float',
        'casual_leave_days' => 'float',
        'nopay_days' => 'float',
        'annual_balance_before' => 'float',
        'casual_balance_before' => 'float',
        'annual_balance_after' => 'float',
        'casual_balance_after' => 'float',
        'breakdown' => 'array',
        'late_days' => 'array',
        'created_leave_ids' => 'array',
        'created_nopay_ids' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }

    public function run()
    {
        return $this->belongsTo(MonthlyLateDeductionRun::class, 'run_id');
    }
}
