<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSchoolResult extends Model
{
    public const OL_GRADES = ['A*', 'A', 'B', 'C', 'D', 'E', 'S', 'F', 'W', 'U'];

    public const AL_SYLLABUSES = ['National', 'Cambridge', 'AQA'];

    protected $table = 'employee_school_results';

    protected $fillable = [
        'employee_id',
        'ol_english_grade',
        'ol_maths_grade',
        'ol_year',
        'al_syllabus',
        'al_stream',
        'al_year',
    ];

    protected $casts = [
        'ol_year' => 'integer',
        'al_year' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }
}
