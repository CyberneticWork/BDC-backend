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
        'status',
        'cancel_reason',
        'cancelled_at',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
        'is_recurring' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('status')->orWhere('status', 'Active');
        });
    }

    public function isCancelled(): bool
    {
        return strcasecmp((string) $this->status, 'Cancelled') === 0;
    }

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
