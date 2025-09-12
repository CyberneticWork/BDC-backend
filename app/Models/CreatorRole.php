<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreatorRole extends Model
{
    use HasFactory;

    protected $fillable = ['role_name'];
    
    public function taskAssignments()
    {
        return $this->hasMany(KpiTaskAssignment::class);
    }
}