<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserActivityLog extends Model
{
    public $timestamps = false;

    protected $table = 'user_activity_logs';

    protected $fillable = [
        'user_id',
        'user_name',
        'user_role',
        'action',
        'module',
        'method',
        'path',
        'subject_type',
        'subject_id',
        'description',
        'meta',
        'status_code',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];
}
