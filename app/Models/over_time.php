<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class over_time extends Model
{
    use SoftDeletes;

    protected $table = 'over_times';

    protected $fillable = [
        'employee_id',
        'shift_code',
        'time_cards_id',
        'ot_hours',
        'morning_ot',
        'afternoon_ot',
        'morning_ot_special',
        'evening_ot_special',
        'morning_ot_amount',
        'morning_ot_special_amount',
        'evening_ot_amount',
        'evening_ot_special_amount',
        'total_ot_amount',
        'holiday_ot_hours',
        'holiday_ot_amount',
        'holiday_shift_hours',
        'holiday_outside_hours',
        'holiday_shift_amount',
        'holiday_outside_amount',
        'status',
    ];

    protected $casts = [
        'ot_hours' => 'float',
        'morning_ot' => 'float',
        'afternoon_ot' => 'float',
        'morning_ot_special' => 'float',
        'evening_ot_special' => 'float',
        'morning_ot_amount' => 'float',
        'morning_ot_special_amount' => 'float',
        'evening_ot_amount' => 'float',
        'evening_ot_special_amount' => 'float',
        'total_ot_amount' => 'float',
        'holiday_ot_hours' => 'float',
        'holiday_ot_amount' => 'float',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }

    public function shift()
    {
        return $this->belongsTo(shifts::class, 'shift_code');
    }

    public function timeCard()
    {
        return $this->belongsTo(time_card::class, 'time_cards_id');
    }
}


/*
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class over_time extends Model
{
    use SoftDeletes;

    protected $table = 'over_times'; // ✅ make sure correct (most common)

    protected $fillable = [
        'employee_id',
        'shift_code',
        'time_cards_id',
        'ot_hours',
        'morning_ot',
        'afternoon_ot',
        'morning_ot_special',
        'evening_ot_special',
        'morning_ot_amount',
        'morning_ot_special_amount',
        'evening_ot_amount',
        'evening_ot_special_amount',
        'total_ot_amount',
        'status',
    ];

    protected $casts = [
        'ot_hours' => 'float',
        'morning_ot' => 'float',
        'afternoon_ot' => 'float',
        'morning_ot_special' => 'float',
        'evening_ot_special' => 'float',
        'morning_ot_amount' => 'float',
        'morning_ot_special_amount' => 'float',
        'evening_ot_amount' => 'float',
        'evening_ot_special_amount' => 'float',
        'total_ot_amount' => 'float',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }
    public function shift()
    {
        return $this->belongsTo(shifts::class, 'shift_code', 'id');
    }
    public function timeCard()
    {
       // return $this->belongsTo(time_card::class, 'time_cards_id', 'id');
       return $this->belongsTo(\App\Models\time_card::class, 'time_cards_id');
    }
}
*/