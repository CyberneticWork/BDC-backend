<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiTask extends Model
{
    use HasFactory;

    protected $fillable = ['task_name', 'description'];
    
    public function assignments()
    {
        return $this->hasMany(KpiTaskAssignment::class);
    }
}