<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingPayment extends Model
{
    protected $fillable = [
        'company_id',
        'employee_id',
        'source_type',
        'source_id',
        'amount',
        'status',
        'paid_by',
        'paid_at',
        'note',
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }
}
