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
        'midnight_roster'
    ];

    protected $casts = [
        'midnight_roster' => 'boolean',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
    ];
}