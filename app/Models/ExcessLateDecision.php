<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExcessLateDecision extends Model
{
    protected $table = 'excess_late_decisions';

    protected $fillable = [
        'employee_id',
        'late_date',
        'late_minutes',
        'shift_minutes',
        'action',
        'leave_type',
        'leave_id',
        'nopay_id',
        'deduct_from',
        'nopay_days',
        'nopay_amount',
        'decided_by',
        'decided_at',
        'notes',
    ];

    protected $casts = [
        'late_date' => 'date',
        'late_minutes' => 'integer',
        'shift_minutes' => 'integer',
        'nopay_days' => 'float',
        'nopay_amount' => 'float',
        'decided_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }
}
