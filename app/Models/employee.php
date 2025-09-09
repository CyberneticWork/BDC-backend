<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'attendance_employee_no',
        'epf',
        'nic',
        'dob',
        'gender',
        'religion',
        'country_of_birth',
        'name_with_initials',
        'full_name',
        'display_name',
        'marital_status',
        'is_active',
        'employment_type_id',
        'organization_assignment_id',
        'spouse_id',
        'profile_photo_path'
    ];
    public function employmentType()
    {
        return $this->belongsTo(employment_type::class);
    }

    public function organizationAssignment()
    {
        return $this->belongsTo(organization_assignment::class, 'organization_assignment_id');
    }

    public function spouse()
    {
        return $this->belongsTo(spouse::class);
    }

    public function children()
    {
        return $this->hasMany(children::class);
    }

    public function contactDetail()
    {
        return $this->hasOne(contact_detail::class);
    }

    public function compensation()
    {
        return $this->hasOne(compensation::class);
    }

    //relation to roster
    public function rosters()
    {
        return $this->hasMany(roster::class);
    }
    //relation to leave master
    public function leaveMasters()
    {
        return $this->hasMany(leave_master::class);
    }
    //relation to employee allowances
    public function employeeAllowances()
    {
        return $this->hasMany(employee_allowances::class);
    }
    //relation to employee deductions
    public function employeeDeductions()
    {
        return $this->hasMany(employee_deductions::class);
    }

    public function allowances()
    {
        return $this->belongsToMany(Allowances::class, 'employee_allowances', 'employee_id', 'allowance_id')
            ->withPivot('custom_amount');
    }

    public function deductions()
    {
        return $this->belongsToMany(Deduction::class, 'employee_deductions', 'employee_id', 'deduction_id')
            ->withPivot('custom_amount');
    }

    public function loans()
    {
        return $this->hasMany(loans::class);
    }

    public function noPayRecords()
    {
        return $this->hasMany(NoPayRecord::class);
    }
    public function salaryProcesses()
    {
        return $this->hasMany(salary_process::class, 'employee_id');
    }
    public function overTimes()
    {
        return $this->hasMany(over_time::class);
    }

    public function pmsTaskUpdates()
    {
        return $this->hasMany(PmsTaskUpdate::class, 'employee_id', 'attendance_employee_no');
    }

    public function pmsTaskAssignees()
    {
        return $this->hasMany(PmsTaskAssignee::class, 'employee_id', 'attendance_employee_no');
    }

    public function pmsTaskCreators()
    {
        return $this->hasMany(PmsTaskCreator::class, 'employee_id', 'attendance_employee_no');
    }

    public function pmsPerformanceReviews()
    {
        return $this->hasMany(PmsPerformanceReview::class, 'employee_id', 'attendance_employee_no');
    }

}
