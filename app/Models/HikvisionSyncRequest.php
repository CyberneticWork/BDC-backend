<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A date range the HR app asked the office bridge to re-read from the device and re-send. */
class HikvisionSyncRequest extends Model
{
    protected $fillable = [
        'device_id',
        'from_date',
        'to_date',
        'status',
        'requested_by',
        'device_punches',
        'imported',
        'skipped',
        'message',
        'errors',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'from_date' => 'date:Y-m-d',
        'to_date' => 'date:Y-m-d',
        'device_punches' => 'integer',
        'imported' => 'integer',
        'skipped' => 'integer',
        'errors' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(HikvisionDevice::class, 'device_id');
    }
}
