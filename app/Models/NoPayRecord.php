<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NoPayRecord extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'date',
        'no_pay_count',
        'description',
        'status',
        'processed_by',
        'type',
        'hours',
        'minutes',
        'start_time',
        'end_time'
    ];

    protected $casts = [
        'date' => 'date',
        'no_pay_count' => 'float',
        'hours' => 'float',
        'minutes' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class)->with('organizationAssignment');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'Pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'Approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'Rejected');
    }
}
/*
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NoPayRecord extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'date',
        'no_pay_count',
        'description',
        'status',
        'processed_by'
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class)->with('organizationAssignment');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'Pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'Approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'Rejected');
    }
}
*/