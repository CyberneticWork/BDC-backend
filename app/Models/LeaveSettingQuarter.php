<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveSettingQuarter extends Model
{
    use HasFactory;

    protected $fillable = [
        'leave_setting_id',
        'quarter_number',
        'name',
        'start_month',
        'end_month',
        'leave_days',
    ];

    protected $casts = [
        'start_month' => 'integer',
        'end_month' => 'integer',
        'quarter_number' => 'integer',
        'leave_days' => 'integer',
    ];

    public function leaveSetting()
    {
        return $this->belongsTo(LeaveSetting::class);
    }

    public function leaveTypes()
    {
        return $this->hasMany(LeaveSettingQuarterLeaveType::class, 'quarter_id')->orderBy('name');
    }
}
