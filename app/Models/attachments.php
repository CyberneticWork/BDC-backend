<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class attachments extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'course_id',
        'name',
        'type',
        'url',
        'size',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationship: Attachment belongs to Course (specify foreign key)
    public function course(): BelongsTo
    {
        return $this->belongsTo(courses::class, 'course_id');  // Explicitly set foreign key
    }
}
