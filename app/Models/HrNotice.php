<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrNotice extends Model
{
    protected $fillable = [
        'company_id',
        'scope',
        'department_id',
        'location_id',
        'employee_ids',
        'title',
        'body',
        'created_by',
    ];

    protected $casts = [
        'employee_ids' => 'array',
    ];

    public function department()
    {
        return $this->belongsTo(departments::class, 'department_id');
    }

    public function location()
    {
        return $this->belongsTo(CompanyLocation::class, 'location_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
