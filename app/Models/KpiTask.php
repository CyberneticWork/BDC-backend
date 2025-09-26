<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KpiTask extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['task_name'];
    
    // Add soft delete dates to the model
    protected $dates = ['deleted_at'];
    
    public function assignments()
    {
        return $this->hasMany(KpiTaskAssignment::class);
    }
}