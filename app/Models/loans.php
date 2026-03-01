<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class loans extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'loan_id',
        'employee_id',
        'loan_amount',
        'interest_rate_per_annum',
        'installment_amount',
        'start_from',
        'with_interest',
        'installment_count',
        'status',
        
    ];


     // ✅ THIS FIXES "Array to string conversion"
    protected $casts = [
        'with_interest' => 'boolean',
        'loan_amount' => 'float',
        'installment_amount' => 'float',
        'interest_rate_per_annum' => 'float',
        'installment_count' => 'integer',
        'schedule' => 'array', // JSON <-> array automatic
        'start_from' => 'date',
    ];

    //relationships

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }
}
