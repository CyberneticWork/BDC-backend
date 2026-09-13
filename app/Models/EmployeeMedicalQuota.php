<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeMedicalQuota extends Model
{
    protected $fillable = [
        'employee_id',
        'year',
        'allocated_amount',
        'notes',
    ];

    protected $casts = [
        'allocated_amount' => 'float',
    ];
}
