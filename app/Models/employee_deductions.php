<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class employee_deductions extends Model
{
    protected $fillable = [
        'employee_id',
        'attendance_employee_no',
        'deduction_id',
        'custom_amount',
        'is_active',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }

    public function deduction()
    {
        return $this->belongsTo(deduction::class);
    }
}
