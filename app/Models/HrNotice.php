<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrNotice extends Model
{
    protected $fillable = [
        'company_id',
        'scope',
        'department_id',
        'title',
        'body',
        'created_by',
    ];

    public function department()
    {
        return $this->belongsTo(departments::class, 'department_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
