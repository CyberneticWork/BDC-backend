<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeWiseDeduction extends Model
{
    protected $fillable = [
        'deduction_code',
        'deduction_name',
        'deduction_description',
        'employee_id',
        'amount',
        'date',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }
}
