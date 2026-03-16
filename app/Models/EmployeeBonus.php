<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeBonus extends Model
{
    protected $fillable = [
        'employee_id',
        'bonus_id',
        'custom_amount',
        'month',          // 
        'year',
        'is_active'
    ];
}