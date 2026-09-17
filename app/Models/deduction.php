<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class deduction extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'department_id',
        'company_id',     // Add this line
        'deduction_code',
        'deduction_name',
        'description',
        'amount',
        'status',
        'category',
        'deduction_type',
        'deduct_from',
        'startDate',
        'endDate',
        'created_at',
        'updated_at',
        'deleted_at'
    ];


    public function department(): BelongsTo
    {
        return $this->belongsTo(departments::class);
    }


    // Update the company relationship to direct relationship
    public function company(): BelongsTo
    {
        return $this->belongsTo(company::class);
    }

    public function employeeDeductions()
    {
        return $this->hasMany(employee_deductions::class);
    }
}
