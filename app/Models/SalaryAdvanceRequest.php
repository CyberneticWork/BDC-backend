<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryAdvanceRequest extends Model
{
    use SoftDeletes;

    protected $table = 'salary_advance_requests';

    protected $fillable = [
        'employee_id',
        'amount',
        'reason',
        'needed_on',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'needed_on' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
