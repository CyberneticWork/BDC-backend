<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Services\CompensationCalculationService;

class compensation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'employee_category',
        'basic_salary',
        'monthly_bonus',
        'sports_fund_percentage',
        'staff_fund_amount',
        'increment_value',
        'increment_effected_date',
        'bank_name',
        'branch_name',
        'bank_code',
        'branch_code',
        'bank_account_no',
        'account_holder_name',
        'comments',
        'secondary_emp',
        'primary_emp_basic',
        'enable_epf_etf',
        'ot_active',
        'ot_active_special',
        'early_deduction',
        'increment_active',
        'active_nopay',
        'ot_morning',
        'ot_evening',
        'ot_morning_rate',
        'ot_night_rate',

        'ot_morning_special',
        'ot_evening_special',
        'ot_morning_rate_special',
        'ot_night_rate_special',
        'br1',
        'br2',
        'stamp',
    ];

    protected $appends = [
        'total_salary',
        'sports_fund_amount',
        'remaining_total_salary',
    ];
    public function employee()
    {
        return $this->belongsTo(employee::class);
    }

    public function salaryProcesses()
    {
        return $this->hasMany(salary_process::class, 'employee_id');
    }

    public function getCompensationSummary(): array
    {
        return app(CompensationCalculationService::class)->calculateCompensationSummary(
            (float) ($this->basic_salary ?? 0),
            (float) ($this->monthly_bonus ?? 0),
            (float) ($this->sports_fund_percentage ?? 0),
            (float) ($this->staff_fund_amount ?? 0),
        );
    }

    public function getTotalSalaryAttribute(): float
    {
        return $this->getCompensationSummary()['total_salary'];
    }

    public function getSportsFundAmountAttribute(): float
    {
        return $this->getCompensationSummary()['sports_fund_amount'];
    }

    public function getRemainingTotalSalaryAttribute(): float
    {
        return $this->getCompensationSummary()['remaining_total_salary'];
    }
}
