<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class courses extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'duration',
        'created_by',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationship: Course belongs to User (creator)
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Relationship: Course has many Modules (specify foreign key)
    public function modules(): HasMany
    {
        return $this->hasMany(modules::class, 'course_id');  // Explicitly set foreign key
    }

    // Relationship: Course has many Attachments (specify foreign key)
    public function attachments(): HasMany
    {
        return $this->hasMany(attachments::class, 'course_id');  // Explicitly set foreign key
    }

    // Relationship: Course has many Exams
    public function exams(): HasMany
    {
        return $this->hasMany(exams::class);
    }

    // Relationship: Course has many Enrollments
    public function enrollments(): HasMany
    {
        return $this->hasMany(enrollments::class);
    }

    // Relationship: Course has many Progress records
    public function progress(): HasMany
    {
        return $this->hasMany(progress::class);
    }

    // Relationship: Course has many Certificates
    public function certificates(): HasMany
    {
        return $this->hasMany(certificates::class);
    }
}
