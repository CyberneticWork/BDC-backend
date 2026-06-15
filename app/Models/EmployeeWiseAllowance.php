<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeWiseAllowance extends Model
{
    protected $fillable = [
        'allowance_code',
        'allowance_name',
        'allowance_description',
        'employee_id',
        'amount',
        'date',
        'status'
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }
}
