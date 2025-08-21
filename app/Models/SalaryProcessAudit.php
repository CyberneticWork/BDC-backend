<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryProcessAudit extends Model
{
    public $timestamps = false; // We only need created_at
    
    protected $fillable = [
        'salary_process_id',
        'user_id',
        'user_name',
        'action',
        'changes'
    ];
    
    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime'
    ];
    
    public function salaryProcess()
    {
        return $this->belongsTo(salary_process::class, 'salary_process_id');
    }
    
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
