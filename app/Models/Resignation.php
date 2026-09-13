<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Resignation extends Model
{
    use HasFactory, SoftDeletes;
//
    protected $fillable = [
        'employee_id',
        'resigning_date',
        'last_working_day',
        'resignation_reason',
        'status',
        'submitted_via',
        'notes',
        'exit_interview_form_path',
        'clearance_form_path',
        'processed_by',
        'processed_at'
    ];

    protected $casts = [
        'resigning_date' => 'date',
        'last_working_day' => 'date',
        'processed_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function documents()
    {
        return $this->hasMany(ResignationDocument::class);
    }
}
