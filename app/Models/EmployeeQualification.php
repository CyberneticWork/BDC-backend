<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeQualification extends Model
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FOLLOWING = 'following';

    public const TYPES = [
        'Certificate',
        'Diploma',
        'Higher Diploma',
        'Higher National Diploma',
        'Bachelors',
        'Bachelors Honours',
        'Postgraduate Certificate',
        'Post Graduate Diploma',
        'Masters by Course Work',
        'Masters with Course Work and a Research Component',
        'Master of Philosophy',
        'Doctorate',
    ];

    protected $table = 'employee_qualifications';

    protected $fillable = [
        'employee_id',
        'status',
        'qualification_type',
        'course_name',
        'institute_name',
        'completion_year',
        'sort_order',
    ];

    protected $casts = [
        'completion_year' => 'integer',
        'sort_order' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }
}
