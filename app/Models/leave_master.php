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
        'leave_duration',
        'leave_duration',
        'cancel_from',
        'cancel_to',
        'reason',
        'status',
        'over_limit'
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }

}
