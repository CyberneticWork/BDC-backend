<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class company extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_code',
        'name',
        'location',
        'established',
        'nopay_working_days',
        'default_sports_fund_percentage',
    ];

    protected $casts = [
        'nopay_working_days' => 'integer',
        'default_sports_fund_percentage' => 'float',
    ];

    public function departments()
    {
        return $this->hasMany(departments::class);
    }

    // Relationship: company → organization_assignments
    public function organizationAssignments()
    {
        return $this->hasMany(organization_assignment::class, 'company_id');
    }

    // Employees through organization_assignments
    public function employees()
    {
        return $this->hasManyThrough(
            employee::class,
            organization_assignment::class,
            'company_id', // Foreign key on organization_assignments
            'organization_assignment_id', // Foreign key on employees
            'id', // Local key on companies
            'id' // Local key on organization_assignments
        );
    }
    // Relationship: company → leave_calendars
    public function leaveCalendars()
    {
        return $this->hasMany(leaveCalendar::class, 'company_id');
    }

    // Relationship: company → rosters
    public function rosters()
    {
        return $this->hasMany(roster::class);
    }
}
