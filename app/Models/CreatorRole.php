<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreatorRole extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['role_name'];

    // SoftDeletes will use `deleted_at` column
    protected $dates = ['deleted_at'];

    public function taskAssignments()
    {
        return $this->hasMany(KpiTaskAssignment::class);
    }
}