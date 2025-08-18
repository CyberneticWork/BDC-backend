<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class compensation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'basic_salary',
        'increment_value',
        'increment_effected_date',
        'bank_name',
        'branch_name',
        'bank_code',
        'branch_code',
        'bank_account_no',
        'comments',
        'secondary_emp',
        'primary_emp_basic',
        'enable_epf_etf',
        'ot_active',
        'early_deduction',
        'increment_active',
        'active_nopay',
        'ot_morning',
        'ot_evening',
        'ot_morning_rate',
        'ot_night_rate',
        'br1',
        'br2',
        'stamp',
    ];
    public function employee()
    {
        return $this->belongsTo(employee::class);
    }

    public function salaryProcesses()
    {
        return $this->hasMany(salary_process::class, 'employee_id');
    }
}
