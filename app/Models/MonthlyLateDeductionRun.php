<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyLateDeductionRun extends Model
{
    protected $fillable = [
        'month',
        'year',
        'company_id',
        'status',
        'applied_by',
        'applied_at',
        'employee_count',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(MonthlyLateDeductionItem::class, 'run_id');
    }
}
