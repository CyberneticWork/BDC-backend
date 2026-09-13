<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WeeklyOffEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'employee_id',
        'off_date',
        'days',
        'source',
        'status',
        'reason',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'off_date' => 'date',
        'days' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }
}
