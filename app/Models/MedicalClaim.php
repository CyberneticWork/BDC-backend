<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicalClaim extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'year',
        'amount',
        'description',
        'bill_path',
        'bill_name',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }
}
