<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Roster extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'shift_code',
        'roster_id',
        'company_id',
        'department_id',
        'sub_department_id',
        'employee_id',
        'is_recurring',
        'recurrence_pattern',
        'notes',
        'date_from',
        'date_to',
    ];

    //relation for company
    public function company()
    {
        return $this->belongsTo(company::class);
    }

    //relation for shift
    public function shift()
    {
       // return $this->belongsTo(shifts::class);
       return $this->belongsTo(shifts::class, 'shift_code', 'id');
    }
    //relation for department
    public function department()
    {
        return $this->belongsTo(departments::class);
    }

    //relation for sub_department
    public function subDepartment()
    {
        return $this->belongsTo(sub_departments::class);
    }

    //relation for employees
    public function employee()
    {
        return $this->belongsTo(employee::class);
    }
}
