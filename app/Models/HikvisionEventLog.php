<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HikvisionEventLog extends Model
{
    protected $fillable = [
        'device_id',
        'serial_no',
        'employee_no',
        'event_time',
        'source',
        'time_card_id',
        'status',
        'message',
    ];

    protected $casts = [
        'event_time' => 'datetime',
        'serial_no' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(HikvisionDevice::class, 'device_id');
    }
}
