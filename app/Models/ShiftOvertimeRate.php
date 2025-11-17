<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShiftOvertimeRate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'shift_id',
        'normal_hours_rate',
        'ot_rate',
        'holiday_rate',
        'ignore_hours_threshold'
    ];

    protected $casts = [
        'normal_hours_rate' => 'decimal:2',
        'ot_rate' => 'decimal:2',
        'holiday_rate' => 'decimal:2',
        'ignore_hours_threshold' => 'decimal:2'
    ];

    public function shift()
    {
        return $this->belongsTo(shifts::class, 'shift_id', 'id');
    }
}
