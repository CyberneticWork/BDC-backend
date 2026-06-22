<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeWiseBonus extends Model
{
    protected $fillable = [
        'bonus_code',
        'bonus_name',
        'bonus_description',
        'employee_id',
        'amount',
        'date',
        'is_annual',
        'payment_months',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
        'is_annual' => 'boolean',
        'payment_months' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }
}
