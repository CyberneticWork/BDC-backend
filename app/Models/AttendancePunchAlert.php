<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendancePunchAlert extends Model
{
    protected $fillable = [
        'employee_id',
        'work_date',
        'kind',
        'shift_id',
        'sent_at',
    ];

    protected $casts = [
        'work_date' => 'date',
        'sent_at' => 'datetime',
    ];
}
