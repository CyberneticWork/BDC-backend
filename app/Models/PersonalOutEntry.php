<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PersonalOutEntry extends Model
{
    use SoftDeletes;

    protected $table = 'personal_out_entries';

    protected $fillable = [
        'employee_id',
        'date',
        'out_time',
        'in_time',
        'minutes',
        'deduct_days',
        'leave_days',
        'nopay_days',
        'allocation',
        'leave_master_ids',
        'nopay_record_id',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'minutes' => 'integer',
        'deduct_days' => 'float',
        'leave_days' => 'float',
        'nopay_days' => 'float',
        'allocation' => 'array',
        'leave_master_ids' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
