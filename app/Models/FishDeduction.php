<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FishDeduction extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'fish_deductions';

    protected $fillable = [
        'company_id',
        'employee_id',
        'amount',
        'deduction_date',
        'applicable_month',
        'salary_year',
        'salary_month',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'deduction_date' => 'date:Y-m-d',
        'salary_year' => 'integer',
        'salary_month' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }

    public function company()
    {
        return $this->belongsTo(company::class, 'company_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForCompany($query, $companyId)
    {
        if ($companyId) {
            return $query->where('company_id', $companyId);
        }
        return $query;
    }

    public function scopeForMonth($query, $year, $month)
    {
        return $query->where('salary_year', (int) $year)
                     ->where('salary_month', (int) $month);
    }
}
