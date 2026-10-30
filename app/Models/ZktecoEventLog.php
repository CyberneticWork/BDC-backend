<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZktecoEventLog extends Model
{
    protected $fillable = [
        'device_id',
        'serial_no',
        'external_id',
        'employee_no',
        'event_time',
        'source',
        'time_card_id',
        'status',
        'message',
    ];

    protected $casts = [
        'event_time' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(ZktecoDevice::class, 'device_id');
    }
}
