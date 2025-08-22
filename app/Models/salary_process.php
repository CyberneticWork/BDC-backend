<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class salary_process extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'employee_no',
        'full_name',
        'company_name',
        'department_name',
        'sub_department_name',
        'basic_salary',
        'increment_active',
        'increment_value',
        'increment_effected_date',
        'ot_morning',
        'ot_evening',
        'enable_epf_etf',
        'br1',
        'br2',
        'br_status',
        'stamp',
        'total_loan_amount',
        'installment_count',
        'installment_amount',
        'approved_no_pay_days',
        'allowances',
        'deductions',
        'salary_breakdown',
        'month',
        'year',
        'status'
    ];

    protected $casts = [
        'increment_active' => 'boolean',
        // OT values are decimals
        'ot_morning' => 'decimal:2',
        'ot_evening' => 'decimal:2',
        'enable_epf_etf' => 'boolean',
        'br1' => 'boolean',
        'br2' => 'boolean',
        'basic_salary' => 'decimal:2',
        'total_loan_amount' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'allowances' => 'json',
        'deductions' => 'json',
        'salary_breakdown' => 'json',
        'increment_effected_date' => 'date',
    ];
    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }
    public function compensation()
    {
        return $this->belongsTo(compensation::class, 'employee_id');
    }

    public function contactDetails()
    {
        return $this->belongsTo(contact_detail::class, 'employee_id');
    }


}
