<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveSettingQuarterLeaveType extends Model
{
    use HasFactory;

    protected $fillable = [
        'quarter_id',
        'type_key',
        'name',
        'days',
    ];

    protected $casts = [
        'days' => 'integer',
    ];

    public function quarter()
    {
        return $this->belongsTo(LeaveSettingQuarter::class, 'quarter_id');
    }
}
