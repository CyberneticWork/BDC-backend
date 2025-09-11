<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class modules extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'course_id',
        'title',
        'content',
        'completed',
        'path',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'completed' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationship: Module belongs to Course (specify foreign key)
    public function course(): BelongsTo
    {
        return $this->belongsTo(courses::class, 'course_id');  // Explicitly set foreign key
    }

    // Relationship: Module has many Progress records
    public function progress(): HasMany
    {
        return $this->hasMany(progress::class);
    }
}
