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
        'profile_photo_path',
        'email',
        'password'
    ];

    protected $hidden = [
        'password'
    ];

    // Existing relations ...

    // **My Profile API සදහා relations**
    public function contactDetail()
    {
        return $this->hasOne(contact_detail::class);
    }

    public function compensation()
    {
        return $this->hasOne(compensation::class);
    }

    public function employmentType()
    {
        return $this->belongsTo(employment_type::class, 'employment_type_id');
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

    // Optional: allowances, deductions
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

    public function rosters()
    {
        return $this->hasMany(Roster::class);
    }

    public function documents()
    {
        return $this->hasMany(documents::class);
    }

    public function overtimes()
    {
        return $this->hasMany(over_time::class, 'employee_id');
    }
}