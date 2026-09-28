<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class organization_assignment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'department_id',
        'sub_department_id',
        'location_id',
        'designation_id',
        'current_supervisor',
        'date_of_joining',
        'day_off',
        'confirmation_date',
        'probationary_period',
        'training_period',
        'contract_period',
        'probationary_period_from',
        'probationary_period_to',
        'training_period_from',
        'training_period_to',
        'contract_period_from',
        'contract_period_to',
        'date_of_resigning',
        'resigned_reason',
        'is_active',
        'letter_path'
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }

    public function department()
    {
        return $this->belongsTo(departments::class, 'department_id');
    }

    public function company()
    {
        return $this->belongsTo(company::class, 'company_id');
    }

    public function subDepartment()
    {
        return $this->belongsTo(sub_departments::class, 'sub_department_id');
    }

    public function location()
    {
        return $this->belongsTo(CompanyLocation::class, 'location_id');
    }

    public function designation()
    {
        return $this->belongsTo(designation::class, 'designation_id');
    }
}
