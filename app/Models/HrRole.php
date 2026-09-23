<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrRole extends Model
{
    protected $table = 'hr_roles';

    protected $fillable = [
        'role_key',
        'name',
        'based_on',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public const SYSTEM = [
        ['role_key' => 'admin', 'name' => 'Administrator', 'based_on' => 'admin'],
        ['role_key' => 'hr', 'name' => 'HR', 'based_on' => 'hr'],
        ['role_key' => 'supervisor', 'name' => 'Supervisor', 'based_on' => 'supervisor'],
        ['role_key' => 'user', 'name' => 'User', 'based_on' => 'user'],
        ['role_key' => 'employee', 'name' => 'Employee', 'based_on' => 'employee'],
    ];

    public const STAFF_BASES = ['hr', 'supervisor', 'user'];
}