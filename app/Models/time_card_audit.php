<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class time_card_audit extends Model
{
    protected $table = 'time_card_audits';

    protected $fillable = [
        'time_card_id',
        'employee_id',
        'entry_date',
        'action',
        'source',
        'reason',
        'old_values',
        'new_values',
        'user_id',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'entry_date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function timeCard()
    {
        return $this->belongsTo(time_card::class, 'time_card_id');
    }
}
