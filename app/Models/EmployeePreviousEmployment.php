<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeePreviousEmployment extends Model
{
    public const COMMENT_WORD_LIMIT = 50;

    protected $table = 'employee_previous_employments';

    protected $fillable = [
        'employee_id',
        'organization_name',
        'last_designation',
        'join_date',
        'last_date',
        'comments',
        'sort_order',
    ];

    protected $casts = [
        'join_date' => 'date:Y-m-d',
        'last_date' => 'date:Y-m-d',
        'sort_order' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }
}
