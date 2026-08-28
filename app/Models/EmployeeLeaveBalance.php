<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeLeaveBalance extends Model
{
    use SoftDeletes;

    protected $table = 'employee_leave_balances';

    protected $fillable = [
        'employee_id',
        'year',
        'leave_type',
        'entitled_days',
        'notes',
        'status',
    ];

    protected $casts = [
        'year' => 'integer',
        'entitled_days' => 'float',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }
}
