<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class bonuses extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'bonus_code',
        'bonus_name',
        'status',
        'bonus_type',
        'amount',
        'company_id',
        'department_id',
        'fixed_date',
        'variable_from',
        'variable_to',
    ];

    protected $casts = [
        'fixed_date' => 'date',
        'variable_from' => 'date',
        'variable_to' => 'date'
    ];

    public function company()
    {
        return $this->belongsTo(company::class);
    }

    public function department()
    {
        return $this->belongsTo(departments::class);
    }

    // Optional: if you create employee_bonuses pivot later
    // public function employeeBonuses()
    // {
    //     return $this->hasMany(employee_bonuses::class);
    // }
}