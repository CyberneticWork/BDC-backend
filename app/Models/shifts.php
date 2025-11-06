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
        'night_ot_start',
        'night_ot_end',
        'midnight_roster'
    ];


}
