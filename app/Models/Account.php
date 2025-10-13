<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Account extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'accountNumber',
        'accountName',
        'accountType',
        'accountSubCategory',
        'openingBalance',
        'created_by',
        'created_at',
        'updated_at',
    ];



    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

   
}
