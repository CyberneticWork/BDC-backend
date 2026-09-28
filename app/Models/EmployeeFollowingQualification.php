<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeFollowingQualification extends Model
{
    public const LECTURE_TYPES = ['Weekday', 'Weekend'];

    protected $table = 'employee_following_qualifications';

    protected $fillable = [
        'employee_id',
        'qualification_name',
        'institute_name',
        'start_year',
        'start_month',
        'end_year',
        'end_month',
        'lecture_type',
        'sort_order',
    ];

    protected $casts = [
        'start_year' => 'integer',
        'start_month' => 'integer',
        'end_year' => 'integer',
        'end_month' => 'integer',
        'sort_order' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }
}
