<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class leave_master extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'reporting_date',
        'leave_type',
        'leave_date',
        'leave_from',
        'leave_to',
        'is_half_day',
        'period',
        'is_short_leave',      // 
        'short_leave_slot',
        'leave_duration',
        'requested_days',
        'leave_balance_days',
        'nopay_days',
        'nopay_applied',
        'nopay_record_id',
        'cancel_from',
        'cancel_to',
        'reason',
        'requires_evidence',
        'evidence_path',
        'evidence_name',
        'medical_casual_days',
        'medical_annual_days',
        'status',
        'over_limit',
        'covering_employee_id',
        'covering_status',
    ];

    protected $casts = [
        'is_half_day' => 'boolean',
        'is_short_leave' => 'boolean',
        'leave_duration' => 'float',
        'requested_days' => 'float',
        'leave_balance_days' => 'float',
        'nopay_days' => 'float',
        'nopay_applied' => 'boolean',
        'over_limit' => 'float',
        'requires_evidence' => 'boolean',
        'medical_casual_days' => 'float',
        'medical_annual_days' => 'float',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }

    public function coveringEmployee()
    {
        return $this->belongsTo(employee::class, 'covering_employee_id');
    }

}
