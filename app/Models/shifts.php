<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class shifts extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'shift_code',
        'shift_description',
        'start_time',
        'end_time',
        'morning_ot_start',
        'morning_ot_end',
        'morning_ot_rate',
        'morning_ot_max_minutes',
        'night_ot_start',
        'night_ot_end',
        'night_normal_ot_max_minutes',
        'night_normal_ot_rate',
        'night_special_ot_rate',
        'midnight_roster'
    ];

    protected $casts = [
        'midnight_roster' => 'boolean',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'morning_ot_start' => 'datetime:H:i',
        'morning_ot_end' => 'datetime:H:i',
        'night_ot_start' => 'datetime:H:i',
        'night_ot_end' => 'datetime:H:i',
        'morning_ot_rate' => 'decimal:2',
        'night_normal_ot_rate' => 'decimal:2',
        'night_special_ot_rate' => 'decimal:2',
        'morning_ot_max_minutes' => 'integer',
        'night_normal_ot_max_minutes' => 'integer',
    ];
}
